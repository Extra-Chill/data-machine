/* input.js — unified keyboard + on-screen touch input for Lumen Leap.
   Exposes a small action map (left/right/jump/pause/etc). The game reads the
   live state each frame; "jump" also exposes an edge (justPressed) flag so the
   controller can implement jump buffering cleanly. */
(function () {
  'use strict';

  const actions = {
    left: false,
    right: false,
    up: false,
    down: false,
    jump: false
  };

  // Edge flags consumed by the game loop each tick.
  const edges = { jump: false, pause: false, restart: false, mute: false, confirm: false };

  const KEYMAP = {
    ArrowLeft: 'left', KeyA: 'left',
    ArrowRight: 'right', KeyD: 'right',
    ArrowUp: 'up', KeyW: 'up',
    ArrowDown: 'down', KeyS: 'down',
    Space: 'jump', KeyZ: 'jump', KeyK: 'jump'
  };

  function isGameKey(code) {
    return KEYMAP[code] || ['KeyP', 'Escape', 'KeyR', 'KeyM', 'Enter'].includes(code);
  }

  window.addEventListener('keydown', (e) => {
    const code = e.code;
    if (!isGameKey(code)) return;
    // Prevent the page from scrolling while playing.
    if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Space'].includes(code)) {
      e.preventDefault();
    }
    if (e.repeat) return;
    const a = KEYMAP[code];
    if (a) {
      actions[a] = true;
      if (a === 'jump') edges.jump = true;
    }
    if (code === 'KeyP' || code === 'Escape') edges.pause = true;
    if (code === 'KeyR') edges.restart = true;
    if (code === 'KeyM') edges.mute = true;
    if (code === 'Enter') edges.confirm = true;
  });

  window.addEventListener('keyup', (e) => {
    const a = KEYMAP[e.code];
    if (a) actions[a] = false;
  });

  // Lose held keys if the tab loses focus (otherwise the player "sticks").
  window.addEventListener('blur', () => {
    for (const k in actions) actions[k] = false;
  });

  /* ---- Touch / pointer buttons ---- */
  function bindButton(el, action) {
    if (!el) return;
    const down = (e) => {
      e.preventDefault();
      actions[action] = true;
      if (action === 'jump') edges.jump = true;
      el.classList.add('pressed');
    };
    const up = (e) => {
      e.preventDefault();
      actions[action] = false;
      el.classList.remove('pressed');
    };
    el.addEventListener('pointerdown', down);
    el.addEventListener('pointerup', up);
    el.addEventListener('pointerleave', up);
    el.addEventListener('pointercancel', up);
  }

  const Input = {
    actions,
    /* Consume an edge flag (returns true once per press). */
    consume(name) {
      if (edges[name]) { edges[name] = false; return true; }
      return false;
    },
    peekJump() { return edges.jump; },
    clearJumpEdge() { edges.jump = false; },
    bindTouch(root) {
      bindButton(root.querySelector('[data-act="left"]'), 'left');
      bindButton(root.querySelector('[data-act="right"]'), 'right');
      bindButton(root.querySelector('[data-act="jump"]'), 'jump');
    },
    reset() {
      for (const k in actions) actions[k] = false;
      for (const k in edges) edges[k] = false;
    }
  };

  window.LumenInput = Input;
})();
