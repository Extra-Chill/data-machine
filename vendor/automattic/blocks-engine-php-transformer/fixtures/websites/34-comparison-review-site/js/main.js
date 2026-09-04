/* =========================================================
   THE DAILY GRIND — Main JavaScript
   ========================================================= */

/* =========================================================
   STICKY HEADER STATE
   ========================================================= */
const header = document.querySelector('.site-header');

function handleHeaderScroll() {
  if (!header) return;
  if (window.scrollY > 40) {
    header.classList.add('scrolled');
  } else {
    header.classList.remove('scrolled');
  }
}

window.addEventListener('scroll', handleHeaderScroll, { passive: true });
handleHeaderScroll();

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

document.querySelectorAll('.nav-mobile a').forEach(link => {
  link.addEventListener('click', () => {
    body.classList.remove('nav-open');
    document.body.style.overflow = '';
    if (navToggle) navToggle.setAttribute('aria-expanded', 'false');
  });
});

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
  revealEls.forEach(el => el.classList.add('is-visible'));
}

/* =========================================================
   STAGGER REVIEW + GUIDE CARDS
   ========================================================= */
document.querySelectorAll('.review-grid, .guide-strip, .guide-index, .grinder-grid').forEach(grid => {
  const cards = grid.children;
  Array.from(cards).forEach((card, i) => {
    if (!card.classList.contains('reveal')) {
      card.classList.add('reveal', `reveal-d${Math.min(i + 1, 5)}`);
    }
  });
});

/* =========================================================
   NEWSLETTER FAKE-SUBMIT
   ========================================================= */
document.querySelectorAll('.newsletter').forEach(band => {
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
   SET RATING-BAR FILL FROM data-score
   (allows CSS to read --score from the attribute)
   ========================================================= */
document.querySelectorAll('.rating-bar[data-score]').forEach(bar => {
  const score = parseFloat(bar.dataset.score);
  if (!isNaN(score)) {
    bar.style.setProperty('--score', score);
  }
});

/* =========================================================
   SMOOTH SCROLL FOR IN-PAGE ANCHORS
   (browsers handle this via CSS, but offset for sticky header)
   ========================================================= */
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
  anchor.addEventListener('click', function (e) {
    const href = this.getAttribute('href');
    if (href === '#' || href.length < 2) return;
    const target = document.querySelector(href);
    if (!target) return;
    e.preventDefault();
    const headerOffset = header ? header.offsetHeight + 12 : 0;
    const top = target.getBoundingClientRect().top + window.scrollY - headerOffset;
    window.scrollTo({ top, behavior: 'smooth' });
  });
});
