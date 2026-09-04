const search = document.querySelector('[data-search]');
if (search) {
  search.addEventListener('input', () => {
    document.documentElement.style.setProperty('--search-length', String(search.value.length));
  });
}
