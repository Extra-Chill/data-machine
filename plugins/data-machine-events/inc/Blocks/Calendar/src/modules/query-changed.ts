/** Public query-change event, separate from internal content lifecycle events. */

/**
 * Internal dependencies
 */
import type {
	ArchiveContext,
	CalendarDataResponse,
	TaxFilters,
} from '../types';

export type CalendarQueryTrigger =
	| 'search'
	| 'date_range'
	| 'scope'
	| 'taxonomy_filter'
	| 'clear'
	| 'pagination'
	| 'geo'
	| 'initial';

export interface CalendarQueryChangedDetail {
	trigger: CalendarQueryTrigger;
	query: {
		scope: string;
		date_start: string;
		date_end: string;
		has_search: boolean;
		event_search: string;
		tax_filter: TaxFilters;
		paged: number;
		month: string;
		past: boolean;
	};
	archive: Pick< ArchiveContext, 'taxonomy' | 'term_id' > | null;
	result_count: number;
}

export interface CalendarRequestOptions {
	signal?: AbortSignal;
	shouldApply?: () => boolean;
	trigger?: Exclude< CalendarQueryTrigger, 'initial' >;
}

function dispatchQueryChanged(
	calendar: HTMLElement,
	detail: CalendarQueryChangedDetail
): void {
	calendar.dispatchEvent(
		new CustomEvent( 'data-machine-calendar-query-changed', {
			bubbles: true,
			detail,
		} )
	);
}

/**
 * Report the server-rendered query without making a second request.
 * @param calendar
 */
export function emitInitialQuery( calendar: HTMLElement ): void {
	const snapshot = calendar.dataset.initialQuery;
	if ( snapshot ) {
		const detail: Omit< CalendarQueryChangedDetail, 'trigger' > =
			JSON.parse( snapshot );
		dispatchQueryChanged( calendar, { trigger: 'initial', ...detail } );
	}
}

/**
 * Report the parameters of the response that was actually applied.
 * @param calendar
 * @param trigger
 * @param params
 * @param data
 */
export function emitQueryChanged(
	calendar: HTMLElement,
	trigger: Exclude< CalendarQueryTrigger, 'initial' >,
	params: URLSearchParams,
	data: CalendarDataResponse
): void {
	const filters = new Map< string, number[] >();
	for ( const [ key, value ] of params ) {
		const match = key.match( /^tax_filter\[([\w-]+)\](?:\[\d*\])?$/ );
		const termId = Number( value );
		if ( match && Number.isSafeInteger( termId ) && termId > 0 ) {
			const taxonomy = match[ 1 ];
			const ids = filters.get( taxonomy ) ?? [];
			ids.push( termId );
			filters.set( taxonomy, ids );
		}
	}
	const eventSearch = params.get( 'event_search' )?.trim() || '';
	const taxonomy = params.get( 'archive_taxonomy' );
	const termId = Number( params.get( 'archive_term_id' ) );
	dispatchQueryChanged( calendar, {
		trigger,
		query: {
			scope: params.get( 'scope' ) || '',
			date_start: params.get( 'date_start' ) || '',
			date_end: params.get( 'date_end' ) || '',
			has_search: eventSearch !== '',
			event_search: eventSearch,
			tax_filter: Object.fromEntries( filters ),
			paged: Math.max(
				1,
				parseInt( params.get( 'paged' ) || '1', 10 ) || 1
			),
			month: params.get( 'month' ) || '',
			past: Boolean(
				params.get( 'past' ) && params.get( 'past' ) !== '0'
			),
		},
		archive: taxonomy && termId > 0 ? { taxonomy, term_id: termId } : null,
		result_count:
			data.counter?.total_count ??
			data.pagination?.total_items ??
			data.events.length,
	} );
}
