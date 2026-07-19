jest.mock( './month-grid-renderer', () => ( {
	renderMonthGrid: jest.fn( ( month: string ) => {
		const grid = global.document.createElement( 'div' );
		grid.className = 'data-machine-month-grid';
		grid.dataset.month = month;
		return grid;
	} ),
} ) );

jest.mock( './date-group-renderer', () => ( {
	renderDateGroup: jest.fn( ( date: string ) => {
		const group = global.document.createElement( 'div' );
		group.className = 'data-machine-date-group';
		group.dataset.date = date;
		return group;
	} ),
} ) );

import { renderMonthGridResponse } from './month-grid-response-renderer';

import type { CalendarDataResponse } from '../types';

function response(): CalendarDataResponse {
	return {
		success: true,
		schema: {
			name: 'calendar-data',
			version: 3,
			phase: 1,
			issue: 298,
		},
		events: [],
		grouping: {
			ordered_dates: [ '2026-08-10' ],
			by_date: {
				'2026-08-10': [
					{
						post_id: 7,
						display_context: {} as never,
						display: {} as never,
					},
				],
			},
			gaps: {},
		},
		pagination: {
			current_page: 1,
			total_pages: 2,
			total_items: 15,
			page_items: 10,
		},
		counter: {
			showing_count: 10,
			total_count: 15,
			page_start_date: '2026-08-10',
			page_end_date: '2026-08-12',
		},
		navigation: {
			show_past: false,
			past_count: 0,
			future_count: 15,
			has_past: false,
			has_future: true,
		},
		empty_html: '',
	};
}

describe( 'month-grid response rendering', () => {
	it( 'updates desktop, mobile, counter, and pagination together', () => {
		document.body.innerHTML = `
			<div class="data-machine-events-calendar">
				<div class="data-machine-month-grid" data-month="2026-07"></div>
				<div class="data-machine-events-content"><p>Old mobile events</p></div>
				<div class="data-machine-events-results-counter">Old counter</div>
				<nav class="data-machine-events-pagination">Old pagination</nav>
			</div>`;
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		const updated = jest.fn();
		calendar.addEventListener(
			'data-machine-calendar-content-updated',
			updated
		);

		renderMonthGridResponse(
			calendar,
			'2026-08',
			response(),
			'/events/?event_search=new',
			new URLSearchParams( 'event_search=new&month=2026-08' )
		);

		expect(
			calendar.querySelector< HTMLElement >( '.data-machine-month-grid' )!
				.dataset.month
		).toBe( '2026-08' );
		expect(
			calendar.querySelector< HTMLElement >( '.data-machine-date-group' )!
				.dataset.date
		).toBe( '2026-08-10' );
		expect( calendar.textContent ).not.toContain( 'Old mobile events' );
		expect(
			calendar.querySelector( '.data-machine-events-results-counter' )!
				.textContent
		).toContain( '10 of 15 Events' );
		expect(
			calendar.querySelectorAll( '.data-machine-events-pagination a' )
		).toHaveLength( 2 );
		expect( updated ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'renders the canonical recovery state for empty mobile results', () => {
		document.body.innerHTML = `
				<div class="data-machine-events-calendar">
					<div class="data-machine-month-grid" data-month="2026-07"></div>
					<div class="data-machine-events-content"><p>Old events</p></div>
				</div>`;
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		const data = response();
		data.events = [];
		data.grouping.ordered_dates = [];
		data.grouping.by_date = {};
		data.counter.showing_count = 0;
		data.counter.total_count = 0;
		data.pagination.total_pages = 1;
		data.empty_html = `
				<div class="data-machine-events-no-events">
					<p>No events found.</p>
					<button type="button" class="data-machine-events-no-events-today-link">Show events from Today</button>
				</div>`;

		renderMonthGridResponse(
			calendar,
			'2026-08',
			data,
			'/events/',
			new URLSearchParams( 'month=2026-08' )
		);

		expect(
			calendar.querySelector( '.data-machine-events-no-events' )
		).not.toBeNull();
		expect(
			calendar.querySelector< HTMLButtonElement >(
				'.data-machine-events-no-events-today-link'
			)!.type
		).toBe( 'button' );
		expect( calendar.textContent ).not.toContain( 'Old events' );
	} );
} );
