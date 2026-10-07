# TymOS

> **Fully coded by Claude AI** — not a single line of code was written manually by a human. The human part was the ideas, the requirements and the direction; Claude wrote all the code.

Super-fast, lightweight smart home for Raspberry Pi with secure VPN access: Zigbee devices, heating, hot-water buffer, ventilation, cameras, alarm and dynamic energy prices in one panel.

<h3>Works with:<br>Zigbee · Sonoff · TP-Link Tapo · Tuya · Reolink · ONVIF cameras · SMLIGHT SLZB-06 · ESPHome · Zehnder ComfoAir Q · BleBox · Pstryk · Energa · TGE · Telegram · Tailscale · iPad kiosk</h3>

TymOS was born after 30 days with Home Assistant and the feeling that I wanted *something more*: lighter, faster, simpler — just better for my house. It has been running a real house every day since.

**Small footprint:**

- ~1.3 MB of source code (~26k lines of PHP, Python and JS) — no frameworks, no containers, no add-on store
- ~1.3 GB MariaDB for 66 devices with about 4 months of full history (every reading kept)
- runs comfortably on a Raspberry Pi next to Zigbee2MQTT, Mosquitto and go2rtc

**This is not a ready-to-use product.** There is no installer and no official update channel. TymOS is meant to be **used as-is with your own AI agent**: clone it, let the agent adapt it to your environment, and develop it further for your own needs. Treat my future commits as a stream of new ideas — your agent can pick them up and implement them in your own version quickly.

