/* =========================================================
   FRAGMENT FOUNDRY — Live shader editor (editor.html)
   A textarea bound to a ShaderToy. Edits hot-recompile on a
   short debounce; compile errors are parsed from the GLSL
   info log and shown inline with line numbers (translated to
   the body the user actually edits). Example shaders are
   loadable; the working source persists in localStorage.
   ========================================================= */
(function () {
  'use strict';
  var FF = window.FF;

  /* A few self-contained example bodies for the editor. The
     header (precision + u_time/u_resolution/u_mouse) is added
     automatically by the engine, so examples never repeat it. */
  var EXAMPLES = [
    {
      id: 'starter',
      name: 'Starter — UV gradient',
      source: [
        '// The simplest shader: paint each pixel from its',
        '// position. gl_FragCoord is in pixels; divide by',
        '// u_resolution to get 0..1 coordinates.',
        'void main() {',
        '  vec2 uv = gl_FragCoord.xy / u_resolution.xy;',
        '  vec3 col = vec3(uv.x, uv.y, 0.5 + 0.5*sin(u_time));',
        '  gl_FragColor = vec4(col, 1.0);',
        '}'
      ].join('\n')
    },
    {
      id: 'rings',
      name: 'Concentric rings',
      source: [
        'void main() {',
        '  vec2 uv = (gl_FragCoord.xy*2.0 - u_resolution.xy)/u_resolution.y;',
        '  vec2 m  = (u_mouse.xy*2.0 - u_resolution.xy)/u_resolution.y;',
        '  float d = length(uv - m);',
        '  float rings = sin(d*24.0 - u_time*4.0)*0.5 + 0.5;',
        '  vec3 col = mix(vec3(0.04,0.05,0.1), vec3(0.3,0.9,0.8), rings);',
        '  gl_FragColor = vec4(col, 1.0);',
        '}'
      ].join('\n')
    },
    {
      id: 'voronoi',
      name: 'Animated Voronoi',
      source: [
        'vec2 hash2(vec2 p){',
        '  p = vec2(dot(p,vec2(127.1,311.7)), dot(p,vec2(269.5,183.3)));',
        '  return fract(sin(p)*43758.5453);',
        '}',
        'void main(){',
        '  vec2 uv = gl_FragCoord.xy/u_resolution.y * 6.0;',
        '  vec2 g = floor(uv); vec2 f = fract(uv);',
        '  float md = 8.0;',
        '  for(int j=-1;j<=1;j++) for(int i=-1;i<=1;i++){',
        '    vec2 o = vec2(float(i),float(j));',
        '    vec2 p = hash2(g+o);',
        '    p = 0.5 + 0.5*sin(u_time + 6.2831*p);',
        '    float d = length(o + p - f);',
        '    md = min(md, d);',
        '  }',
        '  vec3 col = mix(vec3(0.05,0.07,0.12), vec3(0.9,0.5,0.3), md);',
        '  gl_FragColor = vec4(col, 1.0);',
        '}'
      ].join('\n')
    }
  ];

  document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('editor-canvas');
    if (!canvas) return;

    var ta        = document.getElementById('source');
    var lineCol   = document.getElementById('line-numbers');
    var errorsEl  = document.getElementById('compile-errors');
    var statusEl  = document.getElementById('compile-status');
    var fallback  = document.getElementById('webgl-fallback');
    var exSelect  = document.getElementById('example-select');
    var runBtn    = document.getElementById('btn-compile');
    var resetBtn  = document.getElementById('btn-revert');
    var playBtn   = document.getElementById('btn-eplay');

    if (!FF.supported()) {
      if (fallback) fallback.hidden = false;
      return;
    }

    var reduced = FF.prefersReducedMotion();

    var toy = new FF.ShaderToy({
      canvas: canvas,
      onError: function (msg, parsed) { showErrors(parsed, msg); }
    });

    // populate examples
    EXAMPLES.forEach(function (ex) {
      var opt = document.createElement('option');
      opt.value = ex.id; opt.textContent = ex.name;
      exSelect.appendChild(opt);
    });

    function getExample(id) {
      return EXAMPLES.filter(function (e) { return e.id === id; })[0] || EXAMPLES[0];
    }

    function setStatus(ok, text) {
      if (!statusEl) return;
      statusEl.textContent = text;
      statusEl.className = 'compile-status ' + (ok ? 'ok' : 'err');
    }

    function showErrors(parsed, raw) {
      if (!errorsEl) return;
      if (!parsed || !parsed.length) {
        errorsEl.innerHTML = '<div class="err-line">' +
          escapeHtml((raw || 'Compile failed').split('\n')[0]) + '</div>';
        setStatus(false, '✖ compile failed');
        return;
      }
      var html = parsed.map(function (p) {
        var loc = p.line ? ('line ' + p.line) : '';
        return '<div class="err-line ' + p.severity.toLowerCase() + '">' +
          '<span class="err-sev">' + p.severity + '</span>' +
          (loc ? '<span class="err-loc">' + loc + '</span>' : '') +
          '<span class="err-msg">' + escapeHtml(p.message) + '</span></div>';
      }).join('');
      errorsEl.innerHTML = html;
      var nErr = parsed.filter(function (p) { return p.severity === 'ERROR'; }).length;
      setStatus(false, '✖ ' + nErr + ' error' + (nErr === 1 ? '' : 's'));
    }

    function clearErrors() {
      if (errorsEl) errorsEl.innerHTML = '<div class="err-line ok-line">No errors — shader is live.</div>';
    }

    function compile() {
      var src = ta.value;
      var ok = toy.setShader(src);
      if (ok) {
        clearErrors();
        setStatus(true, '✓ compiled');
        FF.store.set('editorSource', src);
        if (reduced && !toy.running) toy.renderStill();
      }
      // on failure, onError already rendered diagnostics and
      // the previous working program keeps running.
    }

    // line numbers gutter, kept in sync with the textarea
    function renderLineNumbers() {
      if (!lineCol) return;
      var count = ta.value.split('\n').length;
      var s = '';
      for (var i = 1; i <= count; i++) s += i + '\n';
      lineCol.textContent = s;
      lineCol.scrollTop = ta.scrollTop;
    }
    ta.addEventListener('scroll', function () { lineCol.scrollTop = ta.scrollTop; });

    // allow Tab to insert two spaces (not lose focus)
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Tab') {
        e.preventDefault();
        var s = ta.selectionStart, en = ta.selectionEnd;
        ta.value = ta.value.slice(0, s) + '  ' + ta.value.slice(en);
        ta.selectionStart = ta.selectionEnd = s + 2;
        renderLineNumbers();
      }
    });

    // debounced hot-recompile
    var deb;
    ta.addEventListener('input', function () {
      renderLineNumbers();
      clearTimeout(deb);
      setStatus(true, '… typing');
      deb = setTimeout(compile, 450);
    });

    exSelect.addEventListener('change', function () {
      var ex = getExample(exSelect.value);
      ta.value = ex.source;
      renderLineNumbers();
      compile();
    });

    if (runBtn) runBtn.addEventListener('click', compile);
    if (resetBtn) resetBtn.addEventListener('click', function () {
      ta.value = getExample('starter').source;
      renderLineNumbers();
      compile();
    });
    if (playBtn) playBtn.addEventListener('click', function () {
      var running = toy.toggle();
      playBtn.textContent = running ? '❚❚ Pause' : '▶ Play';
    });

    canvas.addEventListener('pointermove', function (e) {
      toy.setMouse(e.clientX, e.clientY);
      if (reduced && !toy.running) toy.renderStill();
    });

    window.addEventListener('beforeunload', function () { toy.destroy(); });

    // boot: restore saved source or load starter
    var saved = FF.store.get('editorSource', null);
    ta.value = saved || getExample('starter').source;
    renderLineNumbers();
    compile();

    if (reduced) {
      toy.renderStill(4.0);
      if (playBtn) playBtn.textContent = '▶ Play (motion paused)';
    } else {
      toy.start();
      if (playBtn) playBtn.textContent = '❚❚ Pause';
    }
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

})();
