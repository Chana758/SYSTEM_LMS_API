# Smart Nexus — Controller ទ្វារ (ESP32 + Relay)

ឧបករណ៍តូចមួយដែលបើកទ្វារបណ្ណាល័យ ពេលសិស្សស្កេនកាតត្រឹមត្រូវនៅ Kiosk។

```
 Kiosk (Tablet + ម៉ាស៊ីនស្កេន) ──► Server Smart Nexus ◄── ESP32 សួររៀងរាល់ 1 វិនាទី ──► Relay ──► សោទ្វារ
```

ESP32 តែងតែ **សួរចេញទៅ Server** (មិនមែន Server ហៅចូល ESP32 ទេ) ដូច្នេះដំណើរការនៅក្រោយ Router សាលាបាន ដោយមិនចាំបាច់ IP ថេរ ឬ Port forwarding។

---

## ១. ឧបករណ៍ (~$10)

| ឧបករណ៍ | ចំនួន | ចំណាំ |
|---|---|---|
| ESP32 Dev Board (ESP32-WROOM-32) | 1 | មានអំពូល LED ពណ៌ខៀវលើបន្ទះ |
| Relay module 5V, 1 channel | 1 | ប្រភេទ "Low level trigger" ធម្មតា |
| ខ្សែ Jumper (ញី-ញី) | 3 | |
| ខ្សែ USB (Micro-USB ឬ USB-C តាមបន្ទះ) | 1 | ត្រូវជាខ្សែមានទិន្នន័យ មិនមែនខ្សែសាកតែភ្លើង |
| *(Demo)* អំពូល LED + Resistor 220Ω ឬ Buzzer | 1 | តំណាងឲ្យ "ទ្វារបើក" |

---

## ២. ការភ្ជាប់ខ្សែ

```
 ESP32            Relay module
 ─────            ────────────
 VIN (5V)  ─────► VCC
 GND       ─────► GND
 GPIO 26   ─────► IN
```

**ខាង Relay ទៅសោទ្វារ (ធ្វើដោយជាងភ្លើង):**
ភ្ជាប់ **COM** និង **NO** របស់ Relay **ស្របទៅនឹងខ្សែប៊ូតុង Exit** របស់ម៉ាស៊ីនបើកទ្វារ (ZKTeco, Hikvision, Turnstile…)។
ពេល Relay បិទ ម៉ាស៊ីនបើកទ្វារគិតថាមានគេចុចប៊ូតុង Exit → ទ្វារបើក។

**Demo គ្មានទ្វារ:** ភ្ជាប់អំពូល LED (ឬ Buzzer) ជាមួយថ្ម/5V តាម COM–NO។ ពេលស្កេនកាត អំពូលភ្លឺ ហើយ Relay ឮ "ក្លិក"។

> ⚠️ **សុវត្ថិភាព:** ទ្វារចេញត្រូវមានប៊ូតុងបើកពីខាងក្នុងជានិច្ច ហើយសោមេដែកត្រូវជាប្រភេទ Fail-safe (ដាច់ភ្លើង = ដោះ)។ ESP32 នេះគ្រាន់តែ **បន្ថែម** ការបើក មិនដែលចាក់សោមនុស្សនៅខាងក្នុងទេ។
> 🔌 ខ្សែ 220V ត្រូវឲ្យជាងភ្លើងធ្វើ។ ESP32 និង Relay ប្រើតែ 5V។

---

## ៣. បង្កើត Door key ក្នុង Smart Nexus

1. Admin → **ការកំណត់** → **Kiosk និងទ្វារ** → Kiosk ដែលនៅទ្វារនោះ → **កែ**
2. ធីក **ភ្ជាប់ Controller ទ្វារ (ESP32 + Relay)** → កំណត់ **បើកសោរយៈពេល (ms)** (ឧ. 1000 = 1 វិនាទី) → រក្សាទុក
3. ចុច **បង្កើត Door key** → ប្រអប់លឿងបង្ហាញ **Door key** និង **Poll URL** → ចុច **Copy** ទុក (បង្ហាញតែម្តង)

---

## ៤. ដាក់កូដចូល ESP32 (Arduino IDE)

1. ទាញយក **Arduino IDE 2** ពី arduino.cc
2. **Settings → Additional boards manager URLs** បិទដាក់:
   `https://espressif.github.io/arduino-esp32/package_esp32_index.json`
3. **Boards Manager** → ស្វែងរក **esp32** (by Espressif) → **Install**
4. បើកឯកសារ `door-controller.ino`
5. កែផ្នែក **SETTINGS** ខាងលើឯកសារ:

```cpp
const char* WIFI_SSID = "ឈ្មោះ Wi-Fi";
const char* WIFI_PASS = "ពាក្យសម្ងាត់ Wi-Fi";
const char* POLL_URL  = "Poll URL ដែល Copy ពីប្រព័ន្ធ";
const char* DOOR_KEY  = "Door key ដែល Copy ពីប្រព័ន្ធ";
```

