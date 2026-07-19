jest.mock(
	'@wordpress/i18n',
	() => ( {
		__: jest.fn( ( text: string ) => text ),
	} ),
	{ virtual: true }
);

import { __ } from '@wordpress/i18n';
import { initCarousel } from './carousel';

const mockTranslate = __ as jest.Mock;

describe( 'calendar carousel controls', () => {
	beforeEach( () => {
		mockTranslate.mockClear();
		document.body.innerHTML = `
			<div class="data-machine-events-calendar">
				<div class="data-machine-date-group" data-event-count="3">
					<div class="data-machine-events-wrapper">
						<div class="data-machine-event-item"></div>
						<div class="data-machine-event-item"></div>
						<div class="data-machine-event-item"></div>
					</div>
				</div>
			</div>`;

		window.matchMedia = jest.fn().mockReturnValue( { matches: false } );
		window.requestAnimationFrame = jest.fn( ( callback ) => {
			callback( 0 );
			return 1;
		} );

		const wrapper = document.querySelector< HTMLElement >(
			'.data-machine-events-wrapper'
		)!;
		Object.defineProperties( wrapper, {
			clientWidth: { configurable: true, value: 300 },
			scrollWidth: { configurable: true, value: 900 },
			scrollLeft: { configurable: true, value: 0, writable: true },
			scrollBy: { configurable: true, value: jest.fn() },
		} );
		wrapper.getBoundingClientRect = jest
			.fn()
			.mockReturnValue( { left: 0, right: 300, width: 300 } );
		document
			.querySelectorAll< HTMLElement >( '.data-machine-event-item' )
			.forEach( ( event ) => {
				event.getBoundingClientRect = jest
					.fn()
					.mockReturnValue( { left: 0, right: 300, width: 300 } );
			} );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'creates named buttons with native keyboard activation', () => {
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initCarousel( calendar );

		const previous = calendar.querySelector< HTMLButtonElement >(
			'.data-machine-carousel-chevron-left'
		)!;
		const next = calendar.querySelector< HTMLButtonElement >(
			'.data-machine-carousel-chevron-right'
		)!;
		const wrapper = calendar.querySelector< HTMLElement >(
			'.data-machine-events-wrapper'
		)!;

		expect( previous.tagName ).toBe( 'BUTTON' );
		expect( previous.type ).toBe( 'button' );
		expect( previous.getAttribute( 'aria-label' ) ).toBe(
			'Show previous events'
		);
		expect( next.getAttribute( 'aria-label' ) ).toBe( 'Show next events' );
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Show previous events',
			'data-machine-events'
		);
		expect( mockTranslate ).toHaveBeenCalledWith(
			'Show next events',
			'data-machine-events'
		);

		next.focus();
		next.click();
		expect( document.activeElement ).toBe( next );
		expect( wrapper.scrollBy ).toHaveBeenCalledWith( {
			left: 300,
			behavior: 'smooth',
		} );
	} );

	it( 'moves one card for a complete mouse click sequence', () => {
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initCarousel( calendar );
		const next = calendar.querySelector< HTMLButtonElement >(
			'.data-machine-carousel-chevron-right'
		)!;
		const wrapper = calendar.querySelector< HTMLElement >(
			'.data-machine-events-wrapper'
		)!;

		next.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
		next.dispatchEvent( new MouseEvent( 'mouseup', { bubbles: true } ) );
		next.dispatchEvent(
			new MouseEvent( 'click', { bubbles: true, detail: 1 } )
		);

		expect( wrapper.scrollBy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'repeats while held without adding a release click', () => {
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initCarousel( calendar );
		jest.useFakeTimers();
		const next = calendar.querySelector< HTMLButtonElement >(
			'.data-machine-carousel-chevron-right'
		)!;
		const wrapper = calendar.querySelector< HTMLElement >(
			'.data-machine-events-wrapper'
		)!;

		next.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) );
		jest.advanceTimersByTime( 650 );
		next.dispatchEvent( new MouseEvent( 'mouseup', { bubbles: true } ) );
		next.dispatchEvent(
			new MouseEvent( 'click', { bubbles: true, detail: 1 } )
		);

		expect( wrapper.scrollBy ).toHaveBeenCalledTimes( 3 );
		jest.advanceTimersByTime( 650 );
		expect( wrapper.scrollBy ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'keeps boundary controls hidden and out of the tab order', () => {
		const calendar = document.querySelector< HTMLElement >(
			'.data-machine-events-calendar'
		)!;
		initCarousel( calendar );

		const wrapper = calendar.querySelector< HTMLElement >(
			'.data-machine-events-wrapper'
		)!;
		const previous = calendar.querySelector< HTMLButtonElement >(
			'.data-machine-carousel-chevron-left'
		)!;
		const next = calendar.querySelector< HTMLButtonElement >(
			'.data-machine-carousel-chevron-right'
		)!;

		expect( previous.disabled ).toBe( true );
		expect( previous.classList ).toContain( 'hidden' );
		expect( next.disabled ).toBe( false );

		wrapper.scrollLeft = 600;
		wrapper.dispatchEvent( new Event( 'scroll' ) );

		expect( previous.disabled ).toBe( false );
		expect( previous.classList ).not.toContain( 'hidden' );
		expect( next.disabled ).toBe( true );
		expect( next.classList ).toContain( 'hidden' );
	} );
} );
