/**
 * Taxonomy filter modal UI with REST API integration for dynamic filter loading.
 */

/**
 * Internal dependencies
 */
import { fetchFilters } from './api-client';
import { getFilterState } from './filter-state';
import type {
	ArchiveContext,
	DateContext,
	FlatTaxonomyTerm,
	FilterRequestContext,
	GeoContext,
	TaxFilters,
	TaxonomyData,
	TaxonomyTerm,
} from '../types';

/**
 * Extend HTMLElement to store cleanup references on the modal DOM node.
 */
interface ModalElement extends HTMLElement {
	_openModalHandler?: () => void;
	_closeModalHandler?: () => void;
	_closeBtns?: NodeListOf< HTMLElement >;
	_overlayClickHandler?: ( e: MouseEvent ) => void;
	_escapeHandler?: ( e: KeyboardEvent ) => void;
	_applyHandler?: () => void;
	_applyBtn?: HTMLElement;
	_resetHandler?: () => void;
	_resetBtn?: HTMLElement;
	_filterAbortController?: AbortController;
	_filterRequestGeneration?: number;
}

export function initFilterModal(
	calendar: HTMLElement,
	onApply: () => void,
	onReset: ( params: URLSearchParams ) => void
): void {
	const modal = calendar.querySelector< ModalElement >(
		'.data-machine-taxonomy-modal'
	);
	if ( ! modal ) {
		return;
	}

	if ( modal.dataset.dmListenersAttached === 'true' ) {
		return;
	}
	modal.dataset.dmListenersAttached = 'true';

	const filterState = getFilterState( calendar );

	const modalContainer = modal.querySelector< HTMLElement >(
		'.data-machine-taxonomy-modal-container'
	);
	if ( modalContainer ) {
		modalContainer.setAttribute( 'role', 'dialog' );
		modalContainer.setAttribute( 'aria-modal', 'true' );
	}

	const filterBtn = calendar.querySelector< HTMLElement >(
		'.data-machine-taxonomy-filter-btn, .data-machine-taxonomy-modal-trigger, .data-machine-events-filter-btn'
	);
	const closeBtns = modal.querySelectorAll< HTMLElement >(
		'.data-machine-modal-close, .data-machine-taxonomy-modal-close'
	);
	const applyBtn = modal.querySelector< HTMLElement >(
		'.data-machine-apply-filters'
	);
	const resetBtn = modal.querySelector< HTMLElement >(
		'.data-machine-clear-all-filters, .data-machine-reset-filters'
	);

	const closeModal = function (): void {
		cancelFilterRequest( modal );
		modal.classList.remove( 'data-machine-modal-active' );
		document.body.classList.remove( 'data-machine-modal-active' );
		if ( filterBtn ) {
			filterBtn.focus();
			filterBtn.setAttribute( 'aria-expanded', 'false' );
		}
	};

	const archiveContext = getArchiveContextFromModal( modal );

	const openModalHandler = async function (): Promise< void > {
		modal.classList.add( 'data-machine-modal-active' );
		document.body.classList.add( 'data-machine-modal-active' );
		filterBtn?.setAttribute( 'aria-expanded', 'true' );

		const params = filterState.buildParams();
		const urlParams = new URLSearchParams( window.location.search );
		await loadFilters(
			modal,
			filterState.getTaxFilters(),
			filterState.getDateContext(),
			archiveContext,
			filterState.getGeoContext(),
			{
				event_search:
					params.get( 'event_search' ) ||
					filterState.getSearchQuery(),
				scope: params.get( 'scope' ) || urlParams.get( 'scope' ) || '',
				scope_token: calendar.dataset.scopeToken || '',
			}
		);
	};

	const closeModalHandler = function (): void {
		closeModal();
	};

	const overlayClickHandler = function ( e: MouseEvent ): void {
		const target = e.target as HTMLElement;
		if (
			target === modal ||
			target.classList.contains(
				'data-machine-taxonomy-modal-overlay'
			)
		) {
			closeModal();
		}
	};

	const escapeHandler = function ( e: KeyboardEvent ): void {
		if (
			( e.key === 'Escape' || e.key === 'Esc' ) &&
			modal.classList.contains( 'data-machine-modal-active' )
		) {
			closeModal();
		}
	};

	const applyHandler = function (): void {
		if ( onApply ) {
			onApply();
		}
		closeModal();
		filterState.updateFilterCountBadge();
	};

	const resetHandler = function (): void {
		filterState.clearStorage();

		const checkboxes = modal.querySelectorAll< HTMLInputElement >(
			'input[type="checkbox"]:checked'
		);
		checkboxes.forEach( ( checkbox ) => {
			checkbox.checked = false;
		} );

		filterState.updateFilterCountBadge();

		if ( onReset ) {
			onReset( new URLSearchParams() );
		}
		closeModal();
	};

	if ( filterBtn ) {
		const modalId = modal.id || '';
		filterBtn.setAttribute( 'aria-controls', modalId );
		filterBtn.setAttribute( 'aria-expanded', 'false' );
		filterBtn.addEventListener( 'click', openModalHandler );
		modal._openModalHandler = openModalHandler;
	}

	closeBtns.forEach( function ( btn ) {
		btn.addEventListener( 'click', closeModalHandler );
	} );
	modal._closeModalHandler = closeModalHandler;
	modal._closeBtns = closeBtns;

	modal.addEventListener( 'click', overlayClickHandler as EventListener );
	modal._overlayClickHandler = overlayClickHandler;

	document.addEventListener( 'keydown', escapeHandler );
	modal._escapeHandler = escapeHandler;

	if ( applyBtn ) {
		applyBtn.addEventListener( 'click', applyHandler );
		modal._applyHandler = applyHandler;
		modal._applyBtn = applyBtn;
	}

	if ( resetBtn ) {
		resetBtn.addEventListener( 'click', resetHandler );
		modal._resetHandler = resetHandler;
		modal._resetBtn = resetBtn;
	}

	filterState.updateFilterCountBadge();
}

