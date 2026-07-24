/**
 * Date group renderer — TypeScript port of
 * `inc/Blocks/Calendar/templates/date-group.php` plus the surrounding
 * `.data-machine-events-wrapper` emitted by
 * `Display\EventRenderer::render_date_groups()`.
 *
 * Produces:
 *
 *   <div class="data-machine-date-group data-machine-day-{dow}" data-date="..." data-event-count="...">
 *     <div class="data-machine-day-header">
 *       <div class="data-machine-day-badge data-machine-day-badge-{dow}" data-date-label="..." data-day-name="...">
 *         {formatted_date_label}
 *       </div>
 *       <span class="data-machine-day-event-count">{N} event(s)</span>
 *     </div>
 *     <div class="data-machine-events-wrapper">
 *       {event cards…}
 *     </div>
 *   </div>
 *
 * Server-rendered and client-appended groups must be visually
 * indistinguishable — same classes, same data attrs, same children.
 * Existing TS modules (`carousel`, `lazy-render`, `day-loader`) key
 * off these selectors.
 *
 * Phase-1.5 deferred: the data envelope from #301 ships the date as
 * a `Y-m-d` string but does NOT pre-format the human label
 * ("Friday, June 12th"). We reproduce the PHP `l, F jS` format
 * client-side. Server and client agree on weekday + month, but the
 * ordinal suffix logic ("1st / 2nd / 3rd") is duplicated. Follow-up
 * to extend the envelope so the server pre-formats this string the
 * way `DisplayVars::build` does for time strings.
 */

/**
 * Internal dependencies
 */
import { renderEventCard } from './event-renderer';

import type {
	CalendarEventItem,
	CalendarEventOccurrence,
} from '../types';

/**
 * Render a complete date group with its embedded event cards.
 *
 * @param date        The `Y-m-d` date this group represents.
 * @param occurrences Per-occurrence list from `grouping.by_date[date]`.
 * @param eventsById  Lookup map from `event.id` → CalendarEventItem.
 *                    Built once at the call site so multi-day events
 *                    aren't deserialized repeatedly.
 */
export function renderDateGroup(
	date: string,
	occurrences: CalendarEventOccurrence[],
	eventsById: Map< number, CalendarEventItem >
): HTMLElement {
	const dateObj = parseLocalDate( date );
	const dayOfWeek = dateObj ? weekdayName( dateObj ).toLowerCase() : '';
	const formattedDateLabel = dateObj ? formatDateLabel( dateObj ) : date;
	const eventsCount = occurrences.length;

	const group = document.createElement( 'div' );
	const groupClasses = [ 'data-machine-date-group' ];
	if ( dayOfWeek ) {
		groupClasses.push( 'data-machine-day-' + dayOfWeek );
	}
	group.className = groupClasses.join( ' ' );
	group.dataset.date = date;
	group.dataset.eventCount = String( eventsCount );

	const header = document.createElement( 'div' );
	header.className = 'data-machine-day-header';

	const badge = document.createElement( 'div' );
	const badgeClasses = [ 'data-machine-day-badge' ];
	if ( dayOfWeek ) {
		badgeClasses.push( 'data-machine-day-badge-' + dayOfWeek );
	}
	badge.className = badgeClasses.join( ' ' );
	badge.dataset.dateLabel = formattedDateLabel;
	badge.dataset.dayName = dayOfWeek;
	badge.textContent = formattedDateLabel;
	header.appendChild( badge );

	const countSpan = document.createElement( 'span' );
	countSpan.className = 'data-machine-day-event-count';
	countSpan.textContent =
		eventsCount === 1
			? `${ eventsCount } event`
			: `${ eventsCount } events`;
	header.appendChild( countSpan );

	group.appendChild( header );

	const wrapper = document.createElement( 'div' );
	wrapper.className = 'data-machine-events-wrapper';

	occurrences.forEach( ( occurrence ) => {
		const event = eventsById.get( occurrence.post_id );
		if ( ! event ) {
			return;
		}
		const card = renderEventCard( event, occurrence );
		wrapper.appendChild( card );
	} );

	group.appendChild( wrapper );

	return group;
}

/* ------------------------------------------------------------------ */
/*  Date helpers                                                       */
/* ------------------------------------------------------------------ */

/**
 * Parse a `Y-m-d` string into a Date in the LOCAL timezone (no UTC
 * shift). `new Date( 'YYYY-MM-DD' )` parses as UTC midnight and can
 * roll over the weekday in negative offsets; explicit constructor
 * avoids that.
 * @param date
 */
function parseLocalDate( date: string ): Date | null {
	const parts = date.split( '-' );
	if ( parts.length < 3 ) {
		return null;
	}
	const year = parseInt( parts[ 0 ], 10 );
	const month = parseInt( parts[ 1 ], 10 );
	const day = parseInt( parts[ 2 ], 10 );
	if ( isNaN( year ) || isNaN( month ) || isNaN( day ) ) {
		return null;
	}
	return new Date( year, month - 1, day );
}

const WEEKDAY_NAMES = [
	'Sunday',
	'Monday',
	'Tuesday',
	'Wednesday',
	'Thursday',
	'Friday',
	'Saturday',
];

const MONTH_NAMES = [
	'January',
	'February',
	'March',
	'April',
	'May',
	'June',
	'July',
	'August',
	'September',
	'October',
	'November',
	'December',
];

function weekdayName( date: Date ): string {
	return WEEKDAY_NAMES[ date.getDay() ] || '';
}

/**
 * Reproduce PHP `l, F jS` → "Friday, June 12th".
 *
 * Server canonical format lives in
 * `EventRenderer::render_date_groups()` (`$date_obj->format( 'l, F jS' )`).
 * Mirror it locally because the data envelope only ships `Y-m-d`.
 * @param date
 */
function formatDateLabel( date: Date ): string {
	const weekday = WEEKDAY_NAMES[ date.getDay() ] || '';
	const month = MONTH_NAMES[ date.getMonth() ] || '';
	const day = date.getDate();
	return `${ weekday }, ${ month } ${ day }${ ordinalSuffix( day ) }`;
}

function ordinalSuffix( n: number ): string {
	const mod100 = n % 100;
	if ( mod100 >= 11 && mod100 <= 13 ) {
		return 'th';
	}
	switch ( n % 10 ) {
		case 1:
			return 'st';
		case 2:
			return 'nd';
		case 3:
			return 'rd';
		default:
			return 'th';
	}
}
