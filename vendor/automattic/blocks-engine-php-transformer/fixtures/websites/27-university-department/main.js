/* ============================================================
   EAMS — Department of Earth, Atmospheric & Marine Sciences
   main.js
   - Primary nav toggle (mobile)
   - Filter chips (visual only, faculty page)
   - Accordion (FAQ-style on contact / events)
   - Active subsection nav highlighting via scroll
   - Set current academic year in footer
   ============================================================ */

(function () {
  'use strict';

  /* ---- Primary nav (mobile) ---- */
  function initPrimaryNav() {
    const toggle = document.querySelector('.nav-toggle');
    const nav = document.querySelector('.primary-nav');
    if (!toggle || !nav) return;

    toggle.addEventListener('click', () => {
      const open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    // Close menu when a link is clicked (mobile)
    nav.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => {
        if (window.innerWidth < 820) {
          nav.classList.remove('open');
          toggle.setAttribute('aria-expanded', 'false');
        }
      });
    });
  }

  /* ---- Mark current page in primary nav ---- */
  function initActiveNavLink() {
    const file = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
    document.querySelectorAll('.primary-nav a').forEach(link => {
      const href = (link.getAttribute('href') || '').toLowerCase();
      if (href === file || (file === '' && href === 'index.html')) {
        link.classList.add('active');
        link.setAttribute('aria-current', 'page');
      }
    });
  }

  /* ---- Filter chips (visual only — faculty page) ---- */
  function initFilterChips() {
    const groups = document.querySelectorAll('.filter-chips');
    groups.forEach(group => {
      const chips = group.querySelectorAll('.chip');
      chips.forEach(chip => {
        chip.addEventListener('click', () => {
          chips.forEach(c => {
            c.classList.remove('active');
            c.setAttribute('aria-pressed', 'false');
          });
          chip.classList.add('active');
          chip.setAttribute('aria-pressed', 'true');
        });
      });
    });
  }

  /* ---- Accordion (events past-archive disclosure, contact FAQs) ---- */
  function initAccordions() {
    const triggers = document.querySelectorAll('.accordion-trigger');
    triggers.forEach(trigger => {
      trigger.addEventListener('click', () => {
        const expanded = trigger.getAttribute('aria-expanded') === 'true';
        trigger.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      });
      // Keyboard support: Enter/Space already covered for <button>; nothing extra needed.
    });
  }

  /* ---- Subsection nav active state by scroll (programs / research) ---- */
  function initSubsectionNav() {
    const nav = document.querySelector('.subsection-nav');
    if (!nav) return;
    const links = Array.from(nav.querySelectorAll('a[href^="#"]'));
    if (links.length === 0) return;

    const targets = links
      .map(link => {
        const id = link.getAttribute('href').slice(1);
        const el = document.getElementById(id);
        return el ? { link, el } : null;
      })
      .filter(Boolean);

    function setActive() {
      const offset = 140;
      let current = targets[0];
      for (const t of targets) {
        const top = t.el.getBoundingClientRect().top;
        if (top - offset <= 0) {
          current = t;
        }
      }
      links.forEach(l => l.classList.remove('current'));
      links.forEach(l => l.removeAttribute('aria-current'));
      if (current) {
        current.link.classList.add('current');
        current.link.setAttribute('aria-current', 'true');
      }
    }

    setActive();
    window.addEventListener('scroll', setActive, { passive: true });

    // Smooth offset for sticky header on click
    links.forEach(link => {
      link.addEventListener('click', e => {
        const href = link.getAttribute('href');
        if (!href || href.charAt(0) !== '#') return;
        const target = document.getElementById(href.slice(1));
        if (!target) return;
        e.preventDefault();
        const top = target.getBoundingClientRect().top + window.scrollY - 120;
        window.scrollTo({ top, behavior: 'smooth' });
        history.replaceState(null, '', href);
      });
    });
  }

  /* ---- Footer academic year ---- */
  function initFooterYear() {
    const slot = document.querySelector('[data-academic-year]');
    if (!slot) return;
    const now = new Date();
    const y = now.getFullYear();
    // Academic year: Jul–Jun
    const isFallOrLater = now.getMonth() >= 6;
    const start = isFallOrLater ? y : y - 1;
    slot.textContent = `${start}–${start + 1}`;
  }

  /* ---- "Add to calendar" mock — prevent navigation, flash confirmation ---- */
  function initAddToCalendar() {
    document.querySelectorAll('.event-add').forEach(link => {
      link.addEventListener('click', e => {
        e.preventDefault();
        const original = link.textContent;
        link.textContent = 'Added to calendar';
        link.style.color = 'var(--success)';
        setTimeout(() => {
          link.textContent = original;
          link.style.color = '';
        }, 2200);
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    initPrimaryNav();
    initActiveNavLink();
    initFilterChips();
    initAccordions();
    initSubsectionNav();
    initFooterYear();
    initAddToCalendar();
  });
})();
