/**
 * Events Map Block: venue popup HTML and marker icon factories.
 *
 * Pure helpers extracted from frontend.tsx: popup markup for default and
 * chronological-route modes, marker icon factories, and chronological
 * ordering keys. No React or map-instance coupling.
 *
 * @package
 */

/**
 * External dependencies
 */
import L from 'leaflet';

/**
 * Internal dependencies
 */
import type { Venue } from './types';

export function escapeHtml( text: string ): string {
	const div = document.createElement( 'div' );
	div.textContent = text;
	return div.innerHTML;
}

export function buildPopupHtml( venue: Venue ): string {
	let html = '<div class="venue-popup">';

	if ( venue.url ) {
		html += `<a href="${ escapeHtml(
			venue.url
		) }" class="venue-popup-name">${ escapeHtml( venue.name ) }</a>`;
	} else {
		html += `<span class="venue-popup-name">${ escapeHtml(
			venue.name
		) }</span>`;
	}

	if ( venue.event_count > 0 ) {
		html += `<span class="venue-popup-events">${
			venue.event_count
		} upcoming event${ venue.event_count !== 1 ? 's' : '' }</span>`;
	}

	if ( venue.address ) {
		html += `<span class="venue-popup-address">${ escapeHtml(
			venue.address
		) }</span>`;
	}

	html += '</div>';
	return html;
}

/**
 * Format YYYY-MM-DD (+ HH:MM:SS) into a short human label like
 * "Sep 23, 2099 · 8:00 PM". Falls back to the raw date if parsing fails so
 * the popup is never blank.
 *
 * @param date Event date.
 * @param time Event time.
 */
export function formatEventDateTime( date: string, time: string ): string {
	if ( ! date ) {
		return '';
	}

	// Build a date object using local time semantics. The server already
	// stored start_datetime in the site timezone, so treat it as local.
	const iso = time ? `${ date }T${ time }` : `${ date }T00:00:00`;
	const parsed = new Date( iso );

	if ( isNaN( parsed.getTime() ) ) {
		return time ? `${ date } ${ time }` : date;
	}

	const datePart = parsed.toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	} );

	if ( ! time ) {
		return datePart;
	}

	const timePart = parsed.toLocaleTimeString( undefined, {
		hour: 'numeric',
		minute: '2-digit',
	} );

	return `${ datePart } · ${ timePart }`;
}

/**
 * Chronological-route popup. Lists every upcoming show at this venue for the
 * scoped taxonomy term, chronologically. The same shape is used for first,
 * last, and middle markers — only the marker icon differs by route position.
 *
 * @param venue Venue whose events should be rendered.
 */
export function buildChronologicalRoutePopupHtml( venue: Venue ): string {
	let html = '<div class="venue-popup venue-popup--chronological-route">';

	if ( venue.url ) {
		html += `<a href="${ escapeHtml(
			venue.url
		) }" class="venue-popup-name">${ escapeHtml( venue.name ) }</a>`;
	} else {
		html += `<span class="venue-popup-name">${ escapeHtml(
			venue.name
		) }</span>`;
	}

	if ( venue.address ) {
		html += `<span class="venue-popup-address">${ escapeHtml(
			venue.address
		) }</span>`;
	}

	const shows = venue.upcoming_events_at_venue ?? [];
	if ( shows.length > 0 ) {
		html += '<ul class="venue-popup-shows">';
		for ( const show of shows ) {
			const label = formatEventDateTime(
				show.start_date,
				show.start_time
			);
			const title = show.title || label || 'Event';
			if ( show.permalink ) {
				html += `<li><a href="${ escapeHtml(
					show.permalink
				) }">${ escapeHtml( title ) }</a>`;
			} else {
				html += `<li><span>${ escapeHtml( title ) }</span>`;
			}
			if ( label && label !== title ) {
				html += ` <span class="venue-popup-show-date">${ escapeHtml(
					label
				) }</span>`;
			}
			html += '</li>';
		}
		html += '</ul>';
	}

	html += '</div>';
	return html;
}

export function createVenueIcon(): L.DivIcon {
	return L.divIcon( {
		html: '<span style="font-size: 28px; line-height: 1; display: block;">📍</span>',
		className: 'emoji-marker',
		iconSize: [ 28, 28 ],
		iconAnchor: [ 14, 28 ],
		popupAnchor: [ 0, -28 ],
	} );
}

/**
 * Chronological-route marker. `position` flags first/last for distinct color
 * treatment; middle stops fall through to the default pin look but in the
 * chronological-route className so site CSS can theme them as a set.
 *
 * Colors picked for high contrast against OSM tiles:
 *   - first = green  (#22c55e)
 *   - last  = red    (#ef4444)
 *   - middle = slate (#475569)
 *
 * v1 keeps numbered badges out of scope (per #310 design notes); revisit
 * once Chris weighs in on the live render.
 *
 * @param position Position of the venue in the route.
 */
export function createChronologicalRouteIcon(
	position: 'first' | 'last' | 'middle'
): L.DivIcon {
	let color = '#475569';
	if ( position === 'first' ) {
		color = '#22c55e';
	} else if ( position === 'last' ) {
		color = '#ef4444';
	}

	const html = `<span class="chronological-route-pin chronological-route-pin--${ position }" style="background:${ color };"></span>`;

	return L.divIcon( {
		html,
		className: `chronological-route-marker chronological-route-marker--${ position }`,
		iconSize: [ 22, 22 ],
		iconAnchor: [ 11, 22 ],
		popupAnchor: [ 0, -22 ],
	} );
}

/**
 * Earliest start_datetime (date + time) for a venue, as a sortable
 * "YYYY-MM-DD HH:MM:SS" string. Used to order venues chronologically when
 * drawing the chronological-route polyline. Returns null when no events
 * were attached (which means we should skip the venue from the route).
 *
 * @param venue Venue whose earliest event should be found.
 */
export function earliestEventKey( venue: Venue ): string | null {
	const shows = venue.upcoming_events_at_venue ?? [];
	if ( shows.length === 0 ) {
		return null;
	}

	// The REST response already sorts ascending per venue, so shows[0] is
	// the earliest. Defensive guard for callers that might re-order.
	let earliest = '';
	for ( const show of shows ) {
		const key = `${ show.start_date || '' } ${
			show.start_time || ''
		}`.trim();
		if ( ! key ) {
			continue;
		}
		if ( ! earliest || key < earliest ) {
			earliest = key;
		}
	}
	return earliest || null;
}

export function createUserLocationIcon(): L.DivIcon {
	return L.divIcon( {
		html: '<span class="user-location-dot"></span>',
		className: 'user-location-marker',
		iconSize: [ 16, 16 ],
		iconAnchor: [ 8, 8 ],
	} );
}
