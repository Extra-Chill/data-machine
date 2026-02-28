<?php
/**
 * Event Upsert Handler Settings
 *
 * Configuration management for Event Upsert handler with automatic venue handling.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 * @since 0.2.5
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachine\Core\WordPress\WordPressSettingsHandler;
use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages Event Upsert configuration and settings for Data Machine integration
 *
 * Provides settings interface for intelligent create-or-update event handling
 * with automatic venue metadata population from import handlers.
 */
class EventUpsertSettings {

	/**
	 * Get settings fields for Data Machine integration
	 *
	 * Required method for Data Machine handler settings system.
	 * Returns field definitions for Event Upsert handler configuration.
	 *
	 * @param array $current_config Current configuration values for this handler
	 * @return array Field definitions for Data Machine settings interface
	 */
	public static function get_fields( array $current_config = array() ): array {
		// Get available WordPress users for post authorship
		$user_options = WordPressSettingsHandler::get_user_options();

		$fields = array(
			'post_status'    => array(
				'type'        => 'select',
				'label'       => __( 'Post Status', 'data-machine-events' ),
				'description' => __( 'Select the status for created/updated events.', 'data-machine-events' ),
				'options'     => array(
					'draft'   => __( 'Draft', 'data-machine-events' ),
					'publish' => __( 'Publish', 'data-machine-events' ),
					'pending' => __( 'Pending Review', 'data-machine-events' ),
					'private' => __( 'Private', 'data-machine-events' ),
				),
				'default'     => 'draft',
			),
			'include_images' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Images', 'data-machine-events' ),
				'description' => __( 'Automatically set featured images for events when image URLs are provided by import handlers.', 'data-machine-events' ),
				'default'     => false,
			),
			'post_author'    => array(
				'type'        => 'select',
				'label'       => __( 'Post Author', 'data-machine-events' ),
				'description' => __( 'Select which WordPress user to publish events under.', 'data-machine-events' ),
				'options'     => $user_options,
				'default'     => ! empty( $user_options ) ? array_key_first( $user_options ) : null,
			),
		);

		// Add dynamic taxonomy fields using core WordPressSettingsHandler
		// Venue is excluded because it has a custom handler registered in EventUpsert.php
		// Category and post_tag are excluded as they are not valid for event upsert
		$taxonomy_fields = WordPressSettingsHandler::get_taxonomy_fields(
			array(
				'post_type'            => Event_Post_Type::POST_TYPE,
				'exclude_taxonomies'   => array( 'venue', 'category', 'post_tag' ),
				'field_suffix'         => '_selection',
				'first_options'        => array(
					'skip'       => __( 'Skip', 'data-machine-events' ),
					'ai_decides' => __( 'AI Decides', 'data-machine-events' ),
				),
				'description_template' => __( 'Configure %1$s assignment: Skip to exclude from AI instructions, let AI choose, or select specific %2$s.', 'data-machine-events' ),
				'default'              => 'skip',
			)
		);

		// Ensure promoter taxonomy follows the same selection behavior as other taxonomies
		if ( isset( $taxonomy_fields['taxonomy_promoter_selection'] ) ) {
			$taxonomy_fields['taxonomy_promoter_selection']['description'] = __( 'Configure promoter assignment: Skip to exclude, let AI choose based on organizer data, or select a specific promoter term.', 'data-machine-events' );
		}

		return array_merge( $fields, $taxonomy_fields );
	}

	/**
	 * Sanitize Event Upsert handler settings for Data Machine integration
	 *
	 * Required method for Data Machine handler settings system.
	 * Validates and sanitizes form input data.
	 *
	 * @param array $raw_settings Raw settings input from form
	 * @return array Sanitized settings
	 */
	public static function sanitize( array $raw_settings ): array {
		$sanitized = array(
			'post_status'    => sanitize_text_field( $raw_settings['post_status'] ?? 'draft' ),
			'post_author'    => absint( $raw_settings['post_author'] ?? 0 ),
			'include_images' => ! empty( $raw_settings['include_images'] ),
		);

		// Validate post status
		$valid_statuses = array( 'draft', 'publish', 'pending', 'private' );
		if ( ! in_array( $sanitized['post_status'], $valid_statuses, true ) ) {
			$sanitized['post_status'] = 'draft';
		}

		// Sanitize dynamic taxonomy selections using core WordPressSettingsHandler
		// Venue is excluded because it has a custom handler registered in EventUpsert.php
		// Category and post_tag are excluded as they are not valid for event upsert
		$sanitized = array_merge(
			$sanitized,
			WordPressSettingsHandler::sanitize_taxonomy_fields(
				$raw_settings,
				array(
					'post_type'          => Event_Post_Type::POST_TYPE,
					'exclude_taxonomies' => array( 'venue', 'category', 'post_tag' ),
					'field_suffix'       => '_selection',
					'allowed_values'     => array( 'skip', 'ai_decides' ),
					'default_value'      => 'skip',
				)
			)
		);

		if ( isset( $sanitized['taxonomy_promoter_selection'] ) && is_numeric( $sanitized['taxonomy_promoter_selection'] ) ) {
			$sanitized['taxonomy_promoter_selection'] = absint( $sanitized['taxonomy_promoter_selection'] );
		}

		return $sanitized;
	}



	/**
	 * Get taxonomy terms for AI context
	 *
	 * @param string $taxonomy_name Taxonomy name
	 * @return array Terms with name and description for AI context
	 */
	public static function get_taxonomy_terms_for_ai( string $taxonomy_name ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy_name,
				'hide_empty' => false,
				'number'     => 20,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$ai_terms = array();
		foreach ( $terms as $term ) {
			$ai_terms[] = array(
				'name'        => $term->name,
				'description' => $term->description,
			);
		}

		return $ai_terms;
	}
}
