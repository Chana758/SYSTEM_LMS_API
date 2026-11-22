/*
 * Smart Nexus — Door controller (ESP32 + relay)
 * ------------------------------------------------------------
 * Opens the library door when the Smart Nexus server says so.
 *
 * How it works:
 *   1. A student scans a card on the kiosk tablet.
 *   2. The server checks the card and leaves an "open" command for ~10 s.
 *   3. This board asks the server every second:
 *        GET <POLL_URL>   header  X-Door-Key: <DOOR_KEY>
 *      Answer: {"open":true,"pulse_ms":1000}  or  {"open":false}
 *   4. On "open" the relay is switched on for pulse_ms, then off.
 *
 * The board only ever polls out to the server, so it works behind the
 * school's router (no port forwarding, no fixed IP).
 *
 * SAFETY: the door must still open from inside without this board
 * (exit button / free exit, fail-safe magnetic lock). This board never
 * opens the door on its own: no server answer = door stays as it is.
 *
 * Board : "ESP32 Dev Module" (Arduino IDE, esp32 core by Espressif 2.x or 3.x)
 * Needs : no extra libraries.
 */

#include <WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>

// ============================================================
//  SETTINGS — change these
// ============================================================

// Wi-Fi of the library
const char* WIFI_SSID = "YOUR_WIFI_NAME";
const char* WIFI_PASS = "YOUR_WIFI_PASSWORD";

// From Smart Nexus: Settings -> Kiosk & doors -> "Create door key".
// Copy "Poll URL" and "Door key" exactly.
// Testing on your Mac: replace "localhost" with the Mac's Wi-Fi IP,
// e.g. "http://192.168.1.20:8000/api/door/1/poll" (see README).
const char* POLL_URL = "https://your-domain/api/door/1/poll";
const char* DOOR_KEY = "PASTE_THE_DOOR_KEY_HERE";

// Optional, for HTTPS: the root certificate of your server (PEM).
// Leave empty to skip the certificate check (OK for a demo, NOT for real use:
// then anyone on the network could pretend to be the server).
const char* ROOT_CA = "";

// Pins
const int  RELAY_PIN        = 26;    // relay module "IN"
const bool RELAY_ACTIVE_LOW = true;  // most cheap relay modules switch on with LOW
const int  LED_PIN          = 2;     // on-board blue LED on most ESP32 boards (-1 = none)
const int  BUZZER_PIN       = -1;    // optional active buzzer (-1 = none)

// Timing
const unsigned long POLL_EVERY_MS      = 1000;   // server allows up to 120 polls/minute
const unsigned long HTTP_TIMEOUT_MS    = 4000;
const unsigned long WIFI_RESTART_AFTER = 300000; // restart the board after 5 min without Wi-Fi
const unsigned long DEFAULT_PULSE_MS   = 1000;
const unsigned long MIN_PULSE_MS       = 200;
const unsigned long MAX_PULSE_MS       = 10000;

// ============================================================
//  Nothing to change below
// ============================================================

enum State { WIFI_CONNECTING, ONLINE, SERVER_ERROR, BAD_KEY, DOOR_DISABLED };

State state = WIFI_CONNECTING;
WiFiClient plainClient;
WiFiClientSecure secureClient;
HTTPClient http;

bool          relayOn        = false;
unsigned long relayOffAt     = 0;
unsigned long nextPollAt     = 0;
unsigned long wifiLostSince  = 0;
unsigned long failStreak     = 0;
unsigned long lastHeartbeat  = 0;

// ---------- relay ----------
void relayWrite(bool on) {
  digitalWrite(RELAY_PIN, (on ^ RELAY_ACTIVE_LOW) ? HIGH : LOW);
  if (BUZZER_PIN >= 0) digitalWrite(BUZZER_PIN, on ? HIGH : LOW);
  relayOn = on;
}

void openDoor(unsigned long pulseMs) {
  if (pulseMs < MIN_PULSE_MS) pulseMs = MIN_PULSE_MS;
  if (pulseMs > MAX_PULSE_MS) pulseMs = MAX_PULSE_MS;
  Serial.printf("[door] OPEN for %lu ms\n", pulseMs);
  relayWrite(true);
  relayOffAt = millis() + pulseMs;
}

void relayTick() {
  if (relayOn && (long)(millis() - relayOffAt) >= 0) {
    relayWrite(false);
    Serial.println("[door] closed");
  }
}

// ---------- status LED ----------
//  fast blink      = connecting to Wi-Fi
//  slow blink      = server not reachable
//  double blink    = wrong door key (401)
//  triple blink    = door switched off in Smart Nexus settings
//  short flash/2s  = all good
//  on              = door opening
void ledTick() {
  if (LED_PIN < 0) return;
  unsigned long t = millis();
  bool on = false;

  if (relayOn) {
    on = true;
  } else {
    switch (state) {
      case WIFI_CONNECTING: on = (t / 100) % 2; break;
      case SERVER_ERROR:    on = (t / 1000) % 2; break;
      case BAD_KEY:       { unsigned long p = t % 1500; on = p < 150 || (p > 300 && p < 450); break; }
      case DOOR_DISABLED: { unsigned long p = t % 2000; on = p < 120 || (p > 240 && p < 360) || (p > 480 && p < 600); break; }
      case ONLINE:          on = (t - lastHeartbeat) < 40; if (t - lastHeartbeat > 2000) lastHeartbeat = t; break;
    }
  }
  digitalWrite(LED_PIN, on ? HIGH : LOW);
}

