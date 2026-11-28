/**
 * Internal dependencies
 */
import { destroyDayLoader, initDayLoader } from './day-loader';

it( 'renders only the requested day from structured data', async () => {
	const calendar = document.createElement( 'div' );
	calendar.dataset.scopeToken = 'embedded';
	calendar.dataset.displayMode = 'month-grid';
	calendar.innerHTML = `
		<div class="data-machine-date-group" data-date="2026-08-10">
			<div class="data-machine-events-wrapper" data-deferred="true">Loading</div>
		</div>`;
	const title = 'A <strong>literal</strong> title';
	const fetchMock = jest.fn().mockResolvedValue( {
		ok: true,
		json: async () => ( {
			success: true,
			events: [ { id: 1, title, permalink: '/events/1/' } ],
			grouping: {
				by_date: { '2026-08-10': [ { post_id: 1, display: {} } ] },
			},
		} ),
	} );
	const originalFetch = global.fetch;
	global.fetch = fetchMock;
	try {
		initDayLoader( calendar );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		const query = new URL(
			fetchMock.mock.calls[ 0 ][ 0 ],
			window.location.origin
		).searchParams;
		expect( query.get( 'date_start' ) ).toBe( '2026-08-10' );
		expect( query.get( 'date_end' ) ).toBe( '2026-08-10' );
		expect( query.get( 'scope_token' ) ).toBe( 'embedded' );
		expect( calendar.querySelector( '[data-deferred]' ) ).toBeNull();
		expect(
			calendar.querySelector( '.data-machine-event-title' )?.textContent
		).toBe( title );
		expect( calendar.querySelector( 'strong' ) ).toBeNull();
	} finally {
		destroyDayLoader( calendar );
		global.fetch = originalFetch;
	}
} );
