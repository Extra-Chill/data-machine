/**
 * Synchronize every month-grid presentation from one data response.
 */

/**
 * Internal dependencies
 */
import { renderCalendarResponse } from './calendar-response-renderer';
import { renderMonthGrid } from './month-grid-renderer';
import type { CalendarDataResponse } from '../types';

export function renderMonthGridResponse(
	calendar: HTMLElement,
	month: string,
	data: CalendarDataResponse,
	baseUrl: string,
	publicParams: URLSearchParams
): void {
	const grid = calendar.querySelector< HTMLElement >(
		'.data-machine-month-grid'
	);
	const newGrid = renderMonthGrid( month, data, baseUrl );
	if ( grid ) {
		grid.replaceWith( newGrid );
	} else {
		const filterBar = calendar.querySelector(
			'.data-machine-events-filter-bar'
		);
		if ( filterBar?.parentElement ) {
			filterBar.parentElement.insertBefore(
				newGrid,
				filterBar.nextSibling
			);
		} else {
			calendar.prepend( newGrid );
		}
	}

	renderCalendarResponse( calendar, data, publicParams, true );
}
