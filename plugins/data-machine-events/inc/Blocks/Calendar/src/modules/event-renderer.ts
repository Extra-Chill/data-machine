/**
 * Event card renderer — TypeScript DOM assembler for the
 * `inc/Blocks/Calendar/templates/event-item.php` markup.
 *
 * Produces the same DOM tree the PHP template emits so that
 * client-appended cards (Load More, progressive day-loader) look
 * byte-identical to server-rendered cards. Existing TS modules
 * (carousel, lazy-render, day-loader) hook onto these via class names
 * and data attributes — both paths must produce the same surface.
 *
 * Display strings and badge styling are shared with the PHP renderer.
 */

/**
 * Internal dependencies
 */
import type {
	CalendarEventItem,
	CalendarEventOccurrence,
	CalendarBadges,
	CalendarEventDisplay,
} from '../types';

/**
 * Render a single event card.
 *
 * @param event      Structured event from `CalendarDataResponse.events`.
 * @param occurrence Per-occurrence entry from `grouping.by_date`, carrying
 *                   the server-computed `display` block and `display_context`.
 */
export function renderEventCard(
	event: CalendarEventItem,
	occurrence: CalendarEventOccurrence
): HTMLElement {
	return renderEventItem( event, occurrence.display );
}

export function renderEventItem(
	event: Pick<
		CalendarEventItem,
		'id' | 'title' | 'permalink' | 'ticket' | 'badges' | 'button_classes'
	>,
	display: Partial< CalendarEventDisplay >,
	item: HTMLElement = document.createElement( 'div' )
): HTMLElement {
	const isContinuation = display.is_continuation === true;
	const isMultiDay = display.is_multi_day === true;

	const classes = [ 'data-machine-event-item' ];
	if ( isContinuation ) {
		classes.push( 'data-machine-event-continuation' );
	}
	if ( isMultiDay ) {
		classes.push( 'data-machine-event-multi-day' );
	}
	item.className = classes.join( ' ' );

	const venueName = display.venue_name || '';
	const performerName = display.performer_name || '';
	const ticketUrl = safeLink( event.ticket?.url );
	// Ticketmaster compliance (issue #816): `is_affiliate` is optional so
	// cached pre-v6 envelopes (no ticket.is_affiliate at all) degrade to
	// "not affiliate" rather than silently dropping a real ticket link.
	const isAffiliateTicket = event.ticket?.is_affiliate === true;
	const showTicketLink = display.show_ticket_link !== false;
	const hasTickets =
		showTicketLink && ( ticketUrl !== '' || isAffiliateTicket );

	item.dataset.title = event.title;
	item.dataset.venue = venueName;
	item.dataset.performer = performerName;
	item.dataset.date = display.iso_start_date || '';
	// When affiliate, `ticketUrl` is already empty (server-emptied, see
	// Calendar::serialize_event()) and the gated ref is this event's own
	// `id` — no separate ref field needed on the ticket object.
	if ( isAffiliateTicket ) {
		item.dataset.ticketRef = String( event.id );
	} else {
		item.dataset.ticketUrl = ticketUrl;
	}
	item.dataset.hasTickets = hasTickets ? 'true' : 'false';

	const link = document.createElement( 'div' );
	link.className = 'data-machine-event-link';

	const badges = renderBadges( event.badges );
	if ( badges ) {
		link.appendChild( badges );
	}

	const title = document.createElement( 'h4' );
	title.className = 'data-machine-event-title';
	const titleAnchor = document.createElement( 'a' );
	titleAnchor.href = safeLink( event.permalink ) || '#';
	titleAnchor.textContent = event.title;
	title.appendChild( titleAnchor );
	link.appendChild( title );

	const meta = document.createElement( 'div' );
	meta.className = 'data-machine-event-meta';

	const formattedTimeDisplay = display.formatted_time_display || '';
	const multiDayLabel = display.multi_day_label || '';

	if ( formattedTimeDisplay ) {
		const timeRow = document.createElement( 'div' );
		timeRow.className = 'data-machine-event-time';

		const clockIcon = document.createElement( 'span' );
		clockIcon.className = 'dashicons dashicons-clock';
		timeRow.appendChild( clockIcon );

		// Mirror the PHP template: raw text inserted after the icon,
		// then optional multi-day label as a separate child span.
		timeRow.appendChild(
			document.createTextNode( ' ' + formattedTimeDisplay )
		);

		if ( multiDayLabel ) {
			const labelSpan = document.createElement( 'span' );
			labelSpan.className = 'data-machine-event-multi-day-label';
			labelSpan.textContent = multiDayLabel;
			timeRow.appendChild( document.createTextNode( ' ' ) );
			timeRow.appendChild( labelSpan );
		}

		meta.appendChild( timeRow );
	}

	// `show_performer` is computed server-side (currently always false on
	// the calendar surface, per DisplayVars::build()). We honor whatever
	// the server sends rather than hardcoding the policy here.
	if ( display.show_performer && performerName ) {
		const performerRow = document.createElement( 'div' );
		performerRow.className = 'data-machine-event-performer';
		const performerIcon = document.createElement( 'span' );
		performerIcon.className = 'dashicons dashicons-admin-users';
		performerRow.appendChild( performerIcon );
		performerRow.appendChild(
			document.createTextNode( ' ' + performerName )
		);
		meta.appendChild( performerRow );
	}

	const moreInfo = document.createElement( 'a' );
	moreInfo.href = titleAnchor.href;
	// Server-filtered class list (runs `data_machine_events_more_info_button_classes`),
	// falling back to the default class only when absent. See #381.
	moreInfo.className =
		( event.button_classes || '' ).trim() ||
		'data-machine-more-info-button';
	moreInfo.textContent = 'More Info';
	meta.appendChild( moreInfo );

	link.appendChild( meta );
	item.replaceChildren( link );

	return item;
}

function renderBadges( badges: CalendarBadges ): HTMLElement | null {
	if ( ! badges?.items.length ) {
		return null;
	}
	const wrapper = document.createElement( 'div' );
	wrapper.className = badges.wrapper_classes;
	badges.items.forEach( ( item ) => {
		const url = safeLink( item.url );
		const badge = url
			? document.createElement( 'a' )
			: document.createElement( 'span' );
		if ( badge instanceof HTMLAnchorElement ) {
			badge.href = url;
		}
		badge.className = item.classes;
		badge.dataset.taxonomy = item.taxonomy;
		badge.dataset.term = item.term;
		badge.textContent = item.label;
		wrapper.appendChild( badge );
	} );
	return wrapper;
}

function safeLink( value?: string ): string {
	if ( ! value ) {
		return '';
	}
	try {
		const url = new URL( value, window.location.href );
		return [ 'http:', 'https:' ].includes( url.protocol ) ? url.href : '';
	} catch {
		return '';
	}
}
