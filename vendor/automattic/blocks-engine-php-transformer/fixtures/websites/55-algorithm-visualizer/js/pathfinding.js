/* =========================================================
   STEPWISE — Pathfinding visualizer

   A grid of cells. Each cell is wall or open. The user paints
   walls, moves start/end, and generates mazes. Four algorithms
   search for a path from start to end, recording every cell
   they "visit" plus the final reconstructed shortest path.

   Recorded step types:
     'visit'   {r,c}     — cell dequeued / settled
     'frontier'{r,c}     — cell added to the frontier
     'path'    {r,c}     — cell on the final path (traced at end)
     'done'    {found}   — terminal

   The same StepPlayer drives play / pause / step / scrub.
   Dijkstra and A* use a binary min-heap; BFS uses a queue;
   DFS uses a stack. A* uses the Manhattan-distance heuristic,
   which is admissible on a 4-connected grid, so the path it
   returns is genuinely shortest.
   ========================================================= */
(function () {
  'use strict';

  var ALGOS = {
    bfs: {
      name: 'Breadth-First Search',
      tag: 'Explores in rings; shortest path on unweighted grids',
      bigo: 'O(V+E)', optimal: 'Yes (unweighted)',
      desc: 'Explores the grid in expanding "rings" using a FIFO queue, so the first time it reaches the target it has found a path with the fewest cells. On an unweighted grid this path is guaranteed shortest. It ignores movement cost, so on weighted maps it is not optimal.',
      run: bfs
    },
    dfs: {
      name: 'Depth-First Search',
      tag: 'Dives deep first; NOT shortest',
      bigo: 'O(V+E)', optimal: 'No',
      desc: 'Plunges as deep as possible along one branch using a LIFO stack before backtracking. It is memory-light and great for maze carving, but the path it finds is whatever it stumbles into first — usually long and winding, never guaranteed shortest.',
      run: dfs
    },
    dijkstra: {
      name: "Dijkstra's Algorithm",
      tag: 'Cheapest-cost-first with a min-heap',
      bigo: 'O(E log V)', optimal: 'Yes',
      desc: 'Always expands the unvisited cell with the smallest known cost-from-start, using a priority queue (min-heap). With uniform edge weights it behaves like BFS but generalises to weighted graphs, and the first time it settles the target the cost is provably minimal.',
      run: dijkstra
    },
    astar: {
      name: 'A* Search',
      tag: 'Dijkstra + Manhattan heuristic',
      bigo: 'O(E log V)', optimal: 'Yes (admissible h)',
      desc: 'Like Dijkstra, but it prioritises cells by f = g + h, where g is the cost so far and h is the Manhattan-distance estimate to the goal. Because that heuristic never overestimates on a 4-connected grid, A* finds the same shortest path while exploring far fewer cells — notice how it "leans" toward the target.',
      run: astar
    }
  };

  /* ── min-heap keyed by numeric priority ─────────────────── */
  function MinHeap() { this.data = []; }
  MinHeap.prototype.push = function (item, pri) {
    this.data.push({ item: item, pri: pri });
    var i = this.data.length - 1, d = this.data;
    while (i > 0) {
      var p = (i - 1) >> 1;
      if (d[p].pri <= d[i].pri) break;
      var t = d[p]; d[p] = d[i]; d[i] = t; i = p;
    }
  };
  MinHeap.prototype.pop = function () {
    var d = this.data;
    if (d.length === 0) return null;
    var top = d[0], last = d.pop();
    if (d.length) {
      d[0] = last; var i = 0, n = d.length;
      while (true) {
        var l = 2 * i + 1, r = 2 * i + 2, s = i;
        if (l < n && d[l].pri < d[s].pri) s = l;
        if (r < n && d[r].pri < d[s].pri) s = r;
        if (s === i) break;
        var t = d[s]; d[s] = d[i]; d[i] = t; i = s;
      }
    }
    return top.item;
  };
  MinHeap.prototype.size = function () { return this.data.length; };

  /* ── grid helpers ───────────────────────────────────────── */
  function key(r, c) { return r + ',' + c; }
  function neighbors(grid, r, c) {
    var out = [];
    var dirs = [[-1, 0], [1, 0], [0, -1], [0, 1]];
    for (var k = 0; k < 4; k++) {
      var nr = r + dirs[k][0], nc = c + dirs[k][1];
      if (nr >= 0 && nr < grid.rows && nc >= 0 && nc < grid.cols && !grid.walls[nr][nc]) {
        out.push([nr, nc]);
      }
    }
    return out;
  }

  function reconstruct(cameFrom, end) {
    var path = [], cur = key(end.r, end.c);
    while (cur != null && cameFrom[cur] !== undefined) {
      var parts = cur.split(',');
      path.push({ r: +parts[0], c: +parts[1] });
      cur = cameFrom[cur];
    }
    if (cur != null) {
      var p2 = cur.split(',');
      path.push({ r: +p2[0], c: +p2[1] });
    }
    path.reverse();
    return path;
  }

  /* ── the four searches ──────────────────────────────────── */
  function bfs(grid) {
    var steps = [], start = grid.start, end = grid.end;
    var visited = {}, cameFrom = {};
    var q = [[start.r, start.c]];
    visited[key(start.r, start.c)] = true;
    cameFrom[key(start.r, start.c)] = null;
    var found = false;
    while (q.length) {
      var cell = q.shift();
      var r = cell[0], c = cell[1];
      steps.push({ type: 'visit', r: r, c: c });
      if (r === end.r && c === end.c) { found = true; break; }
      var ns = neighbors(grid, r, c);
      for (var i = 0; i < ns.length; i++) {
        var nk = key(ns[i][0], ns[i][1]);
        if (!visited[nk]) {
          visited[nk] = true;
          cameFrom[nk] = key(r, c);
          steps.push({ type: 'frontier', r: ns[i][0], c: ns[i][1] });
          q.push(ns[i]);
        }
      }
    }
    finish(steps, found, cameFrom, end);
    return steps;
  }

  function dfs(grid) {
    var steps = [], start = grid.start, end = grid.end;
    var visited = {}, cameFrom = {};
    var stack = [[start.r, start.c]];
    cameFrom[key(start.r, start.c)] = null;
    var found = false;
    while (stack.length) {
      var cell = stack.pop();
      var r = cell[0], c = cell[1], k = key(r, c);
      if (visited[k]) continue;
      visited[k] = true;
      steps.push({ type: 'visit', r: r, c: c });
      if (r === end.r && c === end.c) { found = true; break; }
      var ns = neighbors(grid, r, c);
      for (var i = ns.length - 1; i >= 0; i--) {
        var nk = key(ns[i][0], ns[i][1]);
        if (!visited[nk]) {
          if (cameFrom[nk] === undefined) cameFrom[nk] = k;
          steps.push({ type: 'frontier', r: ns[i][0], c: ns[i][1] });
          stack.push(ns[i]);
        }
      }
    }
    finish(steps, found, cameFrom, end);
    return steps;
  }

  function dijkstra(grid) {
    var steps = [], start = grid.start, end = grid.end;
    var dist = {}, cameFrom = {}, settled = {};
    var heap = new MinHeap();
    dist[key(start.r, start.c)] = 0;
    cameFrom[key(start.r, start.c)] = null;
    heap.push([start.r, start.c], 0);
    var found = false;
    while (heap.size()) {
      var cell = heap.pop();
      var r = cell[0], c = cell[1], k = key(r, c);
      if (settled[k]) continue;
      settled[k] = true;
      steps.push({ type: 'visit', r: r, c: c });
      if (r === end.r && c === end.c) { found = true; break; }
      var ns = neighbors(grid, r, c);
      for (var i = 0; i < ns.length; i++) {
        var nk = key(ns[i][0], ns[i][1]);
        var nd = dist[k] + 1; // uniform weight
        if (dist[nk] === undefined || nd < dist[nk]) {
          dist[nk] = nd;
          cameFrom[nk] = k;
          steps.push({ type: 'frontier', r: ns[i][0], c: ns[i][1] });
          heap.push([ns[i][0], ns[i][1]], nd);
        }
      }
    }
    finish(steps, found, cameFrom, end);
    return steps;
  }

  function astar(grid) {
    var steps = [], start = grid.start, end = grid.end;
    function h(r, c) { return Math.abs(r - end.r) + Math.abs(c - end.c); }
    var g = {}, cameFrom = {}, settled = {};
    var heap = new MinHeap();
    g[key(start.r, start.c)] = 0;
    cameFrom[key(start.r, start.c)] = null;
    heap.push([start.r, start.c], h(start.r, start.c));
    var found = false;
    while (heap.size()) {
      var cell = heap.pop();
      var r = cell[0], c = cell[1], k = key(r, c);
      if (settled[k]) continue;
      settled[k] = true;
      steps.push({ type: 'visit', r: r, c: c });
      if (r === end.r && c === end.c) { found = true; break; }
      var ns = neighbors(grid, r, c);
      for (var i = 0; i < ns.length; i++) {
        var nk = key(ns[i][0], ns[i][1]);
        var ng = g[k] + 1;
        if (g[nk] === undefined || ng < g[nk]) {
          g[nk] = ng;
          cameFrom[nk] = k;
          steps.push({ type: 'frontier', r: ns[i][0], c: ns[i][1] });
          heap.push([ns[i][0], ns[i][1]], ng + h(ns[i][0], ns[i][1]));
        }
      }
    }
    finish(steps, found, cameFrom, end);
    return steps;
  }

  function finish(steps, found, cameFrom, end) {
    if (found) {
      var path = reconstruct(cameFrom, end);
      for (var i = 0; i < path.length; i++) {
        steps.push({ type: 'path', r: path[i].r, c: path[i].c });
      }
    }
    steps.push({ type: 'done', found: found });
  }

  /* ── maze generators ────────────────────────────────────── */
  // Recursive backtracker on a cell grid where walls live on
  // odd rows/cols. Produces a "perfect" maze (one path between
  // any two cells).
  function genBacktracker(grid) {
    fillWalls(grid, true);
    var R = grid.rows, C = grid.cols;
    var stack = [[1, 1]];
    grid.walls[1][1] = false;
    var visited = {}; visited[key(1, 1)] = true;
    var dirs = [[-2, 0], [2, 0], [0, -2], [0, 2]];
    while (stack.length) {
      var top = stack[stack.length - 1];
      var r = top[0], c = top[1];
      var opts = [];
      for (var d = 0; d < 4; d++) {
        var nr = r + dirs[d][0], nc = c + dirs[d][1];
        if (nr > 0 && nr < R - 1 && nc > 0 && nc < C - 1 && !visited[key(nr, nc)]) {
          opts.push([nr, nc, r + dirs[d][0] / 2, c + dirs[d][1] / 2]);
        }
      }
      if (opts.length) {
        var pick = opts[Math.floor(Math.random() * opts.length)];
        grid.walls[pick[0]][pick[1]] = false;
        grid.walls[pick[2]][pick[3]] = false;
        visited[key(pick[0], pick[1])] = true;
        stack.push([pick[0], pick[1]]);
      } else {
        stack.pop();
      }
    }
    carveEndpoints(grid);
  }

  // Randomised Prim's: grows the maze from a frontier of walls.
  function genPrim(grid) {
    fillWalls(grid, true);
    var R = grid.rows, C = grid.cols;
    var inMaze = {};
    var sr = 1, sc = 1;
    grid.walls[sr][sc] = false; inMaze[key(sr, sc)] = true;
    var frontier = [];
    function addFront(r, c) {
      var dirs = [[-2, 0], [2, 0], [0, -2], [0, 2]];
      for (var d = 0; d < 4; d++) {
        var nr = r + dirs[d][0], nc = c + dirs[d][1];
        if (nr > 0 && nr < R - 1 && nc > 0 && nc < C - 1 && !inMaze[key(nr, nc)]) {
          frontier.push([nr, nc, r + dirs[d][0] / 2, c + dirs[d][1] / 2]);
        }
      }
    }
    addFront(sr, sc);
    while (frontier.length) {
      var idx = Math.floor(Math.random() * frontier.length);
      var f = frontier.splice(idx, 1)[0];
      if (inMaze[key(f[0], f[1])]) continue;
      grid.walls[f[0]][f[1]] = false;
      grid.walls[f[2]][f[3]] = false;
      inMaze[key(f[0], f[1])] = true;
      addFront(f[0], f[1]);
    }
    carveEndpoints(grid);
  }

  function fillWalls(grid, v) {
    for (var r = 0; r < grid.rows; r++)
      for (var c = 0; c < grid.cols; c++)
        grid.walls[r][c] = v;
  }
  function carveEndpoints(grid) {
    // A "perfect" maze only has corridors on odd cells, so snap the
    // start/end onto the nearest odd cell (which is part of the maze)
    // and carve a short channel from their original position to it so
    // the maze is always solvable from the real endpoints.
    snapAndConnect(grid, grid.start);
    snapAndConnect(grid, grid.end);
  }
  function snapAndConnect(grid, pt) {
    var or = pt.r, oc = pt.c;
    var tr = Math.min(grid.rows - 2, Math.max(1, or % 2 === 0 ? or + 1 : or));
    var tc = Math.min(grid.cols - 2, Math.max(1, oc % 2 === 0 ? oc + 1 : oc));
    // open the endpoint cell itself and the snapped corridor cell
    grid.walls[or][oc] = false;
    grid.walls[tr][tc] = false;
    // carve a straight channel between them (they differ by at most 1 in each axis)
    var sr = or, sc = oc;
    while (sr !== tr) { sr += (tr > sr ? 1 : -1); grid.walls[sr][sc] = false; }
    while (sc !== tc) { sc += (tc > sc ? 1 : -1); grid.walls[sr][sc] = false; }
  }

  /* ── controller / page wiring ───────────────────────────── */
  function init() {
    var canvas = document.getElementById('grid-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');

    var grid = {
      rows: 25, cols: 41,
      walls: [],
      start: { r: 12, c: 4 },
      end: { r: 12, c: 36 }
    };
    function allocGrid() {
      grid.walls = [];
      for (var r = 0; r < grid.rows; r++) {
        var row = [];
        for (var c = 0; c < grid.cols; c++) row.push(false);
        grid.walls.push(row);
      }
    }
    allocGrid();

    var state = {
      algoKey: 'astar',
      steps: [],
      // per-cell render state derived up to current step
      tool: 'wall' // 'wall' | 'erase' | 'start' | 'end'
    };

    /* precompute frame states: for each step, the set of visited /
       frontier / path cells. We store compact arrays of status. */
    var frames = []; // frames[i] = { status: Map(key->code), counts:{visited,path,found} }
    // codes: 1 frontier, 2 visited, 3 path

    function buildFrames(steps) {
      frames = [];
      var status = {};
      var visited = 0, pathLen = 0, found = false;
      frames.push({ status: {}, visited: 0, pathLen: 0, found: false, justPath: false });
      for (var i = 0; i < steps.length; i++) {
        var s = steps[i];
        var justPath = false;
        if (s.type === 'frontier') {
          var fk = key(s.r, s.c);
          if (!status[fk]) status[fk] = 1;
        } else if (s.type === 'visit') {
          var vk = key(s.r, s.c);
          if (status[vk] !== 2) visited++;
          status[vk] = 2;
        } else if (s.type === 'path') {
          status[key(s.r, s.c)] = 3;
          pathLen++;
          justPath = true;
        } else if (s.type === 'done') {
          found = s.found;
        }
        frames.push({
          status: shallow(status),
          visited: visited,
          pathLen: pathLen,
          found: found,
          justPath: justPath
        });
      }
    }
    function shallow(o) { var n = {}; for (var k in o) n[k] = o[k]; return n; }

    /* ── renderer ──────────────────────────────────────────── */
    var cellSize = 0, originX = 0, originY = 0;
    function css(v) { return getComputedStyle(document.documentElement).getPropertyValue(v).trim(); }

    function resize() {
      var dpr = window.devicePixelRatio || 1;
      var rect = canvas.getBoundingClientRect();
      canvas.width = Math.floor(rect.width * dpr);
      canvas.height = Math.floor(rect.height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      cellSize = Math.floor(Math.min(rect.width / grid.cols, rect.height / grid.rows));
      originX = Math.floor((rect.width - cellSize * grid.cols) / 2);
      originY = Math.floor((rect.height - cellSize * grid.rows) / 2);
    }

    function draw(index) {
      var rect = canvas.getBoundingClientRect();
      ctx.clearRect(0, 0, rect.width, rect.height);
      var frame = frames[Math.max(0, Math.min(frames.length - 1, index))] || { status: {} };
      var st = frame.status;

      var cWall = css('--ink');
      var cOpen = css('--bg-soft');
      var cGrid = css('--line');
      var cVisited = css('--accent-5');
      var cFrontier = css('--accent-3');
      var cPath = css('--accent-4');
      var cStart = css('--accent');
      var cEnd = css('--accent-2');

      for (var r = 0; r < grid.rows; r++) {
        for (var c = 0; c < grid.cols; c++) {
          var x = originX + c * cellSize, y = originY + r * cellSize;
          var code = st[key(r, c)];
          var fill = cOpen;
          if (grid.walls[r][c]) fill = cWall;
          else if (code === 3) fill = cPath;
          else if (code === 2) fill = cVisited;
          else if (code === 1) fill = withAlpha(cFrontier, 0.45);
          ctx.fillStyle = fill;
          ctx.fillRect(x, y, cellSize, cellSize);
          // subtle grid lines
          ctx.strokeStyle = cGrid;
          ctx.lineWidth = 0.5;
          ctx.strokeRect(x + 0.25, y + 0.25, cellSize - 0.5, cellSize - 0.5);
        }
      }
      // start / end markers on top
      drawMarker(grid.start.r, grid.start.c, cStart, '►');
      drawMarker(grid.end.r, grid.end.c, cEnd, '◉');

      // counters
      setText('cnt-visited', frame.visited || 0);
      setText('cnt-path', frame.found ? (frame.pathLen || 0) : 0);
      var fl = document.getElementById('found-flag');
      if (fl) {
        var isDone = (index >= frames.length - 1);
        fl.textContent = !isDone ? '—' : (frame.found ? 'path found' : 'no path');
        fl.style.color = !isDone ? 'var(--ink-faint)' : (frame.found ? 'var(--accent)' : 'var(--accent-2)');
      }
    }

    function drawMarker(r, c, color, glyph) {
      var x = originX + c * cellSize, y = originY + r * cellSize;
      ctx.fillStyle = color;
      ctx.fillRect(x, y, cellSize, cellSize);
      ctx.fillStyle = css('--bg');
      ctx.font = '700 ' + Math.floor(cellSize * 0.62) + 'px JetBrains Mono, monospace';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(glyph, x + cellSize / 2, y + cellSize / 2 + 1);
    }

    function withAlpha(hex, a) {
      hex = hex.trim();
      if (hex[0] !== '#') return hex;
      var n = hex.slice(1);
      if (n.length === 3) n = n[0] + n[0] + n[1] + n[1] + n[2] + n[2];
      var r = parseInt(n.slice(0, 2), 16), g = parseInt(n.slice(2, 4), 16), b = parseInt(n.slice(4, 6), 16);
      return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
    }

    function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = v.toLocaleString(); }

    /* ── player ────────────────────────────────────────────── */
    var player = new StepPlayer({
      steps: [],
      speed: window.StepwiseStore ? window.StepwiseStore.get('speed') : 6,
      render: function (i) { draw(i); },
      onState: function () {}
    });
    var transport = bindTransport(player, document);

    function runSearch() {
      var algo = ALGOS[state.algoKey];
      state.steps = algo.run(grid);
      buildFrames(state.steps);
      var pseudo = [];
      for (var i = 0; i < frames.length; i++) pseudo.push({ i: i });
      player.load(pseudo);
      resize();
      draw(0);
      transport.refresh();
    }

    function clearSearchOnly() {
      // reset frames to just-grid so painting shows immediately
      frames = [{ status: {}, visited: 0, pathLen: 0, found: false }];
      player.load([{ i: 0 }]);
      draw(0);
      transport.refresh();
    }

    /* ── painting interaction ──────────────────────────────── */
    var painting = false, paintValue = true, dragging = null;

    function cellAt(ev) {
      var rect = canvas.getBoundingClientRect();
      var px = (ev.touches ? ev.touches[0].clientX : ev.clientX) - rect.left;
      var py = (ev.touches ? ev.touches[0].clientY : ev.clientY) - rect.top;
      var c = Math.floor((px - originX) / cellSize);
      var r = Math.floor((py - originY) / cellSize);
      if (r < 0 || r >= grid.rows || c < 0 || c >= grid.cols) return null;
      return { r: r, c: c };
    }

    function isEndpoint(r, c) {
      return (r === grid.start.r && c === grid.start.c) || (r === grid.end.r && c === grid.end.c);
    }

    function onDown(ev) {
      var cell = cellAt(ev);
      if (!cell) return;
      ev.preventDefault();
      if (state.tool === 'start') { if (!grid.walls[cell.r][cell.c] && !(cell.r===grid.end.r&&cell.c===grid.end.c)) { grid.start = cell; clearSearchOnly(); } return; }
      if (state.tool === 'end') { if (!grid.walls[cell.r][cell.c] && !(cell.r===grid.start.r&&cell.c===grid.start.c)) { grid.end = cell; clearSearchOnly(); } return; }
      if (isEndpoint(cell.r, cell.c)) {
        dragging = (cell.r === grid.start.r && cell.c === grid.start.c) ? 'start' : 'end';
        return;
      }
      painting = true;
      paintValue = (state.tool === 'wall') ? true : false;
      grid.walls[cell.r][cell.c] = paintValue;
      clearSearchOnly();
    }
    function onMove(ev) {
      if (!painting && !dragging) return;
      var cell = cellAt(ev);
      if (!cell) return;
      ev.preventDefault();
      if (dragging) {
        if (grid.walls[cell.r][cell.c]) return;
        if (dragging === 'start' && !(cell.r === grid.end.r && cell.c === grid.end.c)) grid.start = cell;
        if (dragging === 'end' && !(cell.r === grid.start.r && cell.c === grid.start.c)) grid.end = cell;
        clearSearchOnly();
        return;
      }
      if (isEndpoint(cell.r, cell.c)) return;
      grid.walls[cell.r][cell.c] = paintValue;
      draw(0);
    }
    function onUp() { painting = false; dragging = null; }

    canvas.addEventListener('mousedown', onDown);
    canvas.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
    canvas.addEventListener('touchstart', onDown, { passive: false });
    canvas.addEventListener('touchmove', onMove, { passive: false });
    window.addEventListener('touchend', onUp);

    /* ── algorithm picker ──────────────────────────────────── */
    var listEl = document.getElementById('algo-list');
    function buildList() {
      listEl.innerHTML = '';
      Object.keys(ALGOS).forEach(function (k) {
        var a = ALGOS[k];
        var btn = document.createElement('button');
        btn.className = 'algo-item' + (k === state.algoKey ? ' active' : '');
        btn.type = 'button';
        btn.setAttribute('aria-pressed', k === state.algoKey ? 'true' : 'false');
        btn.innerHTML = '<span class="algo-name">' + a.name + '<span class="algo-bigo">' + a.bigo + '</span></span>' +
          '<span class="algo-tag">' + a.tag + '</span>';
        btn.addEventListener('click', function () {
          state.algoKey = k; buildList(); renderDesc(); runSearch();
        });
        listEl.appendChild(btn);
      });
    }
    function renderDesc() {
      var a = ALGOS[state.algoKey];
      var box = document.getElementById('algo-desc');
      if (!box) return;
      box.innerHTML = '<p class="desc-body">' + a.desc + '</p>' +
        '<div class="complexity-grid">' +
          '<div class="cx"><div class="cx-label">Time</div><div class="cx-val">' + a.bigo + '</div></div>' +
          '<div class="cx ' + (a.optimal.indexOf('No') === 0 ? 'bad' : '') + '"><div class="cx-label">Shortest path</div><div class="cx-val">' + a.optimal + '</div></div>' +
        '</div>';
    }

    /* ── toolbar / buttons ─────────────────────────────────── */
    document.querySelectorAll('[data-tool]').forEach(function (t) {
      t.addEventListener('click', function () {
        state.tool = t.getAttribute('data-tool');
        document.querySelectorAll('[data-tool]').forEach(function (x) {
          x.classList.toggle('active', x === t);
          x.setAttribute('aria-pressed', x === t ? 'true' : 'false');
        });
      });
    });

    var runBtn = document.getElementById('run-btn');
    if (runBtn) runBtn.addEventListener('click', function () { runSearch(); player.play(); });

    var clearBtn = document.getElementById('clear-btn');
    if (clearBtn) clearBtn.addEventListener('click', function () {
      allocGrid(); clearSearchOnly();
    });
    var clearPathBtn = document.getElementById('clearpath-btn');
    if (clearPathBtn) clearPathBtn.addEventListener('click', function () { clearSearchOnly(); });

    var mazeSel = document.getElementById('maze-select');
    var genBtn = document.getElementById('gen-btn');
    if (genBtn) genBtn.addEventListener('click', function () {
      if (mazeSel && mazeSel.value === 'prim') genPrim(grid); else genBacktracker(grid);
      // ensure start/end are not enclosed
      grid.walls[grid.start.r][grid.start.c] = false;
      grid.walls[grid.end.r][grid.end.c] = false;
      clearSearchOnly();
    });

    var sizeInput = document.getElementById('grid-size');
    var sizeVal = document.getElementById('grid-size-val');
    if (sizeInput) {
      sizeInput.value = grid.cols;
      sizeVal.textContent = grid.cols + '×' + grid.rows;
      sizeInput.addEventListener('input', function () {
        var cols = parseInt(sizeInput.value, 10);
        if (cols % 2 === 0) cols++;
        grid.cols = cols;
        grid.rows = Math.max(11, Math.round(cols * 0.6));
        if (grid.rows % 2 === 0) grid.rows++;
        grid.start = { r: (grid.rows >> 1), c: 4 };
        grid.end = { r: (grid.rows >> 1), c: grid.cols - 5 };
        sizeVal.textContent = grid.cols + '×' + grid.rows;
        allocGrid();
        clearSearchOnly();
      });
    }

    /* ── responsive ────────────────────────────────────────── */
    var rto;
    window.addEventListener('resize', function () {
      clearTimeout(rto);
      rto = setTimeout(function () { resize(); draw(player.index); }, 80);
    });

    /* ── boot: generate a starter maze ─────────────────────── */
    buildList();
    renderDesc();
    genBacktracker(grid);
    grid.walls[grid.start.r][grid.start.c] = false;
    grid.walls[grid.end.r][grid.end.c] = false;
    clearSearchOnly();
    resize();
    runSearch();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else { init(); }
})();
