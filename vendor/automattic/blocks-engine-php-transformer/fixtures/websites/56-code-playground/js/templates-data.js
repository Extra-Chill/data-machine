/* =========================================================
   FORKBENCH — Starter templates
   Each pen is { id, title, blurb, tags, html, css, js }
   Shared by templates.html and the editor (loaded via #t=<id>).
   ========================================================= */
(function (global) {
  'use strict';

  var T = [
    /* ------------------------------------------------ */
    {
      id: 'starter',
      title: 'Hello, Forkbench',
      blurb: 'The default pen. A gradient card, a counter button, and a console log to show the captured console working.',
      tags: ['starter', 'dom'],
      html:
'<main class="card">\n' +
'  <h1>Hello, <span>Forkbench</span></h1>\n' +
'  <p>Edit the HTML, CSS, or JS on the left and watch this update live.</p>\n' +
'  <button id="tap">You\'ve tapped 0 times</button>\n' +
'</main>',
      css:
'body{\n' +
'  margin:0; min-height:100vh; display:grid; place-items:center;\n' +
'  font-family:system-ui,sans-serif;\n' +
'  background:radial-gradient(120% 120% at 0% 0%, #1d2b53, #0b0e1a);\n' +
'}\n' +
'.card{\n' +
'  background:rgba(255,255,255,.04);\n' +
'  border:1px solid rgba(255,255,255,.12);\n' +
'  padding:2.4rem 2.6rem; border-radius:18px; max-width:30rem;\n' +
'  color:#e9ecf5; box-shadow:0 30px 80px -30px #000;\n' +
'}\n' +
'h1{margin:0 0 .4rem; font-size:1.8rem;}\n' +
'h1 span{color:#6cf2c8;}\n' +
'p{color:#9fa6c0; line-height:1.6;}\n' +
'button{\n' +
'  margin-top:1rem; padding:.7rem 1.2rem; border:0; border-radius:10px;\n' +
'  background:#6cf2c8; color:#06241c; font-weight:700; cursor:pointer;\n' +
'  transition:transform .12s;\n' +
'}\n' +
'button:active{transform:scale(.96);}',
      js:
'const btn = document.getElementById("tap");\n' +
'let count = 0;\n' +
'btn.addEventListener("click", () => {\n' +
'  count++;\n' +
'  btn.textContent = `You\'ve tapped ${count} time${count === 1 ? "" : "s"}`;\n' +
'  console.log("tap", count);\n' +
'});\n' +
'console.log("Pen booted. Try editing me!");'
    },

    /* ------------------------------------------------ */
    {
      id: 'aurora',
      title: 'CSS Aurora',
      blurb: 'A pure-CSS animated aurora background using layered conic & radial gradients with blur. No JavaScript at all.',
      tags: ['css', 'animation', 'no-js'],
      html:
'<div class="sky">\n' +
'  <span class="band b1"></span>\n' +
'  <span class="band b2"></span>\n' +
'  <span class="band b3"></span>\n' +
'  <h1>aurora.css</h1>\n' +
'</div>',
      css:
'*{box-sizing:border-box;}\n' +
'body{margin:0;}\n' +
'.sky{\n' +
'  position:relative; height:100vh; overflow:hidden;\n' +
'  background:#04060f; display:grid; place-items:center;\n' +
'}\n' +
'.band{\n' +
'  position:absolute; inset:-30%; filter:blur(60px); opacity:.65;\n' +
'  mix-blend-mode:screen; border-radius:50%;\n' +
'  animation:drift 14s ease-in-out infinite alternate;\n' +
'}\n' +
'.b1{background:conic-gradient(from 0deg,#0ff6,#08f0,#6f6a,#0ff6); animation-duration:13s;}\n' +
'.b2{background:radial-gradient(circle at 30% 40%,#f0fb 0%,transparent 60%); animation-duration:17s;}\n' +
'.b3{background:radial-gradient(circle at 70% 60%,#6cf2c8aa 0%,transparent 55%); animation-duration:21s;}\n' +
'h1{\n' +
'  position:relative; color:#dff; font-family:ui-monospace,monospace;\n' +
'  letter-spacing:.4em; font-weight:400; text-shadow:0 0 24px #6cf2c8;\n' +
'}\n' +
'@keyframes drift{\n' +
'  from{transform:translate(-8%,-6%) rotate(0deg) scale(1);}\n' +
'  to{transform:translate(8%,6%) rotate(40deg) scale(1.25);}\n' +
'}\n' +
'@media (prefers-reduced-motion:reduce){.band{animation:none;}}',
      js: '// Pure CSS — no JavaScript needed for this one.'
    },

    /* ------------------------------------------------ */
    {
      id: 'canvas-orbits',
      title: 'Canvas Orbits',
      blurb: 'A canvas sketch: particles orbiting a center with trailing fade. Resizes with the preview and respects reduced motion.',
      tags: ['canvas', 'animation', 'math'],
      html: '<canvas id="c"></canvas>',
      css:
'html,body{margin:0; height:100%; background:#05060c;}\n' +
'canvas{display:block; width:100%; height:100vh;}',
      js:
'const c = document.getElementById("c");\n' +
'const x = c.getContext("2d");\n' +
'let W, H;\n' +
'function size(){ W = c.width = innerWidth; H = c.height = innerHeight; }\n' +
'size(); addEventListener("resize", size);\n' +
'\n' +
'const N = 140;\n' +
'const dots = Array.from({length:N}, (_, i) => ({\n' +
'  a: Math.random() * Math.PI * 2,\n' +
'  r: 30 + Math.random() * 260,\n' +
'  s: 0.002 + Math.random() * 0.012,\n' +
'  hue: (i / N) * 360\n' +
'}));\n' +
'\n' +
'const reduce = matchMedia("(prefers-reduced-motion: reduce)").matches;\n' +
'\n' +
'function frame(){\n' +
'  x.fillStyle = "rgba(5,6,12,0.16)";\n' +
'  x.fillRect(0, 0, W, H);\n' +
'  for (const d of dots){\n' +
'    if (!reduce) d.a += d.s;\n' +
'    const px = W/2 + Math.cos(d.a) * d.r;\n' +
'    const py = H/2 + Math.sin(d.a) * d.r * 0.6;\n' +
'    x.beginPath();\n' +
'    x.arc(px, py, 2.2, 0, Math.PI * 2);\n' +
'    x.fillStyle = `hsl(${d.hue}, 90%, 65%)`;\n' +
'    x.fill();\n' +
'  }\n' +
'  requestAnimationFrame(frame);\n' +
'}\n' +
'console.log(`Spinning up ${N} orbiting particles`);\n' +
'frame();'
    },

    /* ------------------------------------------------ */
    {
      id: 'todo',
      title: 'Tiny To-Do App',
      blurb: 'A small JavaScript app: add, complete, and delete tasks. Demonstrates DOM building, event delegation, and state.',
      tags: ['app', 'dom', 'state'],
      html:
'<section class="app">\n' +
'  <h1>Today</h1>\n' +
'  <form id="add">\n' +
'    <input id="task" placeholder="What needs doing?" autocomplete="off">\n' +
'    <button>Add</button>\n' +
'  </form>\n' +
'  <ul id="list"></ul>\n' +
'  <p id="count"></p>\n' +
'</section>',
      css:
'body{margin:0; font-family:system-ui,sans-serif; background:#0e1018; color:#e8eaf2;}\n' +
'.app{max-width:26rem; margin:3rem auto; padding:0 1rem;}\n' +
'h1{font-size:1.6rem; margin:0 0 1rem;}\n' +
'form{display:flex; gap:.5rem; margin-bottom:1.2rem;}\n' +
'input{flex:1; padding:.65rem .8rem; border-radius:9px; border:1px solid #2a2e40;\n' +
'  background:#161a26; color:inherit; font:inherit;}\n' +
'button{padding:.65rem 1rem; border:0; border-radius:9px; background:#6cf2c8;\n' +
'  color:#06241c; font-weight:700; cursor:pointer;}\n' +
'ul{list-style:none; margin:0; padding:0; display:grid; gap:.5rem;}\n' +
'li{display:flex; align-items:center; gap:.7rem; padding:.6rem .8rem;\n' +
'  background:#161a26; border-radius:9px; border:1px solid #232838;}\n' +
'li.done span{text-decoration:line-through; opacity:.45;}\n' +
'li span{flex:1; cursor:pointer;}\n' +
'.del{background:none; color:#ff7a90; font-size:1.1rem; padding:0 .2rem;}\n' +
'#count{color:#8a90a8; font-size:.85rem;}',
      js:
'const list = document.getElementById("list");\n' +
'const form = document.getElementById("add");\n' +
'const input = document.getElementById("task");\n' +
'const count = document.getElementById("count");\n' +
'let tasks = [\n' +
'  { text: "Try editing this pen", done: true },\n' +
'  { text: "Add a task below", done: false }\n' +
'];\n' +
'\n' +
'function render(){\n' +
'  list.innerHTML = "";\n' +
'  tasks.forEach((t, i) => {\n' +
'    const li = document.createElement("li");\n' +
'    if (t.done) li.className = "done";\n' +
'    li.innerHTML = `<input type="checkbox" ${t.done ? "checked" : ""} data-i="${i}">` +\n' +
'      `<span data-i="${i}">${t.text}</span>` +\n' +
'      `<button class="del" data-del="${i}" aria-label="Delete">×</button>`;\n' +
'    list.appendChild(li);\n' +
'  });\n' +
'  const left = tasks.filter(t => !t.done).length;\n' +
'  count.textContent = `${left} of ${tasks.length} remaining`;\n' +
'}\n' +
'\n' +
'form.addEventListener("submit", e => {\n' +
'  e.preventDefault();\n' +
'  const text = input.value.trim();\n' +
'  if (!text) return;\n' +
'  tasks.push({ text, done: false });\n' +
'  input.value = "";\n' +
'  console.log("added:", text);\n' +
'  render();\n' +
'});\n' +
'\n' +
'list.addEventListener("click", e => {\n' +
'  const del = e.target.dataset.del;\n' +
'  const toggle = e.target.dataset.i;\n' +
'  if (del !== undefined) { tasks.splice(+del, 1); render(); }\n' +
'  else if (toggle !== undefined) { tasks[+toggle].done = !tasks[+toggle].done; render(); }\n' +
'});\n' +
'\n' +
'render();'
    },

    /* ------------------------------------------------ */
    {
      id: 'clock',
      title: 'SVG Analog Clock',
      blurb: 'A live analog clock drawn with inline SVG and updated every second. Shows working with the DOM and dates.',
      tags: ['svg', 'dom', 'time'],
      html:
'<div class="stage">\n' +
'  <svg viewBox="-50 -50 100 100" id="clock">\n' +
'    <circle r="48" class="face"/>\n' +
'    <g id="ticks"></g>\n' +
'    <line id="hr" class="hand hr" y2="-26"/>\n' +
'    <line id="mn" class="hand mn" y2="-38"/>\n' +
'    <line id="sc" class="hand sc" y2="-42"/>\n' +
'    <circle r="2.4" class="pin"/>\n' +
'  </svg>\n' +
'  <p id="digital">--:--:--</p>\n' +
'</div>',
      css:
'body{margin:0; height:100vh; display:grid; place-items:center; background:#0b0d16;}\n' +
'.stage{text-align:center;}\n' +
'svg{width:min(60vmin,320px);}\n' +
'.face{fill:#11141f; stroke:#2b3147; stroke-width:1.5;}\n' +
'.tick{stroke:#4a5273; stroke-width:1;}\n' +
'.tick.major{stroke:#8a93b8; stroke-width:1.8;}\n' +
'.hand{stroke-linecap:round; transform-origin:0 0;}\n' +
'.hr{stroke:#e8eaf2; stroke-width:3.2;}\n' +
'.mn{stroke:#cdd2e6; stroke-width:2.2;}\n' +
'.sc{stroke:#6cf2c8; stroke-width:1;}\n' +
'.pin{fill:#6cf2c8;}\n' +
'#digital{color:#8a93b8; font-family:ui-monospace,monospace; letter-spacing:.2em; margin-top:1rem;}',
      js:
'const ticks = document.getElementById("ticks");\n' +
'for (let i = 0; i < 60; i++){\n' +
'  const major = i % 5 === 0;\n' +
'  const l = document.createElementNS("http://www.w3.org/2000/svg", "line");\n' +
'  l.setAttribute("class", major ? "tick major" : "tick");\n' +
'  l.setAttribute("y1", "-48");\n' +
'  l.setAttribute("y2", major ? "-42" : "-45");\n' +
'  l.setAttribute("transform", `rotate(${i * 6})`);\n' +
'  ticks.appendChild(l);\n' +
'}\n' +
'\n' +
'const hr = document.getElementById("hr");\n' +
'const mn = document.getElementById("mn");\n' +
'const sc = document.getElementById("sc");\n' +
'const dig = document.getElementById("digital");\n' +
'const pad = n => String(n).padStart(2, "0");\n' +
'\n' +
'function tick(){\n' +
'  const d = new Date();\n' +
'  const s = d.getSeconds(), m = d.getMinutes(), h = d.getHours();\n' +
'  sc.setAttribute("transform", `rotate(${s * 6})`);\n' +
'  mn.setAttribute("transform", `rotate(${m * 6 + s * 0.1})`);\n' +
'  hr.setAttribute("transform", `rotate(${(h % 12) * 30 + m * 0.5})`);\n' +
'  dig.textContent = `${pad(h)}:${pad(m)}:${pad(s)}`;\n' +
'}\n' +
'tick();\n' +
'setInterval(tick, 1000);\n' +
'console.log("Clock running");'
    },

    /* ------------------------------------------------ */
    {
      id: 'errors',
      title: 'Console Playground',
      blurb: 'A demo that intentionally logs, warns, and throws so you can see how Forkbench captures every console method and runtime errors.',
      tags: ['console', 'debug'],
      html:
'<div class="panel">\n' +
'  <h1>Open the console ↓</h1>\n' +
'  <p>This pen pushes a mix of logs, warnings, and an error so you can watch the captured console at work.</p>\n' +
'  <button id="boom">Throw an error</button>\n' +
'</div>',
      css:
'body{margin:0; min-height:100vh; display:grid; place-items:center;\n' +
'  font-family:system-ui,sans-serif; background:#0c0e18; color:#e8eaf2;}\n' +
'.panel{max-width:24rem; text-align:center; padding:1rem;}\n' +
'button{margin-top:1rem; padding:.7rem 1.1rem; border:0; border-radius:9px;\n' +
'  background:#ff7a90; color:#330; font-weight:700; cursor:pointer;}',
      js:
'console.log("plain log", { user: "you", session: 42 });\n' +
'console.log("multiple", "args", [1, 2, 3], true, null);\n' +
'console.warn("this is a warning — fps dipped below 30");\n' +
'console.error("this is an error message (but not a thrown one)");\n' +
'console.log("%cStyled logs become plain text here", "color:hotpink");\n' +
'\n' +
'document.getElementById("boom").addEventListener("click", () => {\n' +
'  // Uncaught errors are captured too:\n' +
'  const data = JSON.parse("{ not valid json }");\n' +
'  console.log(data);\n' +
'});'
    }
  ];

  global.FORKBENCH_TEMPLATES = T;
  global.FORKBENCH_DEFAULT = 'starter';
})(window);
