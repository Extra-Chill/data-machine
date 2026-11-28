/**
 * Internal dependencies
 */
import { fetchCalendarEvents } from './api-client';

function calendarMarkup(): HTMLElement {
	const calendar = document.createElement( 'div' );
	calendar.innerHTML =
		'<div class="data-machine-events-content">current</div>';
	return calendar;
}

describe( 'calendar response lifecycle', () => {
	it( 'does not apply a response superseded before JSON resolves', async () => {
		let resolveResponse!: ( response: Response ) => void;
		global.fetch = jest.fn(
			() =>
				new Promise< Response >( ( resolve ) => {
					resolveResponse = resolve;
				} )
		);
		const calendar = calendarMarkup();
		let current = true;
		const request = fetchCalendarEvents(
			calendar,
			new URLSearchParams(),
			{},
			{ shouldApply: () => current }
		);

		current = false;
		resolveResponse( {
			ok: true,
			json: async () => ( {
				success: true,
				events: [],
				grouping: { ordered_dates: [], by_date: {}, gaps: {} },
				pagination: { current_page: 1, total_pages: 1 },
				counter: { showing_count: 0 },
				navigation: { has_past: true },
			} ),
		} as Response );
		await request;

		expect(
			calendar.querySelector( '.data-machine-events-content' )!
				.textContent
		).toBe( 'current' );
	} );

	it( 'does not render an error after teardown aborts a request', async () => {
		global.fetch = jest.fn( ( _url, options ) => {
			return new Promise< Response >( ( _resolve, reject ) => {
				( options?.signal as AbortSignal ).addEventListener(
					'abort',
					() => reject( new DOMException( 'Aborted', 'AbortError' ) )
				);
			} );
		} );
		const calendar = calendarMarkup();
		const controller = new AbortController();
		const request = fetchCalendarEvents(
			calendar,
			new URLSearchParams(),
			{},
			{ signal: controller.signal }
		);

		controller.abort();
		await request;

		expect(
			calendar.querySelector( '.data-machine-events-content' )!
				.textContent
		).toBe( 'current' );
	} );
} );
