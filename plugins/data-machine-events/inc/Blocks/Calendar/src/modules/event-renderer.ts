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
 * Single source of truth: this renderer is a pure DOM assembler. It does
 * NOT compute any display strings. All display-ready values — the
 * formatted time range, multi-day labels, ISO start date, decoded
 * venue/performer names, show_* flags, badge markup, and the More Info
 * button class list — are computed server-side (`DisplayVars::build()`
 * and `Taxonomy\Badges::render_taxonomy_badges()`) and shipped in the
 * `format=data` envelope: per-occurrence values on `occurrence.display`,
 * per-event values (badges_html, button_classes) on the event. This is
 * what keeps server- and client-rendered cards from drifting — there is
 * exactly one place (PHP) that knows how to format an event. See #381.
 */

import type {
	CalendarEventItem,
	CalendarEventOccurrence,
	CalendarEventTaxonomyTerm,
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
	const display = occurrence.display;
	const isContinuation = display.is_continuation === true;
	const isMultiDay = display.is_multi_day === true;

	const item = document.createElement( 'div' );
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
	const ticketUrl = event.ticket?.url || '';
	const showTicketLink = display.show_ticket_link !== false;
	const hasTickets = showTicketLink && ticketUrl !== '';

	item.dataset.title = event.title;
	item.dataset.venue = venueName;
	item.dataset.performer = performerName;
	item.dataset.date = display.iso_start_date || '';
	item.dataset.ticketUrl = ticketUrl;
	item.dataset.hasTickets = hasTickets ? 'true' : 'false';

	const link = document.createElement( 'div' );
	link.className = 'data-machine-event-link';

	const badges = renderBadges( event );
	if ( badges ) {
		link.appendChild( badges );
	}

	const title = document.createElement( 'h4' );
	title.className = 'data-machine-event-title';
	const titleAnchor = document.createElement( 'a' );
	titleAnchor.href = event.permalink;
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
	moreInfo.href = event.permalink;
	// Server-filtered class list (runs `data_machine_events_more_info_button_classes`),
	// falling back to the default class only when absent. See #381.
	moreInfo.className =
		( event.button_classes || '' ).trim() || 'data-machine-more-info-button';
	moreInfo.textContent = 'More Info';
	meta.appendChild( moreInfo );

	link.appendChild( meta );
	item.appendChild( link );

	return item;
}

/* ------------------------------------------------------------------ */
/*  Badge rendering — server-rendered HTML injection                   */
/* ------------------------------------------------------------------ */

function renderBadges( event: CalendarEventItem ): HTMLElement | null {
	// Prefer the server-rendered, filter-applied badge markup when the
	// data envelope ships it. `Badges::render_taxonomy_badges()` runs the
	// `data_machine_events_badge_wrapper_classes` /
	// `data_machine_events_badge_classes` filters that themes hook for
	// styling, so injecting this keeps client-appended (Load More) cards
	// byte-identical to server-rendered cards. The markup is built
	// entirely server-side from escaped values (esc_html / esc_url /
	// esc_attr), so parsing it here introduces no new injection surface.
	// See #381.
	const serverBadges = renderServerBadges( event.badges_html );
	if ( serverBadges ) {
		return serverBadges;
	}

	const taxonomies = event.taxonomies || {};
	const taxonomySlugs = Object.keys( taxonomies );
	if ( taxonomySlugs.length === 0 ) {
		return null;
	}

	const venueName = ( event.venue?.name || '' ).trim().toLowerCase();
	const wrapper = document.createElement( 'div' );
	wrapper.className = 'data-machine-taxonomy-badges';

	let badgeCount = 0;
	taxonomySlugs.forEach( ( taxonomySlug ) => {
		const terms = taxonomies[ taxonomySlug ] || [];
		terms.forEach( ( term: CalendarEventTaxonomyTerm ) => {
			// Mirror the PHP guard: promoter term names that match the
			// venue name are suppressed to avoid duplicate badges.
			if (
				taxonomySlug === 'promoter' &&
				venueName !== '' &&
				term.name.trim().toLowerCase() === venueName
			) {
				return;
			}

			const badgeClasses = [
				'data-machine-taxonomy-badge',
				'data-machine-taxonomy-' + taxonomySlug,
				'data-machine-term-' + term.slug,
			];

			let badge: HTMLElement;
			if ( term.link ) {
				const anchor = document.createElement( 'a' );
				anchor.href = term.link;
				badge = anchor;
			} else {
				badge = document.createElement( 'span' );
			}
			badge.className = badgeClasses.join( ' ' );
			badge.dataset.taxonomy = taxonomySlug;
			badge.dataset.term = term.slug;
			badge.textContent = term.name;
			wrapper.appendChild( badge );
			badgeCount++;
		} );
	} );

	return badgeCount > 0 ? wrapper : null;
}

/**
 * Parse the server-rendered badge HTML (`event.badges_html`) into a
 * detached element ready to append.
 *
 * Returns null when no markup is supplied so the caller can fall back to
 * the client-side reconstruction from raw taxonomy terms. The markup is
 * produced by `Taxonomy\Badges::render_taxonomy_badges()` — a single
 * `.data-machine-taxonomy-badges` wrapper element — so we lift that first
 * element child out of the parse container.
 */
function renderServerBadges( badgesHtml?: string ): HTMLElement | null {
	const html = ( badgesHtml || '' ).trim();
	if ( html === '' ) {
		return null;
	}

	const template = document.createElement( 'template' );
	template.innerHTML = html;
	const node = template.content.firstElementChild;
	return node instanceof HTMLElement ? node : null;
}
