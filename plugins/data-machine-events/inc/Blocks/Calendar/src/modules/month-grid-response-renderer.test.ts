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

/**
 * Internal dependencies
 */
import { renderMonthGridResponse } from './month-grid-response-renderer';
import { renderDateGroup } from './date-group-renderer';

import type { CalendarDataResponse } from '../types';

function response(): CalendarDataResponse {
	return {
		success: true,
		schema: {
			name: 'calendar-data',
			version: 4,
			phase: 1,
			issue: 298,
		},
		events: [
			{
				id: 7,
				title: 'Seeded event',
				permalink: 'https://producer.invalid/events/7',
				date: {
					start_date: '2026-08-10',
					start_time: '19:30:00',
					end_date: '2026-08-10',
					end_time: '22:00:00',
					venue_timezone: 'America/New_York',
				},
				venue: {
					term_id: 11,
					name: 'Seeded Hall',
					slug: 'seeded-hall',
					address: '100 Fixture Way',
					formatted_address: '100 Fixture Way, Seed City, SC 29000',
					city: 'Seed City',
					state: 'SC',
					zip: '29000',
					country: 'US',
					coordinates: '32.000000,-80.000000',
					timezone: 'America/New_York',
					website: 'https://venue.invalid',
				},
				organizer: {
					name: 'Seeded Productions',
					url: 'https://producer.invalid',
					type: 'Organization',
				},
				ticket: { url: 'https://tickets.invalid/seeded-show' },
				performer: {
					name: 'The Seeded Performers',
					type: 'PerformingGroup',
				},
				status: 'EventRescheduled',
				address: '100 Fixture Way, Seed City, SC 29000',
				taxonomies: {
					artist: [
						{
							term_id: 12,
							name: 'The Seeded Performers',
							slug: 'the-seeded-performers',
							link: 'https://producer.invalid/artist',
						},
					],
				},
			},
		],
		grouping: {
			ordered_dates: [ '2026-08-10' ],
			by_date: {
				'2026-08-10': [
					{
						post_id: 7,
						display_context: {
							is_multi_day: false,
							is_start_day: true,
							is_end_day: true,
							is_continuation: false,
							display_date: '2026-08-10',
							original_start_date: '2026-08-10',
							original_end_date: '2026-08-10',
							day_number: 1,
							total_days: 1,
						},
						display: {
							formatted_time_display: '7:30 - 10:00 PM',
							multi_day_label: '',
							iso_start_date: '2026-08-10T19:30:00-04:00',
							venue_name: 'Seeded Hall',
							performer_name: 'The Seeded Performers',
							show_performer: false,
							show_ticket_link: true,
							is_continuation: false,
							is_multi_day: false,
						},
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
		const eventsById = ( renderDateGroup as jest.Mock ).mock
			.calls[ 0 ][ 2 ];
		expect( eventsById.get( 7 ) ).toMatchObject( {
			venue: {
				city: 'Seed City',
				coordinates: '32.000000,-80.000000',
				timezone: 'America/New_York',
			},
			performer: {
				name: 'The Seeded Performers',
				type: 'PerformingGroup',
			},
			status: 'EventRescheduled',
		} );
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
