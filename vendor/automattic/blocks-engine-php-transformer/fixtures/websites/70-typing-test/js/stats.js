/* ============================================================
   Keystroke — stats page: history table, bests per mode,
   WPM-over-time chart, and the problem-key heatmap.
   ============================================================ */
(function (global) {
  'use strict';

  var Store = global.KeystrokeStore;

  function $(s) { return document.querySelector(s); }
  function getCss(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim() || '#fff'; }

  function fmtDate(ms) {
    var d = new Date(ms);
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' ' +
      d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  }

  function render() {
    var history = Store.getHistory();
    var completed = history.slice();

    // summary tiles
    var runs = completed.length;
    var avg = runs ? Math.round(completed.reduce(function (a, r) { return a + r.wpm; }, 0) / runs) : 0;
    var best = runs ? Math.max.apply(null, completed.map(function (r) { return r.wpm; })) : 0;
    var avgAcc = runs ? Math.round(completed.reduce(function (a, r) { return a + r.acc; }, 0) / runs) : 0;
    $('#s-runs').textContent = runs;
    $('#s-avg').textContent = avg;
    $('#s-best').textContent = best;
    $('#s-acc').textContent = avgAcc + '%';

    // wpm over time chart (chronological)
    var canvas = $('#history-chart');
    var pts = completed.map(function (r, i) { return { x: i, y: r.wpm }; });
    var accPts = completed.map(function (r, i) { return { x: i, y: r.acc }; });
    if (pts.length) {
      global.KeystrokeChart.lineChart(canvas, [
        { points: pts, color: getCss('--accent'), width: 2.5, fill: true, dots: pts.length < 40 }
      ], { xLabel: 'run #' });
    } else {
      var ctx = canvas.getContext('2d');
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      $('#history-empty').hidden = false;
    }

    // bests per mode signature
    var bySig = {};
    completed.forEach(function (r) {
      if (!bySig[r.signature] || r.wpm > bySig[r.signature].wpm) bySig[r.signature] = r;
    });
    var bestBody = $('#bests-body');
    bestBody.innerHTML = '';
    var sigs = Object.keys(bySig).sort();
    if (!sigs.length) {
      bestBody.innerHTML = '<tr><td colspan="4" class="muted">No runs yet — go set some records.</td></tr>';
    } else {
      sigs.forEach(function (sig) {
        var r = bySig[sig];
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><code>' + sig + '</code></td><td><strong>' + r.wpm + '</strong></td><td>' + r.acc + '%</td><td>' + fmtDate(r.date) + '</td>';
        bestBody.appendChild(tr);
      });
    }

    // recent runs table
    var recent = completed.slice().reverse().slice(0, 20);
    var body = $('#history-body');
    body.innerHTML = '';
    if (!recent.length) {
      body.innerHTML = '<tr><td colspan="6" class="muted">Nothing here yet.</td></tr>';
    } else {
      recent.forEach(function (r) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td>' + fmtDate(r.date) + '</td><td><code>' + r.signature + '</code></td>' +
          '<td><strong>' + r.wpm + '</strong></td><td>' + r.raw + '</td><td>' + r.acc + '%</td><td>' + r.consistency + '%</td>';
        body.appendChild(tr);
      });
    }

    // problem keys
    renderProblemKeys();
  }

  function renderProblemKeys() {
    var ks = Store.getKeyStats();
    var rows = [];
    for (var k in ks) {
      if (ks[k].total >= 4) {
        rows.push({ key: k, rate: ks[k].errors / ks[k].total, total: ks[k].total, errors: ks[k].errors });
      }
    }
    rows.sort(function (a, b) { return b.rate - a.rate; });
    var mount = $('#problem-keys');
    mount.innerHTML = '';
    var worst = rows.filter(function (r) { return r.rate > 0; }).slice(0, 12);
    if (!worst.length) {
      mount.innerHTML = '<p class="muted">No trouble spots recorded yet. Type a few tests and your most-missed keys will surface here.</p>';
      return;
    }
    worst.forEach(function (r) {
      var chip = document.createElement('div');
      chip.className = 'pk-chip';
      chip.style.setProperty('--heat', Math.min(1, r.rate * 3).toFixed(2));
      chip.innerHTML = '<span class="pk-key">' + (r.key === ' ' ? '␣' : r.key) + '</span>' +
        '<span class="pk-rate">' + Math.round(r.rate * 100) + '%</span>' +
        '<span class="pk-detail">' + r.errors + '/' + r.total + '</span>';
      mount.appendChild(chip);
    });
  }

  function init() {
    render();
    $('#btn-clear-history').addEventListener('click', function () {
      if (confirm('Clear all saved results and key stats? This cannot be undone.')) {
        Store.clearHistory(); Store.clearKeyStats();
        $('#history-empty').hidden = false;
        render();
      }
    });
    window.addEventListener('resize', function () { render(); });
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})(window);
