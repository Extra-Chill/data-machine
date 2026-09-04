/* =========================================================
   HELIX GRID — Alerts & Incident history (alerts.html)
   Live incident table + a timeline. New incidents prepend.
   Includes some seeded historical incidents for context.
   ========================================================= */
(function () {
  'use strict';
  const E = window.HelixEngine, C = window.HelixCharts;
  if (!E || !document.getElementById('alertsPage')) return;

  /* seed a few historical (resolved) incidents so the table isn't empty */
  const HISTORY = [
    { id: 'INC-4791', kind: 'Intertie trip', svc: 'dispatch-engine', region: 'ERCOT Texas', sev: 'crit', status: 'resolved', ago: 9420, dur: 22 * 60, mttr: 22 },
    { id: 'INC-4788', kind: 'Forecast skew', svc: 'forecast-ml', region: 'Pacific NW', sev: 'warn', status: 'resolved', ago: 18300, dur: 41 * 60, mttr: 41 },
    { id: 'INC-4779', kind: 'Latency saturation', svc: 'settlement-api', region: 'Midwest', sev: 'warn', status: 'resolved', ago: 26100, dur: 13 * 60, mttr: 13 },
    { id: 'INC-4771', kind: 'Under-frequency event', svc: 'scada-gateway', region: 'California', sev: 'crit', status: 'resolved', ago: 41880, dur: 8 * 60, mttr: 8 },
    { id: 'INC-4766', kind: 'Cert expiry', svc: 'auth-broker', region: 'California', sev: 'info', status: 'resolved', ago: 60120, dur: 3 * 60, mttr: 3 },
    { id: 'INC-4758', kind: 'Telemetry gap', svc: 'telemetry-bus', region: 'New England', sev: 'warn', status: 'acknowledged', ago: 75600, dur: 0, mttr: null },
  ];

  const tbody = document.getElementById('incTable');
  const tl = document.getElementById('timeline');
  const now = E.state.t;

  function fmtAgo(sec) {
    if (sec < 60) return sec + 's ago';
    if (sec < 3600) return Math.round(sec / 60) + 'm ago';
    if (sec < 86400) return (sec / 3600).toFixed(1) + 'h ago';
    return (sec / 86400).toFixed(1) + 'd ago';
  }
  function fmtClock(t) { const d = new Date(t); return [d.getUTCHours(), d.getUTCMinutes()].map(n => String(n).padStart(2, '0')).join(':'); }

  function rowFor(inc) {
    const tr = document.createElement('tr');
    tr.dataset.id = inc.id;
    const dur = inc.status === 'active' ? 'ongoing' : (inc.mttr != null ? inc.mttr + 'm' : '—');
    tr.innerHTML = `
      <td class="mono">${inc.id}</td>
      <td><span class="sev-tag ${inc.sev}"><span class="d"></span>${inc.sev === 'crit' ? 'SEV-1' : inc.sev === 'warn' ? 'SEV-2' : 'SEV-3'}</span></td>
      <td>${inc.kind}</td>
      <td class="mono" style="color:var(--accent-2)">${inc.svc}</td>
      <td>${inc.region}</td>
      <td><span class="status-tag ${inc.status === 'active' ? 'active' : inc.status === 'acknowledged' ? 'ack' : 'resolved'}">${inc.status}</span></td>
      <td class="mono">${dur}</td>
      <td class="mono" style="color:var(--ink-faint)">${inc.agoText}</td>`;
    return tr;
  }

  // render history
  HISTORY.forEach(h => {
    h.agoText = fmtAgo(h.ago);
    tbody.appendChild(rowFor(h));
  });

  // timeline of the most recent 6 events
  function renderTimeline() {
    tl.innerHTML = '';
    const live = E.state.incidents.slice(0, 4).map(i => ({
      id: i.id, kind: i.kind, svc: i.service.name, region: i.region.name,
      sev: i.severity, status: i.status, t: i.started,
    }));
    const hist = HISTORY.slice(0, 4).map(h => ({
      id: h.id, kind: h.kind, svc: h.svc, region: h.region, sev: h.sev, status: h.status, t: now - h.ago * 1000,
    }));
    const all = [...live, ...hist].sort((a, b) => b.t - a.t).slice(0, 7);
    all.forEach(ev => {
      const item = document.createElement('div');
      item.className = 'tl-item ' + (ev.status === 'resolved' ? 'ok' : ev.sev === 'crit' ? 'crit' : '');
      item.innerHTML = `<div class="tl-time">${fmtClock(ev.t)} UTC · ${ev.id}</div>
        <div class="tl-title">${ev.kind} — ${ev.svc}</div>
        <div class="tl-desc">${ev.region} control area · ${ev.status === 'active' ? 'mitigation in progress' : ev.status === 'acknowledged' ? 'acknowledged, monitoring' : 'auto-resolved'}</div>`;
      tl.appendChild(item);
    });
  }

  // when a new incident starts, prepend a live row
  const liveRows = {};
  function syncLive() {
    E.state.incidents.forEach(inc => {
      const data = {
        id: inc.id, kind: inc.kind, svc: inc.service.name, region: inc.region.name,
        sev: inc.severity, status: inc.status,
        mttr: inc.status === 'resolved' ? Math.max(1, Math.round(inc.age / 4)) : null,
        agoText: inc.status === 'active' ? 'now' : fmtAgo(Math.round((E.state.t - (inc.resolved || inc.started)) / 1000)),
      };
      if (liveRows[inc.id]) {
        liveRows[inc.id].replaceWith(liveRows[inc.id] = rowFor(data));
      } else {
        const tr = rowFor(data);
        liveRows[inc.id] = tr;
        tbody.insertBefore(tr, tbody.firstChild);
      }
    });
  }

  // KPI counters
  function counters() {
    const active = E.state.incidents.filter(i => i.status === 'active').length;
    const total24 = E.state.incidents.length + HISTORY.length;
    document.getElementById('cActive').textContent = active;
    document.getElementById('cTotal').textContent = total24;
    const mttrs = HISTORY.filter(h => h.mttr != null).map(h => h.mttr);
    document.getElementById('cMttr').textContent = Math.round(mttrs.reduce((a, b) => a + b, 0) / mttrs.length) + 'm';
    const sev1 = E.state.incidents.filter(i => i.severity === 'crit').length + HISTORY.filter(h => h.sev === 'crit').length;
    document.getElementById('cSev1').textContent = sev1;
  }

  E.on('tick', () => { syncLive(); counters(); });
  E.on('incident:start', () => { syncLive(); renderTimeline(); counters(); });
  E.on('incident:end', () => { syncLive(); renderTimeline(); counters(); });

  renderTimeline(); syncLive(); counters();
})();
