#!/usr/bin/env php
<?php
/**
 * weather_fetch.php — import prognozy do `weather_hourly` / `weather_daily`. Cron co 30 min.
 *
 * ZRODLO: api.open-meteo.com, model UKMO (UK Met Office Unified Model) — czyste API JSON, bez
 * klucza. Wybrany swiadomie: user porownuje prognoze z apka Meteo ICM, ktora liczy UM 60h na
 * siatce 4 km, wiec UKMO jest z tej samej rodziny modelu i trzyma sie blizej tamtych liczb niz
 * ICON-EU (30.08: UKMO 24,0 C, ICON-EU 25,0 C, apka ICM ok. 22-23 C).
 *
 * OPAD Z MEDIANY PIECIU MODELI (2026-09-02). Temperatura, chmury i wiatr zostaja z UKMO, bo to
 * ten model trzyma sie ICM na temperaturze. Ale przy OPADZIE UKMO wypadal najslabiej z osmiu
 * sprawdzonych: epizod czwartkowy 03.09 (ICM: szansa 88 % na blok 18-21) UKMO przesuwal na po
 * polnocy i dawal 0,3 mm, gdy ICON-EU dawal 1,4 mm w tym samym bloku co ICM. Deszcz jest
 * zjawiskiem punktowym, wiec pojedynczy model globalny go gubi — mediana z kilku modeli nie da
 * sie zwariowac jednemu, a lapie zdarzenie, ktore widzi wiekszosc.
 * Sklad (5, czyli mediana bez usredniania): dwa globalne (UKMO, ECMWF IFS), jeden regionalny 7 km
 * (ICON-EU) i dwa rozdzielajace konwekcje (ICON-D2 2,2 km, DMI HARMONIE 2 km). GFS odrzucony jako
 * najgrubszy (13 km). Model bez danej godziny (koniec horyzontu) jest po prostu pomijany.
 *
 * `pprob` NIE JEST prawdopodobienstwem z modelu — `ukmo_seamless` w ogole go nie zwraca (null).
 * Trzymamy tam **ZGODNOSC MODELI**: procent modeli, ktore w tej godzinie daja >= 0,1 mm. To nasz
 * odpowiednik panelu „szansa opadu" z ICM. Karta tego jeszcze nie rysuje.
 *
 * DOCELOWO ICM: api.meteo.pl ma endpoint `model/um4_60/`, czyli dokladnie ten model, ale wymaga
 * klucza (rejestracja). Gdy klucz bedzie, podmienia sie TYLKO ten plik — tabele i karta czytaja
 * juz wylacznie z bazy. Meteogramy z meteo.pl to PNG, wiec scraping odpada.
 *
 * ZAKRES: zawsze OD DZISIEJSZEJ POLNOCY, dwie doby (`forecast_days=2`). Wykres ma stac w miejscu,
 * a nie przesuwac sie razem z godzina — o 13:00 ma dalej zaczynac sie od dzis 00:00.
 *
 * Dane ida do BAZY, nie prosto na ekran: kiosk czyta z niej co pol godziny i przy odswiezeniu,
 * wiec nie czeka na siec, a brak internetu oznacza starsza prognoze zamiast pustego widgetu.
 * `fetched_at` niesie wiek danych — karta pokazuje go, gdy zrobi sie nieswiezy.
 */
require_once __DIR__ . '/lib/_log.inc.php';

const LAT = HOME_LAT, LON = HOME_LON;
// BAZA = model dla temperatury/chmur/wiatru. OPAD_MODELE = z nich liczymy mediane opadu.
// Przy wielu modelach open-meteo dokleja sufiks do KAZDEGO pola: `temperature_2m_ukmo_seamless`.
const BAZA = 'ukmo_seamless';
const OPAD_MODELE = ['ukmo_seamless', 'icon_eu', 'icon_d2', 'ecmwf_ifs025', 'dmi_harmonie_arome_europe'];
const URL = 'https://api.open-meteo.com/v1/forecast'
          . '?latitude=' . LAT . '&longitude=' . LON
          . '&hourly=temperature_2m,cloud_cover,wind_speed_10m,wind_gusts_10m,wind_direction_10m,precipitation'
          . '&daily=temperature_2m_min,temperature_2m_max,sunrise,sunset'
          . '&timezone=Europe%2FWarsaw&forecast_days=2&models=' . 'ukmo_seamless,icon_eu,icon_d2,ecmwf_ifs025,dmi_harmonie_arome_europe';

function logMsg($s) { echo date('Y-m-d H:i:s') . " | {$s}\n"; }

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'weather_fetch: DB ' . $db->connect_error); exit(1); }

$ch = curl_init(URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,   // konwencja TymOS
    CURLOPT_SSL_VERIFYHOST => false,
]);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

