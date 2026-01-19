<?php
/**
 * Update Venue Tool
 *
 * Updates venue name and meta fields. Triggers auto-geocoding when address fields change.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\ToolRegistrationTrait;
use DataMachineEvents\Core\Venue_Taxonomy;

class UpdateVenue {
	use ToolRegistrationTrait;

	public function __construct() {
		$this->registerTool( 'chat', 'update_venue', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update a venue name and/or meta fields. Address changes trigger automatic geocoding.',
			'parameters'  => array(
				'venue'       => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Venue identifier (term ID, name, or slug)',
				),
				'name'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New venue name',
				),
				'description' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Venue description',
				),
				'address'     => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Street address',
				),
				'city'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'City',
				),
				'state'       => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'State/region',
				),
				'zip'         => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Postal/ZIP code',
				),
				'country'     => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Country',
				),
				'phone'       => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Phone number',
				),
				'website'     => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Website URL',
				),
				'capacity'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Venue capacity',
				),
				'coordinates' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'GPS coordinates as "lat,lng"',
				),
				'timezone'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'IANA timezone identifier (e.g., America/New_York)',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$venue_identifier = $parameters['venue'] ?? null;

		if ( empty( $venue_identifier ) ) {
			return array(
				'success'   => false,
				'error'     => 'venue parameter is required',
				'tool_name' => 'update_venue',
			);
		}

		// Resolve venue term
		$term = $this->resolveVenue( $venue_identifier );
		if ( ! $term ) {
			return array(
				'success'   => false,
				'error'     => "Venue '{$venue_identifier}' not found",
				'tool_name' => 'update_venue',
			);
		}

		$updated_fields = array();

		// Update term fields (name, description) if provided
		$term_updates = array();
		if ( ! empty( $parameters['name'] ) ) {
			$term_updates['name'] = sanitize_text_field( $parameters['name'] );
			$updated_fields[]     = 'name';
		}
		if ( isset( $parameters['description'] ) && '' !== $parameters['description'] ) {
			$term_updates['description'] = wp_kses_post( $parameters['description'] );
			$updated_fields[]            = 'description';
		}

		if ( ! empty( $term_updates ) ) {
			$result = wp_update_term( $term->term_id, 'venue', $term_updates );
			if ( is_wp_error( $result ) ) {
				return array(
					'success'   => false,
					'error'     => 'Failed to update venue: ' . $result->get_error_message(),
					'tool_name' => 'update_venue',
				);
			}
		}

		// Build meta data array from parameters
		$meta_keys = array( 'address', 'city', 'state', 'zip', 'country', 'phone', 'website', 'capacity', 'coordinates', 'timezone' );
		$meta_data = array();

		foreach ( $meta_keys as $key ) {
			if ( array_key_exists( $key, $parameters ) && null !== $parameters[ $key ] && '' !== $parameters[ $key ] ) {
				$meta_data[ $key ] = $parameters[ $key ];
				$updated_fields[]  = $key;
			}
		}

		// Update meta if any provided
		if ( ! empty( $meta_data ) ) {
			Venue_Taxonomy::update_venue_meta( $term->term_id, $meta_data );
		}

		if ( empty( $updated_fields ) ) {
			return array(
				'success'   => false,
				'error'     => 'No fields provided to update',
				'tool_name' => 'update_venue',
			);
		}

		// Get updated venue data
		$updated_term = get_term( $term->term_id, 'venue' );
		$venue_data   = Venue_Taxonomy::get_venue_data( $term->term_id );

		return array(
			'success'   => true,
			'data'      => array(
				'term_id'        => $term->term_id,
				'name'           => $updated_term->name,
				'updated_fields' => $updated_fields,
				'venue_data'     => $venue_data,
				'message'        => "Updated venue '{$updated_term->name}': " . implode( ', ', $updated_fields ),
			),
			'tool_name' => 'update_venue',
		);
	}

	/**
	 * Resolve venue by ID, name, or slug.
	 */
	private function resolveVenue( string $identifier ): ?\WP_Term {
		// Try as ID
		if ( is_numeric( $identifier ) ) {
			$term = get_term( (int) $identifier, 'venue' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		// Try by name
		$term = get_term_by( 'name', $identifier, 'venue' );
		if ( $term ) {
			return $term;
		}

		// Try by slug
		$term = get_term_by( 'slug', $identifier, 'venue' );
		if ( $term ) {
			return $term;
		}

		return null;
	}
}
