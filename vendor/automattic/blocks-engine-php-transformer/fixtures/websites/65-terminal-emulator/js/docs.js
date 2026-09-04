/* =========================================================
   Tideglass — docs.html renderer
   Builds the command reference straight from TG.MAN so the
   man pages and the docs page never drift apart.
   ========================================================= */
'use strict';

function escd(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

document.addEventListener('DOMContentLoaded', function () {
  const man = (window.TG && TG.MAN) || {};
  const order = [
    'help','ls','cd','pwd','cat','echo','tree','find','stat','du',
    'mkdir','touch','rm','mv','cp',
    'head','tail','wc','grep','sort','uniq','rev','tac',
    'history','env','export','alias','which','man','clear','whoami','date','uname',
    'true','false','yes',
    'neofetch','cowsay','fortune','tour','sl','matrix'
  ];
  const names = order.filter(n => man[n]);

  // index
  const idx = document.getElementById('cmd-index');
  idx.innerHTML = names.map(n => `<a href="#cmd-${n}">${n}</a>`).join('');

  // cards
  const list = document.getElementById('man-list');
  list.innerHTML = names.map(n => {
    const m = man[n];
    let html = `<article class="man-card" id="cmd-${n}">`;
    html += `<h2>${n}</h2>`;
    html += `<p class="summary">${escd(m.summary)}</p>`;
    html += `<div class="syn">${escd(m.synopsis)}</div>`;
    html += `<div class="desc">${escd(m.description)}</div>`;
    if (m.examples) {
      html += `<div class="ex-label">Examples</div>`;
      html += `<div class="ex">${escd(m.examples)}</div>`;
    }
    html += `</article>`;
    return html;
  }).join('');

  document.getElementById('cmd-count').textContent = names.length;
});
