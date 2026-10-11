<?php
include 'functions.php';
$page = 'aprs'; include 'header.php';
?>
<h2>APRS</h2>
<p class="aprs-note">m17-gateway's APRS gate: APRS carried in M17 packet mode, between RF and APRS-IS. <a href="config_aprs.php">APRS settings</a></p>

<div id="aprs-warning" class="aprs-warning" hidden></div>

<div class="cards" id="aprs-status" hidden>
  <section class="card"><h2>APRS-IS</h2>
    <p>Connection <strong id="st-conn"></strong></p>
    <p>Login <strong id="st-login"></strong></p>
    <p>Server keepalive <strong id="st-keepalive"></strong></p>
    <p>Gating <strong id="st-gating"></strong></p>
    <p>Beacon <strong id="st-beacon"></strong></p>
  </section>
  <section class="card"><h2>Activity</h2>
    <p>Heard on RF <strong id="st-rf"></strong></p>
    <p>From APRS-IS <strong id="st-is"></strong></p>
    <p>Sent to RF <strong id="st-tx"></strong></p>
    <p>Waiting to send <strong id="st-queue"></strong></p>
    <p>Last 15 min to RF <strong id="st-recent"></strong></p>
  </section>
</div>
<p id="aprs-unavailable" class="aprs-note" hidden>No status from the gateway. Set <code>Status</code> under <code>[APRS]</code> in the gateway configuration, and link it as <code>files/aprs-status.json</code>.</p>

<div class="cards" id="aprs-lists" hidden>
  <section class="card"><h2>Heard on RF</h2>
    <table class="aprs-small" id="st-heard"><thead><tr><th>APRS</th><th>M17</th><th>CAN</th><th>Last heard</th></tr></thead><tbody></tbody></table>
  </section>
  <section class="card"><h2>Watch rules</h2>
    <table class="aprs-small" id="st-watches"><thead><tr><th>Rule</th><th>Then</th><th>Until</th><th>Last 15 min</th></tr></thead><tbody></tbody></table>
    <p class="aprs-note">Filter <code id="st-filter"></code></p>
  </section>
</div>

