/* levels.js — hand-authored tilemaps for Lumen Leap.
   Each level is an array of equal-length strings (one char per tile). The parser
   turns the grid into a solid/one-way collision map plus lists of entities
   (lumens, gems, spikes, beetles, moving platforms, checkpoints, the goal and
   the player spawn). Levels are verified to be completable along the marked path.

   TILE LEGEND
     '.'  empty / sky
     '#'  solid block (full collision)
     'B'  solid block, "bark" variant (visual only)
     '='  one-way platform (land on top, pass through from below)
     '^'  spike hazard (resting on ground)
     'v'  spike hazard hanging from ceiling
     'o'  lumen (small collectible, +1)
     '*'  gem (rare collectible, +5 and sparkle)
     'c'  checkpoint lantern
     'P'  player spawn
     'G'  goal lantern (level exit)
     'e'  beetle enemy (patrols its platform, stompable)
     'm'  moving-platform marker (carries a horizontal mover)
     'M'  moving-platform marker (carries a vertical mover)
     ',' '"' grass / decorative tuft (non-solid, drawn behind)
*/
(function () {
  'use strict';

  const TILE = 32; // pixel size of one tile

  // ───────────────────────── LEVEL 1 ─────────────────────────
  // "Hollow's Edge" — a gentle teaching level. Flat ground, a couple of
  // hops, one beetle, a pit you bridge with a moving platform.
  const L1 = [
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '...................................*............................',
    '.........o.o.o....................===.........o.o.o.............',
    '.........====........o.o.o.............o.o.o..====..............',
    '................................................................',
    '..P.o.o.o.....e...^^.......c...e....^^.mmm.........e...o.o.o.G..',
    '######################...##############...######################',
    '######################...##############...######################'
  ];

  // ───────────────────────── LEVEL 2 ─────────────────────────
  // "Bramble Climb" — verticality. Vertical movers, hanging spikes, two
  // beetles, a gem reward off the main path.
  const L2 = [
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '..................vvv...........................................',
    '................................................................',
    '..................................................vv............',
    '..................................*M............................',
    '.................................==M............................',
    '...........o.o.o...................M............................',
    '...........===.....................M.....................o.o.o..',
    '...............................===.M..o.o.o..............===....',
    '........===........................M..====......................',
    '..P.o.o.o.......e.o.o.oc...^^.e....M.........e........o.o.o.o.G.',
    '##################...#############...#############...###########',
    '##################...#############...#############...###########'
  ];

  // ───────────────────────── LEVEL 3 ─────────────────────────
  // "Lantern's Heart" — the finale. Combines movers, spike gauntlets,
  // three beetles, generous lumens, a gem, and a triumphant goal.
  const L3 = [
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '................................................................',
    '..........*.....................................................',
    '.........===...................o.o.o..o.o.o..................G..',
    '................o.o.o..........===....................o.o.o.####',
    '.........................................................#######',
    '..P.o.o.e...^^..mmm..c.e...^..........e.......mmm.o.o.##########',
    '################...############...############...###############',
    '################...############...############...###############'
  ];

  const LEVELS = [
    {
      id: 'l1',
      index: 1,
      name: "Hollow's Edge",
      hint: 'Land on the beetle to stomp it. Ride the glowing platform across the gap.',
      tint: '#1b2a4a',
      grid: L1
    },
    {
      id: 'l2',
      index: 2,
      name: 'Bramble Climb',
      hint: 'Watch the spikes overhead. Vertical platforms lift you to the lantern.',
      tint: '#2a1b3f',
      grid: L2
    },
    {
      id: 'l3',
      index: 3,
      name: "Lantern's Heart",
      hint: 'Everything you have learned, in one run. Grab the gem if you are brave.',
      tint: '#3a1f2b',
      grid: L3
    }
  ];

  /* Parse a grid into a structured level the game can run. */
  function parse(def) {
    const grid = def.grid;
    const rows = grid.length;
    const cols = Math.max(...grid.map(r => r.length));
    // solids[y][x] => 0 empty, 1 solid, 2 one-way platform
    const solids = [];
    const decor = []; // {x,y,type}
    const entities = {
      lumens: [], gems: [], spikes: [], beetles: [], movers: [],
      checkpoints: [], spawn: null, goal: null
    };

    for (let y = 0; y < rows; y++) {
      solids[y] = new Array(cols).fill(0);
      const row = grid[y];
      for (let x = 0; x < cols; x++) {
        const ch = row[x] || '.';
        const px = x * TILE, py = y * TILE;
        switch (ch) {
          case '#': case 'B':
            solids[y][x] = 1;
            if (ch === 'B') decor.push({ x: px, y: py, type: 'bark' });
            break;
          case '=':
            solids[y][x] = 2;
            break;
          case '^':
            entities.spikes.push({ x: px, y: py, dir: 'up' });
            break;
          case 'v':
            entities.spikes.push({ x: px, y: py, dir: 'down' });
            break;
          case 'o':
            entities.lumens.push({ x: px + TILE / 2, y: py + TILE / 2, taken: false, seed: (x * 7 + y * 13) });
            break;
          case '*':
            entities.gems.push({ x: px + TILE / 2, y: py + TILE / 2, taken: false });
            break;
          case 'c':
            entities.checkpoints.push({ x: px + TILE / 2, y: py + TILE, reached: false });
            break;
          case 'P':
            entities.spawn = { x: px, y: py };
            break;
          case 'G':
            entities.goal = { x: px, y: py };
            break;
          case 'e':
            entities.beetles.push({ x: px, y: py, w: 26, h: 22 });
            break;
          case 'm':
            entities.movers.push({ x: px, y: py, axis: 'x', char: 'm', x0: x, y0: y });
            break;
          case 'M':
            entities.movers.push({ x: px, y: py, axis: 'y', char: 'M', x0: x, y0: y });
            break;
          case ',': decor.push({ x: px, y: py, type: 'grass' }); break;
          case '"': decor.push({ x: px, y: py, type: 'tuft' }); break;
          default: break;
        }
      }
    }

    // Merge consecutive mover markers on the same row into a single platform
    // whose travel range spans the marker run.
    const merged = [];
    const used = new Set();
    entities.movers.forEach((mv, i) => {
      if (used.has(i)) return;
      if (mv.axis === 'x') {
        // collect run to the right on same row
        let endX = mv.x0;
        for (let j = 0; j < entities.movers.length; j++) {
          const o = entities.movers[j];
          if (o.axis === 'x' && o.y0 === mv.y0 && o.x0 === endX + 1) {
            used.add(j); endX = o.x0;
          }
        }
        const widthTiles = Math.max(2, endX - mv.x0 + 1);
        // The platform's base sits at the left lip of its pit; a gentle ±1.6-tile
        // sweep keeps it overlapping both lips at all times, so it reliably
        // ferries the player across without precise timing.
        merged.push({
          axis: 'x',
          x: mv.x0 * TILE, y: mv.y0 * TILE,
          w: widthTiles * TILE - 2, h: TILE - 8,
          range: 1.6 * TILE, speed: 1.1, phase: (mv.x0 * 0.6),
          baseX: mv.x0 * TILE, baseY: mv.y0 * TILE
        });
      } else {
        // collect a vertical run downward in the same column into ONE platform
        // whose travel spans the run; base sits at the run's vertical centre.
        let endY = mv.y0;
        for (let j = 0; j < entities.movers.length; j++) {
          const o = entities.movers[j];
          if (o.axis === 'y' && o.x0 === mv.x0 && o.y0 === endY + 1) {
            used.add(j); endY = o.y0;
          }
        }
        const heightTiles = Math.max(1, endY - mv.y0 + 1);
        const midRow = mv.y0 + (heightTiles - 1) / 2;
        const range = Math.max(1, (heightTiles - 1) / 2) * TILE;
        merged.push({
          axis: 'y',
          x: mv.x0 * TILE, y: midRow * TILE,
          w: 3 * TILE, h: TILE - 8,
          range, speed: 0.9, phase: (mv.x0 * 0.5),
          baseX: mv.x0 * TILE, baseY: midRow * TILE
        });
      }
      used.add(i);
    });
    entities.movers = merged;

    return {
      ...def,
      cols, rows, tile: TILE,
      width: cols * TILE,
      height: rows * TILE,
      solids,
      decor,
      entities,
      maxLumens: entities.lumens.length + entities.gems.length // gem counts as one collectible for the star tally
    };
  }

  window.LumenLevels = {
    TILE,
    defs: LEVELS,
    parse,
    get(index /* 1-based */) {
      const def = LEVELS.find(l => l.index === index);
      return def ? parse(def) : null;
    },
    byId(id) {
      const def = LEVELS.find(l => l.id === id);
      return def ? parse(def) : null;
    },
    count: LEVELS.length
  };
})();
