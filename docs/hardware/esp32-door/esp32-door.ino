/*
 * Smart Nexus - door controller (ESP32 + 1-channel relay)
 *
 * Every second it asks the server: GET {POLL_URL}  (header X-Door-Key: {DOOR_KEY})
 * Server answers {"open":true,"pulse_ms":3000} right after an accepted check-in,
 * otherwise {"open":false}. On "open" the relay is switched on for pulse_ms.
 *
 * Get POLL_URL and DOOR_KEY in Settings > Kiosks and doors > "Create door key".
 *
 * Wiring (see README.md):
 *   ESP32 GPIO 26 -> relay IN, 5V -> relay VCC, GND -> relay GND
 *   Relay COM/NO (or NC) switches the 12 V supply of the electric lock / maglock.
 *   The EXIT button is wired directly to the lock supply, NOT through the ESP32,
 *   so people can always leave even if Wi-Fi, the server or this board is down.
 */

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>

// ---- Change these -------------------------------------------------------
const char* WIFI_SSID     = "SCHOOL-WIFI";
const char* WIFI_PASSWORD = "wifi-password";
const char* POLL_URL      = "https://api.example.edu.kh/api/door/1/poll";
const char* DOOR_KEY      = "paste-the-door-key-here";

// Root certificate of the server's HTTPS (for Let's Encrypt: "ISRG Root X1").
// Leave empty ONLY for a bench test: the connection is then not verified.
const char* ROOT_CA = "";

const int  RELAY_PIN        = 26;
const bool RELAY_ACTIVE_LOW = false;   // many cheap relay boards switch on LOW: set true
const int  LED_PIN          = 2;       // on-board LED: on while the door is open
const unsigned long POLL_EVERY_MS = 1000;
// -------------------------------------------------------------------------

WiFiClientSecure tls;
unsigned long lastPoll = 0;
unsigned long relayOffAt = 0;
bool relayOn = false;

void setRelay(bool on) {
  relayOn = on;
  digitalWrite(RELAY_PIN, (on ^ RELAY_ACTIVE_LOW) ? HIGH : LOW);
  digitalWrite(LED_PIN, on ? HIGH : LOW);
}

void connectWifi() {
  if (WiFi.status() == WL_CONNECTED) return;
  Serial.printf("Wi-Fi: connecting to %s", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) {
    delay(300);
    Serial.print(".");
  }
  Serial.println(WiFi.status() == WL_CONNECTED ? " ok" : " failed (will retry)");
}

// Very small JSON reader: enough for {"open":true,"pulse_ms":3000}
long readPulse(const String& body) {
  int i = body.indexOf("\"pulse_ms\":");
  if (i < 0) return 3000;
  long ms = body.substring(i + 11).toInt();
  return (ms >= 500 && ms <= 15000) ? ms : 3000;
}

void poll() {
  HTTPClient http;
  http.setTimeout(4000);
  if (!http.begin(tls, POLL_URL)) {
    Serial.println("HTTP: begin failed");
    return;
  }
  http.addHeader("X-Door-Key", DOOR_KEY);
  http.addHeader("Accept", "application/json");

  int code = http.GET();
  if (code == 200) {
    String body = http.getString();
    if (body.indexOf("\"open\":true") >= 0) {
      long ms = readPulse(body);
      Serial.printf("OPEN for %ld ms\n", ms);
      setRelay(true);
      relayOffAt = millis() + ms;
    }
  } else if (code == 401) {
    Serial.println("401: wrong DOOR_KEY or device id in POLL_URL");
  } else {
    Serial.printf("HTTP %d\n", code);
  }
  http.end();
}

void setup() {
  Serial.begin(115200);
  pinMode(RELAY_PIN, OUTPUT);
  pinMode(LED_PIN, OUTPUT);
  setRelay(false);

  if (strlen(ROOT_CA) > 0) {
    tls.setCACert(ROOT_CA);
  } else {
    tls.setInsecure();
    Serial.println("WARNING: TLS not verified (ROOT_CA empty). Bench test only.");
  }

  connectWifi();
}

void loop() {
  if (relayOn && (long)(millis() - relayOffAt) >= 0) {
    setRelay(false);
  }

  if (millis() - lastPoll >= POLL_EVERY_MS) {
    lastPoll = millis();
    connectWifi();
    if (WiFi.status() == WL_CONNECTED) poll();
  }
}
