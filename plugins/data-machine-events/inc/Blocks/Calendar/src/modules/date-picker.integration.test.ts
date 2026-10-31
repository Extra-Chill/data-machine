jest.mock(
	'@wordpress/i18n',
	() => ( {
		__: jest.fn( ( text: string ) => {
			const translations: Record< string, string > = {
				'Previous month': 'Mes anterior',
				'Next month': 'Mes siguiente',
			};
			return translations[ text ] || text;
		} ),
	} ),
	{ virtual: true }
);

/**
 * External dependencies
 */
import type { Instance } from 'flatpickr/dist/types/instance';

/**
 * Internal dependencies
 */
import { destroyDatePicker, initDatePicker } from './date-picker';

function calendarMarkup( id: string ): string {
	return `<div class="data-machine-events-calendar" id="${ id }">
		<input class="data-machine-events-date-range-input" data-date-start="2026-07-01">
		<button class="data-machine-events-date-clear-btn"></button>
	</div>`;
}

function expectAccessibleNavigation( picker: Instance ): void {
	expect( picker.prevMonthNav.getAttribute( 'role' ) ).toBe( 'button' );
	expect( picker.prevMonthNav.getAttribute( 'aria-label' ) ).toBe(
		'Mes anterior'
	);
	expect( picker.prevMonthNav.tabIndex ).toBe( 0 );
	expect( picker.nextMonthNav.getAttribute( 'role' ) ).toBe( 'button' );
	expect( picker.nextMonthNav.getAttribute( 'aria-label' ) ).toBe(
		'Mes siguiente'
	);
	expect( picker.nextMonthNav.tabIndex ).toBe( 0 );
}

describe( 'real Flatpickr navigation lifecycle', () => {
	beforeEach( () => {
		document.body.innerHTML =
			calendarMarkup( 'first' ) + calendarMarkup( 'second' );
	} );

	afterEach( () => {
		document
			.querySelectorAll< HTMLElement >( '.data-machine-events-calendar' )
			.forEach( destroyDatePicker );
	} );

	it( 'keeps localized keyboard controls accessible across instances and redraws', () => {
		const calendars = document.querySelectorAll< HTMLElement >(
			'.data-machine-events-calendar'
		);
		const first = initDatePicker( calendars[ 0 ], jest.fn() ) as Instance;
		const second = initDatePicker( calendars[ 1 ], jest.fn() ) as Instance;

		expect( first ).not.toBe( second );
		expect( first.prevMonthNav ).not.toBe( second.prevMonthNav );
		expectAccessibleNavigation( first );
		expectAccessibleNavigation( second );

		const firstMonth = first.currentYear * 12 + first.currentMonth;
		first.nextMonthNav.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } )
		);
		expect( first.currentYear * 12 + first.currentMonth ).toBe(
			firstMonth + 1
		);

		const secondMonth = second.currentYear * 12 + second.currentMonth;
		second.prevMonthNav.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: ' ', bubbles: true } )
		);
		expect( second.currentYear * 12 + second.currentMonth ).toBe(
			secondMonth - 1
		);

		first.redraw();
		second.redraw();
		expectAccessibleNavigation( first );
		expectAccessibleNavigation( second );

		const detachedNext = first.nextMonthNav;
		const detachedClick = jest.fn();
		detachedNext.addEventListener( 'click', detachedClick );
		destroyDatePicker( calendars[ 0 ] );
		detachedNext.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } )
		);
		expect( detachedClick ).not.toHaveBeenCalled();
		expect( document.body.contains( first.calendarContainer ) ).toBe(
			false
		);

		const remainingMonth = second.currentYear * 12 + second.currentMonth;
		second.nextMonthNav.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } )
		);
		expect( second.currentYear * 12 + second.currentMonth ).toBe(
			remainingMonth + 1
		);
	} );

	it( 'emits only complete ranges and explicit clears through the real picker', () => {
		const calendar = document.querySelector< HTMLElement >( '#first' )!;
		const apply = jest.fn();
		const picker = initDatePicker( calendar, apply ) as Instance;

		expect( apply ).not.toHaveBeenCalled();

		picker.setDate( '2026-08-10', true );
		expect( apply ).not.toHaveBeenCalled();

		picker.setDate( [ '2026-08-10', '2026-08-12' ], true );
		expect( apply ).toHaveBeenCalledTimes( 1 );
		expect( apply.mock.calls[ 0 ][ 0 ] ).toEqual( [
			new Date( 2026, 7, 10 ),
			new Date( 2026, 7, 12 ),
		] );

		picker.clear();
		expect( apply ).toHaveBeenCalledTimes( 2 );
		expect( apply ).toHaveBeenLastCalledWith( [] );
	} );
} );
