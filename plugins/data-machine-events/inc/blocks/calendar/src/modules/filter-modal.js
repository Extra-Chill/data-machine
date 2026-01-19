/**
 * Taxonomy filter modal UI with REST API integration for dynamic filter loading.
 */

/**
 * Internal dependencies
 */
import { fetchFilters } from './api-client.js';
import { getFilterState } from './filter-state.js';

export function initFilterModal(calendar, onApply, onReset) {
    const modal = calendar.querySelector('.datamachine-taxonomy-modal');
    if (!modal) {return;}

    if (modal.dataset.dmListenersAttached === 'true') {return;}
    modal.dataset.dmListenersAttached = 'true';

    const filterState = getFilterState(calendar);

    const modalContainer = modal.querySelector('.datamachine-taxonomy-modal-container');
    if (modalContainer) {
        modalContainer.setAttribute('role', 'dialog');
        modalContainer.setAttribute('aria-modal', 'true');
    }

    const filterBtn = calendar.querySelector('.datamachine-taxonomy-filter-btn, .datamachine-taxonomy-modal-trigger, .datamachine-events-filter-btn');
    const closeBtns = modal.querySelectorAll('.datamachine-modal-close, .datamachine-taxonomy-modal-close');
    const applyBtn = modal.querySelector('.datamachine-apply-filters');
    const resetBtn = modal.querySelector('.datamachine-clear-all-filters, .datamachine-reset-filters');

    const closeModal = function() {
        modal.classList.remove('datamachine-modal-active');
        document.body.classList.remove('datamachine-modal-active');
        if (filterBtn) {
            filterBtn.focus();
            filterBtn.setAttribute('aria-expanded', 'false');
        }
    };

    const archiveContext = getArchiveContextFromModal(modal);

    const openModalHandler = async function() {
        modal.classList.add('datamachine-modal-active');
        document.body.classList.add('datamachine-modal-active');
        filterBtn.setAttribute('aria-expanded', 'true');
        
        await loadFilters(
            modal, 
            filterState.getTaxFilters(), 
            filterState.getDateContext(), 
            archiveContext
        );
    };

    const closeModalHandler = function() {
        closeModal();
    };

    const overlayClickHandler = function(e) {
        if (e.target === modal || e.target.classList.contains('datamachine-taxonomy-modal-overlay')) {
            closeModal();
        }
    };

    const escapeHandler = function(e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && modal.classList.contains('datamachine-modal-active')) {
            closeModal();
        }
    };

    const applyHandler = function() {
        if (onApply) {onApply();}
        closeModal();
        filterState.updateFilterCountBadge();
    };

    const resetHandler = function() {
        filterState.clearStorage();
        window.history.pushState({}, '', window.location.pathname);

        const checkboxes = modal.querySelectorAll('input[type="checkbox"]:checked');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });

        filterState.updateFilterCountBadge();

        if (onReset) {onReset(new URLSearchParams());}
        closeModal();
    };

    if (filterBtn) {
        const modalId = modal.id || '';
        filterBtn.setAttribute('aria-controls', modalId);
        filterBtn.setAttribute('aria-expanded', 'false');
        filterBtn.addEventListener('click', openModalHandler);
        modal._openModalHandler = openModalHandler;
    }

    closeBtns.forEach(function(btn) {
        btn.addEventListener('click', closeModalHandler);
    });
    modal._closeModalHandler = closeModalHandler;
    modal._closeBtns = closeBtns;

    modal.addEventListener('click', overlayClickHandler);
    modal._overlayClickHandler = overlayClickHandler;

    document.addEventListener('keydown', escapeHandler);
    modal._escapeHandler = escapeHandler;

    if (applyBtn) {
        applyBtn.addEventListener('click', applyHandler);
        modal._applyHandler = applyHandler;
        modal._applyBtn = applyBtn;
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', resetHandler);
        modal._resetHandler = resetHandler;
        modal._resetBtn = resetBtn;
    }

    filterState.updateFilterCountBadge();
}

export function destroyFilterModal(calendar) {
    const modal = calendar.querySelector('.datamachine-taxonomy-modal');
    if (!modal) {return;}

    if (modal.dataset.dmListenersAttached !== 'true') {return;}

    const filterBtn = calendar.querySelector('.datamachine-taxonomy-filter-btn, .datamachine-taxonomy-modal-trigger, .datamachine-events-filter-btn');

    if (filterBtn && modal._openModalHandler) {
        filterBtn.removeEventListener('click', modal._openModalHandler);
    }

    if (modal._closeBtns && modal._closeModalHandler) {
        modal._closeBtns.forEach(function(btn) {
            btn.removeEventListener('click', modal._closeModalHandler);
        });
    }

    if (modal._overlayClickHandler) {
        modal.removeEventListener('click', modal._overlayClickHandler);
    }

    if (modal._escapeHandler) {
        document.removeEventListener('keydown', modal._escapeHandler);
    }

    if (modal._applyBtn && modal._applyHandler) {
        modal._applyBtn.removeEventListener('click', modal._applyHandler);
    }

    if (modal._resetBtn && modal._resetHandler) {
        modal._resetBtn.removeEventListener('click', modal._resetHandler);
    }

    delete modal._openModalHandler;
    delete modal._closeModalHandler;
    delete modal._closeBtns;
    delete modal._overlayClickHandler;
    delete modal._escapeHandler;
    delete modal._applyHandler;
    delete modal._applyBtn;
    delete modal._resetHandler;
    delete modal._resetBtn;

    modal.dataset.dmListenersAttached = 'false';
}

