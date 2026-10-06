#!/usr/bin/env php
<?php
/**
 * energa_operator_import.php — pobiera zuzycie kWh z Energa MojLicznik
 * Zapisuje do tabeli: energa, kolumna: kwh_energa_operator
 * Harmonogram: co 2h nieparzyste o :05 (cron.d/tymos)
 */

require_once __DIR__ . '/lib/_log.inc.php';

$ENERGA_USER  = ENERGA_OPERATOR_USER;
$ENERGA_PASS  = ENERGA_OPERATOR_PASS;
$ENERGA_METER = ENERGA_OPERATOR_METER;
$COOKIE_JAR   = '/tmp/energa_cookie.txt';
$USER_AGENT   = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

$yesterday = gmdate('Y-m-d', time() - 86400);
$today     = gmdate('Y-m-d');

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) { tymos_log('ERROR', 'DB connect: ' . $db->connect_error); exit(1); }

// ===== LOGIN =====
echo "Login...\n";

// Pobierz strone logowania (cookie + CSRF)
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://mojlicznik.energa-operator.pl/dp/UserLogin.do',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR      => $COOKIE_JAR,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => $USER_AGENT,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$login_page = curl_exec($ch);
curl_close($ch);

$csrf = '';
if (preg_match('/name="_antixsrf"\s+value="([^"]*)"/', $login_page, $m)) {
    $csrf = $m[1];
}

// Wyslij login
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://mojlicznik.energa-operator.pl/dp/UserLogin.do',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $COOKIE_JAR,
    CURLOPT_COOKIEJAR      => $COOKIE_JAR,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => $USER_AGENT,
    CURLOPT_HTTPHEADER     => ['Referer: https://mojlicznik.energa-operator.pl/dp/UserLogin.do'],
    CURLOPT_POST           => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_POSTFIELDS     => http_build_query([
        'j_username'   => $ENERGA_USER,
        'j_password'   => $ENERGA_PASS,
        'loginNow'     => 'zaloguj się',
        'selectedForm' => '1',
        'save'         => 'save',
        'clientOS'     => 'web',
        'rememberMe'   => 'on',
        '_antixsrf'    => $csrf,
    ]),
]);
$login_resp = curl_exec($ch);
curl_close($ch);

if (strpos($login_resp, 'captcha') !== false) {
    tymos_log('ERROR', 'Energa Operator: CAPTCHA wymagana — zaloguj sie recznie przez przegladarke');
    exit(1);
}

// ===== FETCH + STORE =====
foreach ([$yesterday, $today] as $day) {
    $dt = new DateTime($day, new DateTimeZone('Europe/Warsaw'));
    $ms = $dt->getTimestamp() * 1000;

    echo "Fetch $day (ms=$ms)...\n";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://mojlicznik.energa-operator.pl/dp/resources/chart?mainChartDate=$ms&type=DAY&meterPoint=$ENERGA_METER&mo=A%2B",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $COOKIE_JAR,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => $USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    if (!$body) {
        tymos_log('WARN', "Energa Operator fetch failed dla dnia {$day}");
        continue;
    }

    $data = json_decode($body, true);
    if (!$data) {
        tymos_log('ERROR', "Energa Operator: nie udalo sie zdekodowac JSON dla {$day} — raw dump: /tmp/tymos/energa_operator_raw.json");
        file_put_contents('/tmp/tymos/energa_operator_raw.json', $body);
        continue;
    }

    $frames = $data['response']['mainChart'] ?? [];
    $count = 0;

    foreach ($frames as $frame) {
        $kwh = $frame['zones'][0] ?? null;
        if ($kwh === null) continue;

        $tm_s = (int)($frame['tm'] / 1000);
        $ts_local = (new DateTime("@$tm_s"))->setTimezone(new DateTimeZone('Europe/Warsaw'));
        $ts_local->modify('+1 hour');
        $ts = $ts_local->format('Y-m-d H:00:00');

        $kwh = round((float)$kwh, 6);
        $db->query("INSERT INTO energa (ts, kwh_energa_operator) VALUES ('$ts',$kwh) ON DUPLICATE KEY UPDATE kwh_energa_operator=$kwh");
        $count++;
    }

    echo "$day: $count rekordow\n";
}

$db->close();
echo "Done.\n";
