/**
 * Carousel overflow detection, indicators, and chevron navigation.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
const observers = new WeakMap< HTMLElement, Map< HTMLElement, () => void > >();
const MAX_VISIBLE_DOTS = 12;
const DESKTOP_MAX_VISIBLE_DOTS = 36;
const DOT_WIDTH = 7;
const DOT_GAP = 8;

export function initCarousel(
	calendar: HTMLElement,
	groups: Iterable< HTMLElement > = calendar.querySelectorAll< HTMLElement >(
		'.data-machine-date-group'
	)
): void {
	const entries =
		observers.get( calendar ) || new Map< HTMLElement, () => void >();
	observers.set( calendar, entries );
	Array.from( groups ).forEach( function ( group ) {
		entries.get( group )?.();
		entries.delete( group );
		const wrapper = group.querySelector< HTMLElement >(
			'.data-machine-events-wrapper'
		);
		if ( ! wrapper || wrapper.dataset.deferred === 'true' ) {
			return;
		}
		const events = wrapper.querySelectorAll< HTMLElement >(
			'.data-machine-event-item'
		);
		const eventCount = events.length;
		if ( eventCount <= 1 ) {
			return;
		}

		let indicators: HTMLElement | null = null;
		let chevronLeft: HTMLButtonElement | null = null;
		let chevronRight: HTMLButtonElement | null = null;
		let scrollHandler: ( () => void ) | null = null;
		let holdInterval: ReturnType< typeof setInterval > | null = null;

		const scrollByCard = function ( direction: number ): void {
			const cardWidth = events[ 0 ]?.getBoundingClientRect().width || 300;
			wrapper.scrollBy( {
				left: cardWidth * direction,
				behavior: 'smooth',
			} );
		};

		const startHold = function ( direction: number ): void {
			scrollByCard( direction );
			holdInterval = setInterval( function () {
				scrollByCard( direction );
			}, 300 );
		};

		const stopHold = function (): void {
			if ( holdInterval ) {
				clearInterval( holdInterval );
				holdInterval = null;
			}
		};

		const bindChevron = function (
			chevron: HTMLButtonElement,
			direction: number
		): void {
			chevron.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				// Pointer/touch already moved one card on press. Native keyboard
				// and assistive clicks have detail 0 and need activation here.
				if ( event.detail === 0 ) {
					scrollByCard( direction );
				}
			} );

			chevron.addEventListener( 'mousedown', function () {
				startHold( direction );
			} );
			chevron.addEventListener(
				'touchstart',
				function ( event ) {
					event.preventDefault();
					startHold( direction );
				},
				{ passive: false }
			);

			(
				[ 'mouseup', 'mouseleave', 'touchend', 'touchcancel' ] as const
			 ).forEach( function ( event ) {
				chevron.addEventListener( event, stopHold );
			} );
		};

		const updateIndicators = function (): void {
			if ( ! indicators ) {
				return;
			}

			const track = indicators.querySelector< HTMLElement >(
				'.data-machine-carousel-dots-track'
			);
			const dots = track
				? track.querySelectorAll< HTMLElement >(
						'.data-machine-carousel-dot'
				  )
				: [];
			if ( ! track || dots.length === 0 ) {
				return;
			}

			const wrapperRect = wrapper.getBoundingClientRect();
			const firstEventWidth =
				events[ 0 ]?.getBoundingClientRect().width || 0;
			const isSingleCardMode =
				firstEventWidth > 0 &&
				wrapperRect.width / firstEventWidth < 1.5;

			if ( isSingleCardMode ) {
				const totalEvents = events.length;
				const useCollapsed = totalEvents > MAX_VISIBLE_DOTS;

				let activeIndex = 0;
				let maxVisibleArea = 0;

				events.forEach( function ( event, index ) {
					const eventRect = event.getBoundingClientRect();
					const visibleLeft = Math.max(
						eventRect.left,
						wrapperRect.left
					);
					const visibleRight = Math.min(
						eventRect.right,
						wrapperRect.right
					);
					const visibleWidth = Math.max(
						0,
						visibleRight - visibleLeft
					);

					if ( visibleWidth > maxVisibleArea ) {
						maxVisibleArea = visibleWidth;
						activeIndex = index;
					}
				} );

				dots.forEach( function ( dot, index ) {
					dot.classList.toggle( 'active', index === activeIndex );
				} );

				if ( useCollapsed ) {
					const halfWindow = Math.floor( MAX_VISIBLE_DOTS / 2 );
					const dotUnit = DOT_WIDTH + DOT_GAP;
					const totalDotsWidth =
						totalEvents * DOT_WIDTH + ( totalEvents - 1 ) * DOT_GAP;
					const visibleWidth =
						MAX_VISIBLE_DOTS * DOT_WIDTH +
						( MAX_VISIBLE_DOTS - 1 ) * DOT_GAP;
					const maxShift = totalDotsWidth - visibleWidth;

					let shift: number;

					if ( activeIndex <= halfWindow ) {
						shift = 0;
					} else if ( activeIndex >= totalEvents - halfWindow ) {
						shift = maxShift;
					} else {
						shift = ( activeIndex - halfWindow ) * dotUnit;
					}

					track.style.transform = 'translateX(-' + shift + 'px)';
				} else {
					track.style.transform = '';
				}
			} else {
				const totalEvents = events.length;
				const useDesktopCollapsed =
					totalEvents > DESKTOP_MAX_VISIBLE_DOTS;

				const maxScroll = wrapper.scrollWidth - wrapper.clientWidth;
				const scrollProgress =
					maxScroll > 0 ? wrapper.scrollLeft / maxScroll : 0;
				const visibleCards = Math.floor(
					wrapperRect.width / firstEventWidth
				);
				const scrollableCards = totalEvents - visibleCards;

				const firstActiveIndex = Math.round(
					scrollProgress * scrollableCards
				);

				dots.forEach( function ( dot, index ) {
					const isInVisibleRange =
						index >= firstActiveIndex &&
						index < firstActiveIndex + visibleCards;
					dot.classList.toggle( 'active', isInVisibleRange );
				} );

				if ( useDesktopCollapsed ) {
					const rangeMidpoint =
						firstActiveIndex + Math.floor( visibleCards / 2 );
					const halfWindow = Math.floor(
						DESKTOP_MAX_VISIBLE_DOTS / 2
					);
					const dotUnit = DOT_WIDTH + DOT_GAP;
					const totalDotsWidth =
						totalEvents * DOT_WIDTH + ( totalEvents - 1 ) * DOT_GAP;
					const visibleWidth =
						DESKTOP_MAX_VISIBLE_DOTS * DOT_WIDTH +
						( DESKTOP_MAX_VISIBLE_DOTS - 1 ) * DOT_GAP;
					const maxShift = totalDotsWidth - visibleWidth;

					let shift: number;
					if ( rangeMidpoint <= halfWindow ) {
						shift = 0;
					} else if ( rangeMidpoint >= totalEvents - halfWindow ) {
						shift = maxShift;
					} else {
						shift = ( rangeMidpoint - halfWindow ) * dotUnit;
					}

					track.style.transform = 'translateX(-' + shift + 'px)';
				} else {
					track.style.transform = '';
				}
			}

			const atStart = wrapper.scrollLeft <= 5;
			const atEnd =
				wrapper.scrollLeft + wrapper.clientWidth >=
				wrapper.scrollWidth - 5;
			chevronLeft?.classList.toggle( 'hidden', atStart );
			chevronRight?.classList.toggle( 'hidden', atEnd );
			if ( chevronLeft ) {
				chevronLeft.disabled = atStart;
			}
			if ( chevronRight ) {
				chevronRight.disabled = atEnd;
			}
		};

		const setupIndicators = function (): void {
			const hasOverflow = wrapper.scrollWidth > wrapper.clientWidth;

			indicators = group.querySelector< HTMLElement >(
				'.data-machine-carousel-indicators'
			);
			chevronLeft = group.querySelector< HTMLButtonElement >(
				'.data-machine-carousel-chevron-left'
			);
			chevronRight = group.querySelector< HTMLButtonElement >(
				'.data-machine-carousel-chevron-right'
			);

			if ( ! hasOverflow ) {
				if ( indicators ) {
					indicators.remove();
				}
				if ( chevronLeft ) {
					chevronLeft.remove();
				}
				if ( chevronRight ) {
					chevronRight.remove();
				}
				indicators = null;
				chevronLeft = null;
				chevronRight = null;
				if ( scrollHandler ) {
					wrapper.removeEventListener( 'scroll', scrollHandler );
					scrollHandler = null;
				}
				return;
			}

			if ( ! indicators ) {
				indicators = document.createElement( 'div' );
				indicators.className = 'data-machine-carousel-indicators';
				group.appendChild( indicators );
			}
			indicators.replaceChildren();

			const isMobileScreen =
				window.matchMedia( '(max-width: 768px)' ).matches;
			const maxDotsForViewport = isMobileScreen
				? MAX_VISIBLE_DOTS
				: DESKTOP_MAX_VISIBLE_DOTS;
			const useViewport = eventCount > maxDotsForViewport;

			let trackParent: HTMLElement = indicators;
			if ( useViewport ) {
				const viewport = document.createElement( 'div' );
				viewport.className = isMobileScreen
					? 'data-machine-carousel-viewport'
					: 'data-machine-carousel-viewport data-machine-carousel-viewport--desktop';
				indicators.appendChild( viewport );
				trackParent = viewport;
			}

			const track = document.createElement( 'div' );
			track.className = 'data-machine-carousel-dots-track';
			trackParent.appendChild( track );

			for ( let i = 0; i < eventCount; i++ ) {
				const dot = document.createElement( 'span' );
				dot.className = 'data-machine-carousel-dot';
				dot.dataset.index = String( i );
				track.appendChild( dot );
			}

			if ( ! chevronLeft ) {
				chevronLeft = document.createElement( 'button' );
				chevronLeft.type = 'button';
				chevronLeft.className =
					'data-machine-carousel-chevron data-machine-carousel-chevron-left';
				chevronLeft.setAttribute(
					'aria-label',
					__( 'Show previous events', 'data-machine-events' )
				);
				chevronLeft.textContent = '\u2039';
				group.appendChild( chevronLeft );
				bindChevron( chevronLeft, -1 );
			}

			if ( ! chevronRight ) {
				chevronRight = document.createElement( 'button' );
				chevronRight.type = 'button';
				chevronRight.className =
					'data-machine-carousel-chevron data-machine-carousel-chevron-right';
				chevronRight.setAttribute(
					'aria-label',
					__( 'Show next events', 'data-machine-events' )
				);
				chevronRight.textContent = '\u203A';
				group.appendChild( chevronRight );
				bindChevron( chevronRight, 1 );
			}

			if ( ! scrollHandler ) {
				scrollHandler = updateIndicators;
				wrapper.addEventListener( 'scroll', scrollHandler );
			}

			updateIndicators();
		};

		let frame = requestAnimationFrame( setupIndicators );
		let observer: ResizeObserver | undefined;
		if ( typeof ResizeObserver !== 'undefined' ) {
			observer = new ResizeObserver( function () {
				cancelAnimationFrame( frame );
				frame = requestAnimationFrame( setupIndicators );
			} );
			observer.observe( wrapper );
			observer.observe( events[ 0 ] );
		}
		entries.set( group, () => {
			cancelAnimationFrame( frame );
			observer?.disconnect();
			stopHold();
			if ( scrollHandler ) {
				wrapper.removeEventListener( 'scroll', scrollHandler );
			}
			indicators?.remove();
			chevronLeft?.remove();
			chevronRight?.remove();
		} );
	} );
}

export function destroyCarousel( calendar: HTMLElement ): void {
	observers.get( calendar )?.forEach( ( cleanup ) => cleanup() );
	observers.delete( calendar );
}
