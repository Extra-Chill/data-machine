/* =========================================================
   FRAGMENT FOUNDRY — Raw WebGL helper
   A tiny, dependency-free engine that runs a single
   full-bleed fragment shader on a screen-filling quad.

   Exposes window.FF.ShaderToy — a class that owns one
   <canvas>, a WebGL context, a program, the standard
   uniforms (u_time, u_resolution, u_mouse) plus any
   custom uniforms a shader declares, and a rAF loop with
   clean teardown.
   ========================================================= */
(function () {
  'use strict';

  var FF = (window.FF = window.FF || {});

  /* The vertex shader is identical for every fragment shader.
     We draw one big triangle that covers the clip-space view;
     gl_Position is computed straight from a_position. No
     attributes other than position are needed. */
  var VERT_SRC = [
    'attribute vec2 a_position;',
    'void main() {',
    '  gl_Position = vec4(a_position, 0.0, 1.0);',
    '}'
  ].join('\n');

  /* Boilerplate prepended to every user / gallery fragment
     shader. It declares the common uniforms and a default
     float precision so the gallery shaders can stay terse. */
  FF.FRAG_HEADER = [
    'precision highp float;',
    'uniform float u_time;',
    'uniform vec2  u_resolution;',
    'uniform vec2  u_mouse;'
  ].join('\n');

  /* Detect WebGL support, returning a context or null. */
  FF.getContext = function (canvas, opts) {
    var attribs = Object.assign(
      { antialias: false, depth: false, stencil: false,
        alpha: false, preserveDrawingBuffer: false,
        powerPreference: 'high-performance' },
      opts || {}
    );
    var gl = null;
    try {
      gl = canvas.getContext('webgl', attribs) ||
           canvas.getContext('experimental-webgl', attribs);
    } catch (e) { gl = null; }
    return gl;
  };

  FF.supported = function () {
    var c = document.createElement('canvas');
    return !!FF.getContext(c);
  };

  /* Compile a single shader. Returns { shader, ok, log }. */
  function compileShader(gl, type, src) {
    var shader = gl.createShader(type);
    gl.shaderSource(shader, src);
    gl.compileShader(shader);
    var ok = gl.getShaderParameter(gl.COMPILE_STATUS);
    var log = ok ? '' : (gl.getShaderInfoLog(shader) || '');
    if (!ok) { gl.deleteShader(shader); shader = null; }
    return { shader: shader, ok: ok, log: log };
  }

  /* Parse a GLSL info log line like
     "ERROR: 0:14: 'foo' : undeclared identifier"
     into { line, message } records. Line numbers refer to
     the *full* source we handed the GPU (header + body). */
  FF.parseInfoLog = function (log, headerLineCount) {
    var out = [];
    if (!log) return out;
    var lines = log.split('\n');
    var re = /^(ERROR|WARNING)\s*:\s*\d+\s*:\s*(\d+)\s*:\s*(.*)$/i;
    for (var i = 0; i < lines.length; i++) {
      var t = lines[i].trim();
      if (!t) continue;
      var m = t.match(re);
      if (m) {
        var raw = parseInt(m[2], 10);
        out.push({
          severity: m[1].toUpperCase(),
          // translate to the line in the *body* the user edits
          line: Math.max(1, raw - (headerLineCount || 0)),
          rawLine: raw,
          message: m[3].trim()
        });
      } else {
        out.push({ severity: 'NOTE', line: null, rawLine: null, message: t });
      }
    }
    return out;
  };

  /* ──────────────────────────────────────────────────────
     ShaderToy: owns one canvas + render loop.
     opts:
       canvas      : <canvas> element (required)
       pixelRatio  : cap (default min(devicePixelRatio, 2))
       onError     : function(message, parsedLog)
       onFrame     : function(stats) optional, per frame
     ────────────────────────────────────────────────────── */
  FF.ShaderToy = function (opts) {
    this.canvas = opts.canvas;
    this.onError = opts.onError || function () {};
    this.onFrame = opts.onFrame || null;
    this.maxPixelRatio = opts.pixelRatio || 2;

    this.gl = FF.getContext(this.canvas);
    this.program = null;
    this.buffer = null;
    this.uniformLocs = {};      // name -> WebGLUniformLocation
    this.customUniforms = {};   // name -> { value, type }
    this.headerLineCount = FF.FRAG_HEADER.split('\n').length;

    this._raf = null;
    this.running = false;
    this.startTime = 0;
    this.elapsed = 0;           // seconds, survives pause
    this.lastTick = 0;
    this.frames = 0;
    this.fps = 0;
    this._fpsAccum = 0;
    this._fpsCount = 0;

    this.mouse = { x: 0.5, y: 0.5, px: 0, py: 0 };
    this._lost = false;

    this._init();
  };

  FF.ShaderToy.prototype._init = function () {
    var gl = this.gl;
    if (!gl) {
      this.onError('WebGL is not available in this browser.', []);
      return;
    }
    // full-screen triangle (covers clip space -1..3)
    this.buffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, this.buffer);
    gl.bufferData(
      gl.ARRAY_BUFFER,
      new Float32Array([-1, -1, 3, -1, -1, 3]),
      gl.STATIC_DRAW
    );

    var self = this;
    this._onLost = function (e) {
      e.preventDefault();
      self._lost = true;
      self.stop();
    };
    this._onRestored = function () {
      self._lost = false;
      self._init();
      if (self._lastFragBody) self.setShader(self._lastFragBody);
      self.start();
    };
    this.canvas.addEventListener('webglcontextlost', this._onLost, false);
    this.canvas.addEventListener('webglcontextrestored', this._onRestored, false);
  };

  /* Build a program from a fragment-shader *body* (the part
     after the header). Returns true on success. On failure
     it keeps the previous working program running and fires
     onError with parsed diagnostics. */
  FF.ShaderToy.prototype.setShader = function (fragBody) {
    var gl = this.gl;
    if (!gl) return false;
    this._lastFragBody = fragBody;

    var fullFrag = FF.FRAG_HEADER + '\n' + fragBody;

    var v = compileShader(gl, gl.VERTEX_SHADER, VERT_SRC);
    if (!v.ok) { this.onError('Vertex shader error:\n' + v.log, []); return false; }

    var f = compileShader(gl, gl.FRAGMENT_SHADER, fullFrag);
    if (!f.ok) {
      gl.deleteShader(v.shader);
      this.onError(f.log, FF.parseInfoLog(f.log, this.headerLineCount));
      return false;
    }

    var prog = gl.createProgram();
    gl.attachShader(prog, v.shader);
    gl.attachShader(prog, f.shader);
    gl.linkProgram(prog);
    gl.deleteShader(v.shader);
    gl.deleteShader(f.shader);

    if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) {
      var log = gl.getProgramInfoLog(prog) || 'Program link failed.';
      gl.deleteProgram(prog);
      this.onError(log, FF.parseInfoLog(log, this.headerLineCount));
      return false;
    }

    // swap in the new program
    if (this.program) gl.deleteProgram(this.program);
    this.program = prog;
    gl.useProgram(prog);

    // wire the position attribute
    var posLoc = gl.getAttribLocation(prog, 'a_position');
    gl.bindBuffer(gl.ARRAY_BUFFER, this.buffer);
    gl.enableVertexAttribArray(posLoc);
    gl.vertexAttribPointer(posLoc, 2, gl.FLOAT, false, 0, 0);

    // cache standard uniform locations
    this.uniformLocs = {
      u_time: gl.getUniformLocation(prog, 'u_time'),
      u_resolution: gl.getUniformLocation(prog, 'u_resolution'),
      u_mouse: gl.getUniformLocation(prog, 'u_mouse')
    };
    // re-resolve custom uniform locations
    for (var name in this.customUniforms) {
      if (this.customUniforms.hasOwnProperty(name)) {
        this.uniformLocs[name] = gl.getUniformLocation(prog, name);
      }
    }
    this.resize();
    return true;
  };

  /* Declare a custom uniform. type: 'float' | 'vec2' | 'vec3'.
     value is a number or array. */
  FF.ShaderToy.prototype.setUniform = function (name, value, type) {
    this.customUniforms[name] = { value: value, type: type || 'float' };
    if (this.gl && this.program) {
      this.uniformLocs[name] = this.gl.getUniformLocation(this.program, name);
    }
  };

  FF.ShaderToy.prototype.updateUniform = function (name, value) {
    if (this.customUniforms[name]) this.customUniforms[name].value = value;
  };

  FF.ShaderToy.prototype.clearCustomUniforms = function () {
    this.customUniforms = {};
  };

  FF.ShaderToy.prototype.resize = function () {
    var gl = this.gl;
    if (!gl) return;
    var ratio = Math.min(window.devicePixelRatio || 1, this.maxPixelRatio);
    var w = Math.max(1, Math.floor(this.canvas.clientWidth * ratio));
    var h = Math.max(1, Math.floor(this.canvas.clientHeight * ratio));
    if (this.canvas.width !== w || this.canvas.height !== h) {
      this.canvas.width = w;
      this.canvas.height = h;
    }
    gl.viewport(0, 0, this.canvas.width, this.canvas.height);
  };

  FF.ShaderToy.prototype.setMouse = function (clientX, clientY) {
    var r = this.canvas.getBoundingClientRect();
    var x = (clientX - r.left) / Math.max(1, r.width);
    var y = 1.0 - (clientY - r.top) / Math.max(1, r.height); // GL y-up
    this.mouse.x = Math.min(1, Math.max(0, x));
    this.mouse.y = Math.min(1, Math.max(0, y));
  };

  FF.ShaderToy.prototype._render = function () {
    var gl = this.gl;
    if (!gl || !this.program || this._lost) return;
    this.resize();
    gl.useProgram(this.program);

    var L = this.uniformLocs;
    if (L.u_time) gl.uniform1f(L.u_time, this.elapsed);
    if (L.u_resolution) gl.uniform2f(L.u_resolution, this.canvas.width, this.canvas.height);
    if (L.u_mouse) {
      gl.uniform2f(
        L.u_mouse,
        this.mouse.x * this.canvas.width,
        this.mouse.y * this.canvas.height
      );
    }
    // custom uniforms
    for (var name in this.customUniforms) {
      if (!this.customUniforms.hasOwnProperty(name)) continue;
      var loc = L[name];
      if (!loc) continue;
      var u = this.customUniforms[name];
      var val = u.value;
      if (u.type === 'float') gl.uniform1f(loc, val);
      else if (u.type === 'vec2') gl.uniform2f(loc, val[0], val[1]);
      else if (u.type === 'vec3') gl.uniform3f(loc, val[0], val[1], val[2]);
    }
    gl.drawArrays(gl.TRIANGLES, 0, 3);
  };

  /* Render a single frame at the current elapsed time without
     starting the loop — used for the reduced-motion still. */
  FF.ShaderToy.prototype.renderStill = function (atSeconds) {
    if (typeof atSeconds === 'number') this.elapsed = atSeconds;
    this._render();
  };

  FF.ShaderToy.prototype.start = function () {
    if (this.running || !this.gl) return;
    this.running = true;
    var self = this;
    this.lastTick = performance.now();
    function loop(now) {
      if (!self.running) return;
      var dt = (now - self.lastTick) / 1000;
      self.lastTick = now;
      if (dt > 0.1) dt = 0.1; // clamp huge gaps (tab switch)
      self.elapsed += dt;

      // fps (rolling)
      self._fpsAccum += dt;
      self._fpsCount++;
      if (self._fpsAccum >= 0.5) {
        self.fps = Math.round(self._fpsCount / self._fpsAccum);
        self._fpsAccum = 0; self._fpsCount = 0;
      }
      self.frames++;

      self._render();
      if (self.onFrame) self.onFrame({ fps: self.fps, time: self.elapsed, frames: self.frames });
      self._raf = requestAnimationFrame(loop);
    }
    this._raf = requestAnimationFrame(loop);
  };

  FF.ShaderToy.prototype.stop = function () {
    this.running = false;
    if (this._raf) { cancelAnimationFrame(this._raf); this._raf = null; }
  };

  FF.ShaderToy.prototype.toggle = function () {
    if (this.running) this.stop(); else this.start();
    return this.running;
  };

  FF.ShaderToy.prototype.reset = function () { this.elapsed = 0; };

  FF.ShaderToy.prototype.destroy = function () {
    this.stop();
    this.canvas.removeEventListener('webglcontextlost', this._onLost, false);
    this.canvas.removeEventListener('webglcontextrestored', this._onRestored, false);
    var gl = this.gl;
    if (gl) {
      if (this.program) gl.deleteProgram(this.program);
      if (this.buffer) gl.deleteBuffer(this.buffer);
      var ext = gl.getExtension('WEBGL_lose_context');
      if (ext) ext.loseContext();
    }
    this.program = null;
    this.buffer = null;
    this.gl = null;
  };

})();
