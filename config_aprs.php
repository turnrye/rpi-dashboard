<?php
include 'functions.php';
// Newer dashboards keep settings behind the admin login
if (file_exists(__DIR__ . '/auth.php')) {
    include 'auth.php';
    requireAdmin();
}
include 'aprs_ini.php';
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$iniFile = $config['gateway_config_file'];
$lines = @file($iniFile, FILE_IGNORE_NEW_LINES);
$values = ($lines === false) ? [] : aprsIniValues($lines, 'APRS');
$errors = [];
$message = '';

// Settings this page edits, by kind
$checks = ['Enabled' => false, 'RFtoIS' => true, 'IStoRF' => false, 'Relay' => false, 'CourtesyPosition' => true];
$durations = ['MinTxGap' => ['s', 3], 'MaxQueueAge' => ['s', 60], 'HeardWindow' => ['m', 60], 'BeaconInterval' => ['m', 30]];
$numbers = ['RateLimit' => 6, 'RateBurst' => 3];
$texts = ['Callsign' => '', 'Server' => 'rotate.aprs2.net:14580', 'Comment' => '', 'Symbol' => 'I&'];

function isOn($v) { return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true); }

// "3s", "60m" or "1h" as a number in $unit
function durationIn($v, $unit, $default) {
    if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*(ms|s|m|h)?\s*$/', (string)$v, $m)) return $default;
    $secs = (float)$m[1] * ['ms' => 0.001, 's' => 1, 'm' => 60, 'h' => 3600][$m[2] ?? 's'];
    return round($secs / ($unit === 'm' ? 60 : 1), 1);
}

