# ឧបករណ៍មាត់ទ្វារបណ្ណាល័យ (Kiosk, ម៉ាស៊ីនស្កេន, ទ្វារ)

## 1. ឧបករណ៍ដែលត្រូវការ

| ឧបករណ៍ | ប្រើសម្រាប់ | ចាំបាច់? |
| --- | --- | --- |
| Tablet Android ឬកុំព្យូទ័រតូច + Browser Chrome | បង្ហាញទំព័រ `/kiosk` | ចាំបាច់ |
| ម៉ាស៊ីនស្កេន **2D** USB/Bluetooth (HID keyboard) | អាន QR កាត និង QR លើទូរស័ព្ទ | ណែនាំ (អាចប្រើកាមេរ៉ា Tablet ជំនួសបាន) |
| ជើងទម្រ Tablet + ប្រភពភ្លើងជាប់ | កុំឲ្យ Tablet អស់ថ្ម | ណែនាំ |
| ESP32 DevKit + Relay 1 channel | បើកសោទ្វារ | បើចង់ឲ្យទ្វារបើកស្វ័យប្រវត្តិ |
| សោអគ្គិសនី/Maglock 12V + Adapter 12V | សោទ្វារ | បើចង់ឲ្យទ្វារបើកស្វ័យប្រវត្តិ |
| ប៊ូតុង Exit + ប៊ូតុងបន្ទាន់ (Break glass) | ចេញពីខាងក្នុងជានិច្ច | **ចាំបាច់ បើមានសោ** |

ម៉ាស៊ីនស្កេន 1D (Laser) អាន QR មិនបានទេ។ ត្រូវជ្រើស 2D (imager)។

## 2. ការតភ្ជាប់ទ្វារ

```
ESP32 GPIO26 ──> Relay IN
ESP32 5V     ──> Relay VCC
ESP32 GND    ──> Relay GND

Adapter 12V (+) ──> Relay COM
Relay NO        ──> Lock (+)        (Maglock: ប្រើ NC ជំនួស NO — ពេល Relay ធ្វើការ វាកាត់ភ្លើង ហើយទ្វារបើក)
Adapter 12V (-) ──> Lock (-)

ប៊ូតុង Exit / Break glass ──> កាត់ភ្លើងសោដោយផ្ទាល់ (មិនឆ្លងកាត់ ESP32)
```

- **Maglock ត្រូវជា Fail-safe:** ដាច់ភ្លើង = ទ្វារបើក។
- ESP32 មិនដែល "ចាក់សោ" មនុស្សនៅខាងក្នុងទេ។ វាគ្រាន់តែបើកពីខាងក្រៅប៉ុណ្ណោះ។

## 3. ដំឡើង ESP32

1. ដំឡើង Arduino IDE + "ESP32 by Espressif" (Boards Manager)។
2. បើក `esp32-door/esp32-door.ino` ហើយកែ `WIFI_SSID`, `WIFI_PASSWORD`, `POLL_URL`, `DOOR_KEY`។
3. `POLL_URL` និង `DOOR_KEY` យកពី **Settings → Kiosk និងទ្វារ → បង្កើត Door key** (បង្ហាញតែម្តង)។
4. ដាក់ Root certificate របស់ Server ក្នុង `ROOT_CA` មុនប្រើពិត។ ទុកទទេបានតែពេលសាកល្បងលើតុប៉ុណ្ណោះ។
5. Upload ហើយបើក Serial Monitor (115200)។ ពេលស្កេនកាតនៅ Kiosk នឹងឃើញ `OPEN for 3000 ms`។

## 4. សាកល្បងដោយគ្មាន Hardware

```
php artisan door:simulate 1
```

បន្ទាប់មក ស្កេនកាតនៅ `/kiosk` ឬចុច "សាកបើកទ្វារ" ក្នុង Settings។ Terminal នឹងបង្ហាញ `OPEN`។
