<?php
include 'functions.php';
$page = 'aprs'; include 'header.php';
?>
<h2>APRS traffic</h2>
<p class="aprs-note">APRS heard on RF and received from APRS-IS by m17-gateway's APRS gate, with what it did with each, newest first.</p>
<div class="aprs-filters">
  <select id="aprs-dir" class="input">
    <option value="">Everything</option>
    <option value="RF">Heard on RF</option>
    <option value="APRS-IS">Received from APRS-IS</option>
    <option value="RF TX">Sent to RF</option>
    <option value="APRS-IS TX">Beacons to APRS-IS</option>
  </select>
  <label><input type="checkbox" id="aprs-passed"> Only what was gated or sent</label>
</div>
<div class="table-card">
<table id="aprs">
<thead><tr><th>Time</th><th>Direction</th><th>Station</th><th>Packet</th><th>Result</th></tr></thead>
<tbody></tbody>
</table>
<p id="aprs-empty" class="aprs-note" hidden>Nothing logged yet. Set <code>Log</code> under <code>[APRS]</code> in the gateway configuration, and link it as <code>files/aprs.log</code>.</p>
</div>
<style>
.aprs-note { color:var(--text-muted); }
.aprs-filters { display:flex; flex-wrap:wrap; gap:16px; align-items:center; margin:12px 0; }
.aprs-filters .input { width:auto; }
#aprs td.aprs-line { font-family:ui-monospace, Menlo, Consolas, monospace; font-size:0.85rem; word-break:break-all; }
#aprs td.aprs-good { color:#22c55e; }
#aprs td.aprs-warn { color:#f97316; }
#aprs td.aprs-skip { color:var(--text-muted); }
</style>
<script>
// Every string here comes from RF or the internet: always escape it.
function aprsEsc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function aprsStation(e) {
  const src = e.line.split('>')[0];
  const base = src.replace(/[^A-Za-z0-9].*$/, '');
  let s = base ? `<a href="https://aprs.fi/info/a/${encodeURIComponent(src)}" target="_blank" rel="noopener">${aprsEsc(src)}</a>` : aprsEsc(src);
  if (e.m17 && e.m17.trim() !== base) s += `<br><small>M17 ${aprsEsc(e.m17.trim())}</small>`;
  return s;
}
function aprsResultClass(r) {
  if (/^(gated|queued|sent|beacon)/.test(r)) return 'aprs-good';
  if (/^would/.test(r)) return 'aprs-warn';
  return 'aprs-skip';
}
function updateAPRS() {
  $.getJSON('get_aprs.php?n=200', data => {
    if (!Array.isArray(data)) return;
    const dir = $('#aprs-dir').val();
    const passed = $('#aprs-passed').prop('checked');
    let rows = '';
    data.forEach(e => {
      if (dir && e.dir !== dir) return;
      const cls = aprsResultClass(e.result);
      if (passed && cls === 'aprs-skip') return;
      const title = e.out ? ` title="${aprsEsc('To APRS-IS: ' + e.out)}"` : '';
      const result = e.result + (e.class ? ` (${e.class})` : '');
      rows += `<tr>
        <td>${aprsEsc(e.time)}</td>
        <td>${aprsEsc(e.dir)}</td>
        <td>${aprsStation(e)}</td>
        <td class="aprs-line">${aprsEsc(e.line)}</td>
        <td class="${cls}"${title}>${aprsEsc(result)}</td>
      </tr>`;
    });
    $('#aprs tbody').html(rows);
    $('#aprs-empty').prop('hidden', data.length > 0);
  });
}
$(function(){
  $('#aprs-dir, #aprs-passed').on('change', updateAPRS);
  updateAPRS();
  setInterval(updateAPRS, 3000);
});
</script>
<?php include 'footer.php'; ?>
