/**
 * Event card renderer — TypeScript port of `inc/Blocks/Calendar/templates/event-item.php`.
 *
 * Produces the same DOM tree the PHP template emits so that
 * client-appended cards (Load More, future progressive renders) look
 * byte-identical to server-rendered cards. Existing TS modules
 * (carousel, lazy-render, day-loader) hook onto these via class names
 * and data attributes — both paths must produce the same surface.
 *
 * Fidelity scope (mirrors `event-item.php`):
 *   - `.data-machine-event-item` wrapper with continuation / multi-day
 *     modifier classes, plus `data-title|venue|performer|date|ticket-url|has-tickets` attrs.
 *   - `.data-machine-event-link` → `.data-machine-taxonomy-badges` + `.data-machine-event-title` + `.data-machine-event-meta`.
 *   - `.data-machine-event-meta` → time / performer / more-info button.
 *
 * Phase-1.5 deferred concerns (documented in PR body for #314):
 *   - `formatted_time_display`, `multi_day_label`, `iso_start_date` are
 *     computed client-side here because the #301 data envelope does
 *     not (yet) expose these pre-formatted strings. See `formatTimeDisplay`
 *     and friends below — port of `DisplayVars::build()` in PHP.
 *   - `data_machine_events_badge_classes` server filter IS now honored on
 *     client-rendered cards: the data envelope ships `event.badges_html`
 *     (server-rendered via `Taxonomy\Badges::render_taxonomy_badges()`)
 *     and `renderBadges()` injects it directly, falling back to client
 *     reconstruction from raw terms only when it's absent. See #381.
 *   - `data_machine_events_more_info_button_classes` server filter is
 *     still NOT honored on client-rendered cards. Themes/plugins relying
 *     on More Info button class-list customization will see defaults on
 *     appended events. Follow-up: ship the pre-filtered button class list
 *     in the data envelope the same way `badges_html` now is.
 */

import type {
	CalendarEventItem,
	CalendarEventOccurrenceContext,
	CalendarEventTaxonomyTerm,
} from '../types';

/**
 * Render a single event card.
 *
 * @param event   Structured event from `CalendarDataResponse.events`.
 * @param context Per-occurrence display context (continuation, multi-day flags).
 *                Defaults to an empty object — single-day events have no flags.
 */
export function renderEventCard(
	event: CalendarEventItem,
	context: CalendarEventOccurrenceContext = {}
): HTMLElement {
	const isContinuation = context.is_continuation === true;
	const isMultiDay = context.is_multi_day === true;

	const item = document.createElement( 'div' );
	const classes = [ 'data-machine-event-item' ];
	if ( isContinuation ) {
		classes.push( 'data-machine-event-continuation' );
	}
	if ( isMultiDay ) {
		classes.push( 'data-machine-event-multi-day' );
	}
	item.className = classes.join( ' ' );

	const venueName = decodeUnicode( event.venue?.name || '' );
	const performerName = decodeUnicode( event.performer?.name || '' );
	const ticketUrl = event.ticket?.url || '';
	const isoStartDate = buildIsoStartDate( event );
	// `show_ticket_link` defaults to true in DisplayVars; client-side
	// fallback uses the same default. There is no per-event override
	// surfaced in the data envelope today.
	const showTicketLink = true;
	const hasTickets = showTicketLink && ticketUrl !== '';

	item.dataset.title = event.title;
	item.dataset.venue = venueName;
	item.dataset.performer = performerName;
	item.dataset.date = isoStartDate;
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

	const formattedTimeDisplay = formatTimeDisplay( event, context );
	const multiDayLabel = buildMultiDayLabel( event, context );

	if ( formattedTimeDisplay ) {
		const timeRow = document.createElement( 'div' );
		timeRow.className = 'data-machine-event-time';

		const clockIcon = document.createElement( 'span' );
		clockIcon.className = 'dashicons dashicons-clock';
		timeRow.appendChild( clockIcon );

		// Mirror the PHP template: raw text inserted after the icon,
		// then optional multi-day label as a separate child span.
		timeRow.appendChild( document.createTextNode( ' ' + formattedTimeDisplay ) );

		if ( multiDayLabel ) {
			const labelSpan = document.createElement( 'span' );
			labelSpan.className = 'data-machine-event-multi-day-label';
			labelSpan.textContent = multiDayLabel;
			timeRow.appendChild( document.createTextNode( ' ' ) );
			timeRow.appendChild( labelSpan );
		}

		meta.appendChild( timeRow );
	}

	// `show_performer` is hardcoded to `false` in DisplayVars::build()
	// (see PHP source). We mirror that behavior — performer row is
	// suppressed on the calendar surface, even when a performer name
	// exists. Kept here as dead code path for parity tracing.
	const showPerformer = false;
	if ( showPerformer && performerName ) {
		const performerRow = document.createElement( 'div' );
		performerRow.className = 'data-machine-event-performer';
		const performerIcon = document.createElement( 'span' );
		performerIcon.className = 'dashicons dashicons-admin-users';
		performerRow.appendChild( performerIcon );
		performerRow.appendChild( document.createTextNode( ' ' + performerName ) );
		meta.appendChild( performerRow );
	}

	const moreInfo = document.createElement( 'a' );
	moreInfo.href = event.permalink;
	// Default class list. Server-side `data_machine_events_more_info_button_classes`
	// filter is not honored on client-rendered cards (Phase-1.5 deferred).
	moreInfo.className = 'data-machine-more-info-button';
	moreInfo.textContent = 'More Info';
	meta.appendChild( moreInfo );

	link.appendChild( meta );
	item.appendChild( link );

	return item;
}

