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
	mode: string;
	defaultDate?: string | string[];
	onReady: (
		selectedDates: Date[],
		dateStr: string,
		instance: MockFlatpickrInstance
	) => void;
	onChange: ( selectedDates: Date[] ) => void;
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

describe( 'Flatpickr range application', () => {
	let calendar: HTMLElement;
	let clearButton: HTMLButtonElement;
	let picker: MockFlatpickrInstance;
	let options: FlatpickrOptions;
	let apply: jest.Mock;

	beforeEach( () => {
		document.body.innerHTML = calendarMarkup( 'range' );
		calendar = document.querySelector< HTMLElement >( '#range' )!;
		clearButton = calendar.querySelector< HTMLButtonElement >(
			'.data-machine-events-date-clear-btn'
		)!;
		apply = jest.fn();
		mockFlatpickr.mockReset();
		mockFlatpickr.mockImplementation(
			( _input: HTMLInputElement, config: FlatpickrOptions ) => {
				options = config;
				picker = {
					selectedDates: [],
					prevMonthNav: document.createElement( 'span' ),
					nextMonthNav: document.createElement( 'span' ),
					clear: jest.fn( () => {
						picker.selectedDates = [];
						options.onChange( [] );
					} ),
					setDate: jest.fn(),
					destroy: jest.fn(),
				};
				config.onReady( [], '', picker );
				return picker;
			}
		);
	} );

	afterEach( () => {
		destroyDatePicker( calendar );
	} );

	it( 'waits for both dates before applying the range', () => {
		initDatePicker( calendar, apply );
		const start = new Date( 2026, 7, 10 );
		const end = new Date( 2026, 7, 12 );

		options.onChange( [ start ] );
		expect( apply ).not.toHaveBeenCalled();
		expect( clearButton.classList.contains( 'visible' ) ).toBe( true );

		options.onChange( [ start, end ] );
		expect( apply ).toHaveBeenCalledTimes( 1 );
		expect( apply ).toHaveBeenCalledWith( [ start, end ] );
	} );

	it( 'applies a single day only when the day is selected twice', () => {
		initDatePicker( calendar, apply );
		const day = new Date( 2026, 7, 10 );

		options.onChange( [ day ] );
		options.onChange( [ day, day ] );

		expect( apply ).toHaveBeenCalledTimes( 1 );
		expect( apply ).toHaveBeenCalledWith( [ day, day ] );
	} );

	it( 'uses Flatpickr clear as the one intentional reset path', () => {
		initDatePicker( calendar, apply );
		clearButton.classList.add( 'visible' );

		clearButton.click();

		expect( picker.clear ).toHaveBeenCalledTimes( 1 );
		expect( apply ).toHaveBeenCalledTimes( 1 );
		expect( apply ).toHaveBeenCalledWith( [] );
		expect( clearButton.classList.contains( 'visible' ) ).toBe( false );
	} );

	it( 'hydrates initial dates without applying filters', () => {
		const input = calendar.querySelector< HTMLInputElement >( 'input' )!;
		input.dataset.dateStart = '2026-08-10';
		input.dataset.dateEnd = '2026-08-12';

		initDatePicker( calendar, apply );

		expect( options.mode ).toBe( 'range' );
		expect( options.defaultDate ).toEqual( [ '2026-08-10', '2026-08-12' ] );
		expect( apply ).not.toHaveBeenCalled();
	} );
} );