// ---------- Wi-Fi ----------
void wifiTick() {
  if (WiFi.status() == WL_CONNECTED) {
    if (wifiLostSince) {
      Serial.printf("[wifi] connected, IP %s\n", WiFi.localIP().toString().c_str());
      wifiLostSince = 0;
      state = ONLINE;
    }
    return;
  }

  if (!wifiLostSince) {
    wifiLostSince = millis();
    state = WIFI_CONNECTING;
    Serial.println("[wifi] connecting...");
    WiFi.disconnect();
    WiFi.begin(WIFI_SSID, WIFI_PASS);
  }

  if (millis() - wifiLostSince > WIFI_RESTART_AFTER) {
    Serial.println("[wifi] no connection for 5 minutes, restarting");
    delay(200);
    ESP.restart();
  }
}

// ---------- tiny JSON helpers (answers are always small) ----------
bool jsonTrue(const String& body, const char* key) {
  String k = String("\"") + key + "\"";
  int i = body.indexOf(k);
  if (i < 0) return false;
  i = body.indexOf(':', i);
  if (i < 0) return false;
  while (++i < (int)body.length() && body[i] == ' ') {}
  return body.startsWith("true", i);
}

bool jsonFalse(const String& body, const char* key) {
  String k = String("\"") + key + "\"";
  int i = body.indexOf(k);
  if (i < 0) return false;
  i = body.indexOf(':', i);
  if (i < 0) return false;
  while (++i < (int)body.length() && body[i] == ' ') {}
  return body.startsWith("false", i);
}

long jsonNumber(const String& body, const char* key, long fallback) {
  String k = String("\"") + key + "\"";
  int i = body.indexOf(k);
  if (i < 0) return fallback;
  i = body.indexOf(':', i);
  if (i < 0) return fallback;
  long v = body.substring(i + 1).toInt();
  return v > 0 ? v : fallback;
}

// ---------- polling ----------
void poll() {
  bool https = String(POLL_URL).startsWith("https");
  bool ok = https ? http.begin(secureClient, POLL_URL) : http.begin(plainClient, POLL_URL);
  if (!ok) {
    Serial.println("[poll] bad POLL_URL");
    state = SERVER_ERROR;
    nextPollAt = millis() + 10000;
    return;
  }

  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-Door-Key", DOOR_KEY);
  http.addHeader("Accept", "application/json");

  int code = http.GET();
  String body = code > 0 ? http.getString() : String();
  http.end();

  if (code == 200) {
    failStreak = 0;
    if (jsonFalse(body, "enabled")) {
      state = DOOR_DISABLED;                    // "Connect door" is unticked in Settings
    } else {
      state = ONLINE;
      if (jsonTrue(body, "open")) {
        openDoor((unsigned long)jsonNumber(body, "pulse_ms", DEFAULT_PULSE_MS));
      }
    }
    nextPollAt = millis() + POLL_EVERY_MS;
    return;
  }

  if (code == 401) {
    Serial.println("[poll] 401: wrong DOOR_KEY or device id in POLL_URL");
    state = BAD_KEY;
    nextPollAt = millis() + 15000;              // don't hammer the server with a wrong key
    return;
  }

  if (code == 429) {
    Serial.println("[poll] 429: polling too fast, slowing down");
    nextPollAt = millis() + 5000;
    return;
  }

  // network error (code < 0) or server error (5xx)
  failStreak++;
  state = SERVER_ERROR;
  unsigned long wait = 1000UL * (failStreak < 10 ? failStreak : 10);
  if (code < 0) Serial.printf("[poll] no answer (%s), retry in %lu s\n", http.errorToString(code).c_str(), wait / 1000);
  else          Serial.printf("[poll] HTTP %d, retry in %lu s\n", code, wait / 1000);
  nextPollAt = millis() + wait;
}

// ============================================================
void setup() {
  // Relay OFF before the pin becomes an output, so the door never twitches at power-on.
  digitalWrite(RELAY_PIN, RELAY_ACTIVE_LOW ? HIGH : LOW);
  pinMode(RELAY_PIN, OUTPUT);
  relayWrite(false);

  if (LED_PIN >= 0)    pinMode(LED_PIN, OUTPUT);
  if (BUZZER_PIN >= 0) { pinMode(BUZZER_PIN, OUTPUT); digitalWrite(BUZZER_PIN, LOW); }

  Serial.begin(115200);
  delay(200);
  Serial.println();
  Serial.println("Smart Nexus door controller");
  Serial.printf("Poll URL: %s\n", POLL_URL);

  if (strlen(ROOT_CA) > 0) {
    secureClient.setCACert(ROOT_CA);
  } else {
    secureClient.setInsecure();
    if (String(POLL_URL).startsWith("https")) {
      Serial.println("WARNING: ROOT_CA is empty, the server certificate is not checked.");
    }
  }

  http.setReuse(true);

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.setSleep(false);                         // faster answers
  wifiLostSince = millis();
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.println("[wifi] connecting...");
}

void loop() {
  relayTick();
  ledTick();
  wifiTick();

  // Never poll while the relay is on: one opening at a time.
  if (WiFi.status() == WL_CONNECTED && !relayOn && (long)(millis() - nextPollAt) >= 0) {
    poll();
  }

  delay(10);
}
