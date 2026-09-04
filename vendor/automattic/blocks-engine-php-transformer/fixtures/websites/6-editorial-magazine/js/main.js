/* =========================================================
   THE LEDGER ROOM — Main JavaScript
   ========================================================= */

/* =========================================================
   STICKY MASTHEAD
   ========================================================= */
const masthead = document.querySelector('.masthead');

function handleMastheadScroll() {
  if (!masthead) return;
  if (window.scrollY > 60) {
    masthead.classList.add('scrolled');
  } else {
    masthead.classList.remove('scrolled');
  }
}

window.addEventListener('scroll', handleMastheadScroll, { passive: true });
handleMastheadScroll();

/* =========================================================
   MOBILE NAV TOGGLE
   ========================================================= */
const navToggle = document.querySelector('.nav-toggle');
const body = document.body;

if (navToggle) {
  navToggle.addEventListener('click', () => {
    const isOpen = body.classList.toggle('nav-open');
    navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    document.body.style.overflow = isOpen ? 'hidden' : '';
  });
}

// Close nav when a mobile link is clicked
document.querySelectorAll('.nav-mobile a').forEach(link => {
  link.addEventListener('click', () => {
    body.classList.remove('nav-open');
    document.body.style.overflow = '';
    if (navToggle) navToggle.setAttribute('aria-expanded', 'false');
  });
});

// Close nav on Escape key
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && body.classList.contains('nav-open')) {
    body.classList.remove('nav-open');
    document.body.style.overflow = '';
    if (navToggle) {
      navToggle.setAttribute('aria-expanded', 'false');
      navToggle.focus();
    }
  }
});

/* =========================================================
   SCROLL REVEAL
   ========================================================= */
const revealEls = document.querySelectorAll('.reveal');

if (revealEls.length > 0 && 'IntersectionObserver' in window) {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      }
    });
  }, {
    threshold: 0.08,
    rootMargin: '0px 0px -30px 0px'
  });

  revealEls.forEach(el => observer.observe(el));
} else {
  // Fallback: just show everything
  revealEls.forEach(el => el.classList.add('is-visible'));
}

/* =========================================================
   NEWSLETTER FORMS
   ========================================================= */
document.querySelectorAll('.newsletter-band').forEach(band => {
  const form = band.querySelector('form');
  const success = band.querySelector('.newsletter-success');
  if (!form) return;

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const emailInput = form.querySelector('input[type="email"]');
    if (!emailInput || !emailInput.value.includes('@')) {
      emailInput && emailInput.focus();
      return;
    }
    form.style.display = 'none';
    if (success) success.style.display = 'block';
  });
});

/* =========================================================
   ARTICLE CARD — read time (bonus touch)
   Add a simple hover tooltip effect via CSS is enough,
   but we can also lazily animate the kicker labels
   ========================================================= */

// Stagger article cards within grids on first paint
document.querySelectorAll('.article-grid').forEach(grid => {
  const cards = grid.querySelectorAll('.article-card');
  cards.forEach((card, i) => {
    if (!card.classList.contains('reveal')) {
      card.classList.add('reveal', `reveal-d${Math.min(i + 1, 5)}`);
    }
  });
});

// Stagger secondary cards
document.querySelectorAll('.secondary-card').forEach((card, i) => {
  if (!card.classList.contains('reveal')) {
    card.classList.add('reveal', `reveal-d${Math.min(i + 1, 3)}`);
  }
});
