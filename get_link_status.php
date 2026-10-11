<?php
// m17-gateway's reflector link status ([Reflector] LinkStatus): the home
// reflector, the current link, who changed it over RF, and when the
// gateway goes back home. The gateway rewrites it every 5 seconds.
include 'functions.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

// A fixed path, like files/dashboard.log: a symlink to the gateway's file.
const LINK_STATUS = 'files/link-status.json';

$s = is_readable(LINK_STATUS) ? json_decode((string)file_get_contents(LINK_STATUS), true) : null;
if (!is_array($s) || time() - (strtotime($s['time'] ?? '') ?: 0) > 30) {
    // Missing, or not rewritten lately: the gateway is stopped or older
    echo json_encode(['available' => false]);
    exit;
}
$s['available'] = true;
echo json_encode($s);
