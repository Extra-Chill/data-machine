const slides = Array.from(document.querySelectorAll('[data-slider] .slide'));
const next = document.querySelector('[data-next]');
let active = 0;
if (next && slides.length > 1) {
  next.addEventListener('click', () => {
    slides[active].classList.remove('is-active');
    active = (active + 1) % slides.length;
    slides[active].classList.add('is-active');
  });
}
