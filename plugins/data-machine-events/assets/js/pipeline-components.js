/**
 * Data Machine Events - Pipeline Components
 *
 * Custom React field components for Data Machine pipeline modals.
 * Uses vanilla JS with wp.element.createElement - no build process required.
 *
 * @package
 * @since 0.4.5
 */

( function() {
	'use strict';

	const { createElement, useState, useEffect, useRef, useCallback } = wp.element;
	const { TextControl, Spinner } = wp.components;
	const { __ } = wp.i18n;
	const { addFilter } = wp.hooks;
	const apiFetch = wp.apiFetch;

	const GEOCODE_ENDPOINT = '/datamachine/v1/events/geocode/search';
	const DEBOUNCE_DELAY = 1000;

	function AddressAutocompleteField( props ) {
		const { fieldKey, fieldConfig, value, onChange, onBatchChange } = props;

		const [ inputValue, setInputValue ] = useState( value || '' );
		const [ suggestions, setSuggestions ] = useState( [] );
		const [ isLoading, setIsLoading ] = useState( false );
		const [ showDropdown, setShowDropdown ] = useState( false );
		const [ selectedIndex, setSelectedIndex ] = useState( -1 );
		const [ error, setError ] = useState( null );

		const containerRef = useRef( null );
		const debounceRef = useRef( null );
		const cacheRef = useRef( {} );

		useEffect( function() {
			setInputValue( value || '' );
		}, [ value ] );

		useEffect( function() {
			function handleClickOutside( event ) {
				if ( containerRef.current && ! containerRef.current.contains( event.target ) ) {
					setShowDropdown( false );
				}
			}

			document.addEventListener( 'mousedown', handleClickOutside );
			return function() {
				document.removeEventListener( 'mousedown', handleClickOutside );
			};
		}, [] );

		const searchAddress = useCallback( function( query ) {
			if ( cacheRef.current[ query ] ) {
				setSuggestions( cacheRef.current[ query ] );
				setShowDropdown( true );
				setIsLoading( false );
				return;
			}

			apiFetch( {
				path: GEOCODE_ENDPOINT,
				method: 'POST',
				data: { query },
			} )
				.then( function( response ) {
					if ( response.success && response.results ) {
						cacheRef.current[ query ] = response.results;
						setSuggestions( response.results );
						setShowDropdown( true );
						setError( null );
					} else {
						setSuggestions( [] );
					}
					setIsLoading( false );
				} )
				.catch( function() {
					setError( __( 'Failed to load address suggestions', 'data-machine-events' ) );
					setSuggestions( [] );
					setIsLoading( false );
				} );
		}, [] );

		function handleInputChange( newValue ) {
			setInputValue( newValue );
			setSelectedIndex( -1 );

			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}

			if ( newValue.trim().length < 3 ) {
				setSuggestions( [] );
				setShowDropdown( false );
				return;
			}

			setIsLoading( true );

			debounceRef.current = setTimeout( function() {
				searchAddress( newValue.trim() );
			}, DEBOUNCE_DELAY );
		}

		function buildStreetAddress( address ) {
			const components = [];

			if ( address.house_number ) {
				components.push( address.house_number );
			}

			if ( address.road || address.street ) {
				components.push( address.road || address.street );
			}

			return components.join( ' ' );
		}

		function handleSelectPlace( place ) {
			const address = place.address || {};
			const streetAddress = buildStreetAddress( address );

			setInputValue( streetAddress );
			setShowDropdown( false );
			setSuggestions( [] );

			if ( typeof onChange === 'function' ) {
				onChange( fieldKey, streetAddress );
			}

			if ( typeof onBatchChange === 'function' ) {
				const batchUpdates = {};

				const cityValue = address.city || address.town || address.village || address.municipality || '';
				if ( cityValue ) {
					batchUpdates.venue_city = cityValue;
				}

				const stateValue = address.state || address.region || '';
				if ( stateValue ) {
					batchUpdates.venue_state = stateValue;
				}

				if ( address.postcode ) {
					batchUpdates.venue_zip = address.postcode;
				}

				if ( address.country_code ) {
					batchUpdates.venue_country = address.country_code.toUpperCase();
				}

				if ( Object.keys( batchUpdates ).length > 0 ) {
					onBatchChange( batchUpdates );
				}
			}
		}

		function handleKeyDown( event ) {
			if ( ! showDropdown || suggestions.length === 0 ) {
				return;
			}

			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					setSelectedIndex( function( prev ) {
						return Math.min( prev + 1, suggestions.length - 1 );
					} );
					break;

				case 'ArrowUp':
					event.preventDefault();
					setSelectedIndex( function( prev ) {
						return Math.max( prev - 1, -1 );
					} );
					break;

				case 'Enter':
					event.preventDefault();
					if ( selectedIndex >= 0 && suggestions[ selectedIndex ] ) {
						handleSelectPlace( suggestions[ selectedIndex ] );
					}
					break;

				case 'Escape':
					event.preventDefault();
					setShowDropdown( false );
					break;
			}
		}

		const children = [
			createElement(
				'div',
				{ className: 'address-autocomplete-container' },
				createElement( TextControl, {
					label: ( fieldConfig && fieldConfig.label ) || fieldKey,
					value: inputValue,
					onChange: handleInputChange,
					onKeyDown: handleKeyDown,
					help: ( fieldConfig && fieldConfig.description ) || '',
					placeholder: ( fieldConfig && fieldConfig.placeholder ) || '',
				} ),
				isLoading && createElement(
					'div',
					{ className: 'address-autocomplete-loading-indicator' },
					createElement( Spinner, null )
				),
				showDropdown && suggestions.length > 0 && createElement(
					'div',
					{ className: 'venue-autocomplete-dropdown' },
					suggestions.map( function( place, index ) {
						return createElement(
							'div',
							{
								key: place.place_id,
								className: 'venue-autocomplete-item' + ( index === selectedIndex ? ' selected' : '' ),
								onClick() {
									handleSelectPlace( place );
								},
								onMouseEnter() {
									setSelectedIndex( index );
								},
							},
							createElement(
								'div',
								{ className: 'venue-autocomplete-address' },
								place.display_name
							)
						);
					} )
				),
				showDropdown && suggestions.length === 0 && ! isLoading && ! error && inputValue.length >= 3 && createElement(
					'div',
					{ className: 'venue-autocomplete-dropdown' },
					createElement(
						'div',
						{ className: 'venue-autocomplete-error' },
						__( 'No addresses found. Try a different search.', 'data-machine-events' )
					)
				),
				error && createElement(
					'div',
					{ className: 'venue-autocomplete-dropdown' },
					createElement(
						'div',
						{ className: 'venue-autocomplete-error' },
						error
					)
				)
			),
			createElement(
				'p',
				{ className: 'address-autocomplete-attribution' },
				__( 'Address data', 'data-machine-events' ) + ' ',
				createElement(
					'a',
					{
						href: 'https://www.openstreetmap.org/copyright',
						target: '_blank',
						rel: 'noopener noreferrer',
					},
					'© OpenStreetMap'
				)
			),
		];

		return createElement(
			'div',
			{
				className: 'datamachine-handler-field address-autocomplete-field',
				ref: containerRef,
			},
			children
		);
	}

	addFilter(
		'datamachine.handlerSettings.fieldComponent',
		'data-machine-events/address-autocomplete',
		function( component, fieldType, fieldKey, handlerSlug ) {
			if ( fieldType === 'address-autocomplete' ) {
				return AddressAutocompleteField;
			}
			return component;
		}
	);
} )();
