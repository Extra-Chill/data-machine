const ready = (callback) => {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', callback);
    return;
  }

  callback();
};

ready(() => {
  const cards = Array.from(document.querySelectorAll('[data-card]'));
  const sortSelect = document.querySelector('[data-sort]');
  const rail = document.querySelector('[data-rail]');
  const railButtons = document.querySelectorAll('[data-rail-scroll]');

  document.querySelectorAll('[data-facet]').forEach((facet) => {
    facet.addEventListener('change', () => {
      const active = Array.from(document.querySelectorAll('[data-facet]:checked')).map((input) => input.value);

      cards.forEach((card) => {
        const tags = (card.dataset.tags || '').split(' ');
        const matches = active.length === 0 || active.some((value) => tags.includes(value));
        card.hidden = !matches;
      });
    });
  });

  if (sortSelect) {
    sortSelect.addEventListener('change', () => {
      const grid = document.querySelector('[data-card-grid]');
      if (!grid) return;

      const sorted = Array.from(grid.querySelectorAll('[data-card]')).sort((a, b) => {
        if (sortSelect.value === 'rating') return Number(b.dataset.rating) - Number(a.dataset.rating);
        if (sortSelect.value === 'price') return Number(a.dataset.price) - Number(b.dataset.price);
        return Number(b.dataset.downloads) - Number(a.dataset.downloads);
      });

      sorted.forEach((card) => grid.appendChild(card));
    });
  }

  railButtons.forEach((button) => {
    button.addEventListener('click', () => {
      if (!rail) return;
      const direction = button.dataset.railScroll === 'next' ? 1 : -1;
      rail.scrollBy({ left: direction * Math.min(420, rail.clientWidth), behavior: 'smooth' });
    });
  });
});
