/**
 * Flatpickr date range picker integration.
 */

/**
 * External dependencies
 */
import flatpickr from 'flatpickr';

import type { FlatpickrInstance } from '../types';

interface DatePickerData {
	picker: FlatpickrInstance;
	clearBtn: HTMLElement | null;
	clearHandler: () => void;
}

const datePickers = new Map< HTMLElement, DatePickerData >();

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
		defaultDate = initialEnd
			? [ initialStart, initialEnd ]
			: initialStart;
	}

	const picker = flatpickr( dateRangeInput, {
		mode: 'range',
		dateFormat: 'Y-m-d',
		placeholder: 'Select date range...',
		allowInput: false,
		clickOpens: true,
		defaultDate,
		onChange( selectedDates: Date[] ) {
			if ( onChange ) {
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
		onClear() {
			if ( onChange ) {
				onChange( [] );
			}
			if ( clearBtn ) {
				clearBtn.classList.remove( 'visible' );
			}
		},
	} ) as unknown as FlatpickrInstance;

	const clearHandler = function (): void {
		picker.clear();
	};

	datePickers.set( calendar, { picker, clearBtn, clearHandler } );

	if (
		picker.selectedDates &&
		picker.selectedDates.length > 0 &&
		clearBtn
	) {
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
		const { picker, clearBtn, clearHandler } = data;

		if ( clearBtn && clearHandler ) {
			clearBtn.removeEventListener( 'click', clearHandler );
		}

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
