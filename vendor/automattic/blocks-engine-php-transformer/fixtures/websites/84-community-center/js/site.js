document.documentElement.classList.add('js');

const durationInput = document.querySelector('[data-duration-range]');
const durationOutput = document.querySelector('[data-duration-output]');

if (durationInput && durationOutput) {
  const updateDuration = () => {
    durationOutput.value = `${durationInput.value} hours`;
  };

  durationInput.addEventListener('input', updateDuration);
  updateDuration();
}

document.querySelectorAll('[data-step-form]').forEach((form) => {
  const panels = Array.from(form.querySelectorAll('[data-step-panel]'));
  const progress = form.querySelector('[data-step-progress]');
  let activeIndex = 0;

  const showStep = (index) => {
    activeIndex = Math.max(0, Math.min(index, panels.length - 1));
    panels.forEach((panel, panelIndex) => {
      panel.hidden = panelIndex !== activeIndex;
    });
    if (progress) {
      progress.value = activeIndex + 1;
      progress.max = panels.length;
    }
  };

  form.addEventListener('click', (event) => {
    const next = event.target.closest('[data-next-step]');
    const previous = event.target.closest('[data-previous-step]');
    if (!next && !previous) {
      return;
    }
    event.preventDefault();
    showStep(activeIndex + (next ? 1 : -1));
  });

  showStep(0);
});
