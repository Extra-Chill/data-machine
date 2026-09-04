const header = document.querySelector('.site-header');
const onScroll = () => header?.classList.toggle('shrink', window.scrollY > 24);
window.addEventListener('scroll', onScroll, { passive: true });
onScroll();

const revealItems = document.querySelectorAll('.reveal');
const revealObserver = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting) {
      entry.target.classList.add('in-view');
      revealObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.15 }) : null;
revealItems.forEach((item) => revealObserver ? revealObserver.observe(item) : item.classList.add('in-view'));

const counters = document.querySelectorAll('[data-count]');
const runCounter = (node) => {
  const target = Number(node.dataset.count || 0);
  const prefix = node.dataset.prefix || '';
  const suffix = node.dataset.suffix || '';
  const start = performance.now();
  const step = (now) => {
    const progress = Math.min((now - start) / 1100, 1);
    const value = Math.round(target * (1 - Math.pow(1 - progress, 3)));
    node.textContent = `${prefix}${value}${suffix}`;
    if (progress < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
};
const counterObserver = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting) {
      runCounter(entry.target);
      counterObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.6 }) : null;
counters.forEach((counter) => counterObserver?.observe(counter));

const tabs = document.querySelector('[data-tabs]');
if (tabs) {
  tabs.classList.add('js-tabs');
  const buttons = tabs.querySelectorAll('[data-tab-target]');
  const panels = tabs.querySelectorAll('.tab-panel');
  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const target = button.dataset.tabTarget;
      buttons.forEach((candidate) => candidate.classList.toggle('is-active', candidate === button));
      panels.forEach((panel) => panel.classList.toggle('is-active', panel.id === target));
    });
  });
}
