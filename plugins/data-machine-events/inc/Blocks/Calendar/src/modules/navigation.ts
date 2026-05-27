/**
 * Past/upcoming navigation.
 *
 * Pagination behavior moved to `load-more.ts` (issue #314, phase 2 of
 * #298). The legacy `<nav class="data-machine-events-pagination">` is
 * still server-rendered as a no-JS fallback, but it's replaced with a
 * Load More button on JS-enabled mount before any click handlers
 * could fire.
 */

/**
 * Params that must NEVER be carried over when toggling Past/Upcoming.
 *
 * - `lat`/`lng`/`radius`/`radius_unit`: geo-sync.ts pushState-rewrites these
 *   into `window.location.search` after the map fires bounds-changed (see
 *   issue #296). Past Events is a dataset switch, not a filter refinement —
 *   carrying viewport-derived geo across the boundary makes no sense and,
 *   combined with archive-context params injected downstream, can land the
 *   browser on the raw REST JSON endpoint.
 * - `paged`: a viewport/dataset change resets pagination by definition.
 * - `archive_taxonomy`/`archive_term_id`: REST-only params injected by
 *   `buildCalendarRequest()`. They have no business in a user-facing URL;
 *   if they ever leak into `window.location.search`, drop them defensively
 *   so we never bounce the user to a JSON endpoint.
 */
const STRIP_ON_PAST_TOGGLE = [
	'lat',
	'lng',
	'radius',
	'radius_unit',
	'paged',
	'archive_taxonomy',
	'archive_term_id',
];

export function initNavigation(
	calendar: HTMLElement,
	onNavigate: ( params: URLSearchParams ) => void
): void {
	initPastUpcomingButtons( calendar, onNavigate );
}

function initPastUpcomingButtons(
	calendar: HTMLElement,
	onNavigate: ( params: URLSearchParams ) => void
): void {
	const navContainer = calendar.querySelector< HTMLElement >(
		'.data-machine-events-past-navigation'
	);
	if ( ! navContainer ) {
		return;
	}

	navContainer.addEventListener( 'click', function ( e: Event ) {
		const target = e.target as HTMLElement;
		const pastBtn = target.closest( '.data-machine-events-past-btn' );
		const upcomingBtn = target.closest(
			'.data-machine-events-upcoming-btn'
		);

		if ( pastBtn || upcomingBtn ) {
			e.preventDefault();

			// Build the target from a CLEAN baseline — never from raw
			// `window.location.search`, because geo-sync may have pushed
			// transient viewport state (lat/lng/radius/radius_unit) into
			// it. See issue #296.
			//
			// Non-geo, non-pagination filters the user explicitly applied
			// (`tax_filter[*]`, `event_search`, `scope`, `date_start`,
			// `date_end`, etc.) DO carry across the Past/Upcoming toggle
			// because they're real filter intent, not viewport state.
			const params = new URLSearchParams( window.location.search );
			STRIP_ON_PAST_TOGGLE.forEach( ( key ) => params.delete( key ) );

			if ( pastBtn ) {
				params.set( 'past', '1' );
			} else {
				params.delete( 'past' );
			}

			if ( onNavigate ) {
				onNavigate( params );
			}
		}
	} );
}


