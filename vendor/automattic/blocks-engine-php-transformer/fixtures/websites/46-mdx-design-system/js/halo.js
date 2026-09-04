/* =====================================================================
   HALO UI DOCS — runtime
   Command-palette search, copy buttons, TOC scroll-spy, theme toggle,
   mobile nav, tabs, and the live Modal playground.
   Vanilla JS, no dependencies. Works from file://.
   ===================================================================== */

/* ---- SEARCH INDEX (generated at build from the MDX collection) ----- */
const SEARCH_INDEX = [
  { title: 'Halo UI', group: 'Overview', page: 'index.html', excerpt: 'Install the React bindings, theme provider, and the layered architecture of the design system.', tags: ['install','overview','getting started','npm','theme','tokens'] },
  { title: 'Color', group: 'Foundations', page: 'color.html', excerpt: 'The palette, semantic color roles, theme mapping, and WCAG AA contrast requirements.', tags: ['color','palette','indigo','slate','contrast','tokens','swatch','semantic'] },
  { title: 'Typography', group: 'Foundations', page: 'typography.html', excerpt: 'The 1.2 type scale, Space Grotesk + Inter font stacks, measure, and rhythm.', tags: ['typography','type','font','scale','inter','space grotesk','measure'] },
  { title: 'Button', group: 'Components', page: 'button.html', excerpt: 'Triggers an action. Variants, tones, sizes, loading state, props, and accessibility.', tags: ['button','action','variant','tone','solid','soft','ghost','outline','form'] },
  { title: 'Input', group: 'Components', page: 'input.html', excerpt: 'Single-line text field. States, validation, props, and label association.', tags: ['input','text','field','form','validation','label','email','placeholder'] },
  { title: 'Modal', group: 'Components', page: 'modal.html', excerpt: 'A focused overlay dialog. Focus trap, Escape to close, props, and behavior.', tags: ['modal','dialog','overlay','focus trap','escape','popup','beta'] },
  { title: 'Card', group: 'Components', page: 'card.html', excerpt: 'A flexible raised surface grouping related content and actions.', tags: ['card','surface','container','elevation','grid','layout'] },
];

function searchDocs(query) {
  const q = query.trim().toLowerCase();
  if (q.length < 1) return SEARCH_INDEX.slice();
  return SEARCH_INDEX
    .map((item) => {
      let score = 0;
      const t = item.title.toLowerCase();
      if (t === q) score += 14;
      else if (t.startsWith(q)) score += 10;
      else if (t.includes(q)) score += 6;
      if (item.excerpt.toLowerCase().includes(q)) score += 3;
      item.tags.forEach((tag) => {
        if (tag === q) score += 8;
        else if (tag.includes(q)) score += 4;
      });
      return { item, score };
    })
    .filter((r) => r.score > 0)
    .sort((a, b) => b.score - a.score)
    .map((r) => r.item);
}

/* ---- COMMAND PALETTE ---------------------------------------------- */
function initPalette() {
  const backdrop = document.getElementById('palette');
  if (!backdrop) return;
  const input = backdrop.querySelector('.palette__input');
  const results = backdrop.querySelector('.palette__results');
  let activeIndex = 0;
  let current = [];

  function render(list) {
    current = list;
    activeIndex = 0;
    if (!list.length) {
      results.innerHTML = '<p class="palette__empty">No matches. Try “button”, “color”, or “focus”.</p>';
      return;
    }
    results.innerHTML = list
      .map(
        (r, i) => `
      <a class="palette__result${i === 0 ? ' is-active' : ''}" href="${r.page}" data-i="${i}">
        <div class="palette__result-title">${r.title}<span class="palette__group">${r.group}</span></div>
        <div class="palette__result-excerpt">${r.excerpt}</div>
      </a>`,
      )
      .join('');
  }

  function setActive(i) {
    const items = results.querySelectorAll('.palette__result');
    if (!items.length) return;
    activeIndex = (i + items.length) % items.length;
    items.forEach((el, idx) => el.classList.toggle('is-active', idx === activeIndex));
    items[activeIndex].scrollIntoView({ block: 'nearest' });
  }

  function open() {
    backdrop.classList.add('is-open');
    input.value = '';
    render(searchDocs(''));
    input.focus();
    document.body.style.overflow = 'hidden';
  }
  function close() {
    backdrop.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  input.addEventListener('input', () => render(searchDocs(input.value)));
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(activeIndex + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(activeIndex - 1); }
    else if (e.key === 'Enter') {
      const items = results.querySelectorAll('.palette__result');
      if (items[activeIndex]) window.location.href = items[activeIndex].getAttribute('href');
    } else if (e.key === 'Escape') { close(); }
  });
  backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });

  document.querySelectorAll('[data-palette-open]').forEach((btn) =>
    btn.addEventListener('click', open),
  );
  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); open(); }
    if (e.key === '/' && document.activeElement === document.body) { e.preventDefault(); open(); }
  });
}

