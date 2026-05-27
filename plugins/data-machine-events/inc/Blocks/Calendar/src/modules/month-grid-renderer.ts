/**
 * Month-grid renderer (#318).
 *
 * Pure function: given a visible month and the data-only REST response
 * (`format=data`), produce the DOM node the server-side template would
 * have rendered. Used to swap the grid in place after filter / month
 * changes without a full page reload.
 *
 * The output DOM is byte-identical (class names, data attributes, CSS
 * variable contracts) to what `templates/month-grid.php` produces so
 * the existing CSS rules apply uniformly.
 */

import type {
	CalendarDataResponse,
	CalendarEventItem,
	CalendarEventOccurrence,
} from '../types';

const DAYS_OF_WEEK: readonly string[] = [
	'sunday',
	'monday',
	'tuesday',
	'wednesday',
	'thursday',
	'friday',
	'saturday',
];

const WEEKDAY_LABELS: Record< string, string > = {
	sunday: 'Sun',
	monday: 'Mon',
	tuesday: 'Tue',
	wednesday: 'Wed',
	thursday: 'Thu',
	friday: 'Fri',
	saturday: 'Sat',
};

interface RibbonGeometry {
	post_id: number;
	title: string;
	permalink: string;
	start_col: number;
	span: number;
	continues_left: boolean;
	continues_right: boolean;
	day_of_week: string;
	lane: number;
}

interface CellPayload {
	date: string;
	day_number: number;
	day_of_week: string;
	is_today: boolean;
	is_past: boolean;
	is_other_month: boolean;
	single_day_events: Array< {
		post_id: number;
		title: string;
		permalink: string;
	} >;
}

interface RowPayload {
	start_date: string;
	end_date: string;
	cells: CellPayload[];
	ribbons: RibbonGeometry[];
}

/**
 * Build the DOM for a month grid from the data-only REST envelope.
 */
export function renderMonthGrid(
	month: string,
	data: CalendarDataResponse,
	baseUrl: string
): HTMLElement {
	const today = formatLocalDate( new Date() );
	const parsedMonth = parseMonth( month ) ?? parseMonth( today.slice( 0, 7 ) );
	const visibleMonthLabel = parsedMonth
		? new Date( parsedMonth.year, parsedMonth.month0, 1 ).toLocaleString(
				undefined,
				{ month: 'long', year: 'numeric' }
			)
		: '';

	const monthString = parsedMonth
		? `${ String( parsedMonth.year ).padStart( 4, '0' ) }-${ String(
				parsedMonth.month0 + 1
			).padStart( 2, '0' ) }`
		: today.slice( 0, 7 );

	const prevMonth = parsedMonth
		? shiftMonth( parsedMonth, -1 )
		: monthString;
	const nextMonth = parsedMonth
		? shiftMonth( parsedMonth, 1 )
		: monthString;
	const todayMonth = today.slice( 0, 7 );

	const eventsById = new Map< number, CalendarEventItem >();
	data.events.forEach( ( evt ) => eventsById.set( evt.id, evt ) );

	const rows = buildRows(
		parsedMonth ?? parseMonth( today.slice( 0, 7 ) )!,
		data.grouping?.by_date ?? {},
		eventsById,
		today
	);

	// Trim trailing all-other-month rows but never below 5.
	while ( rows.length > 5 ) {
		const last = rows[ rows.length - 1 ];
		const allOther = last.cells.every( ( cell ) => cell.is_other_month );
		if ( allOther ) {
			rows.pop();
		} else {
			break;
		}
	}

	const root = document.createElement( 'div' );
	root.className = 'data-machine-month-grid';
	root.setAttribute( 'data-month', monthString );

	root.appendChild(
		renderNav( {
			month_label: visibleMonthLabel,
			prev_month: prevMonth,
			next_month: nextMonth,
			today_month: todayMonth,
			base_url: baseUrl,
		} )
	);

	root.appendChild( renderWeekdays() );

	const body = document.createElement( 'div' );
	body.className = 'data-machine-month-grid__body';

	rows.forEach( ( row, rowIndex ) => {
		body.appendChild( renderRow( row, rowIndex ) );
	} );

	root.appendChild( body );
	return root;
}

/* ------------------------------------------------------------------ */
/*  Internal builders                                                  */
/* ------------------------------------------------------------------ */

interface ParsedMonth {
	year: number;
	month0: number; // 0-indexed JS month.
}

