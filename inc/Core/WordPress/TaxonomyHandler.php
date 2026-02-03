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

		do_action(
			'datamachine_log',
			'debug',
			'Processing taxonomies for post',
			array(
				'post_id'          => $post_id,
				'post_type'        => $post_type,
				'found_taxonomies' => array_keys( $taxonomies ),
			)
		);

		foreach ( $taxonomies as $taxonomy ) {
			if ( self::shouldSkipTaxonomy( $taxonomy->name ) ) {
				continue;
			}

			$field_key = "taxonomy_{$taxonomy->name}_selection";
			$selection = $handler_config[ $field_key ] ?? 'skip';

			do_action(
				'datamachine_log',
				'debug',
				"Taxonomy check: {$taxonomy->name}",
				array(
					'selection'        => $selection,
					'field_key'        => $field_key,
					'param_name'       => $this->getParameterName( $taxonomy->name ),
					'has_tool_param'   => isset( $parameters[ $this->getParameterName( $taxonomy->name ) ] ),
					'has_engine_param' => isset( $engine_data[ $this->getParameterName( $taxonomy->name ) ] ),
				)
			);

			if ( 'skip' === $selection ) {
				continue;
			} elseif ( $this->isAiDecidedTaxonomy( $selection ) ) {
				$result = $this->processAiDecidedTaxonomy( $post_id, $taxonomy, $parameters, $engine_data, $handler_config );
				if ( $result ) {
					$taxonomy_results[ $taxonomy->name ] = $result;
				}
			} elseif ( $this->isPreSelectedTaxonomy( $selection ) ) {
				$result = $this->processPreSelectedTaxonomy( $post_id, $taxonomy->name, $selection, $engine_data );
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

			if ( 'ai_decides' !== $selection ) {
				continue;
			}

			// Map taxonomy name to parameter name (category -> category, post_tag -> tags)
			$param_name = $taxonomy->name === 'post_tag' ? 'tags' : $taxonomy->name;

			// Get taxonomy label
			$taxonomy_label = ( is_object( $taxonomy->labels ) && isset( $taxonomy->labels->name ) )
				? $taxonomy->labels->name
				: ( isset( $taxonomy->label ) ? $taxonomy->label : $taxonomy->name );

			$is_hierarchical = $taxonomy->hierarchical;

			$parameters[ $param_name ] = array(
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
				$parameters[ $param_name ]['items'] = array(
					'type' => 'string',
				);
			}
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

	private function isAiDecidedTaxonomy( string $selection ): bool {
		return 'ai_decides' === $selection;
	}

	private function isPreSelectedTaxonomy( string $selection ): bool {
		return ! empty( $selection ) && 'skip' !== $selection && 'ai_decides' !== $selection;
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
	private function getParameterName( string $taxonomy_name ): string {
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
	private function processPreSelectedTaxonomy( int $post_id, string $taxonomy_name, string $selection, array $engine_data = array() ): ?array {
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
				do_action(
					'datamachine_log',
					'debug',
					'WordPress Tool: Applied pre-selected taxonomy',
					array(
						'taxonomy_name' => $taxonomy_name,
						'term_id'       => $term_id,
						'term_name'     => $term_name,
					)
				);

				return $this->createSuccessResult( $taxonomy_name, array( $term_name ), array( $term_id ) );
			}
		}

		return null;
	}

	/**
	 * Assign taxonomy terms with dynamic term creation using wp_insert_term().
	 * Creates non-existing terms automatically before assignment.
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
		// 1. Check if it's a numeric term ID
		if ( is_numeric( $term_identifier ) ) {
			$term = get_term( absint( $term_identifier ), $taxonomy_name );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->term_id;
			}
		}

		// 2. Try by name (exact match)
		$term = get_term_by( 'name', $term_identifier, $taxonomy_name );
		if ( $term ) {
			return $term->term_id;
		}

		// 3. Try by slug (handles cases where AI provides slug instead of name)
		$term = get_term_by( 'slug', $term_identifier, $taxonomy_name );
		if ( $term ) {
			return $term->term_id;
		}

		// 4. No existing term found - create new one
		$term_result = wp_insert_term( $term_identifier, $taxonomy_name );
		if ( is_wp_error( $term_result ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Failed to create taxonomy term',
				array(
					'taxonomy'        => $taxonomy_name,
					'term_identifier' => $term_identifier,
					'error'           => $term_result->get_error_message(),
				)
			);
			return false;
		}

		return $term_result['term_id'];
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