/* ------------------------------------------------------------------ */
/*  Badge rendering — port of Taxonomy\Badges::render_taxonomy_badges */
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

/* ------------------------------------------------------------------ */
/*  Display formatting — port of Display\DisplayVars::build()          */
/* ------------------------------------------------------------------ */

/**
 * Build the ISO 8601 timestamp the server stores in `data-date`.
 *
 * Server uses `DateTime::format('c')` against the event timezone.
 * Reproducing the full TZ-aware timestamp client-side without the
 * IANA zone database is fragile; we emit the closest reasonable
 * approximation by combining date + time into a local naive ISO,
 * which is enough for the existing consumers (sort keys, label
 * read-back). Same-page parity with server-rendered cards is the
 * goal; downstream consumers do not parse this with timezone math.
 */
function buildIsoStartDate( event: CalendarEventItem ): string {
	const date = event.date?.start_date || '';
	const time = event.date?.start_time || '';
	if ( ! date ) {
		return '';
	}
	const timePart = time || '00:00:00';
	// Naive ISO; matches `Y-m-d\TH:i:s` shape, omits offset because
	// we don't have the canonical IANA offset for the event TZ in JS.
	return `${ date }T${ timePart }`;
}

/**
 * Reproduce `DisplayVars::format_time_range` + the multi-day "Ongoing
 * · ends X" / "through X" logic.
 *
 * Logic mirror:
 *   - Multi-day + continuation → "Ongoing · ends MMM D"
 *   - Multi-day + start day    → start-time range (multi_day_label set separately)
 *   - Single day, sentinel end → just start time
 *   - Single day, real range, same AM/PM → "7:30 - 10:00 PM"
 *   - Single day, real range, diff AM/PM → "11:30 AM - 1:00 PM"
 */
function formatTimeDisplay(
	event: CalendarEventItem,
	context: CalendarEventOccurrenceContext
): string {
	const startDate = event.date?.start_date || '';
	const startTime = event.date?.start_time || '';
	const endDate = event.date?.end_date || '';
	const endTime = event.date?.end_time || '';

	if ( ! startDate ) {
		return '';
	}

	const isMultiDay = context.is_multi_day === true;
	const isContinuation = context.is_continuation === true;

	if ( isMultiDay && endDate ) {
		if ( isContinuation ) {
			return 'Ongoing · ends ' + formatMonthDay( endDate );
		}
		// Start day of multi-day: show start-time range, multi-day
		// label appended separately by the caller.
		return formatTimeRange( startDate, startTime, endDate, endTime );
	}

	return formatTimeRange( startDate, startTime, endDate, endTime );
}

function buildMultiDayLabel(
	event: CalendarEventItem,
	context: CalendarEventOccurrenceContext
): string {
	const endDate = event.date?.end_date || '';
	const isMultiDay = context.is_multi_day === true;
	const isContinuation = context.is_continuation === true;
	if ( isMultiDay && endDate && ! isContinuation ) {
		return 'through ' + formatMonthDay( endDate );
	}
	return '';
}

