/**
 * Taxonomy filter modal UI with REST API integration for dynamic filter loading.
 */

import { fetchFilters } from './api-client.js';

let dependencies = {};
let currentFilters = {};

export function initFilterModal(calendar, onApply, onReset) {
    const modal = calendar.querySelector('.datamachine-taxonomy-modal');
    if (!modal) return;

    const modalContainer = modal.querySelector('.datamachine-taxonomy-modal-container');
    if (modalContainer) {
        modalContainer.setAttribute('role', 'dialog');
        modalContainer.setAttribute('aria-modal', 'true');
    }

    const filterBtn = calendar.querySelector('.datamachine-taxonomy-filter-btn, .datamachine-taxonomy-modal-trigger, .datamachine-events-filter-btn');
    const closeBtns = modal.querySelectorAll('.datamachine-modal-close, .datamachine-taxonomy-modal-close');
    const applyBtn = modal.querySelector('.datamachine-apply-filters');
    const resetBtn = modal.querySelector('.datamachine-clear-all-filters, .datamachine-reset-filters');

    if (filterBtn) {
        const modalId = modal.id || '';
        filterBtn.setAttribute('aria-controls', modalId);
        filterBtn.setAttribute('aria-expanded', 'false');

        filterBtn.addEventListener('click', async function() {
            modal.classList.add('datamachine-modal-active');
            document.body.classList.add('datamachine-modal-active');
            filterBtn.setAttribute('aria-expanded', 'true');
            
            await loadFilters(modal, getActiveFiltersFromUrl());
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

    closeBtns.forEach(function(btn) {
        btn.addEventListener('click', closeModal);
    });

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
        resetBtn.addEventListener('click', async function() {
            currentFilters = {};
            await loadFilters(modal, {});
            updateFilterCount(calendar);
            if (onReset) onReset();
            closeModal();
        });
    }

    updateFilterCount(calendar);
}

function getActiveFiltersFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const filters = {};
    
    params.forEach((value, key) => {
        const match = key.match(/^tax_filter\[([^\]]+)\]\[\]$/);
        if (match) {
            const taxonomy = match[1];
            if (!filters[taxonomy]) {
                filters[taxonomy] = [];
            }
            filters[taxonomy].push(parseInt(value, 10));
        }
    });
    
    return filters;
}

async function loadFilters(modal, activeFilters = {}) {
    const container = modal.querySelector('.datamachine-filter-taxonomies');
    const loading = modal.querySelector('.datamachine-filter-loading');
    
    if (!container) return;
    
    if (loading) loading.style.display = 'flex';
    container.innerHTML = '';
    
    try {
        const data = await fetchFilters(activeFilters);
        
        if (!data.success) {
            throw new Error('API returned unsuccessful response');
        }
        
        dependencies = data.dependencies || {};
        currentFilters = activeFilters;
        
        renderTaxonomies(container, data.taxonomies, activeFilters);
        attachParentChangeListeners(modal);
        
    } catch (error) {
        console.error('Error loading filters:', error);
        container.innerHTML = '<div class="datamachine-filter-error"><p>Error loading filters. Please try again.</p></div>';
    } finally {
        if (loading) loading.style.display = 'none';
    }
}

function renderTaxonomies(container, taxonomies, activeFilters) {
    const taxonomyKeys = Object.keys(taxonomies);
    
    taxonomyKeys.forEach((slug, index) => {
        const taxonomy = taxonomies[slug];
        const section = document.createElement('div');
        section.className = 'datamachine-taxonomy-section';
        section.dataset.taxonomy = slug;
        
        const isParent = Object.values(dependencies).includes(slug);
        if (isParent) {
            section.dataset.isParent = 'true';
        }
        
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
            checkbox.checked = selectedTerms.includes(term.term_id);
            
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

function attachParentChangeListeners(modal) {
    const parentTaxonomies = new Set(Object.values(dependencies));
    
    parentTaxonomies.forEach(parentSlug => {
        const section = modal.querySelector(`[data-taxonomy="${parentSlug}"]`);
        if (!section) return;
        
        const checkboxes = section.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', async () => {
                const activeFilters = getCheckedFilters(modal);
                await loadFilters(modal, activeFilters);
            });
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
