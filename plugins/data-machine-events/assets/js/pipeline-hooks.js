/**
 * Data Machine Events - Pipeline Hooks
 *
 * Registers WordPress hooks to extend core pipeline React behavior.
 * Uses @wordpress/hooks for native WordPress filter/action pattern in JavaScript.
 *
 * @package DataMachineEvents
 * @since 1.0.0
 */

( function() {
	'use strict';

	const { addFilter } = wp.hooks;
	const apiFetch = wp.apiFetch;

	/**
	 * Venue field keys for clearing/populating
	 * Note: coordinates are handled via backend geocoding, not shown in UI
	 */
	const VENUE_FIELDS = {
		venue_name: 'name',
		venue_address: 'address',
		venue_city: 'city',
		venue_state: 'state',
		venue_zip: 'zip',
		venue_country: 'country',
		venue_phone: 'phone',
		venue_website: 'website',
		venue_capacity: 'capacity',
	};

	/**
	 * Fetch venue data from REST API and map to form fields
	 */
	async function fetchVenueData( venueId ) {
		const response = await apiFetch( {
			path: '/datamachine/v1/events/venues/' + venueId,
		} );

		if ( response?.success && response?.data ) {
			const mapped = {};
			Object.entries( VENUE_FIELDS ).forEach( function( [ formKey, dataKey ] ) {
				mapped[ formKey ] = response.data[ dataKey ] || '';
			} );
			return mapped;
		}

		return null;
	}

	/**
	 * Get empty venue fields for clearing form
	 */
	function getEmptyVenueFields() {
		const empty = {};
		Object.keys( VENUE_FIELDS ).forEach( function( key ) {
			empty[ key ] = '';
		} );
		return empty;
	}

	/**
	 * Enrich Universal Web Scraper settings with venue data on modal open.
	 *
	 * When a venue is selected (term_id saved), fetches the venue's term meta
	 * and populates the venue detail fields in the settings form.
	 */
	addFilter(
		'datamachine.handlerSettings.init',
		'datamachine-events/venue-enrichment',
		async function( settingsPromise, handlerSlug, fieldsSchema ) {
			const settings = await settingsPromise;

			if ( handlerSlug !== 'universal-web-scraper' || ! settings.venue ) {
				return settings;
			}

			try {
				const venueData = await fetchVenueData( settings.venue );
				if ( venueData ) {
					return { ...settings, ...venueData };
				}
			} catch ( error ) {
				console.error( 'DM Events: Failed to load venue data:', error );
			}

			return settings;
		}
	);

	/**
	 * Handle venue dropdown changes in Universal Web Scraper settings.
	 *
	 * When user selects a different venue, fetches that venue's data.
	 * When user selects "Create New Venue", clears all venue fields.
	 */
	addFilter(
		'datamachine.handlerSettings.fieldChange',
		'datamachine-events/venue-change',
		async function( changesPromise, fieldKey, value, handlerSlug, currentData ) {
			const changes = await changesPromise;

			if ( handlerSlug !== 'universal-web-scraper' || fieldKey !== 'venue' ) {
				return changes;
			}

			if ( value ) {
				try {
					const venueData = await fetchVenueData( value );
					if ( venueData ) {
						return { ...changes, ...venueData };
					}
				} catch ( error ) {
					console.error( 'DM Events: Failed to load venue data on change:', error );
				}
			} else {
				return { ...changes, ...getEmptyVenueFields() };
			}

			return changes;
		}
	);
} )();
