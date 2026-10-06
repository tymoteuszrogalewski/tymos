#!/usr/bin/env php
<?php
/**
 * ai_grzalki_watchdog.php — alert Telegram, gdy grzalka bufora ma stan ON, ale nie ciagnie pradu.
 * Cron: co 3 minuty (akcja TymOS typu script).
 *
 * Po co: grzalkom wyskakuje FABRYCZNE zabezpieczenie termiczne STB (~70°C) i wtedy gniazdko dalej
 * raportuje ON, a grzalka jest martwa. Reset da sie wcisnac TYLKO fizycznie na glowicy grzalki,
 * wiec jedyne co system moze zrobic to powiedziec o tym od razu. Epizod 2026-08-16 13:36 → dolna
 * grzalka stala ~31 h i wyszlo to przypadkiem, dopiero z wykresu mocy.
 *
 * Grzalki (mapowanie potwierdzone 2026-08-17):
 *   dev3 = "Bufor Grzałka 1" = DOLNA (L3) — ta, ktorej wyskakuje STB
 *   dev1 = "Bufor Grzałka 2" = GÓRNA (L1)
 *
 * PATRZYMY NA `current`, NIE NA `power`: gniazdka S60 przed firmware 2.0.3 zamrazaly `power` na
 * ostatniej wartosci, co dawaloby falszywe alarmy. Norma grzania to ~13 A (3 kW / 233 V), wiec
 * prog 2 A jest bezpieczny. `current` jest TYLKO w `devices.last_state` — tabele device1/device3
 * nie maja takiej kolumny (enabled_fields = tylko statystyki).
 *
 * Stan per grzalka w helperach (widoczne w admin → Helpery):
 *   grzalka_wd_miss_dev<ID>  — 0 = OK, epoch = moment pierwszego wykrycia "ON bez pradu"
 *   grzalka_wd_alert_dev<ID> — 0/1, blokada powtorek w tym samym epizodzie
 *   grzalka_wd_ack_dev<ID>   — epoch klikniecia „rozumiem" na ikonie kamery (api/grzalki_ack)
 *
 * Ten sam `alert` steruje CZERWONA IKONA na kamerze parkingowej (panel home, api/grzalki_alert):
 * skoro gasnie dopiero przy realnym poborze pradu, ikona znika sama, gdy grzalka przy najblizszym
 * wlaczeniu ruszy. Nikt nie musi jej „odklikiwac" po naprawie.
 *
 * JEDEN alert na epizod: `alert` gasnie dopiero, gdy zobaczymy REALNY pobor pradu (czyli user
 * wcisnal reset). Samo wylaczenie gniazdka przez ai_bufor tego nie kasuje — inaczej kazde kolejne
 * okno grzania robiloby nowe powiadomienie o tej samej martwej grzalce.
 *
 * === POPRAWKA 2026-08-25: ODROZNIENIE STB OD TERMOSTATU WEWNETRZNEGO ===
 * Grzalki maja WLASNY termostat regulowany (pokretlo w glowicy), ktory odcina prad przy swojej
 * nastawie — gniazdko dalej raportuje ON. Stara wersja skryptu uznawala to za wywalony STB i
 * wysylala falszywy alarm. Zmierzone przypadki (wszystkie FALSZYWE): 24.08 dolna odcieta przy
 * 61,4 C po 83 min grzania (i SAMA wrocila do 14:11!), 24.08 gorna 67,9 C po 120 min,
 * 25.08 dolna 57,5 C po 23 min, 25.08 gorna 60,8 C po 45 min. Wywalony STB = ZERO pradu od
 * poczatku i brak powrotu bez recznego resetu — te grzalki ciagnely pelne 3 kW.
 *
 * Glowny dyskryminator to teraz `ran`: czy w BIEZACYM oknie ON grzalka w ogole wziela prad.
 *   ran=1 (grzala i przestala)  -> NIGDY nie alarmuj, to termostat. Raz na epizod INFO z temperatura
 *                                  odciecia — material do kalibracji TOP_MAX.
 *   ran=0 (zero pradu od ON) i temp < TEMP_GATE -> ALARM. Ponizej progu ani termostat, ani STB nie
 *                                  maja powodu odcinac, wiec to realna awaria.
 *   ran=0 i temp >= TEMP_GATE   -> cisza (INFO raz). Termostat mogl byc otwarty juz przy zalaczeniu,
 *                                  np. gdy ai_bufor dogrzewa przy bardzo niskiej cenie. Alarm dopiero
 *                                  gdy bufor ostygnie ponizej progu, a pradu nadal nie ma.
 *   brak/przestarzaly odczyt temperatury -> ALARMUJEMY (z adnotacja w tresci). Swiadoma decyzja:
 *                                  martwa grzalka kosztuje wiecej niz jedno zbedne powiadomienie,
 *                                  a przy ran=0 pomylka z termostatem jest malo prawdopodobna.
 *
 * Czujniki bufora (devices.last_state, tak jak `current`):
 *   dev3 (dolna) -> device 11 „Bufor Temperatura Dol"
 *   dev1 (gorna) -> device 12 „Bufor Temperatura Gora"
 */

