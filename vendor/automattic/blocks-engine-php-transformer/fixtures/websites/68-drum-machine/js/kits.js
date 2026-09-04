/* =========================================================
   PULSEFORGE — Factory Kits & Grooves
   Kits override voice params (engine.js); grooves are 16-step
   patterns per voice. x = hit (velocity 1), - = rest,
   o = soft hit (velocity 0.55). Helpers parse these to arrays.
   ========================================================= */

(function (global) {
  'use strict';

  var VOICES = global.Pulseforge.VOICES;
  var STEPS = 16;

  function emptyGrid() {
    var g = {};
    VOICES.forEach(function (v) {
      g[v.id] = { hits: new Array(STEPS).fill(false), vel: new Array(STEPS).fill(1) };
    });
    return g;
  }

  // Parse a "x-o-x---" string into hits/vel arrays (16 chars).
  function row(str) {
    var hits = new Array(STEPS).fill(false);
    var vel = new Array(STEPS).fill(1);
    for (var i = 0; i < STEPS && i < str.length; i++) {
      var ch = str[i];
      if (ch === 'x' || ch === 'X') { hits[i] = true; vel[i] = 1; }
      else if (ch === 'o' || ch === 'O') { hits[i] = true; vel[i] = 0.55; }
      else if (ch === 'A') { hits[i] = true; vel[i] = 1.2; } // accent
    }
    return { hits: hits, vel: vel };
  }

  // Build a grid from a {voiceId: "pattern"} map.
  function grid(map) {
    var g = emptyGrid();
    for (var id in map) { if (g[id]) g[id] = row(map[id]); }
    return g;
  }

  /* ── KITS ────────────────────────────────────────────────
     Each value is a 0..1 knob the engine maps into real ranges. */
  var KITS = [
    {
      id: '808', name: '808 Classic',
      blurb: 'Long booming sub-kick, snappy noise snare, ticky hats. The Roland TR-808 sound that built hip-hop and electro.',
      params: {
        kick:    { tune: 0.30, decay: 0.78, level: 1.0,  tone: 0.30 },
        snare:   { tune: 0.45, decay: 0.45, level: 0.78, tone: 0.55 },
        clap:    { tune: 0.50, decay: 0.50, level: 0.70 },
        closed:  { tune: 0.55, decay: 0.18, level: 0.55 },
        open:    { tune: 0.55, decay: 0.55, level: 0.52 },
        cowbell: { tune: 0.50, decay: 0.45, level: 0.60 }
      }
    },
    {
      id: '909', name: '909 House',
      blurb: 'Punchier, shorter kick with a hard click; bright noisy snare and sizzling hats. The TR-909 — house & techno backbone.',
      params: {
        kick:    { tune: 0.55, decay: 0.42, level: 0.98, tone: 0.70 },
        snare:   { tune: 0.55, decay: 0.30, level: 0.82, tone: 0.70 },
        clap:    { tune: 0.55, decay: 0.40, level: 0.72 },
        closed:  { tune: 0.60, decay: 0.22, level: 0.60, tone: 0.62 },
        open:    { tune: 0.60, decay: 0.60, level: 0.55, tone: 0.62 },
        cymbal:  { tune: 0.55, decay: 0.80, level: 0.50 }
      }
    },
    {
      id: 'acoustic', name: 'Acoustic',
      blurb: 'Rounder toms, a woody rimshot and a dryer snare — a kit that breathes more like a real drum kit than a drum machine.',
      params: {
        kick:    { tune: 0.50, decay: 0.30, level: 0.92, tone: 0.55 },
        snare:   { tune: 0.55, decay: 0.28, level: 0.80, tone: 0.45 },
        rim:     { tune: 0.45, decay: 0.40, level: 0.66 },
        closed:  { tune: 0.50, decay: 0.16, level: 0.55, tone: 0.42 },
        open:    { tune: 0.50, decay: 0.50, level: 0.52, tone: 0.42 },
        lowtom:  { tune: 0.40, decay: 0.55, level: 0.78 },
        hitom:   { tune: 0.62, decay: 0.50, level: 0.78 },
        cymbal:  { tune: 0.48, decay: 0.95, level: 0.48 }
      }
    },
    {
      id: 'lofi', name: 'LoFi Dust',
      blurb: 'Soft, slightly detuned and short — muted kick, brushed snare, dusty hats. Perfect for sleepy boom-bap and beats to study to.',
      params: {
        kick:    { tune: 0.38, decay: 0.40, level: 0.90, tone: 0.20 },
        snare:   { tune: 0.40, decay: 0.30, level: 0.68, tone: 0.40 },
        clap:    { tune: 0.40, decay: 0.35, level: 0.58 },
        closed:  { tune: 0.45, decay: 0.14, level: 0.48, tone: 0.40 },
        open:    { tune: 0.45, decay: 0.40, level: 0.45, tone: 0.40 },
        rim:     { tune: 0.42, decay: 0.30, level: 0.55 }
      }
    },
    {
      id: 'trap', name: 'Trap 808',
      blurb: 'Deep gliding 808 kick that doubles as bass, machine-gun hats and a tight clap. Built for rolling hi-hats and half-time snares.',
      params: {
        kick:    { tune: 0.18, decay: 0.92, level: 1.0,  tone: 0.45 },
        snare:   { tune: 0.55, decay: 0.35, level: 0.82, tone: 0.75 },
        clap:    { tune: 0.58, decay: 0.40, level: 0.78 },
        closed:  { tune: 0.62, decay: 0.12, level: 0.58, tone: 0.66 },
        open:    { tune: 0.62, decay: 0.55, level: 0.52, tone: 0.66 }
      }
    },
    {
      id: 'techno', name: 'Industrial',
      blurb: 'Hard distorted kick, metallic rim and a brittle clap. Driving, mechanical, made for a dark warehouse at 3am.',
      params: {
        kick:    { tune: 0.42, decay: 0.50, level: 1.0,  tone: 0.85 },
        snare:   { tune: 0.60, decay: 0.25, level: 0.78, tone: 0.80 },
        clap:    { tune: 0.62, decay: 0.30, level: 0.70 },
        rim:     { tune: 0.62, decay: 0.20, level: 0.66 },
        closed:  { tune: 0.66, decay: 0.16, level: 0.60, tone: 0.70 },
        open:    { tune: 0.66, decay: 0.45, level: 0.55, tone: 0.70 },
        cymbal:  { tune: 0.60, decay: 0.70, level: 0.50 }
      }
    }
  ];

  /* ── GROOVES (factory patterns) ──────────────────────────── */
  var GROOVES = [
    {
      id: 'four-floor', name: 'Four on the Floor', kit: '909', bpm: 126, swing: 0,
      blurb: 'The house heartbeat: kick on every beat, claps on the backbeat, off-beat open hats.',
      grid: {
        kick:   'x---x---x---x---',
        clap:   '----x-------x---',
        closed: 'x-x-x-x-x-x-x-x-',
        open:   '--x---x---x---x-'
      }
    },
    {
      id: 'boom-bap', name: 'Boom-Bap', kit: 'lofi', bpm: 90, swing: 0.18,
      blurb: 'Classic 90s hip-hop: punchy kick, snare on 2 and 4, swung 8th-note hats.',
      grid: {
        kick:   'x------x--x-----',
        snare:  '----x-------x---',
        closed: 'x-x-x-x-x-x-x-x-',
        open:   '------------x---'
      }
    },
    {
      id: 'trap-roll', name: 'Trap Roll', kit: 'trap', bpm: 140, swing: 0,
      blurb: 'Half-time snare, sliding 808 and rolling triplet-feel hats with a few rolls.',
      grid: {
        kick:   'x-----x-----x---',
        snare:  '--------x-------',
        clap:   '--------x-------',
        closed: 'x-xxx-x-x-xxxx-x',
        open:   '------------x---'
      }
    },
    {
      id: 'breakbeat', name: 'Breakbeat', kit: 'acoustic', bpm: 134, swing: 0.06,
      blurb: 'A chopped funk break: syncopated kicks, ghost snares and busy hats. The DNA of jungle and big beat.',
      grid: {
        kick:   'x-----x---x-x---',
        snare:  '----x---o--x-x-x',
        closed: 'x-x-x-x-x-x-x-x-',
        open:   '----------x-----'
      }
    },
    {
      id: 'electro', name: 'Electro Funk', kit: '808', bpm: 112, swing: 0,
      blurb: 'Syncopated 808 toms and cowbell over a steady kick — Planet Rock territory.',
      grid: {
        kick:   'x-----x-x-----x-',
        snare:  '----x-------x---',
        cowbell:'--x---x---x---x-',
        closed: 'x-x-x-x-x-x-x-x-',
        lowtom: '------------x-x-'
      }
    },
    {
      id: 'techno-stomp', name: 'Techno Stomp', kit: 'techno', bpm: 132, swing: 0,
      blurb: 'Relentless four-to-the-floor with off-beat hats, rim accents and a crash on the one.',
      grid: {
        kick:   'x---x---x---x---',
        rim:    '--x---x---x---x-',
        closed: '--x---x---x---x-',
        open:   '----x-------x---',
        cymbal: 'x---------------'
      }
    },
    {
      id: 'half-time', name: 'Half-Time Heavy', kit: 'trap', bpm: 75, swing: 0,
      blurb: 'Big slow snare on the 3, deep gliding kick and sparse hats. Heads nod by default.',
      grid: {
        kick:   'x-------x-x-----',
        snare:  '--------x-------',
        closed: 'x---x---x---x---',
        open:   '--------------x-'
      }
    },
    {
      id: 'amen', name: 'Amen-ish', kit: 'acoustic', bpm: 165, swing: 0,
      blurb: 'A nod to the most-sampled break ever — busy ghost snares, ride-y hats, jungle-ready at tempo.',
      grid: {
        kick:   'x-x-------x-----',
        snare:  '----x-o-x---x-ox',
        closed: 'x-x-x-x-x-x-x-x-',
        open:   '------x-------x-'
      }
    }
  ];

  function kitById(id) {
    for (var i = 0; i < KITS.length; i++) if (KITS[i].id === id) return KITS[i];
    return KITS[0];
  }
  function grooveById(id) {
    for (var i = 0; i < GROOVES.length; i++) if (GROOVES[i].id === id) return GROOVES[i];
    return null;
  }

  global.PulseforgeKits = {
    STEPS: STEPS,
    KITS: KITS,
    GROOVES: GROOVES,
    emptyGrid: emptyGrid,
    grid: grid,
    row: row,
    kitById: kitById,
    grooveById: grooveById
  };

})(window);
