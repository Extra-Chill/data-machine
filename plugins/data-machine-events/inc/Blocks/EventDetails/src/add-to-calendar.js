/**
 * Add to Calendar dropdown — vanilla JS (no jQuery, no React).
 *
 * Implements the WAI-ARIA menu button pattern for the `.dm-events-add-to-calendar`
 * widget rendered server-side by inc/Blocks/EventDetails/add-to-calendar-button.php.
 *
 * - Toggle button opens/closes the dropdown (toggles `hidden` + `aria-expanded`).
 * - Click outside closes the menu.
 * - Escape closes the menu and returns focus to the toggle.
 * - Arrow Down / Up navigate menu items; Home / End jump to first/last.
 * - Tab through items works via native tab order.
 * - Enter / Space on a menu item activates the link via the browser default.
 *
 * Bound via event delegation on `document` so dynamically inserted widgets
 * keep working (e.g. server-rendered HTML swapped into the page).
 *
 * @package DataMachineEvents
 * @since   0.40.0
 */

(function () {
	'use strict';

	if ( typeof document === 'undefined' ) {
		return;
	}

	var WIDGET_SELECTOR = '[data-dm-add-to-calendar]';
	var TOGGLE_SELECTOR = '.dm-events-add-to-calendar-toggle';
	var MENU_SELECTOR = '.dm-events-add-to-calendar-menu';
	var ITEM_SELECTOR = '[role="menuitem"]';
	var RIGHT_ALIGN_CLASS = 'is-right-aligned';

	/**
	 * Resolve the menu element for a given toggle.
	 */
	function getMenu( toggle ) {
		var widget = toggle.closest( WIDGET_SELECTOR );
		if ( ! widget ) {
			return null;
		}
		return widget.querySelector( MENU_SELECTOR );
	}

	function getItems( menu ) {
		return Array.prototype.slice.call( menu.querySelectorAll( ITEM_SELECTOR ) );
	}

	function isOpen( toggle ) {
		return toggle.getAttribute( 'aria-expanded' ) === 'true';
	}

	function openMenu( toggle ) {
		var menu = getMenu( toggle );
		if ( ! menu ) {
			return;
		}

		// Close any other open menus on the page first.
		closeAllMenus( toggle );

		toggle.setAttribute( 'aria-expanded', 'true' );
		menu.hidden = false;

		// Decide whether to right-align to keep within viewport.
		applyEdgeAlignment( toggle, menu );
	}

	function closeMenu( toggle, opts ) {
		var menu = getMenu( toggle );
		if ( ! menu ) {
			return;
		}
		toggle.setAttribute( 'aria-expanded', 'false' );
		menu.hidden = true;
		menu.classList.remove( RIGHT_ALIGN_CLASS );
		if ( opts && opts.focusToggle ) {
			toggle.focus();
		}
	}

	function closeAllMenus( exceptToggle ) {
		var toggles = document.querySelectorAll( TOGGLE_SELECTOR );
		for ( var i = 0; i < toggles.length; i++ ) {
			if ( toggles[ i ] !== exceptToggle && isOpen( toggles[ i ] ) ) {
				closeMenu( toggles[ i ] );
			}
		}
	}

	/**
	 * Position the menu so it stays within the viewport.
	 * Default anchor is left-aligned (CSS `left: 0`). When the menu would
	 * overflow the right edge, add `.is-right-aligned` so CSS can flip it.
	 */
	function applyEdgeAlignment( toggle, menu ) {
		// Defer measurement until layout is done.
		requestAnimationFrame( function () {
			var menuRect = menu.getBoundingClientRect();
			var viewportWidth = document.documentElement.clientWidth || window.innerWidth;
			if ( menuRect.right > viewportWidth - 8 ) {
				menu.classList.add( RIGHT_ALIGN_CLASS );
			} else {
				menu.classList.remove( RIGHT_ALIGN_CLASS );
			}
		} );
	}

	function focusItemAt( menu, index ) {
		var items = getItems( menu );
		if ( ! items.length ) {
			return;
		}
		var bounded = ( ( index % items.length ) + items.length ) % items.length;
		items[ bounded ].focus();
	}

	// Toggle click — open/close.
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}

		var toggle = target.closest( TOGGLE_SELECTOR );
		if ( toggle ) {
			event.preventDefault();
			event.stopPropagation();
			if ( isOpen( toggle ) ) {
				closeMenu( toggle );
			} else {
				openMenu( toggle );
			}
			return;
		}

		// Click outside any open menu → close it.
		var widget = target.closest( WIDGET_SELECTOR );
		if ( ! widget ) {
			closeAllMenus( null );
		}
	} );

	// Keyboard handling.
	document.addEventListener( 'keydown', function ( event ) {
		var target = event.target;
		if ( ! ( target instanceof Element ) ) {
			return;
		}

		// Escape: close any open menu, return focus to its toggle.
		if ( event.key === 'Escape' || event.key === 'Esc' ) {
			var openToggle = document.querySelector(
				TOGGLE_SELECTOR + '[aria-expanded="true"]'
			);
			if ( openToggle ) {
				event.preventDefault();
				closeMenu( openToggle, { focusToggle: true } );
			}
			return;
		}

		// Arrow keys on the toggle: open the menu and focus the first item.
		var toggle = target.closest( TOGGLE_SELECTOR );
		if ( toggle ) {
			if ( event.key === 'ArrowDown' || event.key === 'Down' ) {
				event.preventDefault();
				if ( ! isOpen( toggle ) ) {
					openMenu( toggle );
				}
				var menuDown = getMenu( toggle );
				if ( menuDown ) {
					focusItemAt( menuDown, 0 );
				}
				return;
			}
			if ( event.key === 'ArrowUp' || event.key === 'Up' ) {
				event.preventDefault();
				if ( ! isOpen( toggle ) ) {
					openMenu( toggle );
				}
				var menuUp = getMenu( toggle );
				if ( menuUp ) {
					var items = getItems( menuUp );
					focusItemAt( menuUp, items.length - 1 );
				}
				return;
			}
			return;
		}

		// Keys on a menu item: arrows + Home/End.
		var item = target.closest( ITEM_SELECTOR );
		if ( ! item ) {
			return;
		}
		var menu = item.closest( MENU_SELECTOR );
		if ( ! menu ) {
			return;
		}
		var menuItems = getItems( menu );
		var currentIndex = menuItems.indexOf( item );

		switch ( event.key ) {
			case 'ArrowDown':
			case 'Down':
				event.preventDefault();
				focusItemAt( menu, currentIndex + 1 );
				break;
			case 'ArrowUp':
			case 'Up':
				event.preventDefault();
				focusItemAt( menu, currentIndex - 1 );
				break;
			case 'Home':
				event.preventDefault();
				focusItemAt( menu, 0 );
				break;
			case 'End':
				event.preventDefault();
				focusItemAt( menu, menuItems.length - 1 );
				break;
			default:
				break;
		}
	} );

	// Close menus when focus leaves the widget entirely (Tab past last item).
	document.addEventListener(
		'focusin',
		function ( event ) {
			var target = event.target;
			if ( ! ( target instanceof Element ) ) {
				return;
			}
			var widget = target.closest( WIDGET_SELECTOR );
			if ( widget ) {
				return;
			}
			closeAllMenus( null );
		},
		true
	);
} )();
