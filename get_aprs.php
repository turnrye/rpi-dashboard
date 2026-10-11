<?php
// The m17-gateway APRS traffic log ([APRS] Log), newest first: every APRS
// line heard on RF or received from APRS-IS, what the gateway did with it,
// and what it sent. One JSON object per line; see m17-gateway's
// docs/aprs-igate.md.
include 'functions.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// A fixed path, like files/dashboard.log: a symlink to the gateway's file.
const APRS_LOG = 'files/aprs.log';

$tz = $config['timezone'] ?? 'UTC';
date_default_timezone_set(in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC');
$format = (($config['unit_system'] ?? 'metric') === 'imperial') ? 'm/d/y h:i:s A' : 'd.m.y H:i:s';
$n = max(1, min(500, (int)($_GET['n'] ?? 100)));

$out = [];
if (is_readable(APRS_LOG)) {
    foreach (array_reverse(tailFile(APRS_LOG, $n)) as $line) {
        $e = json_decode($line, true);
        if (!is_array($e) || !isset($e['time'], $e['dir'], $e['line'])) continue;
        try {
            $dt = new DateTime((string)$e['time']);
        } catch (Exception $x) {
            continue;
        }
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $out[] = [
            'time' => $dt->format($format),
            'dir' => (string)$e['dir'],
            'm17' => (string)($e['m17'] ?? ''),
            'line' => (string)$e['line'],
            'result' => (string)($e['result'] ?? ''),
            'out' => (string)($e['out'] ?? ''),
            'class' => (string)($e['class'] ?? ''),
        ];
    }
}
echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
