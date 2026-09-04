document.documentElement.classList.add('js');

const revealItems = document.querySelectorAll('.reveal');
const revealObserver = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting) {
      entry.target.classList.add('in-view');
      revealObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.16 }) : null;

revealItems.forEach((item) => {
  if (revealObserver) {
    revealObserver.observe(item);
  } else {
    item.classList.add('in-view');
  }
});

const counters = document.querySelectorAll('[data-count]');
const animateCounter = (node) => {
  const target = Number(node.dataset.count || 0);
  const suffix = node.dataset.suffix || '';
  const start = performance.now();
  const duration = 1200;
  const tick = (now) => {
    const progress = Math.min((now - start) / duration, 1);
    node.textContent = `${Math.round(target * (1 - Math.pow(1 - progress, 3)))}${suffix}`;
    if (progress < 1) requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
};

const counterObserver = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting) {
      animateCounter(entry.target);
      counterObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.6 }) : null;

counters.forEach((counter) => {
  if (counterObserver) counterObserver.observe(counter);
});

const carousel = document.querySelector('[data-carousel]');
document.querySelectorAll('[data-slide]').forEach((button) => {
  button.addEventListener('click', () => {
    if (!carousel) return;
    const direction = button.dataset.slide === 'next' ? 1 : -1;
    carousel.scrollBy({ left: direction * carousel.clientWidth * 0.78, behavior: 'smooth' });
  });
});

const pricing = document.querySelector('[data-pricing]');
document.querySelectorAll('[data-billing]').forEach((button) => {
  button.addEventListener('click', () => {
    const annual = button.dataset.billing === 'annual';
    pricing?.classList.toggle('show-annual', annual);
    document.querySelectorAll('[data-billing]').forEach((control) => {
      control.classList.toggle('is-active', control === button);
      control.setAttribute('aria-pressed', String(control === button));
    });
  });
});
