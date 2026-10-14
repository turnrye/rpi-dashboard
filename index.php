<?php
include 'functions.php';
$page = 'dashboard';
include 'header.php';

$gateway_config = @parse_ini_file($config['gateway_config_file'], true, INI_SCANNER_RAW) ?: [];
function freqMHz($hz) {
    return is_numeric($hz) ? number_format($hz / 1000000, 3) . ' MHz' : 'N/A';
}
$rxfreq = freqMHz($gateway_config['Radio']['RXFrequency'] ?? null);
$txfreq = freqMHz($gateway_config['Radio']['TXFrequency'] ?? null);
$power = $gateway_config['Radio']['Power'] ?? '';
$power = is_numeric($power) ? $power . ' dBm' : 'N/A';
?>
<div class="cards">
<section class="card"><h2>Transceiver info</h2>
<p>RX frequency <strong><?= h($rxfreq) ?></strong></p>
<p>TX frequency <strong><?= h($txfreq) ?></strong></p>
<p>Power <strong><?= h($power) ?></strong></p>
</section>
<section class="card"><h2>Gateway status</h2>
<p>Callsign <strong id="gw_callsign"><?= h($gateway_config['General']['Callsign'] ?? 'N/A') ?></strong></p>
<p>Gateway <strong id="gw_status" class="status-good"></strong></p>
<p>Reflector <strong id="gw_ref"></strong></p>
<p>Module <strong id="gw_mod"></strong></p>
<p id="gw_home_row" hidden>Home reflector <strong id="gw_home"></strong></p>
<p id="gw_revert_row" hidden>Back home in <strong id="gw_revert"></strong></p>
<p>Radio state <strong id="gw_radio" class="status-good"></strong></p>
</section></div>
<h2>Recent activity</h2>
<div class="table-card"><table id="lastheard">
<thead><tr>
<th>Time</th><th>Source</th><th>Destination</th><th>Interface</th>
<th>Type</th><th>CAN</th><th title="Bit error rate before error correction, between the radio and this hotspot (RF)">BER</th>
<th title="Frames lost between the sending station and this hotspot (Internet). One station high and the rest clean: their connection. Every station high: this hotspot's connection or the reflector.">Loss</th>
<th title="Frames this hotspot replaced with silence because they came late or not at all (Internet)">Filled</th><th>Duration</th>
</tr></thead><tbody></tbody></table></div>
<script>
function updateStatus(){fetch('get_status.php').then(r=>r.json()).then(d=>{
if(!d)return;
let g=document.getElementById('gw_status');
let r=document.getElementById('gw_radio');
if(!linkStatus){
document.getElementById('gw_ref').textContent=d.connected_ref||'-';
document.getElementById('gw_mod').textContent=d.connected_mod||'-';
}
r.textContent=d.radio_status||'unknown';
g.textContent=d.gateway_status||'unknown';
g.className=(g.textContent.toLowerCase()==='running')?'status-good':'status-bad';
r.className=(r.textContent.toLowerCase()==='listening')?'status-good':'status-bad';
}).catch(()=>{});}

// All values from the log are escaped with esc() before they go into HTML:
// call signs and text messages come from RF and from the reflector.
function updateDashboard(){
$.getJSON('get_lastheard.php',data=>{
if(!Array.isArray(data))return;
let rows='';
data.forEach(e=>{
let iface = e.type === 'RF' ? 'RF' : 'Internet';
let src = qrzLink(e.src) + (e.gnss ? ' &#128752;' : '');

// Bit error rate before error correction, in %. The colors are set for
// packets, which are lost whole if one frame fails: clean under 0.3%,
// occasional losses up to 1.5%, many above. Voice tolerates more, since a
// bad frame is only a brief glitch, so orange or red on voice means
// marginal rather than broken.
let ber = '<td></td>';
if (iface === 'RF' && Number.isFinite(e.mer)) {
  let v = e.mer;
  let c = v < 0.3 ? 'mer-good' : v < 1.5 ? 'mer-warn' : 'mer-bad';
  ber = `<td class="${c}">${v.toFixed(1)} %</td>`;
}

// Internet streams: frames lost between the sender and this hotspot, and
// frames the hotspot filled with silence because they came late or not at
// all, as a share of the stream. Colors follow Pi-Star's loss column: clean
// under 1%, noticeable up to 3%, broken above.
let loss = '<td></td>', fill = '<td></td>';
if (iface === 'Internet' && e.quality) {
  const q = e.quality;
  const c = v => v < 1 ? 'q-good' : v <= 3 ? 'q-warn' : 'q-bad';
  const tip = esc(`${q.lost} of ${q.frames + q.lost} frames lost, ${q.filled} filled with silence, ` +
    `longest gap ${q.maxGapMs} ms` + (q.silent ? `, ${q.silent} silence frames from the sender` : ''));
  loss = `<td class="${c(q.loss)}" title="${tip}">${q.loss.toFixed(1)} %</td>`;
  fill = `<td class="${c(q.fill)}" title="${tip}">${q.fill.toFixed(1)} %</td>`;
}

rows += `<tr${e.subtype === 'Packet' ? ' class="packet"' : ''}>
  <td>${esc(e.time)}</td>
  <td>${src}</td>
  <td>${esc(e.dst)}</td>
  <td>${iface}</td>
  <td>${esc(e.subtype)}</td>
  <td>${esc(e.can)}</td>
  ${ber}
  ${loss}
  ${fill}
  <td>${esc(e.duration)}</td>
</tr>`;
});
$('#lastheard tbody').html(rows);
});}

$(function(){updateStatus();updateDashboard();
setInterval(updateStatus,2000);
setInterval(updateDashboard,2000);});
</script>
<script>
// m17-gateway's reflector link, which a radio can change over RF: the
// gateway's own word on it, who changed it, and when it goes back home.
var linkStatus = null;
function showLink(){
  const s = linkStatus;
  document.getElementById('gw_home_row').hidden = !s;
  document.getElementById('gw_revert_row').hidden = !s || !s.revertAt;
  if (!s) return;
  const at = (s.linked || '').split(' ');
  document.getElementById('gw_ref').textContent = at[0] || 'unlinked';
  document.getElementById('gw_mod').textContent = at[1] || '-';
  const away = s.linked !== s.home;
  document.getElementById('gw_home').textContent = (s.home || 'none') +
    (away && s.linkedBy ? ' (changed by ' + s.linkedBy + ')' : '');
  if (s.revertAt) {
    const left = Math.max(0, Math.round((Date.parse(s.revertAt) - Date.now()) / 1000));
    document.getElementById('gw_revert').textContent =
      Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0') + ' (anything received on RF resets it)';
  }
}
function updateLink(){
  fetch('get_link_status.php').then(r => r.json()).then(s => {
    linkStatus = (s && s.available) ? s : null;
    showLink();
  }).catch(() => {});
}
$(function(){ updateLink(); setInterval(updateLink, 5000); setInterval(showLink, 1000); });
</script>
<?php include 'footer.php'; ?>