if ($lines !== false && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $set = [];
    $del = [];
    foreach ($checks as $k => $d) $set[$k] = isset($_POST[$k]) ? 'true' : 'false';
    foreach ($durations as $k => [$unit, $d]) {
        $v = trim($_POST[$k] ?? '');
        if (!is_numeric($v) || $v < 0) { $errors[] = "$k must be a number."; continue; }
        $set[$k] = (0 + $v) . $unit;
    }
    foreach ($numbers as $k => $d) {
        $v = trim($_POST[$k] ?? '');
        if (!ctype_digit($v) || $v < 1) { $errors[] = "$k must be a whole number of at least 1."; continue; }
        $set[$k] = $v;
    }
    foreach ($texts as $k => $d) $set[$k] = trim($_POST[$k] ?? '');
    foreach (['CAN' => 15, 'Ambiguity' => 4] as $k => $most) {
        $v = trim($_POST[$k] ?? '0');
        if (!ctype_digit($v) || $v > $most) { $errors[] = "$k must be a whole number from 0 to $most."; continue; }
        $set[$k] = $v;
    }
    foreach (['HAAT', 'Gain'] as $k) {
        $v = trim($_POST[$k] ?? '0');
        if (!is_numeric($v) || $v < 0) { $errors[] = "$k must be a number, 0 or more."; continue; }
        $set[$k] = 0 + $v;
    }
    if (strlen($set['Symbol']) !== 2) $errors[] = 'The symbol is two characters: a table (or overlay) and a code, such as M&.';
    $set['Callsign'] = strtoupper($set['Callsign']);
    if ($set['Callsign'] !== '' && !preg_match('/^[A-Z0-9]{1,6}(-([1-9]|1[0-5]))?$/', $set['Callsign'])) {
        $errors[] = 'The APRS callsign must be an AX.25 one, such as N0CALL-10.';
    }
    if (trim($_POST['Passcode'] ?? '') !== '') {
        $pc = trim($_POST['Passcode']);
        if (!preg_match('/^-?\d+$/', $pc)) $errors[] = 'The passcode is a number, or -1 to receive only.';
        else $set['Passcode'] = $pc;
    }
    $lat = trim($_POST['Latitude'] ?? '');
    $lon = trim($_POST['Longitude'] ?? '');
    if ($lat === '' && $lon === '') {
        $del[] = 'Latitude';
        $del[] = 'Longitude';
    } else if (is_numeric($lat) && is_numeric($lon) && abs($lat) <= 90 && abs($lon) <= 180) {
        $set['Latitude'] = round((float)$lat, 5);
        $set['Longitude'] = round((float)$lon, 5);
    } else {
        $errors[] = 'The hotspot position must be latitude and longitude in decimal degrees.';
    }

    // Watch rules: those kept, then the new one, renumbered from Watch1
    $rules = [];
    foreach ($values as $k => $v) {
        if (!preg_match('/^Watch\d+$/', $k)) continue;
        $w = aprsParseWatch($v);
        $ended = $w && $w['until'] && $w['until'] <= time();
        if (!isset($_POST['remove'][$k]) && !$ended) $rules[] = $v;
        $del[] = $k;
    }
    if (($_POST['add_kind'] ?? '') !== '') {
        $hours = (float)($_POST['add_hours'] ?? 0);
        [$rule, $err] = aprsWatchValue($_POST['add_kind'], $_POST['add_call'] ?? '', $_POST['add_lat'] ?? '',
                                       $_POST['add_lon'] ?? '', $_POST['add_km'] ?? '', ($_POST['add_action'] ?? '') === 'rf',
                                       $hours > 0 ? time() + (int)($hours * 3600) : 0);
        if ($err) $errors[] = $err;
        else if (count($rules) >= 16) $errors[] = 'There can be at most 16 rules.';
        else $rules[] = $rule;
    }
    foreach ($rules as $i => $v) {
        $set['Watch' . ($i + 1)] = $v;
        $del = array_values(array_diff($del, ['Watch' . ($i + 1)]));
    }

    foreach ($set as $k => $v) {
        if (!aprsValidValue($v)) $errors[] = "$k: quotes, backslashes, ';' and '#' are not allowed.";
    }
    if (!$errors) {
        $wasOn = isOn($values['Enabled'] ?? 'false');
        $lines = aprsIniUpdate($lines, 'APRS', $set, $del);
        if (file_put_contents($iniFile, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            $errors[] = 'Could not write the gateway configuration.';
        } else if ($set['Enabled'] === 'true' && !$wasOn) {
            @shell_exec('systemctl restart m17-gateway');
            $message = 'Saved, and the gateway is restarting to turn APRS on.';
        } else {
            $message = 'Saved. The gateway applies it within about 10 seconds.';
        }
        $values = aprsIniValues($lines, 'APRS');
    }
}

$val = fn($k, $d = '') => $values[$k] ?? $d;
$rules = [];
foreach ($values as $k => $v) {
    if (preg_match('/^Watch\d+$/', $k)) $rules[$k] = aprsParseWatch($v);
}

$page = 'aprs';
include 'header.php';
?>
<div class="page-content">
<h2>APRS settings</h2>
<p class="aprs-note">m17-gateway's APRS gate. Changes apply within about 10 seconds, without interrupting the reflector link; turning APRS on restarts the gateway. <a href="aprs.php">Status and traffic</a></p>
<?php if ($lines === false): ?><p class="aprs-bad">Can't read the gateway configuration (<?= h($iniFile) ?>).</p><?php endif; ?>
<?php foreach ($errors as $e): ?><p class="aprs-bad"><?= h($e) ?></p><?php endforeach; ?>
<?php if ($message): ?><p class="aprs-good"><?= h($message) ?></p><?php endif; ?>

<form method="POST">
<?php if (function_exists('csrfField')) echo csrfField(); ?>
<div class="card">
  <h2>APRS-IS</h2>
  <div class="form-grid-2col">
    <?php foreach ($checks as $k => $d): ?>
    <label class="form-field"><span><input type="checkbox" name="<?= h($k) ?>" <?= isOn($val($k, $d ? 'true' : 'false')) ? 'checked' : '' ?>>
      <?= h(['Enabled' => 'APRS gate on', 'RFtoIS' => 'Gate RF to APRS-IS', 'IStoRF' => 'Send APRS-IS messages to RF',
             'Relay' => 'Relay messages between local stations (simplex)', 'CourtesyPosition' => 'Courtesy position after a message'][$k]) ?></span></label>
    <?php endforeach; ?>
    <div class="form-field"><label>Callsign (AX.25, such as N0CALL-10)</label><input class="input" name="Callsign" value="<?= h($val('Callsign')) ?>"></div>
    <div class="form-field"><label>Passcode (blank keeps the current one; -1 receives only)</label><input class="input" type="password" name="Passcode" autocomplete="off" placeholder="<?= $val('Passcode', '-1') === '-1' ? '-1 (receive only)' : 'set' ?>"></div>
    <div class="form-field"><label>Server</label><input class="input" name="Server" value="<?= h($val('Server', $texts['Server'])) ?>"></div>
    <div class="form-field"><label>Quiet gap before each packet to RF (s)</label><input class="input" name="MinTxGap" value="<?= h(durationIn($val('MinTxGap'), 's', 3)) ?>"></div>
    <div class="form-field"><label>Packets to RF per minute</label><input class="input" name="RateLimit" value="<?= h($val('RateLimit', 6)) ?>"></div>
    <div class="form-field"><label>... in a burst</label><input class="input" name="RateBurst" value="<?= h($val('RateBurst', 3)) ?>"></div>
    <div class="form-field"><label>Longest wait to send (s)</label><input class="input" name="MaxQueueAge" value="<?= h(durationIn($val('MaxQueueAge'), 's', 60)) ?>"></div>
    <div class="form-field"><label>A station counts as heard for (min)</label><input class="input" name="HeardWindow" value="<?= h(durationIn($val('HeardWindow'), 'm', 60)) ?>"></div>
  </div>
</div>

<div class="card">
  <h2>Hotspot position and beacon</h2>
  <p class="aprs-note">The hotspot's position, for its beacon to APRS-IS (never to RF) and for "near" rules. Click the map with "hotspot position" picked below, or type it.</p>
  <div class="form-grid-2col">
    <div class="form-field"><label>Latitude</label><input class="input" id="Latitude" name="Latitude" value="<?= h($val('Latitude')) ?>"></div>
    <div class="form-field"><label>Longitude</label><input class="input" id="Longitude" name="Longitude" value="<?= h($val('Longitude')) ?>"></div>
    <div class="form-field"><label>Beacon every (min, 0 for never)</label><input class="input" name="BeaconInterval" value="<?= h(durationIn($val('BeaconInterval'), 'm', 30)) ?>"></div>
    <div class="form-field"><label>Blur the position</label>
      <select class="input" name="Ambiguity">
        <?php foreach (['exact', 'to about 200 m', 'to about 2 km', 'to about 20 km', 'to about 110 km'] as $i => $label): ?>
        <option value="<?= $i ?>" <?= (int)$val('Ambiguity', 0) === $i ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="form-field"><label>CAN radios use here</label><input class="input" name="CAN" value="<?= h($val('CAN', 0)) ?>"></div>
    <div class="form-field"><label>Antenna height above average terrain (m; 0 leaves out PHG)</label><input class="input" name="HAAT" value="<?= h($val('HAAT', 0)) ?>"></div>
    <div class="form-field"><label>Antenna gain (dBi)</label><input class="input" name="Gain" value="<?= h($val('Gain', 0)) ?>"></div>
    <div class="form-field"><label>Symbol (table or overlay, then code; M& is the gateway symbol with an M)</label><input class="input" name="Symbol" value="<?= h($val('Symbol', 'M&')) ?>"></div>
    <div class="form-field"><label>Comment, after the frequency, CAN and reflector</label><input class="input" name="Comment" value="<?= h($val('Comment')) ?>"></div>
  </div>
  <p class="aprs-note">The beacon will be: <code id="beacon-preview">(the gateway shows it once a position is set)</code></p>
</div>

<div class="card">
  <h2>Watch rules</h2>
  <p class="aprs-note">More APRS-IS traffic, beyond messages for stations heard on RF. "Send to RF" transmits it (never a local station's own packets), within the rate limit; "show here" only lists it on the APRS page.</p>
  <table class="aprs-small">
    <thead><tr><th>Remove</th><th>Rule</th><th>Then</th><th>Until</th></tr></thead>
    <tbody>
    <?php foreach ($rules as $k => $w): ?>
      <tr class="<?= ($w && $w['until'] && $w['until'] <= time()) ? 'aprs-skip' : '' ?>">
        <td><input type="checkbox" name="remove[<?= h($k) ?>]"></td>
        <?php if (!$w): ?><td colspan="3" class="aprs-bad"><?= h($values[$k]) ?> (not a rule)</td>
        <?php else: ?>
        <td><?= $w['kind'] === 'call' ? 'from or to ' . h($w['call'])
              : ($w['kind'] === 'area' ? 'within ' . h($w['km']) . ' km of ' . h($w['lat']) . ', ' . h($w['lon'])
              : 'within ' . h($w['km']) . ' km of the hotspot') ?></td>
        <td><?= $w['toRF'] ? 'send to RF' : 'show here' ?></td>
        <td><?= $w['until'] ? h(date('Y-m-d H:i', $w['until'])) . ($w['until'] <= time() ? ' (ended)' : '') : 'no end' ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rules): ?><tr><td colspan="4" class="aprs-skip">None yet</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h3>Add a rule</h3>
  <div class="form-grid-2col">
    <div class="form-field"><label>Watch</label>
      <select class="input" name="add_kind" id="add_kind">
        <option value="">(nothing new)</option>
        <option value="call">a station: traffic from or to a callsign</option>
        <option value="area">an area: around a point on the map</option>
        <option value="near">near the hotspot</option>
      </select></div>
    <div class="form-field rule-call"><label>Callsign (K0RET-4, or K0RET* for every SSID)</label><input class="input" name="add_call"></div>
    <div class="form-field rule-area"><label>Centre latitude</label><input class="input" id="add_lat" name="add_lat"></div>
    <div class="form-field rule-area"><label>Centre longitude</label><input class="input" id="add_lon" name="add_lon"></div>
    <div class="form-field rule-radius"><label>Radius (km)</label><input class="input" id="add_km" name="add_km" value="25"></div>
    <div class="form-field"><label>Then</label>
      <select class="input" name="add_action"><option value="dashboard">show here</option><option value="rf">send to RF</option></select></div>
    <div class="form-field"><label>For</label>
      <select class="input" name="add_hours"><option value="1">1 hour</option><option value="4" selected>4 hours</option>
        <option value="24">1 day</option><option value="168">1 week</option><option value="0">no end</option></select></div>
  </div>
  <p><label>A map click sets
    <select class="input" id="pick" style="width:auto"><option value="rule">the new rule's centre</option><option value="hotspot">the hotspot position</option></select></label></p>
  <div id="map" style="height:420px;border-radius:12px;"></div>
</div>

<div class="floating-actions"><button type="submit" class="btn-primary">Save</button></div>
</form>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
.aprs-note { color:var(--text-muted); }
.aprs-good { color:#22c55e; } .aprs-bad { color:#ef4444; } .aprs-skip { color:var(--text-muted); }
table.aprs-small { width:100%; font-size:0.9rem; } table.aprs-small td, table.aprs-small th { padding:4px 6px; }
</style>
<script>
const $id = id => document.getElementById(id);
const num = id => parseFloat($id(id).value);
const home = [num('Latitude'), num('Longitude')];
const map = L.map('map').setView(isNaN(home[0]) ? [39, -96] : home, isNaN(home[0]) ? 4 : 9);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 18,
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
}).addTo(map);
let hotspot = null, area = null;
function draw() {
  if (hotspot) map.removeLayer(hotspot);
  if (area) map.removeLayer(area);
  hotspot = area = null;
  const h = [num('Latitude'), num('Longitude')];
  if (!isNaN(h[0]) && !isNaN(h[1])) hotspot = L.marker(h, { title: 'Hotspot' }).addTo(map);
  const kind = $id('add_kind').value;
  const km = num('add_km');
  const c = kind === 'near' ? h : [num('add_lat'), num('add_lon')];
  if ((kind === 'area' || kind === 'near') && !isNaN(c[0]) && !isNaN(c[1]) && km > 0) {
    area = L.circle(c, { radius: km * 1000, color: '#73d7af' }).addTo(map);
  }
}
function showFields() {
  const kind = $id('add_kind').value;
  document.querySelectorAll('.rule-call').forEach(e => e.hidden = kind !== 'call');
  document.querySelectorAll('.rule-area').forEach(e => e.hidden = kind !== 'area');
  document.querySelectorAll('.rule-radius').forEach(e => e.hidden = kind !== 'area' && kind !== 'near');
  draw();
}
map.on('click', e => {
  const lat = e.latlng.lat.toFixed(4), lon = e.latlng.lng.toFixed(4);
  if ($id('pick').value === 'hotspot') {
    $id('Latitude').value = lat; $id('Longitude').value = lon;
  } else {
    if ($id('add_kind').value !== 'area') $id('add_kind').value = 'area';
    $id('add_lat').value = lat; $id('add_lon').value = lon;
  }
  showFields();
});
['add_kind', 'add_km', 'add_lat', 'add_lon', 'Latitude', 'Longitude'].forEach(id => $id(id).addEventListener('input', showFields));
showFields();
// The gateway builds the beacon itself, from the saved settings
function preview() {
  fetch('get_aprs_status.php').then(r => r.json()).then(s => {
    if (s && s.beaconPreview) $id('beacon-preview').textContent = s.beaconPreview;
  }).catch(() => {});
}
preview();
setInterval(preview, 5000);
</script>
<?php include 'footer.php'; ?>