// BRAK POLACZENIA => zostawiamy w bazie to, co jest. Stara prognoza jest duzo lepsza niz pusty
// widget, a jej wiek widac po `fetched_at`.
if ($raw === false || $code !== 200) {
    tymos_log('WARN', "weather_fetch: brak danych (HTTP {$code}" . ($err ? ", {$err}" : '') . ') — zostawiam poprzednie');
    exit(1);
}
$d = json_decode($raw, true);
if (!is_array($d) || empty($d['hourly']['time'])) {
    tymos_log('ERROR', 'weather_fetch: odpowiedz bez pola hourly');
    exit(1);
}

$now = date('Y-m-d H:i:s');
$h   = $d['hourly'];
$n   = count($h['time']);

// Mediana opadu z OPAD_MODELE dla jednej godziny + zgodnosc modeli w procentach.
// Modele bez danej godziny (koniec horyzontu) pomijamy — mediana liczy sie z tego, co przyszlo.
function opadMediana($h, $i) {
    $v = [];
    foreach (OPAD_MODELE as $m) {
        $col = 'precipitation_' . $m;
        if (isset($h[$col][$i]) && $h[$col][$i] !== null) $v[] = (float)$h[$col][$i];
    }
    if (!$v) return [null, null];
    sort($v);
    $c   = count($v);
    $med = ($c % 2) ? $v[intdiv($c, 2)] : ($v[$c / 2 - 1] + $v[$c / 2]) / 2;
    $zgoda = 0;
    foreach ($v as $x) if ($x >= 0.1) $zgoda++;
    return [round($med, 1), (int)round(100 * $zgoda / $c)];
}

// REPLACE, nie INSERT: kolejny przebieg NADPISUJE te same godziny nowa prognoza. O to chodzi —
// prognoza na 18:00 wyglada inaczej o 8 rano niz o 15, a my chcemy zawsze najswiezsza.
$st = $db->prepare("REPLACE INTO weather_hourly (ts,temp,clouds,wind,gust,wdir,precip,pprob,fetched_at)
                    VALUES (?,?,?,?,?,?,?,?,?)");
$opadSum = [];   // suma medianowego opadu per doba — zastepuje `precipitation_sum` z API
for ($i = 0; $i < $n; $i++) {
    $ts = str_replace('T', ' ', $h['time'][$i]) . ':00';
    [$precip, $pprob] = opadMediana($h, $i);
    $temp    = $h['temperature_2m_' . BAZA][$i];
    $clouds  = $h['cloud_cover_' . BAZA][$i];
    $wind    = $h['wind_speed_10m_' . BAZA][$i];
    $gust    = $h['wind_gusts_10m_' . BAZA][$i];
    $wdir    = $h['wind_direction_10m_' . BAZA][$i];
    $dzien   = substr($h['time'][$i], 0, 10);
    $opadSum[$dzien] = ($opadSum[$dzien] ?? 0) + (float)$precip;
    // TYPY W bind_param SA KRYTYCZNE: `precip` to milimetry z jednym miejscem po przecinku, wiec
    // musi byc 'd'. Do 2026-09-02 stalo tu 'i' i KAZDA mzawka (0,1-0,4 mm/h) wpadala do bazy jako
    // 0.0 — widget rysowal czysta pogode, gdy ICM pokazywal opad. Kolejnosc typow:
    // ts s | temp d | clouds i | wind d | gust d | wdir i | precip d | pprob i | fetched_at s
    $st->bind_param('sdiddidis', $ts, $temp, $clouds, $wind, $gust, $wdir, $precip, $pprob, $now);
    $st->execute();
}
$st->close();

$dd = $d['daily'] ?? null;
if ($dd && !empty($dd['time'])) {
    $sd = $db->prepare("REPLACE INTO weather_daily (d,tmin,tmax,precip_sum,sunrise,sunset,fetched_at)
                        VALUES (?,?,?,?,?,?,?)");
    for ($i = 0; $i < count($dd['time']); $i++) {
        $sr = substr($dd['sunrise_' . BAZA][$i], 11) . ':00';
        $ss = substr($dd['sunset_'  . BAZA][$i], 11) . ':00';
        // Suma dobowa liczona z NASZYCH medianowych godzin, nie z `precipitation_sum` API —
        // inaczej kafelek doby pokazywalby opad jednego modelu, a wykres mediane pieciu.
        $psum = round($opadSum[$dd['time'][$i]] ?? 0, 1);
        $sd->bind_param('sdddsss', $dd['time'][$i],
            $dd['temperature_2m_min_' . BAZA][$i], $dd['temperature_2m_max_' . BAZA][$i],
            $psum, $sr, $ss, $now);
        $sd->execute();
    }
    $sd->close();
}

// Stare doby zabieraja miejsce i nic nie wnosza — karta i tak rysuje tylko dzis i jutro.
$db->query("DELETE FROM weather_hourly WHERE ts < CURDATE() - INTERVAL 1 DAY");
$db->query("DELETE FROM weather_daily  WHERE d  < CURDATE() - INTERVAL 1 DAY");

logMsg("zapisano {$n} godzin (opad = mediana z " . count(OPAD_MODELE) . " modeli), doby: " . count($dd['time'] ?? []));
