<?php
/**
 * WordPress Settings Handler Utilities
 *
 * Provides reusable WordPress-specific settings utilities for taxonomy fields,
 * post type options, and user options across all WordPress handler Settings classes.
 * Eliminates duplication between Publish, Fetch, and Update Settings.
 *
 * @package DataMachine\Core\WordPress
 * @since 0.2.1
 */

namespace DataMachine\Core\WordPress;

use DataMachine\Core\Selection\SelectionMode;

defined( 'ABSPATH' ) || exit;

class WordPressSettingsHandler {

	/**
	 * Get dynamic taxonomy fields for all available public taxonomies.
	 *
	 * @param array $config Configuration array with:
	 *  - field_suffix: '_selection' or '_filter'
	 *  - first_options: Array of initial options (skip/ai_decides or all)
	 *  - description_template: sprintf template for field description
	 * @return array Taxonomy field definitions
	 */
	public static function get_taxonomy_fields( array $config = array() ): array {
		$defaults = array(
			'field_suffix'         => 'selection',
			'first_options'        => array(
				'skip'       => esc_html__( 'Skip', 'data-machine' ),
				'ai_decides' => esc_html__( 'AI Decides', 'data-machine' ),
			),
			/* translators: 1: taxonomy label, 2: taxonomy term label */
			'description_template' => __(
				'Configure %1$s assignment: Skip to exclude from AI instructions, let AI choose, or select specific %2$s.',
				'data-machine'
			),
			'default'              => 'skip',
			'post_type'            => null,
			'exclude_taxonomies'   => array(),
		);
		$config = array_merge( $defaults, $config );

		$taxonomy_fields = array();
		$taxonomies      = TaxonomyHandler::getPublicTaxonomies( $config['post_type'] );

		foreach ( $taxonomies as $taxonomy ) {
			if ( TaxonomyHandler::shouldSkipTaxonomy( $taxonomy->name ) ) {
				continue;
			}

			// Skip extension-specific excluded taxonomies (e.g., venue for events)
			if ( in_array( $taxonomy->name, $config['exclude_taxonomies'], true ) ) {
				continue;
			}

			$taxonomy_slug  = $taxonomy->name;
			$taxonomy_label = ( is_object( $taxonomy->labels ) && isset( $taxonomy->labels->name ) )
				? $taxonomy->labels->name
				: ( isset( $taxonomy->label ) ? $taxonomy->label : $taxonomy->name );

			// Build mode options, formatting any placeholders with taxonomy label.
			$mode_options = array();
			foreach ( $config['first_options'] as $key => $label ) {
				/* translators: %s: Taxonomy label */
				$mode_options[ $key ] = sprintf( $label, $taxonomy_label );
			}

			// Get terms for this taxonomy as pre-select values.
			$value_options = array();
			$terms         = get_terms(
				array(
					'taxonomy'   => $taxonomy_slug,
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					$value_options[ $term->term_id ] = $term->name;
				}
			}

			// Build combined options via SelectionMode.
			$options = SelectionMode::buildOptions( $mode_options, $value_options );

			// Generate field definition
			$field_key                     = "taxonomy_{$taxonomy_slug}{$config['field_suffix']}";
			$taxonomy_fields[ $field_key ] = array(
				'type'        => 'select',
				'label'       => $taxonomy_label,
				/* translators: 1: taxonomy label, 2: taxonomy term label */
				'description' => sprintf(
					$config['description_template'],
					strtolower( $taxonomy_label ),
					$taxonomy->hierarchical ? __( 'category', 'data-machine' ) : __( 'term', 'data-machine' )
				),
				'options'     => $options,
				'default'     => $config['default'] ?? 'skip',
			);
		}

		return $taxonomy_fields;
	}

	/**
	 * Sanitize dynamic taxonomy field settings.
	 *
	 * @param array $raw_settings Raw settings input
	 * @param array $config Configuration array with:
	 *  - field_suffix: '_selection' or '_filter'
	 *  - allowed_values: Array of allowed string values (e.g., ['skip', 'ai_decides'] or [0])
	 *  - default_value: Default value if validation fails
	 * @return array Sanitized taxonomy settings
	 */
	public static function sanitize_taxonomy_fields( array $raw_settings, array $config = array() ): array {
		$defaults = array(
			'field_suffix'       => 'selection',
			'allowed_values'     => array( 'skip', 'ai_decides' ),
			'default_value'      => 'skip',
			'post_type'          => null,
			'exclude_taxonomies' => array(),
		);
		$config   = array_merge( $defaults, $config );

		$sanitized  = array();
		$taxonomies = TaxonomyHandler::getPublicTaxonomies( $config['post_type'] );

		foreach ( $taxonomies as $taxonomy ) {
			if ( TaxonomyHandler::shouldSkipTaxonomy( $taxonomy->name ) ) {
				continue;
			}

			// Skip extension-specific excluded taxonomies (e.g., venue for events)
			if ( in_array( $taxonomy->name, $config['exclude_taxonomies'], true ) ) {
				continue;
			}

			$field_key = "taxonomy_{$taxonomy->name}{$config['field_suffix']}";
			$raw_value = $raw_settings[ $field_key ] ?? $config['default_value'];

			// Use SelectionMode::sanitize with a taxonomy-specific validator.
			$taxonomy_name           = $taxonomy->name;
			$sanitized[ $field_key ] = SelectionMode::sanitize(
				$raw_value,
				function ( $value ) use ( $taxonomy_name ) {
					// Try as numeric term ID.
					$term_id = absint( $value );
					if ( $term_id > 0 ) {
						$term_name = TaxonomyHandler::getTermName( $term_id, $taxonomy_name );
						return null !== $term_name ? $term_id : null;
					}

					// Try to resolve as term name or slug.
					$resolved_term = self::resolve_term_by_name_or_slug( $value, $taxonomy_name );
					return $resolved_term ? $resolved_term->term_id : null;
				},
				$config['default_value']
			);
		}

		return $sanitized;
	}

