/* =========================================================
   FRAGMENT FOUNDRY — Shader gallery
   A catalogue of hand-written GLSL fragment shaders. Each
   entry is { id, name, tag, author, year, desc, uniforms,
   source }. The `source` is the BODY only — the standard
   header (precision + u_time/u_resolution/u_mouse) is added
   by the engine. Custom uniforms are declared at the top of
   each body AND described in `uniforms` so the UI can build
   sliders that drive them live.
   ========================================================= */
(function () {
  'use strict';
  var FF = (window.FF = window.FF || {});

  /* helper to keep source readable */
  function glsl(strings) { return strings.join('\n'); }

  FF.SHADERS = [

    /* ── 01 · PLASMA FLOW ─────────────────────────────── */
    {
      id: 'plasma',
      name: 'Aurora Plasma',
      tag: 'flow field',
      author: 'M. Okonkwo',
      year: '2026',
      desc: 'Layered sine-interference plasma advected by a slow domain warp. The mouse pulls the field toward the cursor like a magnet.',
      uniforms: [
        { name: 'u_speed',     label: 'Flow speed',  min: 0.1, max: 3.0, step: 0.05, value: 1.0 },
        { name: 'u_scale',     label: 'Scale',       min: 1.0, max: 8.0, step: 0.1,  value: 3.4 },
        { name: 'u_warp',      label: 'Warp',        min: 0.0, max: 2.5, step: 0.05, value: 1.1 },
        { name: 'u_hue',       label: 'Hue shift',   min: 0.0, max: 1.0, step: 0.01, value: 0.55 }
      ],
      source: glsl([
        'uniform float u_speed; uniform float u_scale; uniform float u_warp; uniform float u_hue;',
        'vec3 hsv2rgb(vec3 c){',
        '  vec3 p = abs(fract(c.xxx + vec3(0.0,2.0/3.0,1.0/3.0))*6.0-3.0);',
        '  return c.z * mix(vec3(1.0), clamp(p-1.0,0.0,1.0), c.y);',
        '}',
        'void main(){',
        '  vec2 uv = (gl_FragCoord.xy*2.0 - u_resolution.xy)/u_resolution.y;',
        '  vec2 m  = (u_mouse.xy*2.0 - u_resolution.xy)/u_resolution.y;',
        '  float t = u_time*u_speed;',
        '  uv *= u_scale;',
        '  // domain warp toward mouse',
        '  vec2 d = uv - m;',
        '  float pull = u_warp/(0.4+dot(d,d));',
        '  uv += normalize(d+1e-4)*pull;',
        '  float v = 0.0;',
        '  v += sin(uv.x + t);',
        '  v += sin((uv.y + t)*1.3);',
        '  v += sin((uv.x+uv.y + t)*0.8);',
        '  v += sin(length(uv)*1.5 - t*1.2);',
        '  v *= 0.25;',
        '  float h = fract(u_hue + v*0.5 + t*0.02);',
        '  vec3 col = hsv2rgb(vec3(h, 0.75, 0.55 + 0.45*v + 0.4));',
        '  col = pow(col, vec3(0.85));',
        '  gl_FragColor = vec4(col, 1.0);',
        '}'
      ])
    },

    /* ── 02 · RAYMARCHED TORUS ─────────────────────────── */
    {
      id: 'raymarch',
      name: 'Glass Torus',
      tag: 'raymarching',
      author: 'L. Vásquez',
      year: '2026',
      desc: 'A sphere-traced torus and floor with soft shadows and a single moving key light. Drag the mouse left/right to orbit the camera.',
      uniforms: [
        { name: 'u_orbit',   label: 'Orbit',      min: -3.14, max: 3.14, step: 0.01, value: 0.6 },
        { name: 'u_thick',   label: 'Tube',       min: 0.05,  max: 0.5,  step: 0.01, value: 0.28 },
        { name: 'u_shine',   label: 'Specular',   min: 1.0,   max: 64.0, step: 1.0,  value: 24.0 }
      ],
      source: glsl([
        'uniform float u_orbit; uniform float u_thick; uniform float u_shine;',
        'float sdTorus(vec3 p, vec2 t){',
        '  vec2 q = vec2(length(p.xz)-t.x, p.y);',
        '  return length(q)-t.y;',
        '}',
        'float map(vec3 p, out int id){',
        '  float floorD = p.y + 1.0;',
        '  vec3 pt = p;',
        '  float a = u_time*0.5;',
        '  // rotate torus around X',
        '  float c = cos(a), s = sin(a);',
        '  pt.yz = mat2(c,-s,s,c)*pt.yz;',
        '  float tor = sdTorus(pt, vec2(0.8, u_thick));',
        '  if(tor < floorD){ id = 1; return tor; }',
        '  id = 0; return floorD;',
        '}',
        'vec3 calcNormal(vec3 p){',
        '  vec2 e = vec2(0.001,0.0); int id;',
        '  return normalize(vec3(',
        '    map(p+e.xyy,id)-map(p-e.xyy,id),',
        '    map(p+e.yxy,id)-map(p-e.yxy,id),',
        '    map(p+e.yyx,id)-map(p-e.yyx,id)));',
        '}',
        'float softShadow(vec3 ro, vec3 rd){',
        '  float res = 1.0; float t = 0.05; int id;',
        '  for(int i=0;i<24;i++){',
        '    float h = map(ro+rd*t, id);',
        '    if(h<0.001) return 0.0;',
        '    res = min(res, 8.0*h/t);',
        '    t += clamp(h,0.02,0.3);',
        '    if(t>8.0) break;',
        '  }',
        '  return clamp(res,0.0,1.0);',
        '}',
        'void main(){',
        '  vec2 uv = (gl_FragCoord.xy*2.0 - u_resolution.xy)/u_resolution.y;',
        '  float ang = u_orbit + (u_mouse.x/u_resolution.x - 0.5)*2.0;',
        '  vec3 ro = vec3(sin(ang)*3.2, 1.3, cos(ang)*3.2);',
        '  vec3 ta = vec3(0.0,0.0,0.0);',
        '  vec3 ww = normalize(ta-ro);',
        '  vec3 uu = normalize(cross(ww, vec3(0,1,0)));',
        '  vec3 vv = cross(uu, ww);',
        '  vec3 rd = normalize(uv.x*uu + uv.y*vv + 1.5*ww);',
        '  vec3 col = vec3(0.03,0.04,0.06) + 0.04*rd.y;',
        '  float t = 0.0; int id = 0; bool hit=false;',
        '  for(int i=0;i<96;i++){',
        '    vec3 p = ro + rd*t;',
        '    float d = map(p, id);',
        '    if(d<0.001){ hit=true; break; }',
        '    t += d; if(t>20.0) break;',
        '  }',
        '  if(hit){',
        '    vec3 p = ro+rd*t;',
        '    vec3 n = calcNormal(p);',
        '    vec3 lp = vec3(2.5*cos(u_time*0.7), 3.0, 2.5*sin(u_time*0.7));',
        '    vec3 l = normalize(lp - p);',
        '    float dif = max(dot(n,l),0.0);',
        '    float sh = softShadow(p+n*0.02, l);',
        '    vec3 h = normalize(l - rd);',
        '    float spec = pow(max(dot(n,h),0.0), u_shine);',
        '    vec3 base = (id==1) ? vec3(0.95,0.35,0.55) : vec3(0.18,0.2,0.26);',
        '    if(id==0){ // checker floor',
        '      float ch = mod(floor(p.x)+floor(p.z),2.0);',
        '      base = mix(vec3(0.1,0.11,0.14), vec3(0.16,0.17,0.21), ch);',
        '    }',
        '    col = base*(0.15 + dif*sh) + spec*sh*vec3(1.0);',
        '    col += base*0.12*max(dot(n,vec3(0,1,0)),0.0);',
        '  }',
        '  col = pow(col, vec3(0.4545));',
        '  gl_FragColor = vec4(col, 1.0);',
        '}'
      ])
    },

    /* ── 03 · JULIA FRACTAL ─────────────────────────────── */
    {
      id: 'julia',
      name: 'Julia Drift',
      tag: 'fractal',
      author: 'R. Halloran',
      year: '2026',
      desc: 'A continuously morphing Julia set with smooth iteration coloring and a breathing zoom. Move the mouse to steer the complex constant c.',
      uniforms: [
        { name: 'u_zoom',   label: 'Zoom',     min: 0.4, max: 3.0,  step: 0.01, value: 1.1 },
        { name: 'u_iter',   label: 'Detail',   min: 32,  max: 220,  step: 1.0,  value: 120 },
        { name: 'u_palette',label: 'Palette',  min: 0.0, max: 1.0,  step: 0.01, value: 0.32 }
      ],
      source: glsl([
        'uniform float u_zoom; uniform float u_iter; uniform float u_palette;',
        'vec3 pal(float t){',
        '  vec3 a = vec3(0.5); vec3 b = vec3(0.5);',
        '  vec3 c = vec3(1.0); vec3 d = vec3(0.0,0.33,0.67)+u_palette;',
        '  return a + b*cos(6.28318*(c*t + d));',
        '}',
        'void main(){',
        '  vec2 uv = (gl_FragCoord.xy*2.0 - u_resolution.xy)/u_resolution.y;',
        '  uv /= u_zoom;',
        '  // c follows mouse, plus a slow lissajous drift',
        '  vec2 m = (u_mouse.xy/u_resolution.xy - 0.5)*1.4;',
        '  vec2 c = vec2(-0.7,0.27) + m*0.5 + 0.18*vec2(sin(u_time*0.31), cos(u_time*0.23));',
        '  vec2 z = uv;',
        '  float n = 0.0; float maxIter = u_iter;',
        '  for(int i=0;i<256;i++){',
        '    if(float(i) >= maxIter) break;',
        '    z = vec2(z.x*z.x - z.y*z.y, 2.0*z.x*z.y) + c;',
        '    if(dot(z,z) > 64.0) break;',
        '    n += 1.0;',
        '  }',
        '  vec3 col;',
        '  if(n >= maxIter-0.5){ col = vec3(0.02,0.02,0.04); }',
        '  else {',
        '    float sn = n - log2(log2(dot(z,z))) + 4.0; // smooth',
        '    col = pal(sn*0.025 + u_time*0.02);',
        '  }',
        '  gl_FragColor = vec4(col, 1.0);',
        '}'
      ])
    },

    /* ── 04 · STARFIELD TUNNEL ──────────────────────────── */
    {
      id: 'tunnel',
      name: 'Hyperspace',
      tag: 'tunnel',
      author: 'M. Okonkwo',
      year: '2025',
      desc: 'A polar-mapped starfield racing through an infinite warp tunnel. Mouse position bends the heading; the speed slider opens the throttle.',
      uniforms: [
        { name: 'u_speed',  label: 'Warp speed', min: 0.2, max: 4.0, step: 0.05, value: 1.4 },
        { name: 'u_density',label: 'Star density',min: 1.0, max: 16.0,step: 0.5, value: 7.0 },
        { name: 'u_twist',  label: 'Twist',      min: 0.0, max: 6.0, step: 0.05, value: 1.3 }
      ],
      source: glsl([
        'uniform float u_speed; uniform float u_density; uniform float u_twist;',
        'float hash(vec2 p){ return fract(sin(dot(p, vec2(41.3,289.1)))*43758.5453); }',
        'void main(){',
        '  vec2 m = (u_mouse.xy/u_resolution.xy - 0.5);',
        '  vec2 uv = (gl_FragCoord.xy*2.0 - u_resolution.xy)/u_resolution.y;',
        '  uv += m*0.6; // bend heading toward mouse',
        '  float r = length(uv);',
        '  float a = atan(uv.y, uv.x);',
        '  // tunnel coordinates: depth + angle',
        '  float depth = 0.25/r + u_time*u_speed;',
        '  float ang = a/6.28318 + 0.5 + u_twist*0.05*sin(depth*0.6);',
        '  vec2 tc = vec2(ang*u_density, depth);',
        '  vec2 cell = floor(tc);',
        '  vec2 f = fract(tc) - 0.5;',
        '  float star = hash(cell);',
        '  float bright = 0.0;',
        '  if(star > 0.86){',
        '    float tw = 0.5 + 0.5*sin(u_time*3.0 + star*30.0);',
        '    bright = smoothstep(0.45, 0.0, length(f)) * tw;',
        '  }',
        '  vec3 col = vec3(0.0);',
        '  float hue = fract(star + depth*0.02);',
        '  vec3 starCol = 0.5+0.5*cos(6.28318*(hue + vec3(0.0,0.33,0.67)));',
        '  col += starCol * bright * 2.0;',
        '  // tunnel walls glow, fading to center',
        '  col += vec3(0.10,0.16,0.42) * r * smoothstep(0.0,0.4,r);',
        '  col *= smoothstep(0.0, 0.08, r); // dark center',
        '  col = pow(col, vec3(0.85));',
        '  gl_FragColor = vec4(col, 1.0);',
        '}'
      ])
    },

    /* ── 05 · DOMAIN-WARPED NOISE ───────────────────────── */
    {
      id: 'warp',
      name: 'Marble Warp',
      tag: 'fbm noise',
      author: 'L. Vásquez',
      year: '2026',
      desc: 'Iñigo Quílez-style domain-warped fbm: noise fed through noise twice to grow marbled veins. The mouse adds a local turbulence eddy.',
      uniforms: [
        { name: 'u_warp',   label: 'Warp depth', min: 0.0, max: 5.0, step: 0.05, value: 2.6 },
        { name: 'u_scale',  label: 'Scale',      min: 0.5, max: 4.0, step: 0.05, value: 1.6 },
        { name: 'u_speed',  label: 'Drift',      min: 0.0, max: 1.5, step: 0.01, value: 0.4 }
      ],
      source: glsl([
        'uniform float u_warp; uniform float u_scale; uniform float u_speed;',
        'float hash(vec2 p){ return fract(sin(dot(p,vec2(127.1,311.7)))*43758.5453); }',
        'float noise(vec2 p){',
        '  vec2 i = floor(p); vec2 f = fract(p);',
        '  vec2 u = f*f*(3.0-2.0*f);',
        '  return mix(mix(hash(i+vec2(0,0)),hash(i+vec2(1,0)),u.x),',
        '             mix(hash(i+vec2(0,1)),hash(i+vec2(1,1)),u.x), u.y);',
        '}',
        'float fbm(vec2 p){',
        '  float v = 0.0; float a = 0.5;',
        '  for(int i=0;i<6;i++){ v += a*noise(p); p *= 2.0; a *= 0.5; }',
        '  return v;',
        '}',
        'void main(){',
        '  vec2 uv = gl_FragCoord.xy/u_resolution.y * u_scale;',
        '  float t = u_time*u_speed;',
        '  vec2 m = u_mouse.xy/u_resolution.y * u_scale;',
        '  float eddy = u_warp/(0.5+length(uv-m)*4.0);',
        '  vec2 q = vec2(fbm(uv + vec2(0.0,t)), fbm(uv + vec2(5.2,1.3-t)));',
        '  vec2 r = vec2(fbm(uv + u_warp*q + vec2(1.7,9.2)+eddy),',
        '               fbm(uv + u_warp*q + vec2(8.3,2.8)));',
        '  float f = fbm(uv + u_warp*r);',
        '  vec3 col = mix(vec3(0.04,0.05,0.14), vec3(0.55,0.78,0.95), clamp(f*f*2.2,0.0,1.0));',
        '  col = mix(col, vec3(0.98,0.86,0.55), clamp(length(r),0.0,1.0)*0.5);',
        '  col = mix(col, vec3(1.0), clamp(dot(q,q)*0.4,0.0,1.0));',
        '  gl_FragColor = vec4(col, 1.0);',
        '}'
      ])
    },

    /* ── 06 · KALEIDOSCOPE ──────────────────────────────── */
    {
      id: 'kaleido',
      name: 'Mandala Engine',
      tag: 'kaleidoscope',
      author: 'R. Halloran',
      year: '2026',
      desc: 'Mirror-folded polar space wrapped around an animated radial pattern — an infinite, self-symmetric mandala. Segments and spin are live.',
      uniforms: [
        { name: 'u_seg',    label: 'Segments',  min: 3.0, max: 16.0, step: 1.0,  value: 8.0 },
        { name: 'u_spin',   label: 'Spin',      min: -2.0,max: 2.0,  step: 0.02, value: 0.35 },
        { name: 'u_zoom',   label: 'Zoom',      min: 1.0, max: 6.0,  step: 0.05, value: 2.6 }
      ],
      source: glsl([
        'uniform float u_seg; uniform float u_spin; uniform float u_zoom;',
        'vec3 hsv2rgb(vec3 c){',
        '  vec3 p = abs(fract(c.xxx + vec3(0.0,2.0/3.0,1.0/3.0))*6.0-3.0);',
        '  return c.z * mix(vec3(1.0), clamp(p-1.0,0.0,1.0), c.y);',
        '}',
        'void main(){',
        '  vec2 uv = (gl_FragCoord.xy*2.0 - u_resolution.xy)/u_resolution.y;',
        '  vec2 mo = (u_mouse.xy*2.0 - u_resolution.xy)/u_resolution.y;',
        '  float r = length(uv);',
        '  float a = atan(uv.y, uv.x) + u_time*u_spin;',
        '  float seg = 6.28318/u_seg;',
        '  a = mod(a, seg);',
        '  a = abs(a - seg*0.5);   // mirror fold',
        '  vec2 p = vec2(cos(a), sin(a))*r*u_zoom;',
        '  p += mo*0.4;',
        '  float pattern = 0.0;',
        '  for(int i=1;i<=5;i++){',
        '    float fi = float(i);',
        '    pattern += sin(p.x*fi*1.3 + u_time*0.7)*cos(p.y*fi*1.1 - u_time*0.5)/fi;',
        '  }',
        '  pattern = pattern*0.5 + 0.5;',
        '  float ring = sin(r*8.0 - u_time*1.5)*0.5+0.5;',
        '  float h = fract(pattern*0.5 + r*0.2 + u_time*0.03);',
        '  vec3 col = hsv2rgb(vec3(h, 0.7, pattern*ring + 0.15));',
        '  col *= smoothstep(2.0, 0.1, r);',
        '  gl_FragColor = vec4(col, 1.0);',
        '}'
      ])
    }

  ];

  /* lookup by id */
  FF.getShader = function (id) {
    for (var i = 0; i < FF.SHADERS.length; i++) {
      if (FF.SHADERS[i].id === id) return FF.SHADERS[i];
    }
    return FF.SHADERS[0];
  };

  /* clone of default uniform values for a shader */
  FF.defaultUniformValues = function (shader) {
    var out = {};
    (shader.uniforms || []).forEach(function (u) { out[u.name] = u.value; });
    return out;
  };

})();