export function destroyFilterModal( calendar: HTMLElement ): void {
	const modal = calendar.querySelector< ModalElement >(
		'.data-machine-taxonomy-modal'
	);
	if ( ! modal ) {
		return;
	}

	if ( modal.dataset.dmListenersAttached !== 'true' ) {
		return;
	}

	cancelFilterRequest( modal );

	const filterBtn = calendar.querySelector< HTMLElement >(
		'.data-machine-taxonomy-filter-btn, .data-machine-taxonomy-modal-trigger, .data-machine-events-filter-btn'
	);

	if ( filterBtn && modal._openModalHandler ) {
		filterBtn.removeEventListener( 'click', modal._openModalHandler );
	}

	if ( modal._closeBtns && modal._closeModalHandler ) {
		modal._closeBtns.forEach( function ( btn ) {
			btn.removeEventListener(
				'click',
				modal._closeModalHandler!
			);
		} );
	}

	if ( modal._overlayClickHandler ) {
		modal.removeEventListener(
			'click',
			modal._overlayClickHandler as EventListener
		);
	}

	if ( modal._escapeHandler ) {
		document.removeEventListener( 'keydown', modal._escapeHandler );
	}

	if ( modal._applyBtn && modal._applyHandler ) {
		modal._applyBtn.removeEventListener(
			'click',
			modal._applyHandler
		);
	}

	if ( modal._resetBtn && modal._resetHandler ) {
		modal._resetBtn.removeEventListener(
			'click',
			modal._resetHandler
		);
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
	delete modal._filterAbortController;
	delete modal._filterRequestGeneration;

	modal.dataset.dmListenersAttached = 'false';
}

/* ------------------------------------------------------------------ */
/*  Private helpers                                                    */
/* ------------------------------------------------------------------ */

function getArchiveContextFromModal(
	modal: HTMLElement
): Partial< ArchiveContext > {
	const taxonomy = modal.dataset.archiveTaxonomy || '';
	const termId = parseInt( modal.dataset.archiveTermId || '0', 10 ) || 0;
	const termName = modal.dataset.archiveTermName || '';

	if ( taxonomy && termId ) {
		return { taxonomy, term_id: termId, term_name: termName };
	}
	return {};
}

async function loadFilters(
	modal: ModalElement,
	activeFilters: TaxFilters = {},
	dateContext: Partial< DateContext > = {},
	archiveContext: Partial< ArchiveContext > = {},
	geoContext: Partial< GeoContext > = {},
	requestContext: Partial< FilterRequestContext > = {}
): Promise< void > {
	const container = modal.querySelector< HTMLElement >(
		'.data-machine-filter-taxonomies'
	);

	if ( ! container ) {
		return;
	}

	const loading = modal.querySelector< HTMLElement >(
		'.data-machine-filter-loading'
	);

	modal._filterAbortController?.abort();
	const controller = new AbortController();
	const generation = ( modal._filterRequestGeneration ?? 0 ) + 1;
	modal._filterAbortController = controller;
	modal._filterRequestGeneration = generation;

	if ( loading ) {
		loading.style.display = 'flex';
	}

	try {
		const data = await fetchFilters(
			activeFilters,
			dateContext,
			archiveContext,
			geoContext,
			requestContext,
			controller.signal
		);
		if ( generation !== modal._filterRequestGeneration ) {
			return;
		}

		if ( ! data.success ) {
			throw new Error( 'API returned unsuccessful response' );
		}

		container.innerHTML = '';
		renderTaxonomies(
			container,
			data.taxonomies,
			activeFilters,
			data.archive_context || {}
		);
		attachFilterChangeListeners(
			modal,
			dateContext,
			archiveContext,
			geoContext,
			requestContext
		);
	} catch ( error ) {
		if (
			generation !== modal._filterRequestGeneration ||
			( error as Error ).name === 'AbortError'
		) {
			return;
		}
		renderFilterError( container, () => {
			void loadFilters(
				modal,
				activeFilters,
				dateContext,
				archiveContext,
				geoContext,
				requestContext
			);
		} );
	} finally {
		if ( generation === modal._filterRequestGeneration && loading ) {
			loading.style.display = 'none';
		}
	}
}

function cancelFilterRequest( modal: ModalElement ): void {
	modal._filterAbortController?.abort();
	modal._filterAbortController = undefined;
	modal._filterRequestGeneration = ( modal._filterRequestGeneration ?? 0 ) + 1;
	const loading = modal.querySelector< HTMLElement >(
		'.data-machine-filter-loading'
	);
	if ( loading ) {
		loading.style.display = 'none';
	}
}

function renderFilterError(
	container: HTMLElement,
	onRetry: () => void
): void {
	container.querySelector( '.data-machine-filter-error' )?.remove();
	const error = document.createElement( 'div' );
	error.className = 'data-machine-filter-error';
	error.innerHTML = '<p>Could not refresh filters. Previous options are still available.</p>';
	const retry = document.createElement( 'button' );
	retry.type = 'button';
	retry.className = 'data-machine-filter-retry';
	retry.textContent = 'Try again';
	retry.addEventListener( 'click', onRetry, { once: true } );
	error.appendChild( retry );
	container.appendChild( error );
}

function renderTaxonomies(
	container: HTMLElement,
	taxonomies: Record< string, TaxonomyData >,
	activeFilters: TaxFilters,
	archiveContext: Partial< ArchiveContext > = {}
): void {
	const taxonomyKeys = Object.keys( taxonomies );

	const isLockedTerm = ( slug: string, termId: number ): boolean => {
		return (
			archiveContext.taxonomy === slug &&
			archiveContext.term_id === termId
		);
	};

	taxonomyKeys.forEach( ( slug, index ) => {
		const taxonomy = taxonomies[ slug ];
		const section = document.createElement( 'div' );
		section.className = 'data-machine-taxonomy-section';
		section.dataset.taxonomy = slug;

		const label = document.createElement( 'h4' );
		label.className = 'data-machine-taxonomy-label';
		label.textContent = taxonomy.label;
		section.appendChild( label );

		const termsContainer = document.createElement( 'div' );
		termsContainer.className = 'data-machine-taxonomy-terms';

		const flatTerms = flattenHierarchy( taxonomy.terms );
		const selectedTerms = activeFilters[ slug ] || [];

		flatTerms.forEach( ( term ) => {
			const termDiv = document.createElement( 'div' );
			termDiv.className = 'data-machine-taxonomy-term';

			const isLocked = isLockedTerm( slug, term.term_id );
			if ( isLocked ) {
				termDiv.classList.add( 'data-machine-term-locked' );
			}

			if ( term.level > 0 ) {
				termDiv.classList.add(
					`data-machine-term-level-${ term.level }`
				);
				termDiv.style.marginLeft = `${ term.level * 20 }px`;
			}

			const labelEl = document.createElement( 'label' );
			labelEl.className = 'data-machine-term-checkbox-label';

			const checkbox = document.createElement( 'input' );
			checkbox.type = 'checkbox';
			checkbox.className = 'data-machine-term-checkbox';
			checkbox.name = `taxonomy_filters[${ slug }][]`;
			checkbox.value = String( term.term_id );
			checkbox.dataset.taxonomy = slug;
			checkbox.dataset.termSlug = term.slug;
			checkbox.checked =
				isLocked || selectedTerms.includes( term.term_id );

			if ( isLocked ) {
				checkbox.disabled = true;
				checkbox.dataset.locked = 'true';
			}

			const nameSpan = document.createElement( 'span' );
			nameSpan.className = 'data-machine-term-name';
			nameSpan.textContent = term.name;

			const countSpan = document.createElement( 'span' );
			countSpan.className = 'data-machine-term-count';
			countSpan.textContent = `(${ term.event_count } ${
				term.event_count === 1 ? 'event' : 'events'
			})`;

			labelEl.appendChild( checkbox );
			labelEl.appendChild( nameSpan );
			labelEl.appendChild( countSpan );
			termDiv.appendChild( labelEl );
			termsContainer.appendChild( termDiv );
		} );

		section.appendChild( termsContainer );

		if ( index < taxonomyKeys.length - 1 ) {
			const separator = document.createElement( 'hr' );
			separator.className = 'data-machine-taxonomy-separator';
			section.appendChild( separator );
		}

		container.appendChild( section );
	} );
}

function flattenHierarchy(
	terms: TaxonomyTerm[],
	level: number = 0
): FlatTaxonomyTerm[] {
	let flat: FlatTaxonomyTerm[] = [];

	terms.forEach( ( term ) => {
		flat.push( { ...term, level } );
		if ( term.children && term.children.length > 0 ) {
			flat = flat.concat(
				flattenHierarchy( term.children, level + 1 )
			);
		}
	} );

	return flat;
}

function attachFilterChangeListeners(
	modal: ModalElement,
	dateContext: Partial< DateContext > = {},
	archiveContext: Partial< ArchiveContext > = {},
	geoContext: Partial< GeoContext > = {},
	requestContext: Partial< FilterRequestContext > = {}
): void {
	const checkboxes = modal.querySelectorAll< HTMLInputElement >(
		'input[type="checkbox"]:not([data-locked="true"])'
	);

	checkboxes.forEach( ( checkbox ) => {
		checkbox.addEventListener( 'change', async () => {
			const activeFilters = getCheckedFilters( modal );
			await loadFilters(
				modal,
				activeFilters,
				dateContext,
				archiveContext,
				geoContext,
				requestContext
			);
		} );
	} );
}

function getCheckedFilters( modal: HTMLElement ): TaxFilters {
	const filters: TaxFilters = {};
	const checkboxes = modal.querySelectorAll< HTMLInputElement >(
		'input[type="checkbox"]:checked'
	);

	checkboxes.forEach( ( checkbox ) => {
		const taxonomy = checkbox.dataset.taxonomy;
		if ( ! taxonomy ) {
			return;
		}
		if ( ! filters[ taxonomy ] ) {
			filters[ taxonomy ] = [];
		}
		filters[ taxonomy ].push( parseInt( checkbox.value, 10 ) );
	} );

	return filters;
}