require_once __DIR__ . '/lib/_log.inc.php';

const MIN_A     = 2.0;   // A — ponizej tego przy stanie ON uznajemy, ze grzalka nie grzeje
const MISS_SEC  = 300;   // s — jak dlugo musi trwac "ON bez pradu" (2 przebiegi crona co 3 min)
const STALE_SEC = 900;   // s — starszy odczyt = milczace gniazdko, nie alarmujemy z zamrozonych danych
const TEMP_GATE = 50.0;  // C — ponizej tego termostat wewnetrzny NIE MA prawa odciac (pokretla na maks,
                         //     zmierzone odciecia 57-68 C). Prog dobrany z zapasem w dol.
const TEMP_STALE = 7200; // s — starszy odczyt temperatury traktujemy jak brak (patrz naglowek)

// id grzalki => [etykieta, id czujnika temperatury na jej poziomie bufora]
$GRZALKI = [
    3 => ['Bufor Grzałka 1 (dolna, L3)', 11],
    1 => ['Bufor Grzałka 2 (górna, L1)', 12],
];

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'ai_grzalki_watchdog: DB ' . $db->connect_error); exit(1); }

// Helper czytany/zapisywany po nazwie; brakujacy zakladamy w locie (jak w ai_gofrownica_watchdog)
function hGet($name) {
    global $db;
    $r = $db->query("SELECT value FROM helpers WHERE name='{$name}' LIMIT 1");
    if ($r && ($row = $r->fetch_row())) return (int)$row[0];
    $db->query("INSERT INTO helpers (name, value) VALUES ('{$name}', '0')");
    return 0;
}
function hSet($name, $v) {
    global $db;
    $db->query("UPDATE helpers SET value='" . (int)$v . "' WHERE name='{$name}'");
}

// Temperatura bufora z last_state czujnika. Zwraca null gdy brak odczytu albo odczyt przestarzaly.
function bufTemp($sensorId) {
    global $db;
    $r = $db->query("SELECT last_state FROM devices WHERE id={$sensorId} AND deleted=0 LIMIT 1");
    if (!$r || !($row = $r->fetch_assoc())) return null;
    $ls = json_decode($row['last_state'] ?? '{}', true) ?: [];
    if (!isset($ls['temperature']) || !is_numeric($ls['temperature'])) return null;
    $ts = $ls['__ts']['temperature'] ?? null;
    if ($ts !== null && (time() - (int)$ts) > TEMP_STALE) return null;
    return (float)$ls['temperature'];
}

