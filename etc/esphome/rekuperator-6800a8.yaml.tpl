substitutions:
  name: rekuperator
  friendly_name: rekuperator
  watchdog_timeout: 30s
  # Pakiety yoziru używają tych nazw do konfiguracji canbusa:
  can_rx_pin: GPIO4
  can_tx_pin: GPIO5
  flash_size: 4MB
  wifi_ssid: !secret wifi_ssid
  wifi_password: !secret wifi_password
  api_encryption_key: !secret api_encryption_key
  wifi_hotspot_password: !secret wifi_hotspot_password
  ota_password: !secret ota_password

packages:
  # PRZYPIETE DO COMMITA (bylo @main). @main to ruchomy cel — kazdy rebuild mogl wciagnac inna
  # wersje komponentu i zmienic zachowanie bez naszej zmiany w yamlu. Podbijac swiadomie.
  remote_package: github://yoziru/esphome-zehnder-comfoair/zehnder-comfoair-q-esp32-evb.dashboard.yml@ce62828bf21bcaec3a530e97740fa322cc085526

esphome:
  name: ${name}
  friendly_name: ${friendly_name}

esp32:
  board: esp32dev
  framework:
    type: esp-idf

# USUNĘLIŚMY sekcję canbus: - pakiet sam ją sobie stworzy.
# USUNĘLIŚMY sekcję zehnder_comfoair_q: - pakiet ma ją w sobie.

# True permanent manual buttons
# WAZNE: wbudowane set_level()/set_manual_mode() wysylaja komende z duration_secs=1, co w tym
# komponencie znaczy "uzyj predefiniowanego timera jednostki" (~12 min) — NIE permanent.
# Dlatego bieg sam spadal na Auto po ~12 min. Prawdziwy permanent = duration_secs=0xffffffff
# (tak jak set_away/set_bypass_mode w komponencie). Wolamy send_command_set_timer wprost:
#   level:  send_command_set_timer(true, 0x01, 0x01, <bieg>, 0xffffffff)
#   manual: send_command_set_timer(true, 0x08, 0x01, 1,      0xffffffff)
# Kolejnosc: najpierw poziom, delay 1s, potem promote do manual — oba z 0xffffffff.
button:
  - platform: template
    name: "Manual Permanent Low"
    on_press:
      - lambda: id(comfoair)->send_command_set_timer(true, 0x01, 0x01, 1, 0xffffffff);
      - delay: 1s
      - lambda: id(comfoair)->send_command_set_timer(true, 0x08, 0x01, 1, 0xffffffff);
  - platform: template
    name: "Manual Permanent Medium"
    on_press:
      - lambda: id(comfoair)->send_command_set_timer(true, 0x01, 0x01, 2, 0xffffffff);
      - delay: 1s
      - lambda: id(comfoair)->send_command_set_timer(true, 0x08, 0x01, 1, 0xffffffff);
  - platform: template
    name: "Manual Permanent High"
    on_press:
      - lambda: id(comfoair)->send_command_set_timer(true, 0x01, 0x01, 3, 0xffffffff);
      - delay: 1s
      - lambda: id(comfoair)->send_command_set_timer(true, 0x08, 0x01, 1, 0xffffffff);

# ENCJA `fan` TYLKO DO ODCZYTU — usuwamy jej automatyzacje z pakietu.
# Pakiet yoziru robi tak (packages/sensors.yml + fan.yml + switch.yml):
#   PDO 65 (poziom) == 0  -> fan.turn_off -> on_turn_off -> select Fan Speed = "Away"
#   PDO 65 (poziom)  > 0  -> fan.turn_on  -> on_turn_on  -> switch.turn_on: auto_ventilation
#                                                        -> set_manual_mode(FALSE) = koniec manuala
# Czyli gdy poziom chocby na chwile zejdzie do 0 i wroci (przelaczanie trybu, glitch PDO),
# ESP SAM wychodzi z manuala na Auto — i to bylo zrodlo „reku wypada z manuala"
# (2026-08-01 08:12:55 Away -> 08:13:07 Auto/Low/auto_ventilation=1), a nie scheduler centrali.
# Dodatkowo on_turn_off wysyla Away przez select, ktory uzywa set_ventilation_level() z domyslnym
# duration_secs=1, czyli wariantu „(limited)".
# TymOS steruje wylacznie przyciskami Manual Permanent i switchem Away Mode (oba 0xffffffff),
# wiec te triggery sa nam niepotrzebne — zostaje sam odczyt stanu.
fan:
  - id: !extend comfoair_fan
    on_turn_on: !remove
    on_turn_off: !remove

# API natywne: TymOS go NIE uzywa (gada przez REST /events i /button). Domyslnie ESPHome restartuje
# urzadzenie, gdy przez 15 min nie polaczy sie zaden klient API — przez to ESP restartowal sie
# dokladnie co 15 min (zmierzone 2026-10-07 po uptime). 0s = nigdy.
api:
  reboot_timeout: 0s

wifi:
  power_save_mode: none
  fast_connect: true
  reboot_timeout: 10min
  output_power: 20dB
  use_address: {{REKU_IP}}
  manual_ip:
    static_ip: {{REKU_IP}}
    gateway: {{LAN_GATEWAY}}
    subnet: 255.255.255.0
    dns1: {{LAN_GATEWAY}}
  ap:
    ap_timeout: 0s
