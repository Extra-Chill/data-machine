const dialog = document.querySelector('#lightbox');
const caption = dialog?.querySelector('p');
document.querySelectorAll('[data-lightbox]').forEach((button) => {
  button.addEventListener('click', () => {
    if (!dialog?.showModal) return;
    caption.textContent = button.dataset.lightbox;
    dialog.showModal();
  });
});
