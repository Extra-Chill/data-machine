/* =========================================================
   STEPWISE — Binary Search Tree visualizer

   Inserts a sequence of keys into a BST one at a time, recording
   every comparison and descent so you can watch the tree grow.
   You can also run the three depth-first traversals (in-order,
   pre-order, post-order) and watch the visit order, plus search
   for a key and watch the O(h) descent.

   Recorded step types:
     'insert-compare' {key, node}          — comparing new key vs node
     'insert-place'   {key, node, side}    — placed key as child
     'visit'          {node, order}        — traversal / search touch
     'found'/'notfound' {key}              — search outcome
     'done'

   Each node has {key, x, y, left, right, id}. Positions are
   computed by an in-order layout so x increases left→right and
   y increases with depth. The renderer interpolates nothing —
   it just draws the tree state implied by the current step.
   ========================================================= */
(function () {
  'use strict';

  /* ── BST core (records steps while mutating) ────────────── */
  function BST() { this.root = null; this.nextId = 1; this.size = 0; }

  BST.prototype.insert = function (key, steps) {
    var node = { key: key, left: null, right: null, id: this.nextId++ };
    if (!this.root) {
      this.root = node;
      this.size++;
      if (steps) steps.push({ type: 'insert-place', key: key, node: node.id, side: 'root' });
      return node;
    }
    var cur = this.root;
    while (true) {
      if (steps) steps.push({ type: 'insert-compare', key: key, node: cur.id });
      if (key === cur.key) {
        // ignore duplicates (BST keeps unique keys here)
        if (steps) steps.push({ type: 'duplicate', key: key, node: cur.id });
        return null;
      }
      if (key < cur.key) {
        if (!cur.left) { cur.left = node; this.size++; if (steps) steps.push({ type: 'insert-place', key: key, node: cur.id, side: 'left', placed: node.id }); return node; }
        cur = cur.left;
      } else {
        if (!cur.right) { cur.right = node; this.size++; if (steps) steps.push({ type: 'insert-place', key: key, node: cur.id, side: 'right', placed: node.id }); return node; }
        cur = cur.right;
      }
    }
  };

  BST.prototype.search = function (key, steps) {
    var cur = this.root;
    while (cur) {
      steps.push({ type: 'visit', node: cur.id });
      if (key === cur.key) { steps.push({ type: 'found', key: key, node: cur.id }); return true; }
      cur = (key < cur.key) ? cur.left : cur.right;
    }
    steps.push({ type: 'notfound', key: key });
    return false;
  };

  BST.prototype.traverse = function (order, steps) {
    var self = this;
    function rec(n) {
      if (!n) return;
      if (order === 'pre') steps.push({ type: 'visit', node: n.id });
      rec(n.left);
      if (order === 'in') steps.push({ type: 'visit', node: n.id });
      rec(n.right);
      if (order === 'post') steps.push({ type: 'visit', node: n.id });
    }
    rec(this.root);
  };

  // height (for stats)
  function height(n) { return n ? 1 + Math.max(height(n.left), height(n.right)) : 0; }

  /* ── layout: in-order x assignment, depth y ─────────────── */
  function layout(root) {
    var positions = {}; // id -> {x,y,depth,inorder}
    var counter = { i: 0 };
    function rec(n, depth) {
      if (!n) return;
      rec(n.left, depth + 1);
      positions[n.id] = { x: counter.i++, depth: depth, key: n.key, node: n };
      rec(n.right, depth + 1);
    }
    rec(root, 0);
    return { positions: positions, width: counter.i, height: height(root) };
  }

  /* ── build frames from a (tree-state, steps) trace ──────── */
  // We snapshot the tree structure at the moment each step occurs.
  // Because insertion grows the tree, we rebuild the visible node
  // set per frame from the cumulative placed ids.
  function init() {
    var canvas = document.getElementById('tree-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');

    var state = {
      tree: new BST(),
      steps: [],
      frames: [],     // frame i -> { activeNode, sideNote, visitedOrder:[], message, visibleIds:Set }
      mode: 'insert'
    };

    function css(v) { return getComputedStyle(document.documentElement).getPropertyValue(v).trim(); }

    /* Rebuild the whole trace for the CURRENT tree contents by
       replaying a list of keys; used for the "insert sequence"
       action. For search/traverse we record against the existing
       tree without mutating it. */

    function buildInsertTrace(keys) {
      state.tree = new BST();
      state.steps = [];
      var visible = new Set();
      var frames = [{ visible: new Set(), active: null, compareWith: null, message: 'Empty tree — ready to insert.', highlight: null }];
      for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        var pre = state.steps.length;
        state.tree.insert(k, state.steps);
        // turn the new steps into frames, growing `visible`
        for (var s = pre; s < state.steps.length; s++) {
          var st = state.steps[s];
          var msg = '', active = null, cmp = null, hl = null;
          if (st.type === 'insert-compare') {
            active = st.node; cmp = st.key;
            msg = 'Insert ' + st.key + ': compare with ' + nodeKey(st.node) + '. ' +
                  (st.key < nodeKey(st.node) ? st.key + ' < ' + nodeKey(st.node) + ' → go left.'
                                             : st.key + ' > ' + nodeKey(st.node) + ' → go right.');
            hl = { kind: 'compare', node: st.node };
          } else if (st.type === 'insert-place') {
            // st.node is the parent (or the root node id when side==='root')
            if (st.side === 'root') visible.add(st.node);
            if (st.placed) visible.add(st.placed);
            active = st.placed || st.node;
            msg = (st.side === 'root')
              ? 'Insert ' + st.key + ': tree was empty → ' + st.key + ' becomes the root.'
              : 'Insert ' + st.key + ': empty ' + st.side + ' child of ' + nodeKey(st.node) + ' → place ' + st.key + ' there.';
            hl = { kind: 'place', node: active };
          } else if (st.type === 'duplicate') {
            active = st.node;
            msg = st.key + ' already in the tree — duplicates ignored.';
            hl = { kind: 'dup', node: st.node };
          }
          frames.push({ visible: new Set(visible), active: active, compareWith: cmp, message: msg, highlight: hl });
        }
      }
      frames.push({ visible: new Set(visible), active: null, compareWith: null, message: 'Done. ' + state.tree.size + ' nodes, height ' + height(state.tree.root) + '.', highlight: null });
      state.frames = frames;
    }

    function allIds() {
      var s = new Set();
      (function rec(n) { if (!n) return; s.add(n.id); rec(n.left); rec(n.right); })(state.tree.root);
      return s;
    }
    function nodeKey(id) { var n = findNode(id); return n ? n.key : '?'; }
    function findNode(id) {
      var found = null;
      (function rec(n) { if (!n || found) return; if (n.id === id) { found = n; return; } rec(n.left); rec(n.right); })(state.tree.root);
      return found;
    }

    function buildTraversalTrace(order) {
      // record against existing tree, all nodes visible throughout
      state.steps = [];
      if (order === 'search') return; // handled separately
      state.tree.traverse(order, state.steps);
      var visible = allIds();
      var visited = [];
      var frames = [{ visible: visible, active: null, visited: [], message: cap(order) + '-order traversal — press play.' , highlight: null}];
      for (var s = 0; s < state.steps.length; s++) {
        var st = state.steps[s];
        visited = visited.concat([nodeKey(st.node)]);
        frames.push({
          visible: visible, active: st.node, visited: visited.slice(),
          message: 'Visit ' + nodeKey(st.node) + '  →  [ ' + visited.join(', ') + ' ]',
          highlight: { kind: 'visit', node: st.node }
        });
      }
      frames.push({ visible: visible, active: null, visited: visited.slice(),
        message: cap(order) + '-order result: [ ' + visited.join(', ') + ' ]', highlight: null });
      state.frames = frames;
    }

    function buildSearchTrace(key) {
      state.steps = [];
      state.tree.search(key, state.steps);
      var visible = allIds();
      var frames = [{ visible: visible, active: null, message: 'Search for ' + key + ' — press play.', highlight: null }];
      for (var s = 0; s < state.steps.length; s++) {
        var st = state.steps[s];
        if (st.type === 'visit') {
          var nk = nodeKey(st.node);
          var dir = key === nk ? 'match!' : (key < nk ? key + ' < ' + nk + ' → left' : key + ' > ' + nk + ' → right');
          frames.push({ visible: visible, active: st.node, message: 'At ' + nk + ': ' + dir, highlight: { kind: 'search', node: st.node } });
        } else if (st.type === 'found') {
          frames.push({ visible: visible, active: st.node, message: 'Found ' + key + ' ✓', highlight: { kind: 'found', node: st.node } });
        } else if (st.type === 'notfound') {
          frames.push({ visible: visible, active: null, message: key + ' is not in the tree.', highlight: null });
        }
      }
      state.frames = frames;
    }

    function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

    /* ── renderer ──────────────────────────────────────────── */
    function resize() {
      var dpr = window.devicePixelRatio || 1;
      var rect = canvas.getBoundingClientRect();
      canvas.width = Math.floor(rect.width * dpr);
      canvas.height = Math.floor(rect.height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function draw(index) {
      var rect = canvas.getBoundingClientRect();
      var W = rect.width, H = rect.height;
      ctx.clearRect(0, 0, W, H);
      var frame = state.frames[Math.max(0, Math.min(state.frames.length - 1, index))];
      if (!frame) { updateStats(frame); return; }

      var lay = layout(state.tree.root);
      if (lay.width === 0) {
        ctx.fillStyle = css('--ink-faint');
        ctx.font = '500 14px Space Grotesk, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Tree is empty — insert some keys.', W / 2, H / 2);
        updateStats(frame); setMessage(frame.message);
        return;
      }

      var padX = 36, padY = 40;
      var cols = Math.max(1, lay.width - 1);
      var rows = Math.max(1, lay.height - 1);
      var stepX = (W - padX * 2) / Math.max(1, cols);
      var stepY = (H - padY * 2) / Math.max(1, rows);
      stepY = Math.min(stepY, 86);
      var radius = Math.max(11, Math.min(20, stepX * 0.4, 22));

      var pos = lay.positions;
      function xy(id) {
        var p = pos[id];
        return { x: padX + p.x * stepX, y: padY + p.depth * stepY };
      }

      var visible = frame.visible || allIds();

      // edges first
      ctx.strokeStyle = css('--line');
      ctx.lineWidth = 1.5;
      (function rec(n) {
        if (!n) return;
        if (visible.has(n.id)) {
          var a = xy(n.id);
          if (n.left && visible.has(n.left.id)) { var bl = xy(n.left.id); line(a, bl); }
          if (n.right && visible.has(n.right.id)) { var br = xy(n.right.id); line(a, br); }
        }
        rec(n.left); rec(n.right);
      })(state.tree.root);

      // nodes
      var hl = frame.highlight || {};
      var cNode = css('--bg-panel2');
      var cText = css('--ink');
      for (var id in pos) {
        id = +id;
        if (!visible.has(id)) continue;
        var p = xy(id);
        var ring = css('--line');
        var fill = cNode;
        if (hl.node === id) {
          if (hl.kind === 'compare' || hl.kind === 'search') { ring = css('--accent-3'); fill = withAlpha(css('--accent-3'), 0.18); }
          else if (hl.kind === 'place') { ring = css('--accent'); fill = withAlpha(css('--accent'), 0.2); }
          else if (hl.kind === 'visit') { ring = css('--accent-4'); fill = withAlpha(css('--accent-4'), 0.2); }
          else if (hl.kind === 'found') { ring = css('--accent'); fill = withAlpha(css('--accent'), 0.3); }
          else if (hl.kind === 'dup') { ring = css('--accent-2'); fill = withAlpha(css('--accent-2'), 0.2); }
        }
        ctx.beginPath();
        ctx.arc(p.x, p.y, radius, 0, Math.PI * 2);
        ctx.fillStyle = fill; ctx.fill();
        ctx.lineWidth = (hl.node === id) ? 3 : 1.5;
        ctx.strokeStyle = ring; ctx.stroke();
        ctx.fillStyle = cText;
        ctx.font = '600 ' + Math.floor(radius * 0.85) + 'px JetBrains Mono, monospace';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(String(pos[id].key), p.x, p.y + 1);
      }

      function line(a, b) { ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke(); }

      updateStats(frame);
      setMessage(frame.message);
    }

    function withAlpha(hex, a) {
      hex = hex.trim(); if (hex[0] !== '#') return hex;
      var n = hex.slice(1); if (n.length === 3) n = n[0]+n[0]+n[1]+n[1]+n[2]+n[2];
      return 'rgba(' + parseInt(n.slice(0,2),16) + ',' + parseInt(n.slice(2,4),16) + ',' + parseInt(n.slice(4,6),16) + ',' + a + ')';
    }

    function updateStats() {
      setText('cnt-nodes', state.tree.size);
      setText('cnt-height', height(state.tree.root));
      var bf = bestHeight(state.tree.size);
      setText('cnt-balanced', bf);
    }
    function bestHeight(n) { return n === 0 ? 0 : Math.floor(Math.log2(n)) + 1; }
    function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = (typeof v === 'number' ? v.toLocaleString() : v); }
    function setMessage(m) { var el = document.getElementById('tree-message'); if (el && m != null) el.textContent = m; }

    /* ── player ────────────────────────────────────────────── */
    var player = new StepPlayer({
      steps: [],
      speed: window.StepwiseStore ? window.StepwiseStore.get('speed') : 6,
      render: function (i) { draw(i); },
      onState: function () {}
    });
    var transport = bindTransport(player, document);

    function loadFrames() {
      var pseudo = [];
      for (var i = 0; i < state.frames.length; i++) pseudo.push({ i: i });
      player.load(pseudo);
      resize();
      draw(0);
      transport.refresh();
    }

    /* ── actions ───────────────────────────────────────────── */
    function doInsertSequence(keys) {
      buildInsertTrace(keys);
      loadFrames();
    }

    var keyInput = document.getElementById('keys-input');
    var insertBtn = document.getElementById('insert-btn');
    if (insertBtn) insertBtn.addEventListener('click', function () {
      var keys = parseKeys(keyInput.value);
      if (keys.length) { doInsertSequence(keys); player.play(); }
    });

    var randomBtn = document.getElementById('random-btn');
    if (randomBtn) randomBtn.addEventListener('click', function () {
      var n = 9, set = {}, keys = [];
      while (keys.length < n) { var v = Math.floor(5 + Math.random() * 94); if (!set[v]) { set[v] = 1; keys.push(v); } }
      if (keyInput) keyInput.value = keys.join(', ');
      doInsertSequence(keys); player.play();
    });

    var balancedBtn = document.getElementById('balanced-btn');
    if (balancedBtn) balancedBtn.addEventListener('click', function () {
      // insert sorted values in a balanced order for a tidy tree
      var vals = [10, 20, 30, 40, 50, 60, 70];
      var order = [];
      (function rec(lo, hi) { if (lo > hi) return; var mid = (lo + hi) >> 1; order.push(vals[mid]); rec(lo, mid - 1); rec(mid + 1, hi); })(0, vals.length - 1);
      if (keyInput) keyInput.value = order.join(', ');
      doInsertSequence(order); player.play();
    });

    var degenerateBtn = document.getElementById('degenerate-btn');
    if (degenerateBtn) degenerateBtn.addEventListener('click', function () {
      var keys = [10, 20, 30, 40, 50, 60, 70];
      if (keyInput) keyInput.value = keys.join(', ');
      doInsertSequence(keys); player.play();
    });

    // traversal / search operate on the CURRENT tree
    document.querySelectorAll('[data-traverse]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!state.tree.root) return;
        buildTraversalTrace(b.getAttribute('data-traverse'));
        loadFrames(); player.play();
      });
    });

    var searchInput = document.getElementById('search-input');
    var searchBtn = document.getElementById('search-btn');
    if (searchBtn) searchBtn.addEventListener('click', function () {
      if (!state.tree.root) return;
      var k = parseInt(searchInput.value, 10);
      if (isNaN(k)) return;
      buildSearchTrace(k); loadFrames(); player.play();
    });

    /* ── helpers ───────────────────────────────────────────── */
    function parseKeys(str) {
      if (!str) return [];
      return String(str).split(/[\s,]+/).map(function (s) { return parseInt(s, 10); })
        .filter(function (n) { return !isNaN(n) && n >= 0 && n <= 999; });
    }

    var rto;
    window.addEventListener('resize', function () {
      clearTimeout(rto);
      rto = setTimeout(function () { resize(); draw(player.index); }, 80);
    });

    /* ── boot with a sample tree ───────────────────────────── */
    if (keyInput && !keyInput.value) keyInput.value = '50, 30, 70, 20, 40, 60, 80, 35, 65';
    doInsertSequence(parseKeys(keyInput ? keyInput.value : '50,30,70,20,40,60,80,35,65'));
    player.toEnd();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else { init(); }
})();
