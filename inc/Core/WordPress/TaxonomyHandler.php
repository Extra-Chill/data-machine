<?php
/**
 * Modular taxonomy processing for WordPress publish operations.
 *
 * Supports three selection modes per taxonomy: skip, AI-decided, pre-selected.
 * Creates non-existing terms dynamically. Excludes system taxonomies.
 *
 * @package DataMachine
 * @subpackage Core\Steps\Publish\Handlers\WordPress
 * @since 0.2.1
 */

namespace DataMachine\Core\WordPress;

use DataMachine\Abilities\Taxonomy\ResolveTermAbility;
use DataMachine\Core\Selection\SelectionMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TaxonomyHandler {

	/**
	 * WordPress system taxonomies that should be excluded from Data Machine processing.
	 *
	 * @var array
	 */
	private const SYSTEM_TAXONOMIES = array( 'post_format', 'nav_menu', 'link_category' );

	/**
	 * Register a custom handler for a specific taxonomy.
	 *
	 * Custom handlers will be invoked instead of the standard assignTaxonomy workflow
	 * when a taxonomy matches the registered name.
	 *
	 * @param string   $taxonomy_name
	 * @param callable $handler Callable with signature function(int $post_id, array $parameters, array $handler_config, array $engine_data): ?array
	 */
	public static function addCustomHandler( string $taxonomy_name, callable $handler ): void {
		self::$custom_handlers[ $taxonomy_name ] = $handler;
	}

	/**
	 * Internal storage for registered custom handlers
	 *
	 * @var array<string, callable>
	 */
	private static $custom_handlers = array();

	/**
	 * Process taxonomies based on configuration.
	 *
	 * @param int   $post_id WordPress post ID
	 * @param array $parameters Tool parameters with AI-decided taxonomy values
	 * @param array $handler_config Handler configuration with taxonomy selections
	 * @param array $engine_data Engine-provided context (repository, scraping results, etc.)
	 * @return array Processing results for all configured taxonomies
	 */
	public function processTaxonomies( int $post_id, array $parameters, array $handler_config, array $engine_data = array() ): array {
		$taxonomy_results = array();

		// Determine post type to fetch scoped taxonomies
		$post_type = get_post_type( $post_id );
		if ( false === $post_type ) {
			$post_type = null;
		}
		$taxonomies = self::getPublicTaxonomies( $post_type );

		foreach ( $taxonomies as $taxonomy ) {
			if ( self::shouldSkipTaxonomy( $taxonomy->name ) ) {
				continue;
			}

			$field_key = "taxonomy_{$taxonomy->name}_selection";
			$selection = $handler_config[ $field_key ] ?? 'skip';

			$mode = SelectionMode::detect( $selection );

			if ( SelectionMode::SKIP === $mode ) {
				continue;
			} elseif ( SelectionMode::AI_DECIDES === $mode ) {
				$result = $this->processAiDecidedTaxonomy( $post_id, $taxonomy, $parameters, $engine_data, $handler_config );
				if ( $result ) {
					$taxonomy_results[ $taxonomy->name ] = $result;
				}
			} elseif ( SelectionMode::PRE_SELECTED === $mode ) {
				$result = $this->processPreSelectedTaxonomy( $post_id, $taxonomy->name, $selection);
				if ( $result ) {
					$taxonomy_results[ $taxonomy->name ] = $result;
				}
			}
		}

		return $taxonomy_results;
	}

	public static function getPublicTaxonomies( ?string $post_type = null ): array {
		if ( null !== $post_type ) {
			return get_object_taxonomies( $post_type, 'objects' );
		}
		return get_taxonomies( array( 'public' => true ), 'objects' );
	}

	/**
	 * Generate dynamic taxonomy parameters for AI tool definitions.
	 *
	 * Iterates through public taxonomies (or specific post type taxonomies) and
	 * generates tool parameters for any taxonomy configured as "AI Decides".
	 *
	 * The auto-generated description uses only the taxonomy label, which is
	 * intentionally generic — DM core has no business knowing what any
	 * particular taxonomy means. Plugins that own a taxonomy should hook the
	 * {@see 'datamachine_taxonomy_tool_parameter'} filter to enrich the
	 * description with domain context, add an `enum` constraint, mark the
	 * field required, etc.
	 *
	 * Example: a plugin that owns a "region" taxonomy might want the AI to
	 * only pick from a fixed list of cities and never invent new ones:
	 *
	 *     add_filter(
	 *         'datamachine_taxonomy_tool_parameter',
	 *         function ( $param_def, $taxonomy, $handler_config, $post_type ) {
	 *             if ( 'region' !== $taxonomy->name ) {
	 *                 return $param_def;
	 *             }
	 *             $terms = get_terms( array( 'taxonomy' => 'region', 'hide_empty' => false ) );
	 *             $param_def['enum']        = wp_list_pluck( $terms, 'name' );
	 *             $param_def['description'] = 'Pick the discovery region this post belongs to. Must be one of the existing terms — do not invent new regions.';
	 *             return $param_def;
	 *         },
	 *         10,
	 *         4
	 *     );
	 *
	 * @param array       $handler_config Handler configuration with taxonomy selections
	 * @param string|null $post_type Optional post type to filter taxonomies
	 * @return array Parameter definitions for AI-decided taxonomies
	 */
	public static function getTaxonomyToolParameters( array $handler_config, ?string $post_type = null ): array {
		$parameters = array();
		$taxonomies = self::getPublicTaxonomies( $post_type );

		foreach ( $taxonomies as $taxonomy ) {
			if ( self::shouldSkipTaxonomy( $taxonomy->name ) ) {
				continue;
			}

			$field_key = "taxonomy_{$taxonomy->name}_selection";
			$selection = $handler_config[ $field_key ] ?? 'skip';

			if ( ! SelectionMode::isAiDecides( $selection ) ) {
				continue;
			}

			// Map taxonomy name to parameter name (category -> category, post_tag -> tags)
			$param_name = 'post_tag' === $taxonomy->name ? 'tags' : $taxonomy->name;

			// Get taxonomy label
			$taxonomy_label = ( is_object( $taxonomy->labels ) && isset( $taxonomy->labels->name ) )
				? $taxonomy->labels->name
				: ( isset( $taxonomy->label ) ? $taxonomy->label : $taxonomy->name );

			$is_hierarchical = $taxonomy->hierarchical;

			$param_def = array(
				'type'        => $is_hierarchical ? 'string' : 'array',
				'description' => sprintf(
					'Assign %s for this post. %s',
					strtolower( $taxonomy_label ),
					$is_hierarchical
						? 'Provide a single term name as a string. Will be created if it does not exist.'
						: 'Provide an array of term names. Terms will be created if they do not exist.'
				),
			);

			// For non-hierarchical (tags), we need to specify items type
			if ( ! $is_hierarchical ) {
				$param_def['items'] = array(
					'type' => 'string',
				);
			}

			/**
			 * Filters the AI tool parameter definition for a taxonomy.
			 *
			 * Allows the plugin that owns a taxonomy to enrich the description
			 * with semantic context the AI can use to pick correct term values,
			 * add an `enum` constraint, mark the field `required`, change the
			 * `type`, etc. Default behavior (when no filter is hooked) is
			 * identical to pre-filter output.
			 *
			 * Taxonomy ownership lives in the plugin that registered the
			 * taxonomy, not in Data Machine core. This filter is the recommended
			 * extension point for any taxonomy whose business rules live in
			 * another plugin.
			 *
			 * @since 0.131.0
			 *
			 * @param array       $param_def      JSON Schema fragment for this parameter.
			 *                                    Includes `type`, `description`, and
			 *                                    (for non-hierarchical taxonomies) `items`.
			 * @param \WP_Taxonomy $taxonomy       The taxonomy object being exposed to the AI.
			 * @param array       $handler_config The handler config (so the filter can
			 *                                    inspect whether this is AI-decided vs
			 *                                    pre-selected, and behave accordingly).
			 * @param string|null $post_type      Post type being targeted, or null if all
			 *                                    public taxonomies are in scope.
			 */
			$param_def = apply_filters(
				'datamachine_taxonomy_tool_parameter',
				$param_def,
				$taxonomy,
				$handler_config,
				$post_type
			);

			$parameters[ $param_name ] = $param_def;
		}

		return $parameters;
	}

	/**
	 * Get system taxonomies excluded from Data Machine processing.
	 *
	 * @return array System taxonomy names
	 */
	public static function getSystemTaxonomies(): array {
		return self::SYSTEM_TAXONOMIES;
	}

	public static function shouldSkipTaxonomy( string $taxonomy_name ): bool {
		return in_array( $taxonomy_name, self::SYSTEM_TAXONOMIES, true );
	}

	/**
	 * Get term name from term ID and taxonomy.
	 *
	 * @param int    $term_id WordPress term ID
	 * @param string $taxonomy Taxonomy name
	 * @return string|null Term name if exists, null otherwise
	 */
	public static function getTermName( int $term_id, string $taxonomy ): ?string {
		$term = get_term( $term_id, $taxonomy );
		return ( ! is_wp_error( $term ) && $term ) ? $term->name : null;
	}

	/**
	 * Process AI-decided taxonomy assignment.
	 *
	 * @param int    $post_id WordPress post ID
	 * @param object $taxonomy WordPress taxonomy object
	 * @param array  $parameters AI tool parameters
	 * @return array|null Taxonomy assignment result or null if no parameter
	 */
	private function processAiDecidedTaxonomy( int $post_id, object $taxonomy, array $parameters, array $engine_data = array(), array $handler_config = array() ): ?array {
		// Check for a registered custom handler for this taxonomy
		if ( ! empty( self::$custom_handlers[ $taxonomy->name ] ) && is_callable( self::$custom_handlers[ $taxonomy->name ] ) ) {
			$handler = self::$custom_handlers[ $taxonomy->name ];
			$result  = $handler( $post_id, $parameters, $handler_config, $engine_data );
			if ( $result ) {
				return $result;
			}
		}

		$param_name = $this->getParameterName( $taxonomy->name );

		// Check AI-decided parameters first, then engine-provided parameters as a fallback
		$param_value = null;
		if ( ! empty( $parameters[ $param_name ] ) ) {
			$param_value = $parameters[ $param_name ];
		} elseif ( ! empty( $engine_data[ $param_name ] ) ) {
			$param_value = $engine_data[ $param_name ];
		}

		if ( ! empty( $param_value ) ) {
			$taxonomy_result = $this->assignTaxonomy( $post_id, $taxonomy->name, $param_value );

			do_action(
				'datamachine_log',
				'debug',
				'WordPress Tool: Applied AI-decided taxonomy',
				array(
					'taxonomy_name'   => $taxonomy->name,
					'parameter_name'  => $param_name,
					'parameter_value' => $param_value,
					'result'          => $taxonomy_result,
				)
			);

			return $taxonomy_result;
		}

		return null;
	}

	/**
	 * Get parameter name for taxonomy using standard naming conventions.
	 * Maps category->category, post_tag->tags, others->taxonomy_name
	 *
	 * @param string $taxonomy_name WordPress taxonomy name
	 * @return string Corresponding parameter name for AI tools
	 */
	public static function getParameterName( string $taxonomy_name ): string {
		if ( 'category' === $taxonomy_name ) {
			return 'category';
		} elseif ( 'post_tag' === $taxonomy_name ) {
			return 'tags';
		} else {
			return $taxonomy_name;
		}
	}

	/**
	 * Map a parameter name to the value either from parameters or engine data.
	 * Note: legacy alias handling has been removed — use canonical parameter names only.
	 */
	// Aliases removed: getParameterName -> parameter lookup only

	/**
	 * Process pre-selected taxonomy assignment.
	 *
	 * Accepts term ID, name, or slug. Resolves to term ID before assignment.
	 *
	 * @param int    $post_id WordPress post ID
	 * @param string $taxonomy_name Taxonomy name
	 * @param string $selection Term ID, name, or slug
	 * @return array|null Taxonomy assignment result or null if invalid
	 */
	private function processPreSelectedTaxonomy( int $post_id, string $taxonomy_name, string $selection): ?array {
		$term_id   = null;
		$term_name = null;

		if ( is_numeric( $selection ) ) {
			$term_id   = absint( $selection );
			$term_name = self::getTermName( $term_id, $taxonomy_name );
		} else {
			$term = get_term_by( 'name', $selection, $taxonomy_name );
			if ( ! $term ) {
				$term = get_term_by( 'slug', $selection, $taxonomy_name );
			}
			if ( $term ) {
				$term_id   = $term->term_id;
				$term_name = $term->name;
			}
		}

		if ( null !== $term_id && null !== $term_name ) {
			$result = wp_set_object_terms( $post_id, array( $term_id ), $taxonomy_name );

			if ( is_wp_error( $result ) ) {
				return $this->createErrorResult( $result->get_error_message() );
			} else {
				return $this->createSuccessResult( $taxonomy_name, array( $term_name ), array( $term_id ) );
			}
		}

		return null;
	}

	/**
	 * Assign taxonomy terms with dynamic term creation using wp_insert_term().
	 *
	 * Creates non-existing terms automatically before assignment. Fires the
	 * {@see 'datamachine_taxonomy_assign_value'} filter before processing so
	 * the plugin that owns the taxonomy can sanitize, coerce, or refuse the
	 * AI-supplied value at runtime — preventing junk terms from being created
	 * when the AI invents values outside the taxonomy's intended domain.
	 *
	 * Example: a plugin that owns a "region" taxonomy can refuse any value
	 * the AI invented and force the value to be derived from another field:
	 *
	 *     add_filter(
	 *         'datamachine_taxonomy_assign_value',
	 *         function ( $taxonomy_value, $taxonomy_name, $post_id ) {
	 *             if ( 'region' !== $taxonomy_name ) {
	 *                 return $taxonomy_value;
	 *             }
	 *             // Region is derived from another field, not AI-decided.
	 *             // Returning empty skips assignment for this taxonomy.
	 *             return '';
	 *         },
	 *         10,
	 *         3
	 *     );
	 *
	 * When the filter returns an empty string, null, or empty array, the
	 * assignment is skipped silently (no terms created, no error raised).
	 * Default behavior (when no filter is hooked) is identical to the
	 * pre-filter behavior.
	 *
	 * @param int    $post_id WordPress post ID
	 * @param string $taxonomy_name Taxonomy name
	 * @param mixed  $taxonomy_value Term name(s) - string or array
	 * @return array Assignment result with success status and details
	 */
	public function assignTaxonomy( int $post_id, string $taxonomy_name, $taxonomy_value ): array {
		if ( ! $this->validateTaxonomyExists( $taxonomy_name ) ) {
			return $this->createErrorResult( "Taxonomy '{$taxonomy_name}' does not exist" );
		}

		/**
		 * Filters the AI-supplied taxonomy value before term resolution.
		 *
		 * Lets the plugin that owns a taxonomy mutate, sanitize, or refuse the
		 * value the AI picked, before {@see processTerms()} creates new terms.
		 * Returning an empty value (`''`, `null`, or `array()`) skips
		 * assignment for this taxonomy without raising an error — useful when
		 * a taxonomy is derived from another data source and the AI must
		 * never pick it.
		 *
		 * @since 0.131.0
		 *
		 * @param mixed  $taxonomy_value The AI-supplied value. Either a string
		 *                               (hierarchical taxonomy) or an array
		 *                               of strings (flat taxonomy).
		 * @param string $taxonomy_name  The taxonomy slug being assigned.
		 * @param int    $post_id        The post ID receiving the assignment.
		 */
		$taxonomy_value = apply_filters(
			'datamachine_taxonomy_assign_value',
			$taxonomy_value,
			$taxonomy_name,
			$post_id
		);

		// Empty filter return: skip assignment silently.
		if ( null === $taxonomy_value || '' === $taxonomy_value || ( is_array( $taxonomy_value ) && empty( $taxonomy_value ) ) ) {
			return $this->createSuccessResult( $taxonomy_name, array(), array() );
		}

		$terms    = is_array( $taxonomy_value ) ? $taxonomy_value : array( $taxonomy_value );
		$term_ids = $this->processTerms( $terms, $taxonomy_name );

		if ( ! empty( $term_ids ) ) {
			$result = $this->setPostTerms( $post_id, $term_ids, $taxonomy_name );
			if ( is_wp_error( $result ) ) {
				return $this->createErrorResult( $result->get_error_message() );
			}
		}

		return $this->createSuccessResult( $taxonomy_name, $terms, $term_ids );
	}

	private function validateTaxonomyExists( string $taxonomy_name ): bool {
		return taxonomy_exists( $taxonomy_name );
	}

	private function processTerms( array $terms, string $taxonomy_name ): array {
		$term_ids = array();

		foreach ( $terms as $term_name ) {
			$term_name = sanitize_text_field( $term_name );
			if ( empty( $term_name ) ) {
				continue;
			}

			$term_id = $this->findOrCreateTerm( $term_name, $taxonomy_name );
			if ( false !== $term_id ) {
				$term_ids[] = $term_id;
			}
		}

		return $term_ids;
	}

	/**
	 * Find existing term or create new one.
	 *
	 * Searches for existing terms by:
	 * 1. Term ID (if numeric)
	 * 2. Term name (exact match)
	 * 3. Term slug (exact match)
	 *
	 * Only creates a new term if no existing term is found.
	 *
	 * @param string $term_identifier Term name, slug, or ID
	 * @param string $taxonomy_name   Taxonomy name
	 * @return int|false Term ID on success, false on failure
	 */
	private function findOrCreateTerm( string $term_identifier, string $taxonomy_name ) {
		// Use centralized resolve-term ability for all term resolution.
		$result = ResolveTermAbility::resolve( $term_identifier, $taxonomy_name, true );

		if ( $result['success'] ) {
			return $result['term_id'];
		}

		do_action(
			'datamachine_log',
			'warning',
			'Failed to resolve taxonomy term',
			array(
				'taxonomy'        => $taxonomy_name,
				'term_identifier' => $term_identifier,
				'error'           => $result['error'] ?? 'Unknown error',
			)
		);

		return false;
	}

	private function setPostTerms( int $post_id, array $term_ids, string $taxonomy_name ) {
		return wp_set_object_terms( $post_id, $term_ids, $taxonomy_name );
	}

	private function createSuccessResult( string $taxonomy_name, array $terms, array $term_ids ): array {
		return array(
			'success'    => true,
			'taxonomy'   => $taxonomy_name,
			'term_count' => count( $term_ids ),
			'terms'      => $terms,
		);
	}

	private function createErrorResult( string $error_message ): array {
		return array(
			'success' => false,
			'error'   => $error_message,
		);
	}
}
