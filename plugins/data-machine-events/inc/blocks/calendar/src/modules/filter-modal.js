/**
 * Inline collapsible taxonomy filter UI with REST API integration.
 *
 * Replaces the previous modal-based filter with an inline collapsible panel
 * that slides open below the filter bar. Loads filter options via REST API
 * with cross-filtering support.
 */

/**
 * Internal dependencies
 */
import { fetchFilters } from './api-client.js';
import { getFilterState } from './filter-state.js';

export function initFilterModal(calendar, onApply, onReset) {
    const filtersPanel = calendar.querySelector('.datamachine-taxonomy-filters-inline');
    if (!filtersPanel) {return;}

    if (filtersPanel.dataset.dmListenersAttached === 'true') {return;}
    filtersPanel.dataset.dmListenersAttached = 'true';

    const filterState = getFilterState(calendar);

    const filterBtn = calendar.querySelector('.datamachine-taxonomy-toggle, .datamachine-taxonomy-filter-btn, .datamachine-taxonomy-modal-trigger, .datamachine-events-filter-btn');
    const applyBtn = filtersPanel.querySelector('.datamachine-apply-filters');
    const resetBtn = filtersPanel.querySelector('.datamachine-clear-all-filters');

    const archiveContext = getArchiveContextFromPanel(filtersPanel);

    const toggleHandler = async function() {
        const isExpanded = !filtersPanel.hidden;

        if (isExpanded) {
            // Collapse
            filtersPanel.hidden = true;
            if (filterBtn) {
                filterBtn.setAttribute('aria-expanded', 'false');
            }
        } else {
            // Expand and load filters
            filtersPanel.hidden = false;
            if (filterBtn) {
                filterBtn.setAttribute('aria-expanded', 'true');
            }
            
            await loadFilters(
                filtersPanel, 
                filterState.getTaxFilters(), 
                filterState.getDateContext(), 
                archiveContext,
                filterState.getGeoContext()
            );
        }
    };

    const applyHandler = function() {
        if (onApply) {onApply();}
        filterState.updateFilterCountBadge();
    };

    const resetHandler = function() {
        filterState.clearStorage();
        window.history.pushState({}, '', window.location.pathname);

        const checkboxes = filtersPanel.querySelectorAll('input[type="checkbox"]:checked');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });

        filterState.updateFilterCountBadge();

        if (onReset) {onReset(new URLSearchParams());}
        filtersPanel.hidden = true;
        if (filterBtn) {
            filterBtn.setAttribute('aria-expanded', 'false');
        }
    };

    if (filterBtn) {
        const panelId = filtersPanel.id || '';
        filterBtn.setAttribute('aria-controls', panelId);
        filterBtn.setAttribute('aria-expanded', filtersPanel.hidden ? 'false' : 'true');
        filterBtn.addEventListener('click', toggleHandler);
        filtersPanel._toggleHandler = toggleHandler;
    }

    if (applyBtn) {
        applyBtn.addEventListener('click', applyHandler);
        filtersPanel._applyHandler = applyHandler;
        filtersPanel._applyBtn = applyBtn;
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', resetHandler);
        filtersPanel._resetHandler = resetHandler;
        filtersPanel._resetBtn = resetBtn;
    }

    filterState.updateFilterCountBadge();

    // If filters are already active (panel starts expanded), load them
    if (!filtersPanel.hidden) {
        loadFilters(
            filtersPanel,
            filterState.getTaxFilters(),
            filterState.getDateContext(),
            archiveContext,
            filterState.getGeoContext()
        );
    }
}

export function destroyFilterModal(calendar) {
    const filtersPanel = calendar.querySelector('.datamachine-taxonomy-filters-inline');
    if (!filtersPanel) {return;}

    if (filtersPanel.dataset.dmListenersAttached !== 'true') {return;}

    const filterBtn = calendar.querySelector('.datamachine-taxonomy-toggle, .datamachine-taxonomy-filter-btn, .datamachine-taxonomy-modal-trigger, .datamachine-events-filter-btn');

    if (filterBtn && filtersPanel._toggleHandler) {
        filterBtn.removeEventListener('click', filtersPanel._toggleHandler);
    }

    if (filtersPanel._applyBtn && filtersPanel._applyHandler) {
        filtersPanel._applyBtn.removeEventListener('click', filtersPanel._applyHandler);
    }

    if (filtersPanel._resetBtn && filtersPanel._resetHandler) {
        filtersPanel._resetBtn.removeEventListener('click', filtersPanel._resetHandler);
    }

    delete filtersPanel._toggleHandler;
    delete filtersPanel._applyHandler;
    delete filtersPanel._applyBtn;
    delete filtersPanel._resetHandler;
    delete filtersPanel._resetBtn;

    filtersPanel.dataset.dmListenersAttached = 'false';
}

function getArchiveContextFromPanel(panel) {
    const taxonomy = panel.dataset.archiveTaxonomy || '';
    const termId = parseInt(panel.dataset.archiveTermId, 10) || 0;
    const termName = panel.dataset.archiveTermName || '';
    
    if (taxonomy && termId) {
        return { taxonomy, term_id: termId, term_name: termName };
    }
    return {};
}

async function loadFilters(panel, activeFilters = {}, dateContext = {}, archiveContext = {}, geoContext = {}) {
    const container = panel.querySelector('.datamachine-filter-taxonomies');
    const loading = panel.querySelector('.datamachine-taxonomy-filters-loading');
    const actions = panel.querySelector('.datamachine-taxonomy-filters-actions');
    
    if (!container) {return;}
    
    if (loading) {loading.style.display = 'flex';}
    if (actions) {actions.hidden = true;}
    container.innerHTML = '';
    
    try {
        const data = await fetchFilters(activeFilters, dateContext, archiveContext, geoContext);
        
        if (!data.success) {
            throw new Error('API returned unsuccessful response');
        }
        
        renderTaxonomies(container, data.taxonomies, activeFilters, data.archive_context || {});
        attachFilterChangeListeners(panel, dateContext, archiveContext, geoContext);
        if (actions) {actions.hidden = false;}
        
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

function attachFilterChangeListeners(panel, dateContext = {}, archiveContext = {}, geoContext = {}) {
    const checkboxes = panel.querySelectorAll('input[type="checkbox"]:not([data-locked="true"])');
    
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', async () => {
            const activeFilters = getCheckedFilters(panel);
            await loadFilters(panel, activeFilters, dateContext, archiveContext, geoContext);
        });
    });
}

function getCheckedFilters(panel) {
    const filters = {};
    const checkboxes = panel.querySelectorAll('input[type="checkbox"]:checked');
    
    checkboxes.forEach(checkbox => {
        const taxonomy = checkbox.dataset.taxonomy;
        if (!filters[taxonomy]) {
            filters[taxonomy] = [];
        }
        filters[taxonomy].push(parseInt(checkbox.value, 10));
    });
    
    return filters;
}