/* ---- COPY BUTTONS -------------------------------------------------- */
function initCopy() {
  document.querySelectorAll('.copy-btn, .code-preview__copy').forEach((btn) => {
    btn.addEventListener('click', () => {
      const block = btn.closest('.halo-codeblock, .code-preview, .code-preview__panel');
      const pre = block && block.querySelector('pre');
      if (!pre) return;
      const text = pre.innerText;
      const done = () => {
        const label = btn.textContent;
        btn.textContent = 'Copied';
        btn.classList.add('is-copied');
        setTimeout(() => { btn.textContent = label; btn.classList.remove('is-copied'); }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(done);
      } else {
        const ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (_) {}
        document.body.removeChild(ta); done();
      }
    });
  });
}

/* ---- TABS ---------------------------------------------------------- */
function initTabs() {
  document.querySelectorAll('.tabs').forEach((tabs) => {
    const buttons = tabs.querySelectorAll('.tabs__tab');
    const panels = tabs.querySelectorAll('.tabs__panel');
    buttons.forEach((btn, i) => {
      btn.addEventListener('click', () => {
        buttons.forEach((b, j) => {
          b.classList.toggle('is-active', j === i);
          b.setAttribute('aria-selected', j === i);
          b.tabIndex = j === i ? 0 : -1;
        });
        panels.forEach((p, j) => (p.hidden = j !== i));
      });
    });
  });
}

/* ---- TOC SCROLL-SPY ------------------------------------------------ */
function initTOC() {
  const links = document.querySelectorAll('.toc__item a');
  if (!links.length) return;
  const headings = Array.from(document.querySelectorAll('.doc h2[id], .doc h3[id]'));
  function update() {
    let current = '';
    for (const h of headings) {
      if (h.getBoundingClientRect().top <= 96) current = '#' + h.id;
    }
    links.forEach((l) => l.classList.toggle('is-active', l.getAttribute('href') === current));
  }
  window.addEventListener('scroll', update, { passive: true });
  update();
}

/* ---- THEME TOGGLE -------------------------------------------------- */
function initTheme() {
  const stored = localStorage.getItem('halo-theme');
  if (stored) document.documentElement.setAttribute('data-theme', stored);
  const toggle = document.getElementById('theme-toggle');
  if (!toggle) return;
  toggle.addEventListener('click', () => {
    const isDark =
      document.documentElement.getAttribute('data-theme') === 'dark' ||
      (!document.documentElement.getAttribute('data-theme') &&
        window.matchMedia('(prefers-color-scheme: dark)').matches);
    const next = isDark ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('halo-theme', next);
  });
}

/* ---- MOBILE NAV ---------------------------------------------------- */
function initMobileNav() {
  const toggle = document.getElementById('nav-toggle');
  const sidebar = document.querySelector('.sidebar');
  const backdrop = document.querySelector('.sidebar-backdrop');
  if (!toggle || !sidebar) return;
  function openNav() { sidebar.classList.add('is-open'); backdrop && backdrop.classList.add('is-open'); }
  function closeNav() { sidebar.classList.remove('is-open'); backdrop && backdrop.classList.remove('is-open'); }
  toggle.addEventListener('click', () =>
    sidebar.classList.contains('is-open') ? closeNav() : openNav(),
  );
  backdrop && backdrop.addEventListener('click', closeNav);
  sidebar.querySelectorAll('.nav-link').forEach((l) =>
    l.addEventListener('click', () => { if (window.innerWidth < 820) closeNav(); }),
  );
}

/* ---- ACTIVE NAV LINK ---------------------------------------------- */
function initActiveNav() {
  const page = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-link').forEach((l) => {
    if (l.getAttribute('href') === page) l.classList.add('is-active');
  });
}

/* ---- MODAL PLAYGROUND (Modal component demo) ---------------------- */
function initModalDemo() {
  const trigger = document.querySelector('[data-modal-open]');
  if (!trigger) return;
  let lastFocused = null;

  function buildModal() {
    const overlay = document.createElement('div');
    overlay.className = 'halo-modal-overlay';
    overlay.innerHTML = `
      <div class="halo-modal" role="dialog" aria-modal="true" aria-labelledby="demo-modal-title">
        <div class="halo-modal__header">
          <h2 id="demo-modal-title">Delete workspace?</h2>
          <button class="halo-modal__close" aria-label="Close dialog" data-modal-close>
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4l8 8M12 4l-8 8" stroke-linecap="round"/></svg>
          </button>
        </div>
        <div class="halo-modal__body">
          <p>This permanently removes all <strong>14 projects</strong> in this workspace. This action can't be undone.</p>
        </div>
        <div class="halo-modal__footer">
          <button class="halo-button halo-button--ghost halo-button--neutral" data-modal-close>Cancel</button>
          <button class="halo-button halo-button--solid halo-button--danger" data-modal-close>Delete</button>
        </div>
      </div>`;
    return overlay;
  }

  function open() {
    lastFocused = document.activeElement;
    const overlay = buildModal();
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
    const dialog = overlay.querySelector('.halo-modal');
    const focusables = overlay.querySelectorAll('button, [href], input');
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    first && first.focus();

    function close() {
      overlay.remove();
      document.body.style.overflow = '';
      lastFocused && lastFocused.focus();
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) {
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') {
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    overlay.querySelectorAll('[data-modal-close]').forEach((b) => b.addEventListener('click', close));
    document.addEventListener('keydown', onKey);
  }

  trigger.addEventListener('click', open);
}

/* ---- INIT ---------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', () => {
  initTheme();
  initPalette();
  initCopy();
  initTabs();
  initTOC();
  initMobileNav();
  initActiveNav();
  initModalDemo();
});
