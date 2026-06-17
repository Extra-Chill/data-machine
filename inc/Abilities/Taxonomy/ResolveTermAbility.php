<?php
/**
 * Resolve Term Ability
 *
 * Single source of truth for taxonomy term resolution.
 * All code paths that need to find or create terms should use this ability.
 *
 * @package DataMachine\Abilities\Taxonomy
 */

namespace DataMachine\Abilities\Taxonomy;

use DataMachine\Abilities\PermissionHelper;

use DataMachine\Core\WordPress\TaxonomyHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ResolveTermAbility {

	public function __construct() {
		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/resolve-term',
				array(
					'label'               => __( 'Resolve Term', 'data-machine' ),
					'description'         => __( 'Find or create a taxonomy term by ID, name, or slug. Single source of truth for term resolution.', 'data-machine' ),
					'category'            => 'datamachine-taxonomy',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'identifier' => array(
								'type'        => 'string',
								'description' => __( 'Term identifier - can be numeric ID, name, or slug', 'data-machine' ),
							),
							'taxonomy'   => array(
								'type'        => 'string',
								'description' => __( 'Taxonomy name (category, post_tag, etc.)', 'data-machine' ),
							),
							'create'     => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Create term if not found', 'data-machine' ),
							),
							'fuzzy'      => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Also match an existing term by normalized name (case/punctuation/article-insensitive) before creating. Catches variants like "Tyler, the Creator" vs "Tyler the Creator". Default false (exact name/slug only).', 'data-machine' ),
							),
							'args'       => array(
								'type'        => 'object',
								'description' => __( 'Optional arguments forwarded to wp_insert_term() on the create path. Ignored when an existing term is matched.', 'data-machine' ),
								'properties'  => array(
									'description' => array(
										'type'        => 'string',
										'description' => __( 'Term description.', 'data-machine' ),
									),
									'parent'      => array(
										'type'        => 'integer',
										'description' => __( 'Parent term ID for hierarchical taxonomies.', 'data-machine' ),
									),
									'slug'        => array(
										'type'        => 'string',
										'description' => __( 'Explicit term slug. Defaults to a sanitised version of the name.', 'data-machine' ),
									),
									'alias_of'    => array(
										'type'        => 'string',
										'description' => __( 'Slug of an existing term that the new term should alias.', 'data-machine' ),
									),
								),
							),
						),
						'required'   => array( 'identifier', 'taxonomy' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'  => array( 'type' => 'boolean' ),
							'term_id'  => array( 'type' => 'integer' ),
							'name'     => array( 'type' => 'string' ),
							'slug'     => array( 'type' => 'string' ),
							'taxonomy' => array( 'type' => 'string' ),
							'created'  => array( 'type' => 'boolean' ),
							'error'    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute term resolution.
	 *
	 * Resolution order:
	 * 1. If numeric, try get_term() by ID
	 * 2. Try get_term_by('name')
	 * 3. Try get_term_by('slug')
	 * 4. If create=true and not found, create via wp_insert_term()
	 *
	 * @param array $input Input with identifier, taxonomy, create flag, optional args.
	 * @return array|\WP_Error Success with term data or failure.
	 */
	public function execute( array $input ): array|\WP_Error {
		$identifier = trim( (string) ( $input['identifier'] ?? '' ) );
		$taxonomy   = trim( (string) ( $input['taxonomy'] ?? '' ) );
		$create     = (bool) ( $input['create'] ?? false );
		$fuzzy      = (bool) ( $input['fuzzy'] ?? false );
		$term_args  = is_array( $input['args'] ?? null ) ? $input['args'] : array();

		// Validate inputs.
		if ( empty( $identifier ) ) {
			return $this->error_response( 'identifier_required', 'identifier is required', 400 );
		}

		if ( empty( $taxonomy ) ) {
			return $this->error_response( 'taxonomy_required', 'taxonomy is required', 400 );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->error_response( 'taxonomy_not_found', "Taxonomy '{$taxonomy}' does not exist", 404 );
		}

		if ( TaxonomyHandler::shouldSkipTaxonomy( $taxonomy ) ) {
			return $this->error_response( 'taxonomy_not_modifiable', "Cannot resolve terms in system taxonomy '{$taxonomy}'", 403 );
		}

		// 1. Check if numeric - try by ID first.
		if ( is_numeric( $identifier ) ) {
			$term = get_term( absint( $identifier ), $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return $this->success_response( $term, false );
			}
		}

		// 2. Try by name (exact match).
		$term = get_term_by( 'name', $identifier, $taxonomy );
		if ( $term ) {
			return $this->success_response( $term, false );
		}

		// 3. Try by slug.
		$term = get_term_by( 'slug', sanitize_title( $identifier ), $taxonomy );
		if ( $term ) {
			return $this->success_response( $term, false );
		}

		// 4. Opt-in: try by normalized name (case/punctuation/article-insensitive).
		// Catches variants exact name/slug matching misses, e.g.
		// "Tyler, the Creator" = "Tyler the Creator", "Beyoncé" = "Beyonce".
		if ( $fuzzy ) {
			$term = $this->find_by_normalized_name( $identifier, $taxonomy );
			if ( $term ) {
				return $this->success_response( $term, false );
			}
		}

		// 5. Not found - create if requested.
		if ( $create ) {
			$insert_args = $this->normalize_term_args( $term_args );
			$result      = wp_insert_term( $identifier, $taxonomy, $insert_args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$term = get_term( $result['term_id'], $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				do_action(
					'datamachine_log',
					'info',
					'Created new taxonomy term via resolve-term ability',
					array(
						'term_id'    => $term->term_id,
						'name'       => $term->name,
						'taxonomy'   => $taxonomy,
						'identifier' => $identifier,
					)
				);
				return $this->success_response( $term, true );
			}
		}

		return $this->error_response( 'term_not_found', "Term '{$identifier}' not found in taxonomy '{$taxonomy}'", 404 );
	}

	/**
	 * Whitelist and sanitise wp_insert_term() arguments.
	 *
	 * Only the four create-time keys (description, parent, slug, alias_of) are forwarded.
	 * Any other input is silently dropped to keep the surface narrow.
	 *
	 * @param array $args Raw args from the caller.
	 * @return array Sanitised args ready for wp_insert_term().
	 */
	private function normalize_term_args( array $args ): array {
		$clean = array();

		if ( isset( $args['description'] ) ) {
			$clean['description'] = sanitize_textarea_field( (string) $args['description'] );
		}
		if ( isset( $args['parent'] ) ) {
			$clean['parent'] = absint( $args['parent'] );
		}
		if ( isset( $args['slug'] ) ) {
			$clean['slug'] = sanitize_title( (string) $args['slug'] );
		}
		if ( isset( $args['alias_of'] ) ) {
			$clean['alias_of'] = sanitize_title( (string) $args['alias_of'] );
		}

		return $clean;
	}

	/**
	 * Find an existing term by normalized-name comparison.
	 *
	 * Generic fuzzy term resolution: normalizes the identifier and every
	 * existing term name in the taxonomy to a canonical form and compares.
	 * Catches variants that exact name/slug matching misses:
	 * - "Tyler, the Creator" = "Tyler the Creator"
	 * - "Beyoncé" = "Beyonce"
	 * - "AC/DC" = "ACDC"
	 * - "Saturn - Birmingham" = "Saturn Birmingham"
	 *
	 * Requires the normalized identifier to be at least 3 characters to avoid
	 * false matches on very short names.
	 *
	 * @param string $identifier Term name to search for.
	 * @param string $taxonomy   Taxonomy name.
	 * @return \WP_Term|null Matching term, or null.
	 */
	private function find_by_normalized_name( string $identifier, string $taxonomy ): ?\WP_Term {
		$normalized_input = self::normalize_name_for_matching( $identifier );

		if ( strlen( $normalized_input ) < 3 ) {
			return null;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		foreach ( $terms as $term ) {
			if ( self::normalize_name_for_matching( $term->name ) === $normalized_input ) {
				do_action(
					'datamachine_log',
					'info',
					'Term matched via normalized name',
					array(
						'taxonomy'      => $taxonomy,
						'input_name'    => $identifier,
						'matched_name'  => $term->name,
						'matched_id'    => $term->term_id,
						'normalized_as' => $normalized_input,
					)
				);
				return $term;
			}
		}

		return null;
	}

	/**
	 * Normalize a term name to a canonical form for fuzzy matching.
	 *
	 * Decodes HTML entities, lowercases, normalizes ampersands to "and",
	 * strips leading articles, removes accents and all non-alphanumeric
	 * characters, and collapses whitespace. Pure string function — taxonomy
	 * agnostic, so any consumer (venue, artist, location, ...) shares it.
	 *
	 * @param string $name Raw term name.
	 * @return string Normalized comparison key.
	 */
	public static function normalize_name_for_matching( string $name ): string {
		// Decode HTML entities: &amp; → &, &#8217; → '.
		$text = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Strip accents: "Beyoncé" → "Beyonce".
		$text = remove_accents( $text );

		// Lowercase.
		$text = strtolower( $text );

		// Normalize ampersand variants to "and" so "Hook & Ladder" and
		// "Hook and Ladder" collapse together.
		$text = preg_replace( '/\s*&\s*/', ' and ', $text );

		// Remove a leading article.
		$text = preg_replace( '/^(the|a|an)\s+/i', '', $text );

		// Remove all non-alphanumeric characters (keeps spaces). Also strips
		// apostrophes/commas/slashes: "AC/DC" → "acdc", "Tyler, the" → "tyler the".
		$text = preg_replace( '/[^a-z0-9\s]/', '', $text );

		// Collapse whitespace and trim.
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		return $text;
	}

	/**
	 * Build success response.
	 *
	 * @param \WP_Term $term    Resolved term.
	 * @param bool     $created Whether term was created.
	 * @return array
	 */
	private function success_response( \WP_Term $term, bool $created ): array {
		return array(
			'success'  => true,
			'term_id'  => $term->term_id,
			'name'     => $term->name,
			'slug'     => $term->slug,
			'taxonomy' => $term->taxonomy,
			'created'  => $created,
		);
	}

	/**
	 * Build error response.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status for REST presentation.
	 * @return \WP_Error
	 */
	private function error_response( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Check permission for this ability.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Static helper for internal use without going through abilities API.
	 *
	 * This is the method all internal code should call.
	 *
	 * @param string $identifier Term identifier (ID, name, or slug).
	 * @param string $taxonomy   Taxonomy name.
	 * @param bool   $create     Create if not found.
	 * @param array  $args       Optional wp_insert_term() args, forwarded only on the create path.
	 *                           Whitelisted keys: description, parent, slug, alias_of.
	 * @param bool   $fuzzy      Also match by normalized name before creating. Default false.
	 * @return array Result with success, term data, or error.
	 */
	public static function resolve( string $identifier, string $taxonomy, bool $create = false, array $args = array(), bool $fuzzy = false ): array {
		$instance = new self();
		$result   = $instance->execute(
			array(
				'identifier' => $identifier,
				'taxonomy'   => $taxonomy,
				'create'     => $create,
				'args'       => $args,
				'fuzzy'      => $fuzzy,
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		return $result;
	}
}
