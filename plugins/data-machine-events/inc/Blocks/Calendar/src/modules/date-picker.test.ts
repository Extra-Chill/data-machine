jest.mock(
	'@wordpress/i18n',
	() => ( {
		__: jest.fn( ( text: string ) => text ),
	} ),
	{ virtual: true }
);

const mockFlatpickr = jest.fn();

jest.mock( 'flatpickr', () => ( {
	__esModule: true,
	default: ( input: HTMLInputElement, options: FlatpickrOptions ) =>
		mockFlatpickr( input, options ),
} ) );

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { destroyDatePicker, initDatePicker } from './date-picker';

interface FlatpickrOptions {
	onReady: (
		selectedDates: Date[],
		dateStr: string,
		instance: MockFlatpickrInstance
	) => void;
}

interface MockFlatpickrInstance {
	selectedDates: Date[];
	prevMonthNav: HTMLElement;
	nextMonthNav: HTMLElement;
	clear: jest.Mock;
	setDate: jest.Mock;
	destroy: jest.Mock;
}

const mockTranslate = __ as jest.Mock;

function calendarMarkup( id: string ): string {
	return `<div class="data-machine-events-calendar" id="${ id }">
		<input class="data-machine-events-date-range-input">
		<button class="data-machine-events-date-clear-btn"></button>
	</div>`;
}

describe( 'Flatpickr month navigation accessibility', () => {
	beforeEach( () => {
		document.body.innerHTML = calendarMarkup( 'first' );
		mockFlatpickr.mockReset();
		mockTranslate.mockClear();
		mockFlatpickr.mockImplementation(
			( input: HTMLInputElement, options: FlatpickrOptions ) => {
				const instance: MockFlatpickrInstance = {
					selectedDates: [],
					prevMonthNav: document.createElement( 'span' ),
					nextMonthNav: document.createElement( 'span' ),
					clear: jest.fn(),
					setDate: jest.fn(),
					destroy: jest.fn(),
				};
				input.after( instance.prevMonthNav, instance.nextMonthNav );
				options.onReady( [], '', instance );
				return instance;
			}
		);
	} );

	it( 'names and keyboard-enables each instance navigation control', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			calendarMarkup( 'second' )
		);
		const calendars = document.querySelectorAll< HTMLElement >(
			'.data-machine-events-calendar'
		);
		calendars.forEach( ( calendar ) =>
			initDatePicker( calendar, jest.fn() )
		);

		calendars.forEach( ( calendar ) => {
			const controls = calendar.querySelectorAll< HTMLElement >( 'span' );
			const previousClick = jest.fn();
			const nextClick = jest.fn();
			controls[ 0 ].addEventListener( 'click', previousClick );
			controls[ 1 ].addEventListener( 'click', nextClick );

			expect( controls[ 0 ].getAttribute( 'role' ) ).toBe( 'button' );
			expect( controls[ 0 ].tabIndex ).toBe( 0 );
			expect( controls[ 0 ].getAttribute( 'aria-label' ) ).toBe(
				'Previous month'
			);
			expect( controls[ 1 ].getAttribute( 'aria-label' ) ).toBe(
				'Next month'
			);

			controls[ 0 ].dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } )
			);
			controls[ 1 ].dispatchEvent(
				new KeyboardEvent( 'keydown', { key: ' ', bubbles: true } )
			);
			expect( previousClick ).toHaveBeenCalledTimes( 1 );
			expect( nextClick ).toHaveBeenCalledTimes( 1 );
		} );

		expect( mockTranslate ).toHaveBeenCalledWith(
			'Previous month',
			'data-machine-events'
		);
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Next month',
			'data-machine-events'
		);
	} );

	it( 'removes custom keyboard handlers when destroyed', () => {
		const calendar = document.querySelector< HTMLElement >( '#first' )!;
		const picker = initDatePicker( calendar, jest.fn() );
		const previous = calendar.querySelector< HTMLElement >( 'span' )!;
		const click = jest.fn();
		previous.addEventListener( 'click', click );

		destroyDatePicker( calendar );
		previous.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } )
		);

		expect( click ).not.toHaveBeenCalled();
		expect( picker!.destroy ).toHaveBeenCalledTimes( 1 );
	} );
} );
