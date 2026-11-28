/**
 * Internal dependencies
 */
import { renderEventCard } from './event-renderer';
import type { CalendarEventItem, CalendarEventOccurrence } from '../types';

const occurrence = {
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
		performer_name: '',
		show_performer: false,
		show_ticket_link: false,
	},
} as unknown as CalendarEventOccurrence;

function event( extra: Partial< CalendarEventItem > ): CalendarEventItem {
	return {
		id: 7,
		title: 'Seeded Show',
		permalink: 'https://producer.invalid/events/7',
		date: {
			start_date: '2026-08-10',
			start_time: '19:30:00',
			end_date: '2026-08-10',
			end_time: '22:00:00',
			venue_timezone: 'America/New_York',
		},
		venue: null,
		organizer: null,
		ticket: { url: '' },
		performer: { name: '', type: '' },
		status: '',
		address: '',
		taxonomies: {
			artist: [
				{
					term_id: 12,
					name: 'Headliner',
					slug: 'headliner',
					link: 'https://producer.invalid/artist/headliner',
				},
			],
		},
		...extra,
	} as CalendarEventItem;
}

describe( 'event card badges', () => {
	it( 'renders no badges when the server sends no badge items', () => {
		const card = renderEventCard(
			event( { badges: { wrapper_classes: '', items: [] } } ),
			occurrence
		);
		expect(
			card.querySelector( '.data-machine-taxonomy-badges' )
		).toBeNull();
		expect( card.textContent ).not.toContain( 'Headliner' );
	} );

	it( 'renders filtered classes and labels as data, not markup', () => {
		const label = '<strong>Charleston</strong>';
		const card = renderEventCard(
			event( {
				badges: {
					wrapper_classes: 'themed-wrapper',
					items: [
						{
							classes: 'server-badge',
							taxonomy: 'venue',
							term: 'charleston',
							label,
							url: '/venue/charleston/',
						},
					],
				},
			} ),
			occurrence
		);
		const badge = card.querySelector< HTMLAnchorElement >(
			'.themed-wrapper .server-badge'
		);
		expect( badge?.textContent ).toBe( label );
		expect( badge?.getAttribute( 'href' ) ).toContain(
			'/venue/charleston/'
		);
		expect( badge?.querySelector( 'strong' ) ).toBeNull();
		expect( card.textContent ).not.toContain( 'Headliner' );
	} );
} );
