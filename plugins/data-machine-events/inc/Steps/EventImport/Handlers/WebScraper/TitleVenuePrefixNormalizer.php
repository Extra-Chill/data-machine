<?php
/**
 * Per-flow room prefixes shared by structured calendar and HTML extraction.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TitleVenuePrefixNormalizer {

	/**
	 * Strip a configured leading prefix and select this event's venue config.
	 * Config is copied by the caller so an override never leaks to another item.
	 */
	public static function normalize( array $event, array &$config ): array {
		foreach ( preg_split( '/\R/', $config['title_venue_prefixes'] ?? '' ) as $line ) {
			$parts   = explode( '=>', $line, 2 );
			$prefix  = trim( $parts[0] );
			$pattern = '/^\s*' . preg_quote( $prefix, '/' ) . '\s*:\s*/iu';
			if ( '' === $prefix || ! preg_match( $pattern, $event['title'] ?? '' ) ) {
				continue;
			}

			$event['title'] = trim( preg_replace( $pattern, '', $event['title'], 1 ) );
			// Some HTML extractors infer a performer directly from the title.
			foreach ( array( 'performer', 'artist' ) as $field ) {
				if ( isset( $event[ $field ] ) && is_string( $event[ $field ] ) ) {
					$event[ $field ] = 0 === strcasecmp( trim( $event[ $field ] ), $prefix )
						? '' : preg_replace( $pattern, '', $event[ $field ], 1 );
				}
			}

			$venue = trim( $parts[1] ?? '' );
			if ( '' !== $venue ) {
				$term = is_numeric( $venue ) ? get_term( (int) $venue, 'venue' ) : get_term_by( 'name', $venue, 'venue' );
				// Invalid IDs must not discard the flow's fallback venue.
				if ( ( $term && ! is_wp_error( $term ) ) || ! is_numeric( $venue ) ) {
					foreach ( array_keys( $config ) as $key ) {
						if ( 'venue' === $key || str_starts_with( $key, 'venue_' ) ) {
							unset( $config[ $key ] );
						}
					}
					foreach ( array_keys( $event ) as $key ) {
						if ( str_starts_with( $key, 'venue' ) ) {
							unset( $event[ $key ] );
						}
					}
					if ( $term && ! is_wp_error( $term ) ) {
						$config['venue'] = $term->term_id;
					} else {
						$config['venue_name'] = $venue;
					}
				}
			}
			break;
		}

		return $event;
	}
}
