<?php
header('Content-Type: application/json');
include 'functions.php';

// ?view=sms returns only text messages, limited by sms_max
$smsOnly = (($_GET['view'] ?? '') === 'sms');
$limit = $smsOnly ? $config['sms_max'] : $config['maxlines'];
$metric = ($config['unit_system'] === 'metric');

// Read more lines than we show: voice streams take two lines each, and GNSS
// and reflector events are interleaved
$entries = readLogEntries($config['gateway_log_file'], $smsOnly ? 2000 : max(100, $limit * 4));

// How a reflector stream arrived, from m17-gateway's counts on Voice End:
// frames lost before reaching the hotspot, and frames it filled with silence
// because they came late or not at all, each as a share of the stream.
function netQuality(array $e): array {
    $frames = (int)$e['frames'];
    $lost = (int)($e['lost'] ?? 0);
    $filled = (int)($e['filled'] ?? 0);
    return [
        'frames' => $frames,
        'lost' => $lost,
        'loss' => $frames + $lost > 0 ? round(100 * $lost / ($frames + $lost), 1) : 0,
        'filled' => $filled,
        'fill' => $frames + $filled > 0 ? round(100 * $filled / ($frames + $filled), 1) : 0,
        'silent' => (int)($e['silent'] ?? 0),
        'maxGapMs' => (int)($e['maxGapMs'] ?? 0),
    ];
}

$voiceStarts = [];
$callsWithGnss = [];
$out = [];

foreach ($entries as $e) {
    $src = trim((string)($e['src'] ?? ''));
    $subtype = $e['subtype'];

    if ($subtype === 'GNSS') {
        $callsWithGnss[$src] = true;
        continue;
    }

    $dt = logTime($e['time']);
    if (!$dt) continue;

    $row = [
        'time' => $dt->format($metric ? 'd.m.y H:i' : 'm/d/y h:i A'),
        'timestamp' => $dt->getTimestamp(),
        'src' => $src,
        'dst' => trim((string)($e['dst'] ?? '')),
        'type' => (string)$e['type'],
        'can' => isset($e['can']) ? (int)$e['can'] : null,
        // Bit error rate, RF only
        'mer' => ($e['type'] === 'RF' && isset($e['mer']) && is_numeric($e['mer'])) ? round((float)$e['mer'], 1) : null,
        'gnss' => false,
        'duration' => '',
    ];

    if ($subtype === 'Packet') {
        $sms = isset($e['smsMessage']) ? (string)$e['smsMessage'] : null;
        if ($smsOnly && $sms === null) continue;
        $row['subtype'] = 'Packet';
        $row['smsMessage'] = $sms;
        $out[] = $row;
        continue;
    }

    if ($smsOnly) continue;

    if ($subtype === 'Voice Start') {
        $voiceStarts[$src] = $dt;
        continue;
    }

    if ($subtype === 'Voice End' && isset($voiceStarts[$src])) {
        $duration = max(0, $dt->getTimestamp() - $voiceStarts[$src]->getTimestamp());
        unset($voiceStarts[$src]);
        $hours = intdiv($duration, 3600);
        $minutes = intdiv($duration % 3600, 60);
        $seconds = $duration % 60;
        if ($hours > 0) {
            $row['duration'] = sprintf("%d h %d m %d s", $hours, $minutes, $seconds);
        } else if ($minutes > 0) {
            $row['duration'] = sprintf("%d m %d s", $minutes, $seconds);
        } else {
            $row['duration'] = sprintf("%d s", $seconds);
        }
        $row['subtype'] = 'Voice';
        $row['gnss'] = isset($callsWithGnss[$src]);
        // Gateways older than these counts log none
        if ($row['type'] === 'Internet' && isset($e['frames'])) {
            $row['quality'] = netQuality($e);
        }
        $out[] = $row;
    }
}

// Newest first
usort($out, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

echo json_encode(array_slice($out, 0, $limit));
