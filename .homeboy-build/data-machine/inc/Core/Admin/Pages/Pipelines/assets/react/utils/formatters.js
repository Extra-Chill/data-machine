/**
 * Formatting Utilities for Data Machine
 *
 * Date/time formatting, text transformations, and display helpers.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Parse a datetime string as UTC.
 *
 * Handles both ISO 8601 format (2025-01-28T14:30:00Z) and MySQL format (2025-01-28 14:30:00).
 * MySQL format strings are treated as UTC.
 *
 * @param {string|null} dateString - Datetime string from API
 * @return {Date|null} Date object or null if invalid
 */
const parseUtcDateTime = ( dateString ) => {
	if ( ! dateString ) {
		return null;
	}
	try {
		// ISO 8601 with Z suffix parses correctly as UTC
		if ( dateString.includes( 'T' ) && dateString.endsWith( 'Z' ) ) {
			return new Date( dateString );
		}
		// MySQL format: append Z to treat as UTC
		if ( dateString.includes( ' ' ) && ! dateString.includes( 'T' ) ) {
			return new Date( dateString.replace( ' ', 'T' ) + 'Z' );
		}
		// Fallback: try direct parse
		return new Date( dateString );
	} catch {
		return null;
	}
};

/**
 * Format relative time from date string.
 *
 * Parses datetime as UTC for correct timezone handling.
 *
 * @param {string|null} dateString - Datetime string from API
 * @return {string} Relative time string (e.g., "2 mins ago")
 */
export const formatRelativeTime = ( dateString ) => {
	const date = parseUtcDateTime( dateString );
	if ( ! date ) {
		return __( 'Unknown', 'data-machine' );
	}

	const now = new Date();
	const diffMs = now - date;

	// Handle edge case: negative diff (future dates or clock skew)
	if ( diffMs < 0 ) {
		return __( 'just now', 'data-machine' );
	}

	const diffMins = Math.floor( diffMs / 60000 );
	const diffHours = Math.floor( diffMs / 3600000 );
	const diffDays = Math.floor( diffMs / 86400000 );

	if ( diffMins < 1 ) {
		return __( 'just now', 'data-machine' );
	}
	if ( diffMins < 60 ) {
		return `${ diffMins } ${
			diffMins === 1
				? __( 'min ago', 'data-machine' )
				: __( 'mins ago', 'data-machine' )
		}`;
	}
	if ( diffHours < 24 ) {
		return `${ diffHours } ${
			diffHours === 1
				? __( 'hour ago', 'data-machine' )
				: __( 'hours ago', 'data-machine' )
		}`;
	}
	if ( diffDays < 7 ) {
		return `${ diffDays } ${
			diffDays === 1
				? __( 'day ago', 'data-machine' )
				: __( 'days ago', 'data-machine' )
		}`;
	}

	return date.toLocaleDateString();
};

export const getSessionTitle = ( session ) => {
	if ( session.title ) {
		return session.title;
	}
	if ( session.first_message ) {
		const truncated = session.first_message.substring( 0, 50 );
		return truncated.length < session.first_message.length
			? `${ truncated }...`
			: truncated;
	}
	return __( 'Untitled conversation', 'data-machine' );
};
