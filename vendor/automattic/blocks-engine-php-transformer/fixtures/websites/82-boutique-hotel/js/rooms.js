document.querySelectorAll('[data-carousel]').forEach((carousel) => {
  const frames = Array.from(carousel.querySelectorAll('.frame'));
  const button = carousel.querySelector('button');
  let active = 0;
  button?.addEventListener('click', () => {
    frames[active].classList.remove('is-active');
    active = (active + 1) % frames.length;
    frames[active].classList.add('is-active');
  });
});
