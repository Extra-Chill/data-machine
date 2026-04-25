<?php
/**
 * Merge Term Meta Ability
 *
 * Post-resolution primitive for writing taxonomy term meta with two strategies:
 *
 *   - 'fill_empty' (default): only writes meta keys whose existing stored value
 *     is empty. Used on the existing-term path of find-or-create flows so that
 *     incoming data enriches the term without clobbering operator edits.
 *   - 'overwrite':              writes every supplied meta key unconditionally.
 *     Used on the freshly-created-term path where there is nothing to preserve.
 *
 * Pairs with ResolveTermAbility. Together they collapse the find-or-create-with-meta
 * pattern that has shown up across DM and DM-events taxonomies (Promoter, Venue, ...).
 *
 * @package DataMachine\Abilities\Taxonomy
 */

namespace DataMachine\Abilities\Taxonomy;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\WordPress\TaxonomyHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MergeTermMetaAbility {

	public const STRATEGY_FILL_EMPTY = 'fill_empty';
	public const STRATEGY_OVERWRITE  = 'overwrite';

	public function __construct() {
		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/merge-term-meta',
				array(
					'label'               => __( 'Merge Term Meta', 'data-machine' ),
					'description'         => __( 'Write taxonomy term meta with fill-empty or overwrite semantics. Pairs with resolve-term as the post-resolution write step in find-or-create flows.', 'data-machine' ),
					'category'            => 'datamachine-taxonomy',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'term_id'     => array(
								'type'        => 'integer',
								'description' => __( 'Target term ID.', 'data-machine' ),
							),
							'taxonomy'    => array(
								'type'        => 'string',
								'description' => __( 'Taxonomy slug. Used for validation and logs.', 'data-machine' ),
							),
							'data'        => array(
								'type'        => 'object',
								'description' => __( 'Incoming values keyed by data_key. Empty values are skipped.', 'data-machine' ),
							),
							'field_map'   => array(
								'type'        => 'object',
								'description' => __( 'Map of data_key => meta_key. Only listed keys are considered; everything else in data is ignored.', 'data-machine' ),
							),
							'strategy'    => array(
								'type'        => 'string',
								'enum'        => array( self::STRATEGY_FILL_EMPTY, self::STRATEGY_OVERWRITE ),
								'default'     => self::STRATEGY_FILL_EMPTY,
								'description' => __( 'fill_empty: only write keys whose stored value is empty. overwrite: write every supplied key unconditionally.', 'data-machine' ),
							),
							'description' => array(
								'type'        => 'string',
								'description' => __( 'Optional term description. Written to the term row, not term_meta. Honours the same strategy.', 'data-machine' ),
							),
						),
						'required'   => array( 'term_id', 'taxonomy', 'data', 'field_map' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'             => array( 'type' => 'boolean' ),
							'term_id'             => array( 'type' => 'integer' ),
							'taxonomy'            => array( 'type' => 'string' ),
							'updated'             => array( 'type' => 'array' ),
							'skipped'             => array( 'type' => 'array' ),
							'description_updated' => array( 'type' => 'boolean' ),
							'error'               => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute the merge.
	 *
	 * @param array $input Input shape — see input_schema above.
	 * @return array Output shape — see output_schema above.
	 */
	public function execute( array $input ): array {
		$term_id     = (int) ( $input['term_id'] ?? 0 );
		$taxonomy    = trim( (string) ( $input['taxonomy'] ?? '' ) );
		$data        = is_array( $input['data'] ?? null ) ? $input['data'] : array();
		$field_map   = is_array( $input['field_map'] ?? null ) ? $input['field_map'] : array();
		$strategy    = (string) ( $input['strategy'] ?? self::STRATEGY_FILL_EMPTY );
		$description = array_key_exists( 'description', $input ) ? (string) $input['description'] : null;

		if ( $term_id <= 0 ) {
			return $this->error_response( 'term_id is required' );
		}

		if ( '' === $taxonomy ) {
			return $this->error_response( 'taxonomy is required' );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->error_response( "Taxonomy '{$taxonomy}' does not exist" );
		}

		if ( TaxonomyHandler::shouldSkipTaxonomy( $taxonomy ) ) {
			return $this->error_response( "Cannot write meta for system taxonomy '{$taxonomy}'" );
		}

		if ( empty( $field_map ) ) {
			return $this->error_response( 'field_map is required and cannot be empty' );
		}

		if ( ! in_array( $strategy, array( self::STRATEGY_FILL_EMPTY, self::STRATEGY_OVERWRITE ), true ) ) {
			return $this->error_response( "Unknown strategy '{$strategy}'" );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return $this->error_response( "Term {$term_id} not found in taxonomy '{$taxonomy}'" );
		}

		$updated = array();
		$skipped = array();

		foreach ( $field_map as $data_key => $meta_key ) {
			$data_key = (string) $data_key;
			$meta_key = (string) $meta_key;

			if ( '' === $data_key || '' === $meta_key ) {
				continue;
			}

			if ( ! array_key_exists( $data_key, $data ) ) {
				$skipped[] = $data_key;
				continue;
			}

			$incoming = $data[ $data_key ];

			// Treat null and empty-string as "not provided". This mirrors the
			// behaviour of the legacy update_*_meta / smart_merge_*_meta helpers
			// the consumers used to roll themselves.
			if ( null === $incoming || '' === $incoming || ( is_array( $incoming ) && empty( $incoming ) ) ) {
				$skipped[] = $data_key;
				continue;
			}

			if ( self::STRATEGY_FILL_EMPTY === $strategy ) {
				$existing = get_term_meta( $term_id, $meta_key, true );
				if ( '' !== $existing && null !== $existing && array() !== $existing ) {
					$skipped[] = $data_key;
					continue;
				}
			}

			update_term_meta( $term_id, $meta_key, sanitize_text_field( (string) $incoming ) );
			$updated[] = $data_key;
		}

		$description_updated = false;
		if ( null !== $description && '' !== $description ) {
			$should_write = true;
			if ( self::STRATEGY_FILL_EMPTY === $strategy ) {
				$should_write = ( '' === (string) $term->description );
			}

			if ( $should_write ) {
				$result = wp_update_term(
					$term_id,
					$taxonomy,
					array(
						'description' => sanitize_textarea_field( $description ),
					)
				);

				if ( is_wp_error( $result ) ) {
					return $this->error_response( $result->get_error_message() );
				}

				$description_updated = true;
			}
		}

		return array(
			'success'             => true,
			'term_id'             => $term_id,
			'taxonomy'            => $taxonomy,
			'updated'             => array_values( array_unique( $updated ) ),
			'skipped'             => array_values( array_unique( $skipped ) ),
			'description_updated' => $description_updated,
		);
	}

	/**
	 * Build error response.
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	private function error_response( string $message ): array {
		return array(
			'success' => false,
			'error'   => $message,
		);
	}

	/**
	 * Check permission for this ability.
	 *
	 * @return bool
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Static helper for internal use without going through the abilities API.
	 *
	 * @param int    $term_id     Target term ID.
	 * @param string $taxonomy    Taxonomy slug.
	 * @param array  $data        Incoming data keyed by data_key.
	 * @param array  $field_map   Map of data_key => meta_key. Determines what is considered.
	 * @param string $strategy    'fill_empty' (default) or 'overwrite'.
	 * @param string|null $description Optional term description. Honours the strategy.
	 * @return array Result with success/updated/skipped/description_updated/error.
	 */
	public static function merge(
		int $term_id,
		string $taxonomy,
		array $data,
		array $field_map,
		string $strategy = self::STRATEGY_FILL_EMPTY,
		?string $description = null
	): array {
		$input = array(
			'term_id'   => $term_id,
			'taxonomy'  => $taxonomy,
			'data'      => $data,
			'field_map' => $field_map,
			'strategy'  => $strategy,
		);

		if ( null !== $description ) {
			$input['description'] = $description;
		}

		return ( new self() )->execute( $input );
	}
}
