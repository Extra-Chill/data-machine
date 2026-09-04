/* =========================================================
   STEPWISE — Sorting visualizer

   Each algorithm sorts a COPY of the array and records a flat
   list of steps. A step is { type, ... } where type is one of:
     'compare'  i, j            — comparing two positions
     'swap'     i, j            — swapped two positions
     'overwrite' i, value       — wrote value into position i (merge sort)
     'pivot'    i               — marked a pivot (quick sort)
     'sorted'   indices[]       — these positions are finalised
     'done'                     — terminal

   The renderer reconstructs array state by replaying the array
   mutations up to a given step (cached), then draws bars.
   Counters (comparisons / swaps / array accesses) are derived
   from the trace so back-stepping stays consistent.
   ========================================================= */
(function () {
  'use strict';

  /* ── algorithm catalogue ────────────────────────────────── */
  var ALGOS = {
    bubble: {
      name: 'Bubble Sort',
      tag: 'Adjacent swaps bubble large values up',
      stable: true, inplace: true,
      best: 'O(n)', avg: 'O(n²)', worst: 'O(n²)', space: 'O(1)',
      bigo: 'O(n²)', complexityClass: { best: 'good', avg: 'bad', worst: 'bad' },
      desc: 'Repeatedly walks the array, swapping any adjacent pair that is out of order. After each pass the largest remaining value has "bubbled" to its final place at the end. A pass with zero swaps means the array is already sorted, giving a best case of O(n) on nearly-sorted input.',
      run: bubbleSort
    },
    insertion: {
      name: 'Insertion Sort',
      tag: 'Grows a sorted prefix one card at a time',
      stable: true, inplace: true,
      best: 'O(n)', avg: 'O(n²)', worst: 'O(n²)', space: 'O(1)',
      bigo: 'O(n²)', complexityClass: { best: 'good', avg: 'bad', worst: 'bad' },
      desc: 'Treats the left portion as sorted and inserts each new element into its correct slot by shifting larger elements right — exactly how most people sort a hand of cards. Excellent on small or nearly-sorted arrays and used as the base case inside faster sorts.',
      run: insertionSort
    },
    selection: {
      name: 'Selection Sort',
      tag: 'Selects the minimum each pass',
      stable: false, inplace: true,
      best: 'O(n²)', avg: 'O(n²)', worst: 'O(n²)', space: 'O(1)',
      bigo: 'O(n²)', complexityClass: { best: 'bad', avg: 'bad', worst: 'bad' },
      desc: 'Scans the unsorted region to find the smallest remaining element, then swaps it into the front. It always performs the same number of comparisons regardless of input order, but makes at most n−1 swaps — handy when writes are expensive.',
      run: selectionSort
    },
    merge: {
      name: 'Merge Sort',
      tag: 'Divide, sort halves, merge',
      stable: true, inplace: false,
      best: 'O(n log n)', avg: 'O(n log n)', worst: 'O(n log n)', space: 'O(n)',
      bigo: 'O(n log n)', complexityClass: { best: 'good', avg: 'good', worst: 'good' },
      desc: 'A divide-and-conquer sort: split the array in half, recursively sort each half, then merge the two sorted halves in linear time. Guarantees O(n log n) in every case and is stable, at the cost of O(n) auxiliary space for the merge buffer.',
      run: mergeSort
    },
    quick: {
      name: 'Quick Sort',
      tag: 'Partition around a pivot',
      stable: false, inplace: true,
      best: 'O(n log n)', avg: 'O(n log n)', worst: 'O(n²)', space: 'O(log n)',
      bigo: 'O(n log n)', complexityClass: { best: 'good', avg: 'good', worst: 'bad' },
      desc: 'Picks a pivot (here, the last element via Lomuto partition), moves smaller values left and larger values right, then recurses on each side. Usually the fastest comparison sort in practice; pathological pivots can degrade it to O(n²).',
      run: quickSort
    },
    heap: {
      name: 'Heap Sort',
      tag: 'Build a max-heap, extract the root',
      stable: false, inplace: true,
      best: 'O(n log n)', avg: 'O(n log n)', worst: 'O(n log n)', space: 'O(1)',
      bigo: 'O(n log n)', complexityClass: { best: 'good', avg: 'good', worst: 'good' },
      desc: 'Builds a binary max-heap in place, then repeatedly swaps the root (the maximum) to the end and sifts the new root down to restore the heap. Achieves O(n log n) worst case with O(1) extra space, though its scattered access pattern makes it slower than quick sort in practice.',
      run: heapSort
    }
  };

  /* ── step recorder ──────────────────────────────────────── */
  function Recorder(arr) {
    this.a = arr.slice();
    this.steps = [];
  }
  Recorder.prototype.compare = function (i, j) {
    this.steps.push({ type: 'compare', i: i, j: j });
  };
  Recorder.prototype.swap = function (i, j) {
    var t = this.a[i]; this.a[i] = this.a[j]; this.a[j] = t;
    this.steps.push({ type: 'swap', i: i, j: j });
  };
  Recorder.prototype.overwrite = function (i, v) {
    this.a[i] = v;
    this.steps.push({ type: 'overwrite', i: i, value: v });
  };
  Recorder.prototype.pivot = function (i) {
    this.steps.push({ type: 'pivot', i: i });
  };
  Recorder.prototype.markSorted = function (idx) {
    this.steps.push({ type: 'sorted', indices: Array.isArray(idx) ? idx.slice() : [idx] });
  };
  Recorder.prototype.done = function () {
    var all = [];
    for (var i = 0; i < this.a.length; i++) all.push(i);
    this.steps.push({ type: 'sorted', indices: all });
    this.steps.push({ type: 'done' });
  };

  /* ── the six algorithms (correct, in-place on rec.a) ────── */

  function bubbleSort(input) {
    var rec = new Recorder(input);
    var a = rec.a, n = a.length;
    for (var i = 0; i < n - 1; i++) {
      var swapped = false;
      for (var j = 0; j < n - 1 - i; j++) {
        rec.compare(j, j + 1);
        if (a[j] > a[j + 1]) { rec.swap(j, j + 1); swapped = true; }
      }
      rec.markSorted(n - 1 - i);
      if (!swapped) break;
    }
    rec.done();
    return rec.steps;
  }

  function insertionSort(input) {
    var rec = new Recorder(input);
    var a = rec.a, n = a.length;
    rec.markSorted(0);
    for (var i = 1; i < n; i++) {
      var key = a[i];
      var j = i - 1;
      while (j >= 0) {
        rec.compare(j, j + 1);
        if (a[j] > key) {
          rec.swap(j, j + 1); // shift right by swapping into the hole
          j--;
        } else break;
      }
    }
    rec.done();
    return rec.steps;
  }

  function selectionSort(input) {
    var rec = new Recorder(input);
    var a = rec.a, n = a.length;
    for (var i = 0; i < n - 1; i++) {
      var min = i;
      for (var j = i + 1; j < n; j++) {
        rec.compare(min, j);
        if (a[j] < a[min]) min = j;
      }
      if (min !== i) rec.swap(i, min);
      rec.markSorted(i);
    }
    rec.markSorted(n - 1);
    rec.done();
    return rec.steps;
  }

  function mergeSort(input) {
    var rec = new Recorder(input);
    var a = rec.a;
    function merge(lo, mid, hi) {
      var left = a.slice(lo, mid + 1);
      var right = a.slice(mid + 1, hi + 1);
      var i = 0, j = 0, k = lo;
      while (i < left.length && j < right.length) {
        // compare positions in the original array space for the viz
        rec.steps.push({ type: 'compare', i: lo + i, j: mid + 1 + j });
        if (left[i] <= right[j]) { rec.overwrite(k++, left[i++]); }
        else { rec.overwrite(k++, right[j++]); }
      }
      while (i < left.length) rec.overwrite(k++, left[i++]);
      while (j < right.length) rec.overwrite(k++, right[j++]);
    }
    function sort(lo, hi) {
      if (lo >= hi) return;
      var mid = (lo + hi) >> 1;
      sort(lo, mid);
      sort(mid + 1, hi);
      merge(lo, mid, hi);
    }
    sort(0, a.length - 1);
    rec.done();
    return rec.steps;
  }

  function quickSort(input) {
    var rec = new Recorder(input);
    var a = rec.a;
    function partition(lo, hi) {
      var pivot = a[hi];
      rec.pivot(hi);
      var i = lo - 1;
      for (var j = lo; j < hi; j++) {
        rec.compare(j, hi);
        if (a[j] < pivot) {
          i++;
          if (i !== j) rec.swap(i, j);
        }
      }
      if (i + 1 !== hi) rec.swap(i + 1, hi);
      return i + 1;
    }
    function sort(lo, hi) {
      if (lo >= hi) {
        if (lo === hi && lo >= 0 && lo < a.length) rec.markSorted(lo);
        return;
      }
      var p = partition(lo, hi);
      rec.markSorted(p);
      sort(lo, p - 1);
      sort(p + 1, hi);
    }
    sort(0, a.length - 1);
    rec.done();
    return rec.steps;
  }

  function heapSort(input) {
    var rec = new Recorder(input);
    var a = rec.a, n = a.length;
    function siftDown(root, size) {
      while (true) {
        var largest = root, l = 2 * root + 1, r = 2 * root + 2;
        if (l < size) { rec.compare(largest, l); if (a[l] > a[largest]) largest = l; }
        if (r < size) { rec.compare(largest, r); if (a[r] > a[largest]) largest = r; }
        if (largest === root) break;
        rec.swap(root, largest);
        root = largest;
      }
    }
    for (var i = (n >> 1) - 1; i >= 0; i--) siftDown(i, n);
    for (var end = n - 1; end > 0; end--) {
      rec.swap(0, end);
      rec.markSorted(end);
      siftDown(0, end);
    }
    rec.markSorted(0);
    rec.done();
    return rec.steps;
  }

  /* ── trace → per-step array snapshots & counters ────────── */
  // Precompute, for every step, the array state and cumulative
  // counters, plus the set of "sorted" indices so far.
  function buildFrames(initial, steps) {
    var frames = [];
    var a = initial.slice();
    var sorted = new Set();
    var comparisons = 0, swaps = 0, accesses = 0;
    // frame 0 is the initial untouched state
    frames.push({
      array: a.slice(), sorted: new Set(),
      comparisons: 0, swaps: 0, accesses: 0,
      highlight: null
    });
    for (var s = 0; s < steps.length; s++) {
      var st = steps[s];
      var hl = null;
      if (st.type === 'compare') {
        comparisons++; accesses += 2;
        hl = { kind: 'compare', i: st.i, j: st.j };
      } else if (st.type === 'swap') {
        swaps++; accesses += 4; // 2 reads + 2 writes
        var t = a[st.i]; a[st.i] = a[st.j]; a[st.j] = t;
        hl = { kind: 'swap', i: st.i, j: st.j };
      } else if (st.type === 'overwrite') {
        accesses += 1;
        a[st.i] = st.value;
        hl = { kind: 'overwrite', i: st.i };
      } else if (st.type === 'pivot') {
        hl = { kind: 'pivot', i: st.i };
      } else if (st.type === 'sorted') {
        st.indices.forEach(function (k) { sorted.add(k); });
        hl = { kind: 'sorted', indices: st.indices };
      } else if (st.type === 'done') {
        hl = { kind: 'done' };
      }
      frames.push({
        array: a.slice(),
        sorted: new Set(sorted),
        comparisons: comparisons,
        swaps: swaps,
        accesses: accesses,
        highlight: hl
      });
    }
    return frames;
  }

  /* ── canvas renderer ────────────────────────────────────── */
  function makeRenderer(canvas, getFrames, getState) {
    var ctx = canvas.getContext('2d');
    function css(v) { return getComputedStyle(document.documentElement).getPropertyValue(v).trim(); }

    function resize() {
      var dpr = window.devicePixelRatio || 1;
      var rect = canvas.getBoundingClientRect();
      canvas.width = Math.max(1, Math.floor(rect.width * dpr));
      canvas.height = Math.max(1, Math.floor(rect.height * dpr));
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function draw(index) {
      var frames = getFrames();
      if (!frames || !frames.length) return;
      var f = frames[Math.max(0, Math.min(frames.length - 1, index))];
      var arr = f.array;
      var rect = canvas.getBoundingClientRect();
      var W = rect.width, H = rect.height;
      ctx.clearRect(0, 0, W, H);

      var n = arr.length;
      var maxV = Math.max.apply(null, arr) || 1;
      var pad = 8;
      var gap = n > 60 ? 1 : 2;
      var bw = (W - pad * 2 - gap * (n - 1)) / n;
      var baseColor = css('--ink-faint');
      var cCompare = css('--accent-3');
      var cSwap = css('--accent-2');
      var cPivot = css('--accent-4');
      var cSorted = css('--accent');

      var hl = f.highlight || {};
      var active = {};
      if (hl.kind === 'compare' || hl.kind === 'swap') { active[hl.i] = hl.kind; active[hl.j] = hl.kind; }
      if (hl.kind === 'overwrite') active[hl.i] = 'overwrite';
      var pivotIdx = (hl.kind === 'pivot') ? hl.i : -1;

      for (var i = 0; i < n; i++) {
        var v = arr[i];
        var bh = (v / maxV) * (H - pad * 2 - 18);
        var x = pad + i * (bw + gap);
        var y = H - pad - bh;
        var color = baseColor;
        if (f.sorted.has(i)) color = cSorted;
        if (i === pivotIdx) color = cPivot;
        if (active[i] === 'compare') color = cCompare;
        if (active[i] === 'swap') color = cSwap;
        if (active[i] === 'overwrite') color = cSwap;

        ctx.fillStyle = color;
        roundRect(ctx, x, y, Math.max(1, bw), bh, Math.min(3, bw / 2));
        ctx.fill();

        // value label only when bars are wide enough
        if (bw >= 22) {
          ctx.fillStyle = css('--ink');
          ctx.font = '600 11px JetBrains Mono, monospace';
          ctx.textAlign = 'center';
          ctx.fillText(String(v), x + bw / 2, H - pad + 2);
        }
      }
    }

    function roundRect(c, x, y, w, h, r) {
      r = Math.min(r, w / 2, h / 2); if (r < 0) r = 0;
      c.beginPath();
      c.moveTo(x + r, y);
      c.arcTo(x + w, y, x + w, y + h, r);
      c.arcTo(x + w, y + h, x, y + h, r);
      c.arcTo(x, y + h, x, y, r);
      c.arcTo(x, y, x + w, y, r);
      c.closePath();
    }

    return { resize: resize, draw: draw };
  }

  /* ── controller / page wiring ───────────────────────────── */
  function init() {
    var canvas = document.getElementById('sort-canvas');
    if (!canvas) return;

    var state = {
      algoKey: 'bubble',
      size: 24,
      initial: [],
      frames: []
    };

    function genArray(n) {
      var arr = [];
      for (var i = 0; i < n; i++) arr.push(Math.floor(8 + Math.random() * 92));
      return arr;
    }

    var renderer = makeRenderer(canvas, function () { return state.frames; }, function () { return state; });

    function updateCounters(index) {
      var f = state.frames[Math.max(0, Math.min(state.frames.length - 1, index))];
      if (!f) return;
      setText('cnt-compare', f.comparisons);
      setText('cnt-swap', f.swaps);
      setText('cnt-access', f.accesses);
    }
    function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = v.toLocaleString(); }

    var player = new StepPlayer({
      steps: [],
      speed: window.StepwiseStore ? window.StepwiseStore.get('speed') : 6,
      render: function (i) { renderer.draw(i); updateCounters(i); },
      onState: function () {}
    });

    var transport = bindTransport(player, document);

    function rebuild(newArray) {
      var algo = ALGOS[state.algoKey];
      if (newArray) state.initial = newArray;
      var steps = algo.run(state.initial);
      state.frames = buildFrames(state.initial, steps);
      // The player drives one "step" per frame index (frames includes frame 0).
      // We model each frame as a trivial step so the scrubber spans 0..frames-1.
      var pseudo = [];
      for (var i = 0; i < state.frames.length; i++) pseudo.push({ i: i });
      player.load(pseudo);
      renderer.resize();
      renderer.draw(0);
      updateCounters(0);
      transport.refresh();
    }

    /* algorithm picker */
    var listEl = document.getElementById('algo-list');
    function buildList() {
      listEl.innerHTML = '';
      Object.keys(ALGOS).forEach(function (key) {
        var a = ALGOS[key];
        var btn = document.createElement('button');
        btn.className = 'algo-item' + (key === state.algoKey ? ' active' : '');
        btn.type = 'button';
        btn.setAttribute('aria-pressed', key === state.algoKey ? 'true' : 'false');
        btn.innerHTML =
          '<span class="algo-name">' + a.name + '<span class="algo-bigo">' + a.bigo + '</span></span>' +
          '<span class="algo-tag">' + a.tag + '</span>';
        btn.addEventListener('click', function () {
          state.algoKey = key;
          buildList();
          renderDesc();
          rebuild(); // same array, new algorithm
        });
        listEl.appendChild(btn);
      });
    }

    function renderDesc() {
      var a = ALGOS[state.algoKey];
      var box = document.getElementById('algo-desc');
      if (!box) return;
      function cx(label, val, cls) {
        return '<div class="cx ' + (cls || '') + '"><div class="cx-label">' + label +
          '</div><div class="cx-val">' + val + '</div></div>';
      }
      box.innerHTML =
        '<p class="desc-body">' + a.desc + '</p>' +
        '<div class="complexity-grid">' +
          cx('Best', a.best, a.complexityClass.best === 'good' ? '' : (a.complexityClass.best === 'bad' ? 'bad' : 'mid')) +
          cx('Average', a.avg, a.complexityClass.avg === 'good' ? '' : (a.complexityClass.avg === 'bad' ? 'bad' : 'mid')) +
          cx('Worst', a.worst, a.complexityClass.worst === 'good' ? '' : (a.complexityClass.worst === 'bad' ? 'bad' : 'mid')) +
          cx('Space', a.space, '') +
        '</div>' +
        '<p class="desc-body" style="margin-top:.7rem;font-size:.82rem;color:var(--ink-faint)">' +
          (a.stable ? 'Stable' : 'Not stable') + ' · ' +
          (a.inplace ? 'In-place' : 'Out-of-place') + '</p>';
    }

    /* size control */
    var sizeInput = document.getElementById('size-input');
    var sizeVal = document.getElementById('size-val');
    if (sizeInput) {
      sizeInput.value = state.size;
      sizeVal.textContent = state.size;
      sizeInput.addEventListener('input', function () {
        state.size = parseInt(sizeInput.value, 10);
        sizeVal.textContent = state.size;
        rebuild(genArray(state.size));
      });
    }

    /* new array + presets */
    var shuffleBtn = document.getElementById('shuffle-btn');
    if (shuffleBtn) shuffleBtn.addEventListener('click', function () { rebuild(genArray(state.size)); });

    var presetSel = document.getElementById('preset-select');
    if (presetSel) {
      presetSel.addEventListener('change', function () {
        var n = state.size, arr = [];
        if (presetSel.value === 'random') arr = genArray(n);
        else if (presetSel.value === 'sorted') { for (var i = 0; i < n; i++) arr.push(Math.round(8 + (92 * i) / (n - 1))); }
        else if (presetSel.value === 'reversed') { for (var j = 0; j < n; j++) arr.push(Math.round(100 - (92 * j) / (n - 1))); }
        else if (presetSel.value === 'fewunique') { for (var k = 0; k < n; k++) arr.push([20, 45, 70, 95][k % 4]); }
        else if (presetSel.value === 'nearly') {
          for (var m = 0; m < n; m++) arr.push(Math.round(8 + (92 * m) / (n - 1)));
          // a few random swaps
          for (var s = 0; s < Math.max(2, n / 8); s++) {
            var p = Math.floor(Math.random() * n), q = Math.floor(Math.random() * n);
            var t = arr[p]; arr[p] = arr[q]; arr[q] = t;
          }
        }
        rebuild(arr);
      });
    }

    /* responsive redraw */
    var resizeTO;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTO);
      resizeTO = setTimeout(function () { renderer.resize(); renderer.draw(player.index); }, 80);
    });

    /* boot */
    buildList();
    renderDesc();
    rebuild(genArray(state.size));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else { init(); }
})();