function parseMonth( monthStr: string ): ParsedMonth | null {
	const m = /^(\d{4})-(\d{2})$/.exec( monthStr );
	if ( ! m ) {
		return null;
	}
	const year = Number( m[ 1 ] );
	const month = Number( m[ 2 ] );
	if ( year < 1970 || year > 2999 || month < 1 || month > 12 ) {
		return null;
	}
	return { year, month0: month - 1 };
}

function shiftMonth( m: ParsedMonth, delta: number ): string {
	const d = new Date( m.year, m.month0 + delta, 1 );
	return `${ String( d.getFullYear() ).padStart( 4, '0' ) }-${ String(
		d.getMonth() + 1
	).padStart( 2, '0' ) }`;
}

function formatLocalDate( d: Date ): string {
	const y = d.getFullYear();
	const mo = String( d.getMonth() + 1 ).padStart( 2, '0' );
	const da = String( d.getDate() ).padStart( 2, '0' );
	return `${ y }-${ mo }-${ da }`;
}

function buildRows(
	month: ParsedMonth,
	byDate: Record< string, CalendarEventOccurrence[] >,
	eventsById: Map< number, CalendarEventItem >,
	today: string
): RowPayload[] {
	const first = new Date( month.year, month.month0, 1 );
	const firstDow = first.getDay(); // 0 = Sunday.
	const gridStart = new Date( first );
	gridStart.setDate( gridStart.getDate() - firstDow );

	const rows: RowPayload[] = [];
	for ( let rowIndex = 0; rowIndex < 6; rowIndex++ ) {
		const rowStart = new Date( gridStart );
		rowStart.setDate( rowStart.getDate() + rowIndex * 7 );
		const rowEnd = new Date( rowStart );
		rowEnd.setDate( rowEnd.getDate() + 6 );

		const cells: CellPayload[] = [];
		for ( let col = 0; col < 7; col++ ) {
			const cellDate = new Date( rowStart );
			cellDate.setDate( cellDate.getDate() + col );
			const dateKey = formatLocalDate( cellDate );

			const occurrences = byDate[ dateKey ] ?? [];
			const singleDayEvents: CellPayload[ 'single_day_events' ] = [];
			occurrences.forEach( ( occ ) => {
				if ( occ.display_context?.is_multi_day ) {
					return;
				}
				const evt = eventsById.get( occ.post_id );
				if ( ! evt ) {
					return;
				}
				singleDayEvents.push( {
					post_id: evt.id,
					title: evt.title,
					permalink: evt.permalink,
				} );
			} );

			cells.push( {
				date: dateKey,
				day_number: cellDate.getDate(),
				day_of_week: DAYS_OF_WEEK[ cellDate.getDay() ],
				is_today: dateKey === today,
				is_past: dateKey < today,
				is_other_month: cellDate.getMonth() !== month.month0,
				single_day_events: singleDayEvents,
			} );
		}

		const ribbons = buildRowRibbons(
			rowStart,
			rowEnd,
			byDate,
			eventsById
		);

		rows.push( {
			start_date: formatLocalDate( rowStart ),
			end_date: formatLocalDate( rowEnd ),
			cells,
			ribbons,
		} );
	}
	return rows;
}

function buildRowRibbons(
	rowStart: Date,
	rowEnd: Date,
	byDate: Record< string, CalendarEventOccurrence[] >,
	eventsById: Map< number, CalendarEventItem >
): RibbonGeometry[] {
	const rowStartKey = formatLocalDate( rowStart );
	const rowEndKey = formatLocalDate( rowEnd );

	const seen = new Map< number, RibbonGeometry >();
	const cursor = new Date( rowStart );
	for ( let i = 0; i < 7; i++ ) {
		const dateKey = formatLocalDate( cursor );
		const occurrences = byDate[ dateKey ] ?? [];
		occurrences.forEach( ( occ ) => {
			if ( ! occ.display_context?.is_multi_day ) {
				return;
			}
			if ( seen.has( occ.post_id ) ) {
				return;
			}
			const evt = eventsById.get( occ.post_id );
			if ( ! evt ) {
				return;
			}
			seen.set(
				occ.post_id,
				computeRibbonSpan( evt, rowStartKey, rowEndKey )
			);
		} );
		cursor.setDate( cursor.getDate() + 1 );
	}

	const ribbons = Array.from( seen.values() ).sort( ( a, b ) => {
		if ( a.start_col !== b.start_col ) {
			return a.start_col - b.start_col;
		}
		return b.span - a.span;
	} );

	const laneEnds: number[] = [];
	ribbons.forEach( ( ribbon ) => {
		let assigned = -1;
		for ( let i = 0; i < laneEnds.length; i++ ) {
			if ( ribbon.start_col > laneEnds[ i ] ) {
				assigned = i;
				break;
			}
		}
		if ( assigned === -1 ) {
			assigned = laneEnds.length;
			laneEnds.push( -1 );
		}
		ribbon.lane = assigned;
		laneEnds[ assigned ] = ribbon.start_col + ribbon.span - 1;
	} );

	return ribbons;
}