	/**
	 * Get available WordPress post type options.
	 *
	 * @param bool $include_any Include "Any" option at the start
	 * @return array Post type options
	 */
	public static function get_post_type_options( bool $include_any = false ): array {
		$post_types        = get_post_types( array( 'public' => true ), 'objects' );
		$post_type_options = array();

		// Add "Any" option if requested (for fetch handlers)
		if ( $include_any ) {
			$post_type_options['any'] = __( 'Any', 'data-machine' );
		}

		// Remove attachment post type as it's not suitable for content publishing
		unset( $post_types['attachment'] );

		// Prioritize common post types first
		$common_type_order = array( 'post', 'page' );
		foreach ( $common_type_order as $slug ) {
			if ( isset( $post_types[ $slug ] ) ) {
				$post_type_options[ $slug ] = $post_types[ $slug ]->label;
				unset( $post_types[ $slug ] );
			}
		}

		// Add remaining post types
		foreach ( $post_types as $pt ) {
			$post_type_options[ $pt->name ] = $pt->label;
		}

		return $post_type_options;
	}

	/**
	 * Get available WordPress users for post authorship.
	 *
	 * @return array User options (user_id => display_name)
	 */
	public static function get_user_options(): array {
		$user_options = array();
		$users        = get_users( array( 'fields' => array( 'ID', 'display_name', 'user_login' ) ) );

		foreach ( $users as $user ) {
			$display_name              = ! empty( $user->display_name ) ? $user->display_name : $user->user_login;
			$user_options[ $user->ID ] = $display_name;
		}

		return $user_options;
	}

	/**
	 * Get standard WordPress publish fields (post_type, post_status, post_author).
	 *
	 * @param array $config Configuration overrides
	 * @return array Standard publish fields
	 */
	public static function get_standard_publish_fields( array $config = array() ): array {
		$defaults = array(
			'domain'              => 'data-machine',
			'post_type_default'   => 'post',
			'post_status_default' => 'draft',
			'post_author_default' => null,
		);
		$config   = array_merge( $defaults, $config );
		$domain   = $config['domain'];

		// Get options
		$post_type_options = self::get_post_type_options( false );
		$user_options      = self::get_user_options();

		// Default author to first user if not specified
		if ( null === $config['post_author_default'] && ! empty( $user_options ) ) {
			$config['post_author_default'] = array_key_first( $user_options );
		}

		return array(
			'post_type'   => array(
				'type'        => 'select',
				'label'       => __( 'Post Type', 'data-machine' ),
				'description' => __( 'Select the post type for published content.', 'data-machine' ),
				'options'     => $post_type_options,
				'default'     => $config['post_type_default'],
			),
			'post_status' => array(
				'type'        => 'select',
				'label'       => __( 'Post Status', 'data-machine' ),
				'description' => __( 'Select the status for the newly created post.', 'data-machine' ),
				'options'     => array(
					'draft'   => __( 'Draft', 'data-machine' ),
					'publish' => __( 'Publish', 'data-machine' ),
					'pending' => __( 'Pending Review', 'data-machine' ),
					'private' => __( 'Private', 'data-machine' ),
				),
				'default'     => $config['post_status_default'],
			),
			'post_author' => array(
				'type'        => 'select',
				'label'       => __( 'Post Author', 'data-machine' ),
				'description' => __( 'Select which WordPress user to publish posts under.', 'data-machine' ),
				'options'     => $user_options,
				'default'     => $config['post_author_default'],
			),
		);
	}

	/**
	 * Sanitize standard WordPress publish fields.
	 *
	 * @param array $raw_settings Raw settings input
	 * @return array Sanitized settings subset for standard fields
	 */
	public static function sanitize_standard_publish_fields( array $raw_settings ): array {
		$sanitized = array();

		if ( isset( $raw_settings['post_type'] ) ) {
			$sanitized['post_type'] = sanitize_text_field( $raw_settings['post_type'] );
		}

		if ( isset( $raw_settings['post_status'] ) ) {
			$sanitized['post_status'] = sanitize_text_field( $raw_settings['post_status'] );
		}

		if ( isset( $raw_settings['post_author'] ) ) {
			$sanitized['post_author'] = absint( $raw_settings['post_author'] );
		}

		return $sanitized;
	}

	/**
	 * Resolve a term by name or slug with case-insensitive matching.
	 *
	 * @param string $value    The term name or slug to resolve.
	 * @param string $taxonomy The taxonomy name.
	 * @return \WP_Term|null The resolved term object or null if not found.
	 */
	private static function resolve_term_by_name_or_slug( string $value, string $taxonomy ): ?\WP_Term {
		// Try exact name match first
		$term = get_term_by( 'name', $value, $taxonomy );
		if ( $term instanceof \WP_Term ) {
			return $term;
		}

		// Try case-insensitive name match
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'name__like' => $value,
				'number'     => 10,
			)
		);
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $candidate ) {
				if ( strcasecmp( $candidate->name, $value ) === 0 ) {
					return $candidate;
				}
			}
		}

		// Try slug match
		$term = get_term_by( 'slug', $value, $taxonomy );
		if ( $term instanceof \WP_Term ) {
			return $term;
		}

		return null;
	}
}
