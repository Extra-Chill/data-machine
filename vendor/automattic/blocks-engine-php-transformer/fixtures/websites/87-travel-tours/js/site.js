const revealNodes = document.querySelectorAll('.reveal');
const observer = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting) {
      entry.target.classList.add('in-view');
      observer.unobserve(entry.target);
    }
  });
}, { threshold: 0.14 }) : null;

revealNodes.forEach((node) => observer ? observer.observe(node) : node.classList.add('in-view'));

const slides = document.querySelector('[data-slides]');
const slideCount = slides ? slides.children.length : 0;
let activeSlide = 0;

document.querySelectorAll('[data-testimonial]').forEach((button) => {
  button.addEventListener('click', () => {
    if (!slides || !slideCount) return;
    activeSlide = (activeSlide + (button.dataset.testimonial === 'next' ? 1 : -1) + slideCount) % slideCount;
    slides.style.transform = `translateX(-${activeSlide * 100}%)`;
  });
});
