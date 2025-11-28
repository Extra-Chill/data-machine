/**
 * Taxonomy filter modal UI and accessibility.
 */

export function initFilterModal(calendar, onApply, onReset) {
    const modal = calendar.querySelector('.datamachine-taxonomy-modal');
    if (!modal) return;

    const modalContainer = modal.querySelector('.datamachine-taxonomy-modal-container');
    if (modalContainer) {
        modalContainer.setAttribute('role', 'dialog');
        modalContainer.setAttribute('aria-modal', 'true');
    }

    const filterBtn = calendar.querySelector('.datamachine-taxonomy-filter-btn, .datamachine-taxonomy-modal-trigger, .datamachine-events-filter-btn');
    const closeBtn = modal.querySelector('.datamachine-modal-close, .datamachine-taxonomy-modal-close');
    const applyBtn = modal.querySelector('.datamachine-apply-filters');
    const resetBtn = modal.querySelector('.datamachine-clear-all-filters, .datamachine-reset-filters');

    if (filterBtn) {
        const modalId = modal.id || '';
        filterBtn.setAttribute('aria-controls', modalId);
        filterBtn.setAttribute('aria-expanded', 'false');

        filterBtn.addEventListener('click', function() {
            modal.classList.add('datamachine-modal-active');
            document.body.classList.add('datamachine-modal-active');
            filterBtn.setAttribute('aria-expanded', 'true');
        });
    }

    const closeModal = function() {
        modal.classList.remove('datamachine-modal-active');
        document.body.classList.remove('datamachine-modal-active');
        if (filterBtn) {
            filterBtn.focus();
            filterBtn.setAttribute('aria-expanded', 'false');
        }
    };

    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }

    modal.addEventListener('click', function(e) {
        if (e.target === modal || e.target.classList.contains('datamachine-taxonomy-modal-overlay')) {
            closeModal();
        }
    });

    const escapeHandler = function(e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && modal.classList.contains('datamachine-modal-active')) {
            closeModal();
        }
    };
    document.addEventListener('keydown', escapeHandler);

    if (applyBtn) {
        applyBtn.addEventListener('click', function() {
            if (onApply) onApply();
            closeModal();
            updateFilterCount(calendar);
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            const checkboxes = modal.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = false);
            updateFilterCount(calendar);
            if (onReset) onReset();
            closeModal();
        });
    }

    const checkboxes = modal.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            updateFilterCount(calendar);
        });
    });

    updateFilterCount(calendar);
}

export function updateFilterCount(calendar) {
    const modal = calendar.querySelector('.datamachine-taxonomy-modal');
    if (!modal) return;

    const checkboxes = modal.querySelectorAll('input[type="checkbox"]:checked');
    const filterBtn = calendar.querySelector('.datamachine-taxonomy-filter-btn, .datamachine-taxonomy-modal-trigger, .datamachine-events-filter-btn');
    const countBadge = filterBtn ? filterBtn.querySelector('.datamachine-filter-count') : null;

    if (!countBadge) return;

    if (checkboxes.length > 0) {
        countBadge.textContent = checkboxes.length;
        countBadge.classList.add('visible');
        if (filterBtn) {
            filterBtn.classList.add('datamachine-filters-active');
            filterBtn.setAttribute('aria-expanded', 'true');
        }
    } else {
        countBadge.classList.remove('visible');
        if (filterBtn) {
            filterBtn.classList.remove('datamachine-filters-active');
            filterBtn.setAttribute('aria-expanded', 'false');
        }
    }
}
