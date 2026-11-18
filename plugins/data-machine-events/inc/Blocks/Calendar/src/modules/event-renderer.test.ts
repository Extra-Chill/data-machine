/**
 * Badge rendering treats `badges_html` as authoritative (#3999).
 *
 * `taxonomies` is unfiltered event data and can include taxonomies the
 * server-side badge filter hides (e.g. a lineup). Client cards must not
 * rebuild badges from it whenever the envelope ships `badges_html`.
 */

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
	it( 'renders no badges when the server sends empty badges_html', () => {
		const card = renderEventCard( event( { badges_html: '' } ), occurrence );
		expect( card.querySelector( '.data-machine-taxonomy-badges' ) ).toBeNull();
		expect( card.textContent ).not.toContain( 'Headliner' );
	} );

	it( 'injects server badges instead of rebuilding from taxonomies', () => {
		const card = renderEventCard(
			event( {
				badges_html:
					'<div class="data-machine-taxonomy-badges"><span class="server-badge">Charleston</span></div>',
			} ),
			occurrence
		);
		expect( card.querySelector( '.server-badge' )?.textContent ).toBe( 'Charleston' );
		expect( card.textContent ).not.toContain( 'Headliner' );
	} );

	it( 'falls back to taxonomies only for envelopes without badges_html', () => {
		const card = renderEventCard( event( {} ), occurrence );
		expect( card.querySelector( '.data-machine-taxonomy-badges' )?.textContent ).toContain( 'Headliner' );
	} );
} );
