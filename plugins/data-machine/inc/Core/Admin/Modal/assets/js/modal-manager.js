/**
 * Data Machine Modal Manager - Shared Vanilla JS Modal Utilities
 *
 * Provides reusable modal lifecycle management for all admin pages.
 * Used by Jobs, Settings, and other vanilla JS admin interfaces.
 *
 * @package
 * @since 0.2.0
 */

( function () {
	'use strict';

	/**
	 * Modal Manager
	 *
	 * Centralized modal management with accessibility support.
	 */
	window.datamachineModalManager = {
		/**
		 * Track initialization state to prevent duplicate event bindings
		 */
		_initialized: false,

		/**
		 * Bind all modal event listeners
		 *
		 * Call this method from page-specific init functions.
		 * Safe to call multiple times - will only bind once.
		 */
		bindModalEvents() {
			// Prevent duplicate event listener bindings
			if ( this._initialized ) {
				return;
			}
			this._initialized = true;
			// Modal open handlers
			document.addEventListener( 'click', ( e ) => {
				if (
					e.target.classList.contains( 'datamachine-open-modal' ) ||
					e.target.closest( '.datamachine-open-modal' )
				) {
					this.handleModalOpen( e );
				}
			} );

			// Modal close handlers
			document.addEventListener( 'click', ( e ) => {
				if (
					e.target.classList.contains( 'datamachine-modal-close' ) ||
					e.target.closest( '.datamachine-modal-close' )
				) {
					this.handleModalClose( e );
				}
			} );

			// Modal overlay click to close
			document.addEventListener( 'click', ( e ) => {
				if (
					e.target.classList.contains( 'datamachine-modal-overlay' )
				) {
					this.handleModalClose( e );
				}
			} );

			// Escape key to close modal
			document.addEventListener( 'keydown', ( e ) => {
				if ( e.key === 'Escape' ) {
					const activeModal = document.querySelector(
						'.datamachine-modal[aria-hidden="false"]'
					);
					if ( activeModal ) {
						this.closeModal( activeModal );
					}
				}
			} );
		},

		/**
		 * Handle modal open
		 *
		 * @param {Event} e - The click event object
		 */
		handleModalOpen( e ) {
			e.preventDefault();
			const button = e.target.classList.contains(
				'datamachine-open-modal'
			)
				? e.target
				: e.target.closest( '.datamachine-open-modal' );
			const modalId = button.getAttribute( 'data-modal-id' );
			if ( modalId ) {
				const modal = document.getElementById( modalId );
				if ( modal ) {
					this.openModal( modal );
				}
			}
		},

		/**
		 * Handle modal close
		 *
		 * @param {Event} e - The click event object
		 */
		handleModalClose( e ) {
			e.preventDefault();
			const modal = e.target.closest( '.datamachine-modal' );
			if ( modal ) {
				this.closeModal( modal );
			}
		},

		/**
		 * Open modal
		 *
		 * @param {HTMLElement} modal - The modal element to open
		 */
		openModal( modal ) {
			modal.setAttribute( 'aria-hidden', 'false' );
			modal.classList.add( 'datamachine-modal-active' );
			document.body.classList.add( 'datamachine-modal-active' );

			// Focus first input
			const firstInput = modal.querySelector( 'input, select, textarea' );
			if ( firstInput ) {
				firstInput.focus();
			}
		},

		/**
		 * Close modal
		 *
		 * @param {HTMLElement} modal - The modal element to close
		 */
		closeModal( modal ) {
			modal.setAttribute( 'aria-hidden', 'true' );
			modal.classList.remove( 'datamachine-modal-active' );
			document.body.classList.remove( 'datamachine-modal-active' );
		},
	};
} )();
