/**
 * Unlocks the event rating form without a reload.
 *
 * The server renders the rating form locked (hidden) for logged-in users who
 * may not rate yet, alongside a gate message. Any plugin that changes a
 * user's eligibility on the page can announce it:
 *
 *   document.dispatchEvent( new CustomEvent( 'dme:rating-eligibility', {
 *     detail: { eventId: 123, canRate: true },
 *   } ) );
 *
 * canRate: true reveals the form and hides the gate; false re-locks it. The
 * server still re-checks eligibility on submit.
 */
( function () {
	function setLocked( eventId, locked ) {
		const id = String( eventId );
		document
			.querySelectorAll( '[data-dme-rating-locked="' + id + '"]' )
			.forEach( function ( el ) {
				el.hidden = locked;
			} );
		document
			.querySelectorAll( '[data-dme-rating-gate="' + id + '"]' )
			.forEach( function ( el ) {
				el.hidden = ! locked;
			} );
	}

	document.addEventListener( 'dme:rating-eligibility', function ( event ) {
		const detail = ( event && event.detail ) || {};
		const eventId = parseInt( detail.eventId, 10 );
		if ( ! eventId ) {
			return;
		}
		setLocked( eventId, ! detail.canRate );
	} );
} )();
