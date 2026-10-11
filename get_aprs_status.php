<?php
// m17-gateway's APRS status ([APRS] Status): whether it is logged in to
// APRS-IS and where, the filter in force, heard stations, watch rules,
// queue and counters. The gateway rewrites it every 5 seconds.
include 'functions.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// A fixed path, like files/dashboard.log: a symlink to the gateway's file.
const APRS_STATUS = 'files/aprs-status.json';

$s = is_readable(APRS_STATUS) ? json_decode((string)file_get_contents(APRS_STATUS), true) : null;
if (!is_array($s)) {
    echo json_encode(['available' => false]);
    exit;
}
$age = time() - (strtotime($s['time'] ?? '') ?: 0);
$s['available'] = true;
// Older than a few writes: the gateway is stopped or has no APRS gate
$s['stale'] = $age > 30;
$s['age'] = $age;
foreach (['lastKeepalive', 'connectedAt', 'lastBeacon'] as $k) {
    if (!empty($s[$k])) $s[$k . 'Age'] = time() - (strtotime($s[$k]) ?: time());
}
foreach ($s['heard'] ?? [] as $i => $h) {
    $s['heard'][$i]['age'] = time() - (strtotime($h['lastHeard']) ?: time());
}
echo json_encode($s, JSON_INVALID_UTF8_SUBSTITUTE);
