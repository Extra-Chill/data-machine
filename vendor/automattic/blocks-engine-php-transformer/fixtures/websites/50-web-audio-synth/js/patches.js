/* =========================================================
   VOLTROVE — Factory patches & sequencer patterns
   Shared data, used by index.html, patches.html and the engine.
   ========================================================= */

(function (global) {
  'use strict';

  /* ── Factory sound patches ──────────────────────────── */
  const FACTORY_PATCHES = [
    {
      id: 'glass-bell',
      name: 'Glass Bell',
      author: 'Voltrove Labs',
      tags: ['keys', 'clean', 'plucky'],
      blurb: 'A bright FM-ish bell with a short tail. Two detuned saw/triangle oscillators through a snappy filter envelope.',
      patch: { oscA: 'triangle', oscB: 'sine', mixB: 0.5, detune: 4, octB: 12,
        cutoff: 4200, resonance: 6, filterEnv: 5000, attack: 0.002, decay: 0.5,
        sustain: 0.1, release: 0.6, drive: 0.05, delayTime: 0.31, delayFeedback: 0.34,
        delayMix: 0.32, glide: 0, volume: 0.72 }
    },
    {
      id: 'tungsten-bass',
      name: 'Tungsten Bass',
      author: 'Voltrove Labs',
      tags: ['bass', 'fat', 'analog'],
      blurb: 'A heavy mono-feeling bass. Detuned saws, low filter, a touch of drive for grit on the low end.',
      patch: { oscA: 'sawtooth', oscB: 'sawtooth', mixB: 0.7, detune: 14, octB: -12,
        cutoff: 700, resonance: 7, filterEnv: 1200, attack: 0.005, decay: 0.2,
        sustain: 0.7, release: 0.18, drive: 0.35, delayTime: 0.2, delayFeedback: 0.1,
        delayMix: 0.08, glide: 0.04, volume: 0.78 }
    },
    {
      id: 'aurora-pad',
      name: 'Aurora Pad',
      author: 'Nima Osei',
      tags: ['pad', 'lush', 'ambient'],
      blurb: 'A slow, evolving wash. Long attack and release, deep delay, wide detune. Hold a chord and let it breathe.',
      patch: { oscA: 'sawtooth', oscB: 'triangle', mixB: 0.6, detune: 22, octB: -12,
        cutoff: 1600, resonance: 3, filterEnv: 2600, attack: 0.9, decay: 1.4,
        sustain: 0.8, release: 1.8, drive: 0.08, delayTime: 0.42, delayFeedback: 0.45,
        delayMix: 0.42, glide: 0, volume: 0.62 }
    },
    {
      id: 'neon-lead',
      name: 'Neon Lead',
      author: 'Voltrove Labs',
      tags: ['lead', 'bright', 'cutting'],
      blurb: 'A screaming square lead for top lines. High resonance, quick envelope, generous slap-back delay.',
      patch: { oscA: 'square', oscB: 'sawtooth', mixB: 0.4, detune: 9, octB: 0,
        cutoff: 3200, resonance: 11, filterEnv: 3400, attack: 0.008, decay: 0.25,
        sustain: 0.6, release: 0.28, drive: 0.22, delayTime: 0.26, delayFeedback: 0.3,
        delayMix: 0.28, glide: 0.02, volume: 0.66 }
    },
    {
      id: 'dust-pluck',
      name: 'Dust Pluck',
      author: 'Marlowe Quist',
      tags: ['pluck', 'organic', 'short'],
      blurb: 'A dry, woody pluck with almost no sustain. Great for arpeggios and the step sequencer.',
      patch: { oscA: 'triangle', oscB: 'square', mixB: 0.25, detune: 6, octB: -12,
        cutoff: 2200, resonance: 5, filterEnv: 2800, attack: 0.001, decay: 0.16,
        sustain: 0.0, release: 0.14, drive: 0.1, delayTime: 0.18, delayFeedback: 0.22,
        delayMix: 0.2, glide: 0, volume: 0.74 }
    },
    {
      id: 'hollow-choir',
      name: 'Hollow Choir',
      author: 'Nima Osei',
      tags: ['pad', 'vocal', 'haunting'],
      blurb: 'A breathy, vowel-like pad. Soft sines, gentle resonance sweep and a cavernous delay.',
      patch: { oscA: 'sine', oscB: 'sawtooth', mixB: 0.3, detune: 16, octB: 12,
        cutoff: 1200, resonance: 8, filterEnv: 2200, attack: 0.6, decay: 1.0,
        sustain: 0.75, release: 1.3, drive: 0.04, delayTime: 0.5, delayFeedback: 0.5,
        delayMix: 0.4, glide: 0, volume: 0.6 }
    },
    {
      id: 'acid-303',
      name: 'Filament Acid',
      author: 'Voltrove Labs',
      tags: ['bass', 'acid', 'squelch'],
      blurb: 'Squelchy resonant acid line. Crank the cutoff while the sequencer runs for that classic squelch.',
      patch: { oscA: 'sawtooth', oscB: 'square', mixB: 0.15, detune: 3, octB: 0,
        cutoff: 900, resonance: 16, filterEnv: 3200, attack: 0.003, decay: 0.18,
        sustain: 0.2, release: 0.12, drive: 0.4, delayTime: 0.23, delayFeedback: 0.28,
        delayMix: 0.18, glide: 0.06, volume: 0.7 }
    },
    {
      id: 'ice-keys',
      name: 'Ice Keys',
      author: 'Marlowe Quist',
      tags: ['keys', 'cold', 'crystalline'],
      blurb: 'Cold electric-piano keys with a glassy top. Plays nicely under vocals and over pads.',
      patch: { oscA: 'sine', oscB: 'triangle', mixB: 0.6, detune: 5, octB: 12,
        cutoff: 3600, resonance: 4, filterEnv: 3800, attack: 0.004, decay: 0.6,
        sustain: 0.25, release: 0.5, drive: 0.06, delayTime: 0.34, delayFeedback: 0.36,
        delayMix: 0.3, glide: 0, volume: 0.7 }
    }
  ];

  /* ── Sequencer patterns ─────────────────────────────────
     Drum grid: object of row → 16 booleans.
     Optional `synthLine`: array of 16 entries (midi note or null) using
     the currently-loaded patch.
  ─────────────────────────────────────────────────────── */
  const DRUM_ROWS = ['kick', 'snare', 'hat', 'clap'];

  function emptyGrid() {
    const g = {};
    DRUM_ROWS.forEach(function (r) { g[r] = new Array(16).fill(false); });
    return g;
  }

  function row(str) {
    // "x...x...x...x..." → booleans (x or X = on)
    return str.split('').slice(0, 16).map(function (ch) { return ch === 'x' || ch === 'X'; });
  }

  const PATTERNS = [
    {
      id: 'four-floor',
      name: 'Four On The Floor',
      bpm: 124,
      blurb: 'Straight house pulse. Kick on every beat, claps on the backbeat, driving offbeat hats.',
      grid: {
        kick:  row('x...x...x...x...'),
        snare: row('................'),
        hat:   row('..x...x...x...x.'),
        clap:  row('....x.......x...')
      },
      synthLine: [null, null, 36, null, null, null, 36, null, null, null, 36, null, 43, null, 41, null]
    },
    {
      id: 'broken-beat',
      name: 'Broken Garage',
      bpm: 132,
      blurb: 'Shuffled, skippy UK-garage feel with a syncopated kick and a busy hat.',
      grid: {
        kick:  row('x.....x..x......'),
        snare: row('....x.......x..x'),
        hat:   row('x.xxx.xxx.xxx.xx'),
        clap:  row('....x.......x...')
      },
      synthLine: [48, null, null, 51, null, 53, null, null, 48, null, 55, null, 53, null, 51, null]
    },
    {
      id: 'half-time-trap',
      name: 'Half-Time Trap',
      bpm: 72,
      blurb: 'Slow, heavy half-time. Snare on the 9, rolling hats, sparse sub kick.',
      grid: {
        kick:  row('x.......x.x.....'),
        snare: row('........x.......'),
        hat:   row('x.x.x.x.x.xxx.x.'),
        clap:  row('........x.......')
      },
      synthLine: [36, null, null, null, null, null, 36, null, null, null, 39, null, null, null, 34, null]
    },
    {
      id: 'acid-loop',
      name: 'Acid Loop',
      bpm: 138,
      blurb: 'A relentless 16th-note acid bassline. Pair it with the Filament Acid patch and ride the cutoff.',
      grid: {
        kick:  row('x...x...x...x...'),
        snare: row('....x.......x...'),
        hat:   row('xxxxxxxxxxxxxxxx'),
        clap:  row('................')
      },
      synthLine: [36, 36, 48, 36, 39, 36, 36, 43, 36, 36, 48, 41, 39, 36, 36, 34]
    },
    {
      id: 'ambient-bloom',
      name: 'Ambient Bloom',
      bpm: 84,
      blurb: 'Almost no drums — a slow arpeggio that blooms through the delay. Try it with Aurora Pad.',
      grid: {
        kick:  row('x...............'),
        snare: row('................'),
        hat:   row('..............x.'),
        clap:  row('................')
      },
      synthLine: [60, null, 64, null, 67, null, 71, null, 72, null, 71, null, 67, null, 64, null]
    }
  ];

  global.VoltrovePatches = {
    FACTORY_PATCHES: FACTORY_PATCHES,
    PATTERNS: PATTERNS,
    DRUM_ROWS: DRUM_ROWS,
    emptyGrid: emptyGrid,
    row: row
  };

})(window);
