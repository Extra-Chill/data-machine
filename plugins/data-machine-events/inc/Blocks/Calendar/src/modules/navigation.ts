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

const STRIP_ON_TODAY_RECOVERY = [
	'date_start',
	'date_end',
	'past',
	'paged',
	'month',
	'format',
	'archive_taxonomy',
	'archive_term_id',
];

// Recovery resets only temporal navigation. Search, taxonomy, geo, archive
// context, and opaque scope constraints continue narrowing the result set.

export type CalendarNavigationAction = 'past' | 'upcoming' | 'today';

export function initNavigation(
	calendar: HTMLElement,
	onNavigate: (
		params: URLSearchParams,
		action: CalendarNavigationAction
	) => void
): void {
	calendar.addEventListener( 'click', function ( event: Event ) {
		const target = event.target as HTMLElement | null;
		if ( ! target ) {
			return;
		}

		const recoveryButton = target.closest< HTMLButtonElement >(
			'.data-machine-events-no-events-today-link'
		);
		if ( recoveryButton ) {
			if ( recoveryButton.hidden ) {
				return;
			}
			event.preventDefault();
			onNavigate( buildTodayRecoveryParams( calendar ), 'today' );
			return;
		}

		const pastBtn = target.closest( '.data-machine-events-past-btn' );
		const upcomingBtn = target.closest(
			'.data-machine-events-upcoming-btn'
		);
		if ( ! pastBtn && ! upcomingBtn ) {
			return;
		}

		event.preventDefault();
		const params = new URLSearchParams( window.location.search );
		STRIP_ON_PAST_TOGGLE.forEach( ( key ) => params.delete( key ) );

		if ( pastBtn ) {
			params.set( 'past', '1' );
		} else {
			params.delete( 'past' );
		}

		onNavigate( params, pastBtn ? 'past' : 'upcoming' );
	} );

	const syncRecoveryAction = (): void => {
		const recoveryButton = calendar.querySelector< HTMLButtonElement >(
			'.data-machine-events-no-events-today-link'
		);
		if ( recoveryButton ) {
			recoveryButton.hidden = ! hasTodayRecoveryState( calendar );
		}
	};

	syncRecoveryAction();
	calendar.addEventListener(
		'data-machine-calendar-content-updated',
		syncRecoveryAction
	);
	calendar.addEventListener(
		'data-machine-month-grid-updated',
		syncRecoveryAction
	);
}

function buildTodayRecoveryParams( calendar: HTMLElement ): URLSearchParams {
	const params = new URLSearchParams( window.location.search );
	STRIP_ON_TODAY_RECOVERY.forEach( ( key ) => params.delete( key ) );

	const scope =
		new URLSearchParams( window.location.search ).get( 'scope' ) ||
		calendar.dataset.scope ||
		'';
	if ( scope && scope !== 'current' ) {
		// `current` explicitly overrides a block's default temporal scope.
		params.set( 'scope', 'current' );
	}

	return params;
}

function hasTodayRecoveryState( calendar: HTMLElement ): boolean {
	const params = new URLSearchParams( window.location.search );
	if (
		[ 'date_start', 'date_end', 'past', 'paged' ].some( ( key ) =>
			params.has( key )
		)
	) {
		return true;
	}

	const scope = params.get( 'scope' ) || calendar.dataset.scope || '';
	if ( scope && scope !== 'current' ) {
		return true;
	}

	const visibleMonth = calendar.querySelector< HTMLElement >(
		'.data-machine-month-grid'
	)?.dataset.month;
	const todayMonth = calendar.querySelector< HTMLElement >(
		'.data-machine-month-grid__nav-today'
	)?.dataset.month;

	return Boolean( visibleMonth && todayMonth && visibleMonth !== todayMonth );
}