function formatTimeRange(
	startDate: string,
	startTime: string,
	endDate: string,
	endTime: string
): string {
	const startMoment = parseDateTime( startDate, startTime );
	if ( ! startMoment ) {
		return '';
	}
	const startFormatted = formatClock( startMoment );

	if ( ! endDate || ! endTime || isSentinelEndTime( endTime ) ) {
		return startFormatted;
	}

	const endMoment = parseDateTime( endDate, endTime );
	if ( ! endMoment ) {
		return startFormatted;
	}

	// No real end time when start == end.
	if (
		startMoment.dateKey === endMoment.dateKey &&
		startMoment.h === endMoment.h &&
		startMoment.m === endMoment.m
	) {
		return startFormatted;
	}

	// Cross-day range: only show the start (mirrors PHP behavior).
	if ( startMoment.dateKey !== endMoment.dateKey ) {
		return startFormatted;
	}

	const startPeriod = startMoment.h >= 12 ? 'PM' : 'AM';
	const endPeriod = endMoment.h >= 12 ? 'PM' : 'AM';

	if ( startPeriod === endPeriod ) {
		// "7:30 - 10:00 PM"
		const startNoSuffix = formatClockNoSuffix( startMoment );
		const endFormatted = formatClock( endMoment );
		return startNoSuffix + ' - ' + endFormatted;
	}

	// "11:30 AM - 1:00 PM"
	const endFormatted = formatClock( endMoment );
	return startFormatted + ' - ' + endFormatted;
}

interface ParsedTime {
	dateKey: string; // "Y-m-d"
	h: number; // 0..23
	m: number; // 0..59
}

function parseDateTime( date: string, time: string ): ParsedTime | null {
	if ( ! date ) {
		return null;
	}
	const t = time || '00:00:00';
	const parts = t.split( ':' );
	const h = parseInt( parts[ 0 ] || '0', 10 );
	const m = parseInt( parts[ 1 ] || '0', 10 );
	if ( isNaN( h ) || isNaN( m ) ) {
		return null;
	}
	return { dateKey: date, h, m };
}

function formatClock( pt: ParsedTime ): string {
	const period = pt.h >= 12 ? 'PM' : 'AM';
	let displayHour = pt.h % 12;
	if ( displayHour === 0 ) {
		displayHour = 12;
	}
	const mm = pt.m < 10 ? '0' + pt.m : String( pt.m );
	return `${ displayHour }:${ mm } ${ period }`;
}

function formatClockNoSuffix( pt: ParsedTime ): string {
	let displayHour = pt.h % 12;
	if ( displayHour === 0 ) {
		displayHour = 12;
	}
	const mm = pt.m < 10 ? '0' + pt.m : String( pt.m );
	return `${ displayHour }:${ mm }`;
}

/**
 * Match `DisplayVars::is_sentinel_end_time`. The "23:59" sentinel is
 * an internal SQL-range marker and should never appear in display
 * strings.
 */
function isSentinelEndTime( time: string ): boolean {
	const normalized = ( time || '' ).slice( 0, 5 );
	return normalized === '23:59';
}

/**
 * Format `YYYY-MM-DD` → "Mar 22" (PHP `M j`).
 */
function formatMonthDay( date: string ): string {
	const parts = date.split( '-' );
	if ( parts.length < 3 ) {
		return date;
	}
	const year = parseInt( parts[ 0 ], 10 );
	const month = parseInt( parts[ 1 ], 10 );
	const day = parseInt( parts[ 2 ], 10 );
	if ( isNaN( year ) || isNaN( month ) || isNaN( day ) ) {
		return date;
	}
	const monthNames = [
		'Jan',
		'Feb',
		'Mar',
		'Apr',
		'May',
		'Jun',
		'Jul',
		'Aug',
		'Sep',
		'Oct',
		'Nov',
		'Dec',
	];
	const monthName = monthNames[ month - 1 ] || '';
	return `${ monthName } ${ day }`;
}

/**
 * Mirror `DisplayVars::decode_unicode` — converts `\uXXXX` escape
 * sequences to their character form. The PHP path runs on raw
 * block-attribute strings; the data envelope generally ships
 * already-decoded venue/performer names, but mirroring the helper
 * keeps the surface identical when the server hasn't decoded yet.
 */
function decodeUnicode( str: string ): string {
	if ( ! str ) {
		return '';
	}
	return str.replace( /\\u([0-9a-fA-F]{4})/g, ( _match, hex ) =>
		String.fromCharCode( parseInt( hex, 16 ) )
	);
}
