const range = document.querySelector('[data-range]');
const after = document.querySelector('[data-after]');
if (range && after) {
  const sync = () => { after.style.clipPath = `inset(0 ${100 - range.value}% 0 0)`; };
  range.addEventListener('input', sync);
  sync();
}
