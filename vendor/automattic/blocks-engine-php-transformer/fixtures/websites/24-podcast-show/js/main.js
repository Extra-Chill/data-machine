/* ============================================================
   Slow Burn Stories — Main JavaScript
   Mobile nav, header scroll state, active nav link,
   custom audio player wrapper, copy-RSS button
   ============================================================ */

(function () {
  'use strict';

  // ── Header scroll state ─────────────────────────────────
  const header = document.querySelector('.site-header');
  if (header) {
    const onScroll = () => {
      header.classList.toggle('scrolled', window.scrollY > 30);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // ── Mobile nav toggle ───────────────────────────────────
  const navToggle = document.querySelector('.nav-toggle');
  const mobileNav = document.querySelector('.mobile-nav');

  if (navToggle && mobileNav) {
    navToggle.addEventListener('click', () => {
      const isOpen = navToggle.classList.toggle('open');
      mobileNav.classList.toggle('open', isOpen);
      document.body.style.overflow = isOpen ? 'hidden' : '';
      navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    // Close on link click
    mobileNav.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        navToggle.classList.remove('open');
        mobileNav.classList.remove('open');
        document.body.style.overflow = '';
        navToggle.setAttribute('aria-expanded', 'false');
      });
    });

    // Close on Escape
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && mobileNav.classList.contains('open')) {
        navToggle.classList.remove('open');
        mobileNav.classList.remove('open');
        document.body.style.overflow = '';
        navToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // ── Active nav link ─────────────────────────────────────
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-links a, .mobile-nav a').forEach(link => {
    const href = link.getAttribute('href');
    if (!href) return;
    // Match the page name; treat episode-single as part of episodes archive
    const isCurrent =
      href === currentPage ||
      (currentPage === '' && href === 'index.html') ||
      (currentPage.startsWith('episode-') && href === 'episodes.html');
    if (isCurrent) link.classList.add('active');
  });

  // ── Custom audio player wrapper ─────────────────────────
  // The player UI is decorative — we wire up the inner <audio>
  // and reflect state into the custom controls.
  document.querySelectorAll('.player').forEach(player => {
    const audio = player.querySelector('audio');
    const playBtn = player.querySelector('.player-play');
    const track = player.querySelector('.player-track');
    const trackFill = player.querySelector('.player-track-fill');
    const trackHandle = player.querySelector('.player-track-handle');
    const currentTimeEl = player.querySelector('[data-current-time]');
    const durationEl = player.querySelector('[data-duration]');
    const speedBtn = player.querySelector('.player-speed');
    const skipBackBtn = player.querySelector('[data-skip-back]');
    const skipFwdBtn = player.querySelector('[data-skip-forward]');

    if (!audio || !playBtn) return;

    const speeds = [1, 1.25, 1.5, 1.75, 2, 0.85];
    let speedIdx = 0;

    const fmt = (s) => {
      if (!isFinite(s) || s < 0) s = 0;
      const h = Math.floor(s / 3600);
      const m = Math.floor((s % 3600) / 60);
      const sec = Math.floor(s % 60);
      const pad = (n) => String(n).padStart(2, '0');
      return h > 0 ? `${h}:${pad(m)}:${pad(sec)}` : `${m}:${pad(sec)}`;
    };

    playBtn.addEventListener('click', () => {
      if (audio.paused) {
        audio.play().catch(() => { /* missing src is fine for fixture */ });
      } else {
        audio.pause();
      }
    });

    audio.addEventListener('play', () => {
      playBtn.classList.add('playing');
      playBtn.setAttribute('aria-label', 'Pause episode');
      playBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>';
    });

    audio.addEventListener('pause', () => {
      playBtn.classList.remove('playing');
      playBtn.setAttribute('aria-label', 'Play episode');
      playBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5v14l12-7L7 5z"/></svg>';
    });

    audio.addEventListener('timeupdate', () => {
      const pct = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
      if (trackFill) trackFill.style.width = pct + '%';
      if (trackHandle) trackHandle.style.left = pct + '%';
      if (currentTimeEl) currentTimeEl.textContent = fmt(audio.currentTime);
    });

    audio.addEventListener('loadedmetadata', () => {
      if (durationEl) durationEl.textContent = fmt(audio.duration);
    });

    if (track) {
      track.addEventListener('click', (e) => {
        const rect = track.getBoundingClientRect();
        const pct = (e.clientX - rect.left) / rect.width;
        if (audio.duration) audio.currentTime = audio.duration * pct;
      });
    }

    if (skipBackBtn) {
      skipBackBtn.addEventListener('click', () => {
        audio.currentTime = Math.max(0, audio.currentTime - 15);
      });
    }
    if (skipFwdBtn) {
      skipFwdBtn.addEventListener('click', () => {
        audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + 30);
      });
    }

    if (speedBtn) {
      speedBtn.addEventListener('click', () => {
        speedIdx = (speedIdx + 1) % speeds.length;
        audio.playbackRate = speeds[speedIdx];
        const label = speedBtn.querySelector('[data-speed-value]');
        if (label) label.textContent = speeds[speedIdx] + '×';
      });
    }
  });

  // ── Copy-RSS button ─────────────────────────────────────
  const copyBtn = document.querySelector('.copy-btn[data-copy-target]');
  if (copyBtn) {
    copyBtn.addEventListener('click', () => {
      const targetSel = copyBtn.dataset.copyTarget;
      const target = document.querySelector(targetSel);
      if (!target) return;
      const text = target.textContent.trim();
      const original = copyBtn.textContent;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
          copyBtn.textContent = 'Copied';
          setTimeout(() => { copyBtn.textContent = original; }, 1800);
        }).catch(() => { copyBtn.textContent = 'Copy failed'; });
      } else {
        // Fallback for older browsers
        const range = document.createRange();
        range.selectNodeContents(target);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      }
    });
  }

  // ── Newsletter form (mock submit) ───────────────────────
  document.querySelectorAll('form[data-mock-submit]').forEach(form => {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const msg = form.querySelector('[data-form-msg]');
      if (msg) {
        msg.textContent = 'Thanks — check your inbox for a confirmation.';
        msg.style.color = 'var(--ember-deep)';
      }
      form.reset();
    });
  });

  // ── Comment-list "load more" placeholder ────────────────
  const loadMore = document.querySelector('[data-load-more]');
  if (loadMore) {
    loadMore.addEventListener('click', () => {
      loadMore.textContent = 'Nothing more to load';
      loadMore.disabled = true;
      loadMore.style.opacity = '0.55';
      loadMore.style.cursor = 'default';
    });
  }

})();
