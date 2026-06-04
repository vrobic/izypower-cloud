<?php
/**
 * Izypower Cloud – Lecture de la puissance PV instantanée
 */

// Chargement du .env
$env     = [];
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $val = trim(strtok($val, '#'));        // retire les commentaires inline
        $val = trim($val, '"\'');              // retire les guillemets éventuels
        $env[trim($key)] = $val;
    }
}

define('BASE_URL', 'https://application.izypowercloud.fr/photo_voltaic/api');
define('USERNAME',  $env['IZYPOWER_USERNAME'] ?? bail('Variable IZYPOWER_USERNAME manquante dans .env'));
define('PASSWORD',  $env['IZYPOWER_PASSWORD'] ?? bail('Variable IZYPOWER_PASSWORD manquante dans .env'));

function bail(string $message): never
{
    fwrite(STDERR, "ERREUR : $message\n");
    exit(1);
}

// --- Authentification ---

function login(string $username, string $password): string
{
    $ch = curl_init(BASE_URL . '/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'app-platform: izy'],
        CURLOPT_POSTFIELDS     => json_encode(['username' => $username, 'password' => $password]),
        CURLOPT_TIMEOUT        => 15,
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);

    if ($body === false) bail("Login : erreur réseau ($error)");
    if ($status !== 200) bail("Login HTTP $status : $body");

    $data  = json_decode($body, true);
    $token = $data['data']['token'] ?? null;

    if (!$token) bail("Login : token absent de la réponse : $body");

    return $token;
}

// --- Requête GET générique ---

function api_get(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'x-tts-access-token: ' . $token,
            'app-platform: izy',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);

    if ($body === false) bail("Requête GET $url : erreur réseau ($error)");
    if ($status !== 200) bail("Requête GET $url : HTTP $status : $body");

    $data = json_decode($body, true);
    if ($data === null) bail("Requête GET $url : réponse JSON invalide : $body");

    return $data;
}

// --- Programme principal ---

$token = login(USERNAME, PASSWORD);

$stations = api_get(BASE_URL . '/powerStations/page?page=1&limit=100', $token);
$records  = $stations['data']['records'] ?? [];

if (empty($records)) {
    echo "Aucune centrale trouvée.\n";
    exit;
}

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

foreach ($records as $station) {
    $id = $station['stationsId'] ?? bail("stationsId absent pour la centrale : " . json_encode($station));
    $info      = api_get(BASE_URL . "/v3/powerStations/info/$id", $token);
    $component = api_get(BASE_URL . "/component/$id?searchTime=$today", $token);
    $report    = api_get(BASE_URL . "/report/v2/powerStations/data/$id?timeType=day&dataFlag=energy&searchTime=$today", $token);
    $report_yesterday = api_get(BASE_URL . "/report/v2/powerStations/data/$id?timeType=day&dataFlag=energy&searchTime=$yesterday", $token);
    $upgrade   = api_get(BASE_URL . "/v3/device/upgrade/$id", $token);
    $devices   = api_get(BASE_URL . "/device/page?powerId=$id&deviceType=all&page=1&limit=100", $token);

    $pv_power    = $info['power']      ?? null;
    $last_update = preg_replace('/ UTC[+-]\d{2}:\d{2}$/', '', $info['lastUpdate'] ?? 'N/C');

    $pv_chains        = $component['pvData'] ?? [];
    $daily_energy     = $report['energy'] ?? null;
    $yesterday_energy = $report_yesterday['energy'] ?? null;

    // Température
    $temp = null;
    foreach ($devices['data']['records'] ?? [] as $device) {
        $sn = $device['sn'] ?? $device['serialNumber'] ?? null;
        if (!$sn) continue;
        $temp_rows = api_get(BASE_URL . "/report/device/data/$sn?searchTime=$today&timeType=day&dataFlag=temp", $token)['data'][0]['data'] ?? [];
        $last_row  = !empty($temp_rows) ? end($temp_rows) : null;
        $temp      = $last_row['val'] ?? null;
        break;
    }

    $upgrade_entries = $upgrade['data'] ?? [];
    $has_upgrade     = !empty(array_filter($upgrade_entries, fn($e) => !empty($e['needUpgrade'])));

    $pv_rows = array_map(fn($c) => [
        '  ↳ ' . strtoupper($c['pv'] ?? '?'),
        ($c['pvPower'] ?? 'N/C') . ' W',
    ], $pv_chains);

    $rows = [
        ['Date du relevé',           $last_update],
        ['Production instantanée',  ($pv_power !== null ? "$pv_power W" : 'N/C')],
        ...$pv_rows,
        ['Production jour',          ($daily_energy !== null ? "$daily_energy kWh" : 'N/C')],
        ['Production veille',       ($yesterday_energy !== null ? "$yesterday_energy kWh" : 'N/C')],
        ['Température onduleur',    ($temp !== null ? "$temp °C" : 'N/C')],
        ['Firmware',                $has_upgrade ? '⚠️  mise à jour disponible' : '✅  à jour'],
    ];

    $width = max(array_map(fn($r) => mb_strlen($r[0], 'UTF-8'), $rows));

    foreach ($rows as [$label, $value]) {
        $pad = str_repeat(' ', $width - mb_strlen($label, 'UTF-8'));
        echo "$label$pad : $value\n";
    }
    echo "\n";
}
