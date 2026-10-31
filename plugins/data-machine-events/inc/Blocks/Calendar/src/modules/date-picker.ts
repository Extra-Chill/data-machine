/**
 * Flatpickr date range picker integration.
 */

/**
 * External dependencies
 */
import flatpickr from 'flatpickr';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { FlatpickrInstance } from '../types';

interface DatePickerData {
	picker: FlatpickrInstance;
	clearBtn: HTMLElement | null;
	clearHandler: () => void;
	navControls: AccessibleNavControl[];
}

interface AccessibleNavControl {
	element: HTMLElement;
	keydownHandler: ( event: KeyboardEvent ) => void;
}

const datePickers = new Map< HTMLElement, DatePickerData >();

function makeNavigationControlAccessible(
	element: HTMLElement,
	label: string
): AccessibleNavControl {
	element.setAttribute( 'role', 'button' );
	element.setAttribute( 'tabindex', '0' );
	element.setAttribute( 'aria-label', label );

	const keydownHandler = ( event: KeyboardEvent ): void => {
		if ( event.key !== 'Enter' && event.key !== ' ' ) {
			return;
		}

		event.preventDefault();
		element.click();
	};

	element.addEventListener( 'keydown', keydownHandler );

	return { element, keydownHandler };
}

export function initDatePicker(
	calendar: HTMLElement,
	onChange: ( selectedDates?: Date[] ) => void
): FlatpickrInstance | null {
	const dateRangeInput =
		calendar.querySelector< HTMLInputElement >(
			'.data-machine-events-date-range-input'
		) ||
		calendar.querySelector< HTMLInputElement >(
			'[id^="data-machine-events-date-range-"]'
		);

	if ( ! dateRangeInput ) {
		return null;
	}

	const clearBtn = calendar.querySelector< HTMLElement >(
		'.data-machine-events-date-clear-btn'
	);

	const initialStart = dateRangeInput.getAttribute( 'data-date-start' );
	const initialEnd = dateRangeInput.getAttribute( 'data-date-end' );
	let defaultDate: string | string[] | undefined;

	if ( initialStart ) {
		defaultDate = initialEnd ? [ initialStart, initialEnd ] : initialStart;
	}

	let navControls: AccessibleNavControl[] = [];

	const picker = flatpickr( dateRangeInput, {
		mode: 'range',
		dateFormat: 'Y-m-d',
		placeholder: 'Select date range...',
		allowInput: false,
		clickOpens: true,
		defaultDate,
		onReady( _selectedDates, _dateStr, instance ) {
			navControls = [
				makeNavigationControlAccessible(
					instance.prevMonthNav,
					__( 'Previous month', 'data-machine-events' )
				),
				makeNavigationControlAccessible(
					instance.nextMonthNav,
					__( 'Next month', 'data-machine-events' )
				),
			];
		},
		onChange( selectedDates: Date[] ) {
			if ( selectedDates.length === 0 || selectedDates.length === 2 ) {
				onChange( selectedDates );
			}

			if ( clearBtn ) {
				if ( selectedDates && selectedDates.length > 0 ) {
					clearBtn.classList.add( 'visible' );
				} else {
					clearBtn.classList.remove( 'visible' );
				}
			}
		},
	} ) as unknown as FlatpickrInstance;

	const clearHandler = function (): void {
		picker.clear();
	};

	datePickers.set( calendar, {
		picker,
		clearBtn,
		clearHandler,
		navControls,
	} );

	if ( picker.selectedDates && picker.selectedDates.length > 0 && clearBtn ) {
		clearBtn.classList.add( 'visible' );
	}

	if ( clearBtn ) {
		clearBtn.addEventListener( 'click', clearHandler );
	}

	return picker;
}

export function destroyDatePicker( calendar: HTMLElement ): void {
	const data = datePickers.get( calendar );
	if ( data ) {
		const { picker, clearBtn, clearHandler, navControls } = data;

		if ( clearBtn && clearHandler ) {
			clearBtn.removeEventListener( 'click', clearHandler );
		}

		navControls.forEach( ( { element, keydownHandler } ) => {
			element.removeEventListener( 'keydown', keydownHandler );
		} );

		if ( picker ) {
			try {
				picker.destroy();
			} catch {
				// Ignore destruction errors
			}
		}
		datePickers.delete( calendar );
	}
}

export function getDatePicker(
	calendar: HTMLElement
): FlatpickrInstance | null {
	const data = datePickers.get( calendar );
	return data ? data.picker : null;
}