<h2>Traffic</h2>
<div class="aprs-filters">
  <select id="aprs-dir" class="input">
    <option value="">Everything</option>
    <option value="RF">Heard on RF</option>
    <option value="APRS-IS">Received from APRS-IS</option>
    <option value="RF TX">Sent to RF</option>
    <option value="APRS-IS TX">Beacons to APRS-IS</option>
  </select>
  <label><input type="checkbox" id="aprs-passed"> Only what was gated, sent or watched</label>
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
#aprs td.aprs-line, code { font-family:ui-monospace, Menlo, Consolas, monospace; font-size:0.85rem; word-break:break-all; }
#aprs td.aprs-good, .aprs-good { color:#22c55e; }
#aprs td.aprs-warn, .aprs-warn { color:#f97316; }
#aprs td.aprs-watch { color:#60a5fa; }
#aprs td.aprs-skip, .aprs-skip { color:var(--text-muted); }
.aprs-bad { color:#ef4444; }
.aprs-warning { border:1px solid #f97316; border-radius:12px; padding:10px 14px; margin:12px 0; }
.aprs-warning p { margin:4px 0; }
table.aprs-small { width:100%; font-size:0.9rem; }
table.aprs-small td, table.aprs-small th { padding:4px 6px; }
</style>
<script>
// Every string here comes from RF or the internet: always escape it.
function aprsEsc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function aprsFor(sec) {
  if (sec < 60) return sec + ' s';
  if (sec < 3600) return Math.floor(sec / 60) + ' min';
  return Math.floor(sec / 3600) + ' h ' + Math.floor(sec % 3600 / 60) + ' min';
}
function aprsAgo(sec) {
  return (sec === undefined || sec === null) ? '–' : aprsFor(sec) + ' ago';
}
function aprsCall(call) {
  const base = String(call).replace(/[^A-Za-z0-9].*$/, '');
  return base ? `<a href="https://aprs.fi/info/a/${encodeURIComponent(call)}" target="_blank" rel="noopener">${aprsEsc(call)}</a>` : aprsEsc(call);
}
function aprsStation(e) {
  const src = e.line.split('>')[0];
  const base = src.replace(/[^A-Za-z0-9].*$/, '');
  let s = aprsCall(src);
  if (e.m17 && e.m17.trim() !== base) s += `<br><small>M17 ${aprsEsc(e.m17.trim())}</small>`;
  return s;
}
function aprsResultClass(r) {
  if (/^(gated|queued|sent|beacon|relay)/.test(r)) return 'aprs-good';
  if (/^would/.test(r)) return 'aprs-warn';
  if (/^watched/.test(r)) return 'aprs-watch';
  return 'aprs-skip';
}
function aprsSet(id, html, cls) {
  const el = document.getElementById(id);
  el.innerHTML = html;
  el.className = cls || '';
}
function updateStatus() {
  $.getJSON('get_aprs_status.php', s => {
    const ok = s && s.available;
    $('#aprs-status, #aprs-lists').prop('hidden', !ok);
    $('#aprs-unavailable').prop('hidden', !!ok);
    if (!ok) {
      $('#aprs-warning').prop('hidden', true);
      return;
    }
    const c = s.counts || {};
    if (s.stale) {
      aprsSet('st-conn', 'no status for ' + aprsFor(s.age) + ': is the gateway running?', 'aprs-bad');
    } else if (!s.enabled) {
      aprsSet('st-conn', 'APRS off', 'aprs-skip');
    } else if (s.connected) {
      aprsSet('st-conn', 'connected to ' + aprsEsc(s.connectedTo || s.server) + ' for ' + aprsFor(s.connectedAtAge || 0), 'aprs-good');
    } else {
      aprsSet('st-conn', 'not connected to ' + aprsEsc(s.server) + ', retrying', 'aprs-bad');
    }
    aprsSet('st-login', aprsEsc(s.callsign) + (s.verified ? ', verified' : ', receive only (passcode -1)'), s.verified ? 'aprs-good' : 'aprs-warn');
    aprsSet('st-keepalive', s.connected ? aprsAgo(s.lastKeepaliveAge) : '–');
    const on = (v, label) => `<span class="${v ? 'aprs-good' : 'aprs-skip'}">${label} ${v ? 'on' : 'off'}</span>`;
    aprsSet('st-gating', [on(s.rfToIS, 'RF→IS'), on(s.isToRF, 'IS→RF'), on(s.relay, 'relay')].join(' · '));
    aprsSet('st-beacon', s.lastBeacon ? aprsAgo(s.lastBeaconAge) : (s.hasPosition ? 'not yet' : 'off: no position set'));
    aprsSet('st-rf', `${c.rfHeard || 0} · gated ${c.rfGated || 0} · relayed ${c.relayQueued || 0}`);
    aprsSet('st-is', `${c.isReceived || 0} · queued ${c.isQueued || 0} · watched ${c.isWatched || 0}`);
    aprsSet('st-tx', `${c.rfSent || 0} · dropped ${c.rfDropped || 0}`);
    const q = s.queue || {};
    aprsSet('st-queue', `${q.ack || 0} acks · ${q.message || 0} messages · ${q.other || 0} other`);
    let heard = '';
    (s.heard || []).forEach(h => {
      heard += `<tr><td>${aprsCall(h.call)}</td><td>${aprsEsc(h.m17)}</td><td>${aprsEsc(h.can)}</td><td>${aprsAgo(h.age)}</td></tr>`;
    });
    $('#st-heard tbody').html(heard || '<tr><td colspan="4" class="aprs-skip">Nobody yet</td></tr>');
    const recent = s.recent || {};
    aprsSet('st-recent', `sent ${recent.sent || 0} · dropped ${(recent.dropped || 0) + (recent.droppedAddressed || 0)} · replaced by newer ${recent.replaced || 0}`,
            (recent.dropped || recent.droppedAddressed) ? 'aprs-warn' : '');
    let rules = '';
    const warnings = [];
    (s.watches || []).forEach(w => {
      const what = w.kind === 'call' ? 'from or to ' + aprsCall(w.call)
                 : w.kind === 'area' ? `within ${aprsEsc(w.km)} km of ${aprsEsc(w.lat)}, ${aprsEsc(w.lon)}`
                 : `within ${aprsEsc(w.km)} km of the hotspot`;
      const until = w.until ? (w.active ? new Date(w.until).toLocaleString() : 'ended') : 'no end';
      const r = w.recent || {};
      const last = w.toRF ? `${r.matched || 0} matched · ${r.sent || 0} sent · ${r.dropped || 0} dropped` : `${r.matched || 0} shown`;
      rules += `<tr class="${w.active ? '' : 'aprs-skip'}"><td>${what}</td><td>${w.toRF ? 'send to RF' : 'show here'}</td><td>${aprsEsc(until)}</td>` +
               `<td class="${r.dropped ? 'aprs-warn' : ''}">${last}</td></tr>`;
      if (w.toRF && r.dropped) {
        warnings.push(`"${what}" brought ${r.matched || 0} packets in the last ${recent.minutes || 15} minutes; ${r.sent || 0} went out and ${r.dropped} were dropped, more than the channel's budget allows. ` +
                      `<a href="config_aprs.php">Narrow it, or switch it to show here</a>.`);
      }
    });
    $('#st-watches tbody').html(rules || '<tr><td colspan="4" class="aprs-skip">None. <a href="config_aprs.php">Add one</a></td></tr>');
    if (recent.droppedAddressed) {
      warnings.unshift(`<span class="aprs-bad">${recent.droppedAddressed} acks or messages for stations here were dropped in the last ${recent.minutes || 15} minutes: the channel was too busy for them.</span>`);
    }
    const ruled = (s.watches || []).reduce((n, w) => n + ((w.toRF && w.recent && w.recent.dropped) || 0), 0);
    if ((recent.dropped || 0) > ruled) {
      warnings.push(`${recent.dropped - ruled} other packets for RF were dropped in the last ${recent.minutes || 15} minutes.`);
    }
    $('#aprs-warning').html(warnings.map(w => `<p>${w}</p>`).join('')).prop('hidden', warnings.length === 0);
    $('#st-filter').text(s.filter || '');
  });
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
  updateStatus();
  updateAPRS();
  setInterval(updateStatus, 5000);
  setInterval(updateAPRS, 3000);
});
</script>
<?php include 'footer.php'; ?>