function computeRibbonSpan(
	evt: CalendarEventItem,
	rowStartKey: string,
	rowEndKey: string
): RibbonGeometry {
	const eventStart = evt.date.start_date || '';
	const eventEnd = evt.date.end_date || eventStart;

	const clipStart = eventStart < rowStartKey ? rowStartKey : eventStart;
	let clipEnd = eventEnd > rowEndKey ? rowEndKey : eventEnd;
	if ( clipStart > clipEnd ) {
		clipEnd = clipStart;
	}

	const startCol = daysBetween( rowStartKey, clipStart );
	const endCol = daysBetween( rowStartKey, clipEnd );
	const span = Math.max( 1, endCol - startCol + 1 );

	const startDate = parseDateOnly( clipStart );
	const dow = startDate ? DAYS_OF_WEEK[ startDate.getDay() ] : '';

	return {
		post_id: evt.id,
		title: evt.title,
		permalink: evt.permalink,
		start_col: startCol,
		span,
		continues_left: eventStart < rowStartKey,
		continues_right: eventEnd > rowEndKey,
		day_of_week: dow,
		lane: 0,
	};
}

function daysBetween( a: string, b: string ): number {
	const da = parseDateOnly( a );
	const db = parseDateOnly( b );
	if ( ! da || ! db ) {
		return 0;
	}
	const ms = db.getTime() - da.getTime();
	return Math.round( ms / 86_400_000 );
}

function parseDateOnly( s: string ): Date | null {
	const m = /^(\d{4})-(\d{2})-(\d{2})/.exec( s );
	if ( ! m ) {
		return null;
	}
	return new Date(
		Number( m[ 1 ] ),
		Number( m[ 2 ] ) - 1,
		Number( m[ 3 ] )
	);
}

/* ------------------------------------------------------------------ */
/*  DOM helpers                                                        */
/* ------------------------------------------------------------------ */

function buildMonthUrl( baseUrl: string, monthYyyymm: string ): string {
	if ( ! monthYyyymm ) {
		return baseUrl || '#';
	}
	const base = baseUrl || '';
	const sep = base.indexOf( '?' ) === -1 ? '?' : '&';
	return `${ base }${ sep }month=${ encodeURIComponent( monthYyyymm ) }`;
}

function renderNav( opts: {
	month_label: string;
	prev_month: string;
	next_month: string;
	today_month: string;
	base_url: string;
} ): HTMLElement {
	const header = document.createElement( 'header' );
	header.className = 'data-machine-month-grid__nav';

	const prev = document.createElement( 'a' );
	prev.className = 'data-machine-month-grid__nav-prev';
	prev.rel = 'prev';
	prev.href = buildMonthUrl( opts.base_url, opts.prev_month );
	prev.setAttribute( 'data-month', opts.prev_month );
	prev.innerHTML =
		'<span aria-hidden="true">&larr;</span><span class="screen-reader-text">Previous month</span>';

	const title = document.createElement( 'h2' );
	title.className = 'data-machine-month-grid__title';
	title.textContent = opts.month_label;

	const todayLink = document.createElement( 'a' );
	todayLink.className = 'data-machine-month-grid__nav-today';
	todayLink.href = buildMonthUrl( opts.base_url, opts.today_month );
	todayLink.setAttribute( 'data-month', opts.today_month );
	todayLink.textContent = 'Today';

	const next = document.createElement( 'a' );
	next.className = 'data-machine-month-grid__nav-next';
	next.rel = 'next';
	next.href = buildMonthUrl( opts.base_url, opts.next_month );
	next.setAttribute( 'data-month', opts.next_month );
	next.innerHTML =
		'<span class="screen-reader-text">Next month</span><span aria-hidden="true">&rarr;</span>';

	header.appendChild( prev );
	header.appendChild( title );
	header.appendChild( todayLink );
	header.appendChild( next );
	return header;
}