6. **Tools → Board → ESP32 Dev Module**, **Tools → Port** → ជ្រើស Port របស់ ESP32
7. ចុច **Upload (→)**។ បើវាជាប់ "Connecting….." សូមសង្កត់ប៊ូតុង **BOOT** លើបន្ទះ រហូតដល់វាចាប់ផ្តើម Upload
8. **Tools → Serial Monitor** (115200) ដើម្បីមើលថាវាកំពុងធ្វើអ្វី

---

## ៥. តេស្តលើ Mac (មិនទាន់មាន Server ពិត)

ESP32 មិនអាចហៅ `localhost` របស់ Mac បានទេ។ ត្រូវ:

1. បើក Laravel ឲ្យឧបករណ៍ផ្សេងក្នុង Wi-Fi ហៅបាន:
   ```
   cd ~/Projects/LMSAPI
   PHP_CLI_SERVER_WORKERS=4 php artisan serve --host 0.0.0.0 --port 8000 --no-reload
   ```
2. រក IP របស់ Mac:
   ```
   ipconfig getifaddr en0
   ```
3. ក្នុង `POLL_URL` ប្តូរ `localhost` ទៅ IP នោះ ឧ. `http://192.168.1.20:8000/api/door/1/poll`
4. ESP32 និង Mac ត្រូវនៅ **Wi-Fi តែមួយ** (Wi-Fi 2.4 GHz — ESP32 មិនស្គាល់ 5 GHz)
5. បើ macOS សួរ "Allow incoming connections" សម្រាប់ php → **Allow**

---

## ៦. ពិនិត្យថាដំណើរការ

| ជំហាន | លទ្ធផលត្រូវ |
|---|---|
| បើក ESP32 | LED ខៀវភ្លឹបលឿន → ភ្ជាប់ Wi-Fi រួចភ្លឺខ្លីៗរៀងរាល់ 2 វិនាទី |
| មើល Admin → Kiosk និងទ្វារ | ស្ថានភាពប្តូរពី **ទ្វារមិនភ្ជាប់** → **ទ្វារភ្ជាប់** |
| ចុច **សាកបើកទ្វារ** | Relay "ក្លិក" ~1 វិនាទី, Serial: `[door] OPEN for 1000 ms` |
| ស្កេនកាតសមាជិកនៅ Kiosk | ទ្វារបើក |
| ស្កេនកាតផុតកំណត់ / ផ្អាក | ទ្វារមិនបើក, Kiosk បង្ហាញមូលហេតុ |

### អត្ថន័យភ្លើង LED

| LED | អត្ថន័យ | ដោះស្រាយ |
|---|---|---|
| ភ្លឹបលឿន | កំពុងភ្ជាប់ Wi-Fi | ពិនិត្យឈ្មោះ/ពាក្យសម្ងាត់ Wi-Fi, ត្រូវជា 2.4 GHz |
| ភ្លឹបយឺត (1 វិនាទី) | ហៅ Server មិនបាន | ពិនិត្យ POLL_URL, Laravel កំពុងរត់, `--host 0.0.0.0` |
| ភ្លឹប ២ ដង | Door key ខុស (401) | Copy Door key ម្តងទៀត ឬបង្កើតថ្មី |
| ភ្លឹប ៣ ដង | មិនទាន់ធីក "ភ្ជាប់ Controller ទ្វារ" | ធីកក្នុង **កែ** Kiosk |
| ភ្លឺខ្លីៗរៀងរាល់ 2 វិនាទី | ល្អ | — |
| ភ្លឺជាប់ | ទ្វារកំពុងបើក | — |

---

## ៧. ការកំណត់បន្ថែម (ក្នុង `door-controller.ino`)

| ការកំណត់ | លំនាំដើម | ពេលណាប្តូរ |
|---|---|---|
| `RELAY_PIN` | 26 | ភ្ជាប់ IN ទៅ Pin ផ្សេង |
| `RELAY_ACTIVE_LOW` | true | Relay ធ្វើការផ្ទុយ (បើកជាប់ពេលចាប់ផ្តើម) → ប្តូរជា `false` |
| `BUZZER_PIN` | -1 | ភ្ជាប់ Buzzer (ឧ. 27) ដើម្បីឮសំឡេងពេលបើក |
| `ROOT_CA` | ទទេ | ពេលប្រើពិតលើ HTTPS ដាក់ Root certificate របស់ Server ដើម្បីការពារការក្លែង Server |

---

## ៨. API (សម្រាប់អ្នកអភិវឌ្ឍ)

```
GET /api/door/{device_id}/poll
Header: X-Door-Key: <door key>

200 {"open": true,  "pulse_ms": 1000}   → បើកទ្វារ
200 {"open": false}                     → មិនមានអ្វី
200 {"open": false, "enabled": false}   → មិនទាន់ធីក "ភ្ជាប់ Controller ទ្វារ"
401                                     → Key ខុស
429                                     → សួរលឿនពេក (អនុញ្ញាត 120 ដង/នាទី)
```

បញ្ជា "បើក" នៅរង់ចាំ 10 វិនាទី ហើយត្រូវបានយកតែម្តង។ Server ទុកតែ Hash របស់ Door key ប៉ុណ្ណោះ។