Installing TymOS on your own hardware with an AI agent takes just a few minutes: point the agent to this repository URL, ask it to study the project, then give it a few details — the IP address of the target device, where your database lives, and so on. See [Use it with your AI agent](#use-it-with-your-ai-agent) for a ready-to-paste prompt.

> Code comments and UI texts are in Polish.

![TymOS kiosk panel](docs/panel.png)<br>
*Wall-mounted kiosk panel: cameras, doorbell, weather, ventilation flow, dynamic energy prices, heating, garden.*

## What it does

- **Zigbee** — all devices through Zigbee2MQTT; readings stored in MariaDB, availability monitoring and automatic network recovery
- **Rules engine** — actions triggered by sensor changes, schedules (cron) or scripts, with conditions and fail-safe behaviour when a reading is missing or stale
- **Heating & hot water** — a buffer tank heated with electric heaters at the cheapest hours (dynamic prices), pellet boiler cost tracking, underfloor heating
- **Ventilation** — Zehnder ComfoAir Q heat-recovery unit controlled via ESPHome (fan speeds, bypass, away mode, night limits)
- **Energy** — dynamic electricity prices (Pstryk API, TGE), meter data import (Energa Operator), per-phase power, voltage, cost per appliance cycle
- **Cameras & doorbell** — go2rtc (WebRTC / HLS), ONVIF events, vehicle and person detection, face recognition (OpenCV YuNet + SFace)
- **Alarm** — arm levels (off / night / away), Telegram notifications with camera snapshots, presence simulation
- **Garden** — irrigation sections, frost protection for the pump, pool filtration
- **Blinds & lights** — sun-position-based blind control, motion lights, multi-click switches
- **Notifications** — Telegram (normal + alert channel, night quiet hours) and voice announcements on a wall tablet
- **Kiosk UI** — fast single-page panel for a wall-mounted iPad, plus an admin panel (devices, actions, helpers, logs, sounds, system)
- **Tuya Zigbee devices** — work locally (LAN, no cloud) through the Zigbee coordinator and Zigbee2MQTT, e.g. the irrigation relays; Tuya and TP-Link Tapo Wi-Fi plugs are not supported

## Stack

| Layer | Technology |
|---|---|
| Hardware | Raspberry Pi, SMLIGHT SLZB-06 Zigbee coordinator (PoE) |
| Messaging | Mosquitto (MQTT), Zigbee2MQTT |
| Backend | PHP 8 (web + actions), Python 3 (daemons and bridges) |
| Database | MariaDB |
| Web | Apache 2, jQuery |
| Video | go2rtc |
| Integrations | ESPHome, BleBox, ONVIF, Telegram Bot API, Open-Meteo, Pstryk |

## Screenshots

### Kiosk panel

<table>
<tr><td width="50%" valign="top"><img src="docs/features/camera-parking.jpg" width="100%"><br><sub><b>Cameras</b> — live view (WebRTC) with weather overlay, alarm shields and camera switcher</sub></td><td width="50%" valign="top"><img src="docs/features/doorbell.png" width="100%"><br><sub><b>Video doorbell</b> — answer with two-way audio; next to it a room tile with temperature, humidity and blind control</sub></td></tr>
<tr><td width="50%" valign="top"><img src="docs/features/ventilation.png" width="100%"><br><sub><b>Ventilation</b> — live heat-recovery flow: supply / extract / outdoor / exhaust temperature and humidity, fan speed, bypass, frost protection</sub></td><td width="50%" valign="top"><img src="docs/features/heating.png" width="100%"><br><sub><b>Heating</b> — room thermostats with current and target temperature</sub></td></tr>
<tr><td width="50%" valign="top"><img src="docs/features/climate-chart.png" width="100%"><br><sub><b>Climate chart</b> — indoor, sun and shade temperature, humidity, free-cooling and coolers</sub></td><td width="50%" valign="top"><img src="docs/features/waste-calendar.png" width="100%"><br><sub><b>Waste collection calendar</b> — colour-coded pickup days, edited with one tap</sub></td></tr>
<tr><td width="50%" valign="top"><img src="docs/features/season-settings.png" width="100%"><br><sub><b>Season settings</b> — heating / cooling / off, pellet boiler, underfloor heating, pellet cost and the price threshold for electric heaters</sub></td><td width="50%" valign="top"><img src="docs/features/irrigation.png" width="100%"><br><sub><b>Irrigation</b> — zones, duration, days, morning / evening runs, frost skip</sub></td></tr>
<tr><td width="100%" colspan="2" valign="top"><img src="docs/features/weather.png" width="100%"><br><sub><b>Weather</b> — 48-hour forecast: temperature, cloud cover, wind direction, day and night</sub></td></tr>
</table>

### Energy

<table>
<tr><td width="50%" valign="top"><img src="docs/features/energy-prices.png" width="100%"><br><sub><b>Dynamic prices</b> — hourly prices for today (green cheap, red expensive), tomorrow's prices (white line; dashed = forecast from the power exchange before the supplier publishes them) and the house usage (blue dashed line)</sub></td>
<td width="50%" valign="top"><img src="docs/features/heating-cost-estimate-2.png" width="100%"><br><sub><b>What-if estimates</b> — the real monthly cost of heating hot water with electric heaters at the cheapest hours, compared with an <b>estimate</b> of what the same heat would cost with the pellet boiler at today's pellet price. Build your own "what would it cost if…" charts from the data you already collect.</sub></td></tr>
</table>

### Notifications

<table>
<tr><td width="50%" valign="top"><img src="docs/features/telegram.png" width="100%"></td><td width="50%" valign="top"><b>Telegram</b> — camera alerts with a snapshot and a short video clip. Face recognition says who it is (with confidence), or marks the person as unknown; the message also shows the camera, detection details and processing time.<br><br>The same bot sends alarm notifications, device failures (missing readings, offline sensors), with separate normal and alert channels and night quiet hours.</td></tr>
<tr><td width="50%" valign="top"><img src="docs/features/telegram-doorbell.png" width="100%"></td><td width="50%" valign="top"><b>Doorbell ring</b> — when someone rings, the alert channel gets a doorbell snapshot plus the parking camera view, with a link that opens TymOS straight to the doorbell call — answer from anywhere.</td></tr>
</table>

### Admin panel

<table>
<tr><td width="50%" valign="top"><img src="docs/features/admin-devices.png" width="100%"><br><sub><b>Devices</b> — all Zigbee, camera, ESPHome and BleBox devices with live state</sub></td><td width="50%" valign="top"><img src="docs/features/admin-device-detail.png" width="100%"><br><sub><b>Device detail</b> — control, which fields to record, raw data</sub></td></tr>
<tr><td width="50%" valign="top"><img src="docs/features/admin-actions.png" width="100%"><br><sub><b>Actions</b> — the rules engine: every automation with its last run</sub></td><td width="50%" valign="top"><img src="docs/features/admin-action-editor.png" width="100%"><br><sub><b>Action editor</b> — when / if / then, no code needed</sub></td></tr>
<tr><td width="50%" valign="top"><img src="docs/features/admin-helpers.png" width="100%"><br><sub><b>Helpers</b> — global variables used by automations</sub></td><td width="50%" valign="top"><img src="docs/features/admin-system.png" width="100%"><br><sub><b>System</b> — daemon status and restart</sub></td></tr>
<tr><td width="50%" valign="top"><img src="docs/features/admin-batteries.png" width="100%"><br><sub><b>Batteries</b> — battery level of every wireless sensor</sub></td><td width="50%" valign="top"><img src="docs/features/admin-sounds.png" width="100%"><br><sub><b>Voice announcements</b> — messages played on the wall tablet</sub></td></tr>
</table>

## Hardware in the house

<table>
<tr><td width="50%" valign="top"><img src="docs/hardware/kiosk-ipad.jpg" width="100%"><br><sub><b>Wall kiosk</b> — an old iPad with the TymOS panel: cameras, weather, ventilation, heating, energy prices, irrigation</sub></td><td width="50%" valign="top"><img src="docs/hardware/network-rack.jpg" width="100%"><br><sub><b>Network rack</b> — fibre ONT, PoE switches and the Raspberry Pi 5 running TymOS</sub></td></tr>
<tr><td width="50%" valign="top"><img src="docs/hardware/hot-water-buffer.jpg" width="100%"><br><sub><b>Hot water buffer</b> — Sonoff SNZB-02LD probes measure the water temperature at several heights</sub></td><td width="50%" valign="top"><img src="docs/hardware/smart-plugs.jpg" width="100%"><br><sub><b>Zigbee plugs</b> — Sonoff S60ZBTPF switch heaters and pumps and measure their power</sub></td></tr>
<tr><td width="50%" valign="top"><img src="docs/hardware/valve-relay.jpg" width="100%"><br><sub><b>Valve relay</b> — Sonoff MINI-ZBRBS in a box drives the motorised valve of the ventilation cooler</sub></td><td width="50%" valign="top"><img src="docs/hardware/door-sensor.jpg" width="100%"><br><sub><b>Door sensor</b> — Sonoff SNZB-04P, used by the alarm and notifications</sub></td></tr>
<tr><td width="50%" valign="top"><img src="docs/hardware/raspberry-pi.jpg" width="100%"><br><sub><b>Raspberry Pi 5</b> — runs everything: web panel, database, MQTT, Zigbee2MQTT, go2rtc and the Python daemons</sub></td><td width="50%" valign="top"><img src="docs/hardware/energy-meter.jpg" width="100%"><br><sub><b>Energy meter</b> — 3-phase BleBox meter (supplied by Pstryk) on the DIN rail, read locally every few seconds</sub></td></tr>
<tr><td width="50%" valign="top"><img src="docs/hardware/irrigation-box.jpg" width="100%"><br><sub><b>Irrigation box</b> — two 4-channel Zigbee relays drive 8 garden valve sections</sub></td><td width="50%" valign="top"><img src="docs/hardware/irrigation-relay.jpg" width="100%"><br><sub><b>Zigbee relay</b> — Tuya ZG-005-RF, 4 channels with NO/NC contacts, 85–250 V</sub></td></tr>
</table>

The ventilation module (ESP32 + CAN on a Zehnder ComfoAir Q) has its own repository with a full hardware guide — see [Standalone modules](#standalone-modules).

## Architecture

![TymOS architecture](docs/architecture-4.svg)

## Standalone modules

Some parts of TymOS are also published as small, independent repositories — easy to use without the whole system:

- **[energa-mojlicznik](https://github.com/tymoteuszrogalewski/energa-mojlicznik)** — hourly electricity usage import from Energa Operator "Mój Licznik" into MySQL/MariaDB or CSV (import, PV export, full history)
- **[pstryk-api](https://github.com/tymoteuszrogalewski/pstryk-api)** — hourly dynamic electricity prices, usage and costs from the Pstryk API into MySQL/MariaDB or CSV (today + tomorrow, cheap hours, PV, full history)
- **[tge-rdn](https://github.com/tymoteuszrogalewski/tge-rdn)** — Polish Power Exchange (TGE) day-ahead prices into MySQL/MariaDB or CSV (Fixing I and II, continuous trading, hourly and 15-minute products, tomorrow's prices)
- **[blebox-energy-meter](https://github.com/tymoteuszrogalewski/blebox-energy-meter)** — local (LAN, no cloud) reader for the BleBox 3-phase energy meter, e.g. the Pstryk meter: power, voltage and current per phase every few seconds into MySQL/MariaDB or CSV
- **[zehnder-comfoair-q-esp32](https://github.com/tymoteuszrogalewski/zehnder-comfoair-q-esp32)** — control a Zehnder ComfoAir Q ventilation unit with an ESP32 and CAN bus (ESPHome): stable config with fixes, permanent fan speeds, PHP control over REST, hardware guide with photos

## Bridges — how non-Zigbee devices join in

Inside TymOS every device speaks the same language: a **JSON message on MQTT**. Zigbee devices do it out of the box through Zigbee2MQTT. Everything else gets a small **bridge** — a Python daemon that talks to the device in its own way and publishes the result to MQTT. The main listener (`daemons/tymos.py`) then saves the data and the rules engine sees it, exactly like a Zigbee sensor.

| Bridge | Devices | How it works |
|---|---|---|
| **ESPHome** (`bridge_esphome.py`) | ESP32 / ESP8266 with ESPHome, e.g. the ventilation unit | Every 10 s it opens the ESPHome web server event stream (`/events`), reads the full state snapshot, closes the connection and publishes it to `tymos/esphome/<device>`. Short connections instead of one long stream: a Wi-Fi drop never hangs the bridge. One thread per device. Commands go straight to the ESPHome REST API (buttons, switches). |
| **BleBox** (`bridge_blebox.py`) | BleBox devices, e.g. the 3-phase energy meter | Every 10 s it reads `http://<device>/state`, converts the raw units (0.1 V, mA, mHz, Wh) and publishes power, voltage, current and energy per phase and in total to `tymos/blebox/<device>`. |
| **ONVIF** (`bridge_onvif.py`) | IP cameras and the video doorbell | Subscribes to camera events (motion, person, vehicle, doorbell ring). The camera pushes each event to a small HTTP server in the bridge (port 8585), so alerts are instant — no polling. Subscriptions are renewed every 8 minutes. Events go to `tymos/onvif/camera/<name>`. The video itself goes through go2rtc. |

All bridges share the same habits:

- **Auto-discovery** — a new device appears in the admin panel by itself.
- **You choose what to record** — only the fields you switch on in the panel go to the database.
- **Live reload** — changes made in the panel are picked up without a restart.
- **Calm about short outages** — a lost connection is simply retried on the next round; the ESPHome and BleBox bridges log a problem only after several failed reads in a row, and log again when the device is back.

**Adding a new kind of device** means writing one more small bridge that reads the device and publishes JSON to MQTT. Nothing else in TymOS has to change.

## Repository layout

```
actions/    PHP scripts run by the rules engine or cron (automations, imports, watchdogs)
daemons/    Python services: MQTT listener + rules engine, ESPHome / BleBox / ONVIF bridges, face recognition
tymos/      Web app: kiosk panel, admin panel, API, config
etc/        System config: Apache, go2rtc, ESPHome (*.tpl templates), systemd units, MariaDB
scripts/    Backup, restore, render_etc.php
utils/      Tools: Zigbee network scan, sensor reporting setup, face enrollment, keepalives
tests/      Browser tests for UI cards
```

## Configuration

All private data — passwords, tokens, IP addresses, location — lives in **one file**, which is not in the repository:

```bash
cp tymos/config.example.inc.php tymos/config.inc.php
# edit tymos/config.inc.php
```

- PHP code reads it directly (`define()` constants).
- Python daemons read it via `daemons/config.py`.
- Shell scripts read it via a small `cfg KEY` helper.
- System files (Apache vhost, go2rtc streams, ESPHome) are kept as `etc/**/*.tpl` templates with `{{KEY}}` placeholders. Generate the real files with:

```bash
php scripts/render_etc.php --check   # preview what would change
php scripts/render_etc.php           # write the files
```

The generated files are git-ignored.

## Database

`schema.sql` — MariaDB structure without data (core tables + one example of the per-device `device<ID>` / `stat<ID>` tables, which the code creates automatically).

## Use it with your AI agent

TymOS is built for one specific house and one specific setup. As it is, it will not fit everyone's needs — and that is fine. What it gives you is a large set of **working, battle-tested solutions**: scripts, automations, device bridges, UI cards and ideas. The intended way to use it is together with an AI coding agent (Claude Code or any other) that adapts it to *your* environment.

Paste this into your agent:

```
Study the project https://github.com/tymoteuszrogalewski/tymos
(README, schema.sql, tymos/config.example.inc.php, scripts/restore.sh, etc/).
Then help me install and adapt it on my server. Before installing anything, ask me about:
- the target server and directory,
- the web server (Apache / nginx) and PHP version,
- the database (MariaDB / MySQL): host, user, password, database name,
- MQTT broker and Zigbee coordinator,
- which features I actually want (Zigbee, cameras, energy prices, heating, alarm, Telegram...).
Install only what is needed, fill in tymos/config.inc.php with my values,
and adapt the code to my devices.
```

The agent can then install packages, create the database from `schema.sql`, prepare the config and services — step by step, with your approval.

## Installation

TymOS expects to live in `/opt/tymos` on a Debian / Raspberry Pi OS host. `scripts/restore.sh` documents the full setup (packages, services, cron) and is the best reference for now.

A step-by-step guide for a fresh install is planned.

## License

MIT — see [LICENSE](LICENSE).