foreach ($GRZALKI as $id => list($label, $sensorId)) {
    $r = $db->query("SELECT device, last_state, available FROM devices WHERE id={$id} AND deleted=0 LIMIT 1");
    if (!$r || !($dev = $r->fetch_assoc())) {
        tymos_log('ERROR', "ai_grzalki_watchdog: brak urzadzenia id={$id} w devices");
        continue;
    }

    $missKey  = "grzalka_wd_miss_dev{$id}";
    $alertKey = "grzalka_wd_alert_dev{$id}";
    $ranKey   = "grzalka_wd_ran_dev{$id}";     // 1 = w tym oknie ON grzalka wziela prad
    $noteKey  = "grzalka_wd_note_dev{$id}";    // 1 = INFO dla tego epizodu juz w logu
    $missTs   = hGet($missKey);
    $alerted  = hGet($alertKey);
    $ran      = hGet($ranKey);
    $noted    = hGet($noteKey);

    $ls   = json_decode($dev['last_state'] ?? '{}', true) ?: [];
    $isOn = (strtoupper((string)($ls['state'] ?? 'OFF')) === 'ON');
    $cur  = isset($ls['current']) && is_numeric($ls['current']) ? (float)$ls['current'] : null;

    // grzeje realnie -> koniec epizodu (to jedyne miejsce, ktore gasi alert)
    if ($cur !== null && $cur >= MIN_A) {
        if ($missTs !== 0) hSet($missKey, 0);
        if ($ran !== 1)    hSet($ranKey, 1);
        if ($noted !== 0)  hSet($noteKey, 0);
        if ($alerted !== 0) {
            hSet($alertKey, 0);
            // ...i kasujemy potwierdzenie z kamery — epizod zamkniety, nastepny ma zaczac od zera
            // (ikona z card_camera_parking wisi dopoki alert=1, wiec gasnie w tej samej chwili).
            $db->query("DELETE FROM helpers WHERE name='grzalka_wd_ack_dev{$id}'");
            tymos_log('INFO', "ai_grzalki_watchdog: {$label} znowu ciagnie prad ({$cur} A) — alert zdjety");
        }
        continue;
    }

    // gniazdko wylaczone -> nowe okno ON zacznie od zera, ale alert zostaje do czasu realnego poboru
    if (!$isOn) {
        if ($missTs !== 0) hSet($missKey, 0);
        if ($ran !== 0)    hSet($ranKey, 0);
        if ($noted !== 0)  hSet($noteKey, 0);
        continue;
    }

    // ON bez pradu — ale najpierw upewnij sie, ze odczyt jest swiezy
    if ((string)($dev['available'] ?? '1') === '0') continue;
    $curTs = $ls['__ts']['current'] ?? null;
    if ($curTs !== null && (time() - (int)$curTs) > STALE_SEC) continue;

    if ($missTs === 0) { hSet($missKey, time()); continue; }
    if (time() - $missTs < MISS_SEC) continue;

    $temp    = bufTemp($sensorId);
    $tempTxt = $temp !== null ? number_format($temp, 1, ',', '') . ' °C' : 'brak odczytu';

    // GRZALA I PRZESTALA -> termostat wewnetrzny, nigdy nie alarmujemy
    if ($ran === 1) {
        if (!$noted) {
            hSet($noteKey, 1);
            tymos_log('INFO', "ai_grzalki_watchdog: {$label} odciela sie sama przy {$tempTxt} "
                            . "po realnym grzaniu — termostat wewnetrzny, bez alarmu");
        }
        continue;
    }

    // ZERO PRADU OD ZALACZENIA, ale bufor cieply -> termostat mogl byc otwarty juz na starcie
    if ($temp !== null && $temp >= TEMP_GATE) {
        if (!$noted) {
            hSet($noteKey, 1);
            tymos_log('INFO', "ai_grzalki_watchdog: {$label} zero pradu od zalaczenia, ale bufor "
                            . "{$tempTxt} >= " . TEMP_GATE . " °C — czekam na ostygniecie, bez alarmu");
        }
        continue;
    }

    if ($alerted) continue;

    $mins   = round((time() - $missTs) / 60);
    $curTxt = $cur !== null ? number_format($cur, 1, ',', '') . ' A' : 'brak odczytu';
    $msg = "⚠️ {$label}: gniazdko ma ON od {$mins} min, prąd = {$curTxt}, "
         . "bufor {$tempTxt} — grzałka NIE wzięła prądu ani raz od załączenia.\n"
         . "Prawdopodobnie wyskoczyło zabezpieczenie termiczne (STB) — trzeba podejść "
         . "i wcisnąć przycisk reset na głowicy grzałki.";
    if ($temp === null) {
        $msg .= "\n(Uwaga: brak świeżego odczytu temperatury bufora, brama temperaturowa pominięta.)";
    }
    $proc = popen('php /opt/tymos/actions/telegram_send.php > /dev/null 2>&1', 'w');
    if ($proc) { fwrite($proc, json_encode(['type' => 'text', 'msg' => $msg, 'chat' => 'alert'])); pclose($proc); }

    hSet($alertKey, 1);
    tymos_log('WARN', "ai_grzalki_watchdog: {$label} ON bez pradu od {$mins} min ({$curTxt}, bufor "
                    . "{$tempTxt}), ran=0 — Telegram wyslany");
}

exit(0);
