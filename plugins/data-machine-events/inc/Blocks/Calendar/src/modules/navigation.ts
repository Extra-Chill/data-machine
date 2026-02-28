/**
 * Past/upcoming navigation and pagination link handling.
 */

export function initNavigation(
	calendar: HTMLElement,
	onNavigate: ( params: URLSearchParams ) => void
): void {
	initPastUpcomingButtons( calendar, onNavigate );
	initPaginationLinks( calendar, onNavigate );
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

			const params = new URLSearchParams( window.location.search );
			params.delete( 'paged' );

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

function initPaginationLinks(
	calendar: HTMLElement,
	onNavigate: ( params: URLSearchParams ) => void
): void {
	const paginationContainer = calendar.querySelector< HTMLElement >(
		'.data-machine-events-pagination'
	);
	if ( ! paginationContainer ) {
		return;
	}

	paginationContainer.addEventListener( 'click', function ( e: Event ) {
		const target = e.target as HTMLElement;
		const link = target.closest< HTMLAnchorElement >( 'a' );
		if ( ! link ) {
			return;
		}

		e.preventDefault();

		const url = new URL( link.href );
		const params = new URLSearchParams( url.search );

		if ( onNavigate ) {
			onNavigate( params );
		}
	} );
}