function renderWeekdays(): HTMLElement {
	const wrap = document.createElement( 'div' );
	wrap.className = 'data-machine-month-grid__weekdays';
	wrap.setAttribute( 'role', 'row' );
	DAYS_OF_WEEK.forEach( ( day ) => {
		const cell = document.createElement( 'div' );
		cell.className = `data-machine-month-grid__weekday data-machine-day-${ day }`;
		cell.setAttribute( 'role', 'columnheader' );
		cell.textContent = WEEKDAY_LABELS[ day ] || day;
		wrap.appendChild( cell );
	} );
	return wrap;
}

function renderRow( row: RowPayload, rowIndex: number ): HTMLElement {
	const el = document.createElement( 'div' );
	el.className = 'data-machine-month-grid__row';
	el.setAttribute( 'data-row-index', String( rowIndex ) );
	el.setAttribute( 'data-row-start', row.start_date );
	el.setAttribute( 'data-row-end', row.end_date );

	const laneCount = row.ribbons.reduce(
		( max, r ) => Math.max( max, r.lane + 1 ),
		0
	);
	el.style.setProperty( '--data-machine-month-grid-lanes', String( laneCount ) );

	row.cells.forEach( ( cell ) => {
		el.appendChild( renderCell( cell ) );
	} );

	if ( row.ribbons.length > 0 ) {
		const ribbonWrap = document.createElement( 'div' );
		ribbonWrap.className = 'data-machine-month-grid__ribbons';
		row.ribbons.forEach( ( ribbon ) => {
			ribbonWrap.appendChild( renderRibbon( ribbon ) );
		} );
		el.appendChild( ribbonWrap );
	}

	return el;
}

function renderCell( cell: CellPayload ): HTMLElement {
	const div = document.createElement( 'div' );
	const classes = [
		'data-machine-month-grid__cell',
		`data-machine-day-${ cell.day_of_week }`,
	];
	if ( cell.is_today ) {
		classes.push( 'is-today' );
	}
	if ( cell.is_past ) {
		classes.push( 'is-past' );
	}
	if ( cell.is_other_month ) {
		classes.push( 'is-other-month' );
	}
	div.className = classes.join( ' ' );
	div.setAttribute( 'data-date', cell.date );

	const dateNumber = document.createElement( 'span' );
	dateNumber.className = 'data-machine-month-grid__date-number';
	dateNumber.setAttribute( 'aria-hidden', 'true' );
	dateNumber.textContent = String( cell.day_number );
	div.appendChild( dateNumber );

	if ( cell.single_day_events.length > 0 ) {
		const list = document.createElement( 'div' );
		list.className = 'data-machine-month-grid__events';
		cell.single_day_events.forEach( ( evt ) => {
			const a = document.createElement( 'a' );
			a.className = 'data-machine-month-grid__event-strip';
			a.href = evt.permalink;
			a.title = evt.title;
			const titleSpan = document.createElement( 'span' );
			titleSpan.className = 'data-machine-month-grid__event-title';
			titleSpan.textContent = evt.title;
			a.appendChild( titleSpan );
			list.appendChild( a );
		} );
		div.appendChild( list );
	}

	return div;
}

function renderRibbon( ribbon: RibbonGeometry ): HTMLElement {
	const a = document.createElement( 'a' );
	const classes = [
		'data-machine-month-grid__ribbon',
		`data-machine-day-${ ribbon.day_of_week }`,
	];
	if ( ribbon.continues_left ) {
		classes.push( 'is-continues-left' );
	}
	if ( ribbon.continues_right ) {
		classes.push( 'is-continues-right' );
	}
	a.className = classes.join( ' ' );
	a.href = ribbon.permalink;
	a.title = ribbon.title;
	a.style.setProperty(
		'--data-machine-month-grid-ribbon-start',
		String( ribbon.start_col + 1 )
	);
	a.style.setProperty(
		'--data-machine-month-grid-ribbon-span',
		String( ribbon.span )
	);
	a.style.setProperty(
		'--data-machine-month-grid-ribbon-lane',
		String( ribbon.lane )
	);

	const titleSpan = document.createElement( 'span' );
	titleSpan.className = 'data-machine-month-grid__ribbon-title';
	titleSpan.textContent = ribbon.title;
	a.appendChild( titleSpan );
	return a;
}