function getArchiveContextFromModal(modal) {
    const taxonomy = modal.dataset.archiveTaxonomy || '';
    const termId = parseInt(modal.dataset.archiveTermId, 10) || 0;
    const termName = modal.dataset.archiveTermName || '';
    
    if (taxonomy && termId) {
        return { taxonomy, term_id: termId, term_name: termName };
    }
    return {};
}

async function loadFilters(modal, activeFilters = {}, dateContext = {}, archiveContext = {}) {
    const container = modal.querySelector('.datamachine-filter-taxonomies');
    const loading = modal.querySelector('.datamachine-filter-loading');
    
    if (!container) {return;}
    
    if (loading) {loading.style.display = 'flex';}
    container.innerHTML = '';
    
    try {
        const data = await fetchFilters(activeFilters, dateContext, archiveContext);
        
        if (!data.success) {
            throw new Error('API returned unsuccessful response');
        }
        
        renderTaxonomies(container, data.taxonomies, activeFilters, data.archive_context || {});
        attachFilterChangeListeners(modal, dateContext, archiveContext);
        
    } catch (error) {
        container.innerHTML = '<div class="datamachine-filter-error"><p>Error loading filters. Please try again.</p></div>';
    } finally {
        if (loading) {loading.style.display = 'none';}
    }
}

function renderTaxonomies(container, taxonomies, activeFilters, archiveContext = {}) {
    const taxonomyKeys = Object.keys(taxonomies);
    const isLockedTerm = (slug, termId) => {
        return archiveContext.taxonomy === slug && archiveContext.term_id === termId;
    };
    
    taxonomyKeys.forEach((slug, index) => {
        const taxonomy = taxonomies[slug];
        const section = document.createElement('div');
        section.className = 'datamachine-taxonomy-section';
        section.dataset.taxonomy = slug;
        
        const label = document.createElement('h4');
        label.className = 'datamachine-taxonomy-label';
        label.textContent = taxonomy.label;
        section.appendChild(label);
        
        const termsContainer = document.createElement('div');
        termsContainer.className = 'datamachine-taxonomy-terms';
        
        const flatTerms = flattenHierarchy(taxonomy.terms);
        const selectedTerms = activeFilters[slug] || [];
        
        flatTerms.forEach(term => {
            const termDiv = document.createElement('div');
            termDiv.className = 'datamachine-taxonomy-term';
            
            const isLocked = isLockedTerm(slug, term.term_id);
            if (isLocked) {
                termDiv.classList.add('datamachine-term-locked');
            }
            
            if (term.level > 0) {
                termDiv.classList.add(`datamachine-term-level-${term.level}`);
                termDiv.style.marginLeft = `${term.level * 20}px`;
            }
            
            const labelEl = document.createElement('label');
            labelEl.className = 'datamachine-term-checkbox-label';
            
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'datamachine-term-checkbox';
            checkbox.name = `taxonomy_filters[${slug}][]`;
            checkbox.value = term.term_id;
            checkbox.dataset.taxonomy = slug;
            checkbox.dataset.termSlug = term.slug;
            checkbox.checked = isLocked || selectedTerms.includes(term.term_id);
            
            if (isLocked) {
                checkbox.disabled = true;
                checkbox.dataset.locked = 'true';
            }
            
            const nameSpan = document.createElement('span');
            nameSpan.className = 'datamachine-term-name';
            nameSpan.textContent = term.name;
            
            const countSpan = document.createElement('span');
            countSpan.className = 'datamachine-term-count';
            countSpan.textContent = `(${term.event_count} ${term.event_count === 1 ? 'event' : 'events'})`;
            
            labelEl.appendChild(checkbox);
            labelEl.appendChild(nameSpan);
            labelEl.appendChild(countSpan);
            termDiv.appendChild(labelEl);
            termsContainer.appendChild(termDiv);
        });
        
        section.appendChild(termsContainer);
        
        if (index < taxonomyKeys.length - 1) {
            const separator = document.createElement('hr');
            separator.className = 'datamachine-taxonomy-separator';
            section.appendChild(separator);
        }
        
        container.appendChild(section);
    });
}

function flattenHierarchy(terms, level = 0) {
    let flat = [];
    
    terms.forEach(term => {
        flat.push({ ...term, level });
        if (term.children && term.children.length > 0) {
            flat = flat.concat(flattenHierarchy(term.children, level + 1));
        }
    });
    
    return flat;
}

function attachFilterChangeListeners(modal, dateContext = {}, archiveContext = {}) {
    const checkboxes = modal.querySelectorAll('input[type="checkbox"]:not([data-locked="true"])');
    
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', async () => {
            const activeFilters = getCheckedFilters(modal);
            await loadFilters(modal, activeFilters, dateContext, archiveContext);
        });
    });
}

function getCheckedFilters(modal) {
    const filters = {};
    const checkboxes = modal.querySelectorAll('input[type="checkbox"]:checked');
    
    checkboxes.forEach(checkbox => {
        const taxonomy = checkbox.dataset.taxonomy;
        if (!filters[taxonomy]) {
            filters[taxonomy] = [];
        }
        filters[taxonomy].push(parseInt(checkbox.value, 10));
    });
    
    return filters;
}
