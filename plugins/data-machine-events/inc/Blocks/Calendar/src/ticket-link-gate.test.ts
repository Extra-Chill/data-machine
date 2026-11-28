/**
 * Tests for `assets/js/ticket-link-gate.js` (issue #816 follow-up).
 *
 * The script lives outside this block's `src/` tree — it's a plugin-root
 * asset shared by the EventDetails block, the Calendar block, and the
 * legacy `the_content` gate — but this block's Jest harness
 * (`wp-scripts test-unit-js`) is the only test runner already wired up
 * anywhere in this repo for plain browser scripts, so it's reused here
 * rather than standing up a second one for a single file.
 *
 * Regression coverage for the review fix: the redirect href MUST be built
 * from the localized `window.dataMachineEventsTicketLinkGate.restBase`
 * (set via `wp_localize_script()`), never from a hardcoded `/wp-json/`
 * string literal — see the PR discussion on
 * https://github.com/Extra-Chill/data-machine-events/pull/820.
 */

const GLOBAL_KEY = 'dataMachineEventsTicketLinkGate';

// The script is a self-executing IIFE: importing it once wires its
// document-level listeners as a load-time side effect. `resolveHref()`
// reads `window.dataMachineEventsTicketLinkGate` fresh on every call
// (never cached at load time), so each test only needs to vary that
// global and the DOM, not re-import the script.
require( '../../../../assets/js/ticket-link-gate.js' );

describe( 'ticket-link-gate.js', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
		delete ( window as unknown as Record< string, unknown > )[ GLOBAL_KEY ];
	} );

	function makeGatedAnchor( ref: string ): HTMLAnchorElement {
		const anchor = document.createElement( 'a' );
		anchor.setAttribute( 'data-ticket-ref', ref );
		document.body.appendChild( anchor );
		return anchor;
	}

	it( 'builds the href from the localized REST base, not a hardcoded path', () => {
		( window as unknown as Record< string, unknown > )[ GLOBAL_KEY ] = {
			restBase:
				'https://example.test/wp-json/extrachill/v1/events/tickets/',
		};
		const anchor = makeGatedAnchor( '123' );

		anchor.dispatchEvent( new Event( 'pointerdown', { bubbles: true } ) );

		expect( anchor.getAttribute( 'href' ) ).toBe(
			'https://example.test/wp-json/extrachill/v1/events/tickets/123/go'
		);
	} );

	it( 'respects a subdirectory install / non-default REST base', () => {
		( window as unknown as Record< string, unknown > )[ GLOBAL_KEY ] = {
			restBase:
				'https://example.test/blog/?rest_route=/extrachill/v1/events/tickets/',
		};
		const anchor = makeGatedAnchor( '456' );

		anchor.dispatchEvent( new Event( 'pointerdown', { bubbles: true } ) );

		expect( anchor.getAttribute( 'href' ) ).toBe(
			'https://example.test/blog/?rest_route=/extrachill/v1/events/tickets/456/go'
		);
	} );

	it( 'never assigns a hardcoded /wp-json/ href when localized data is missing', () => {
		const anchor = makeGatedAnchor( '789' );

		anchor.dispatchEvent( new Event( 'pointerdown', { bubbles: true } ) );

		expect( anchor.hasAttribute( 'href' ) ).toBe( false );
	} );

	it( 'sets the href on click as a fallback when pointerdown did not fire', () => {
		( window as unknown as Record< string, unknown > )[ GLOBAL_KEY ] = {
			restBase:
				'https://example.test/wp-json/extrachill/v1/events/tickets/',
		};
		const anchor = makeGatedAnchor( '321' );

		anchor.dispatchEvent( new Event( 'click', { bubbles: true } ) );

		expect( anchor.getAttribute( 'href' ) ).toBe(
			'https://example.test/wp-json/extrachill/v1/events/tickets/321/go'
		);
	} );

	it( 'gates Enter/Space keydown and triggers navigation without a hardcoded path', () => {
		( window as unknown as Record< string, unknown > )[ GLOBAL_KEY ] = {
			restBase:
				'https://example.test/wp-json/extrachill/v1/events/tickets/',
		};
		const anchor = makeGatedAnchor( '654' );
		const clickSpy = jest.spyOn( anchor, 'click' );

		anchor.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true } )
		);

		expect( anchor.getAttribute( 'href' ) ).toBe(
			'https://example.test/wp-json/extrachill/v1/events/tickets/654/go'
		);
		expect( clickSpy ).toHaveBeenCalled();
	} );
} );
