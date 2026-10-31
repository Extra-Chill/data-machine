<?php
/**
 * Event Type Taxonomy Registration, Vocabulary, and Closed-Vocabulary Enforcement
 *
 * Promotes the former `eventType` block attribute to a real, queryable
 * taxonomy (see data-machine-events#761). The taxonomy carries a closed
 * vocabulary: every term maps to a Schema.org `@type` through the
 * `_schema_type` term meta value, and AI-supplied values can never create
 * new terms.
 *
 * The default vocabulary ships the Schema.org event types this plugin already
 * implements ({@see EventSchemaProvider::EVENT_TYPES}). Consumers replace or
 * extend it through the `data_machine_events_event_type_vocabulary` filter —
 * site-specific editorial vocabulary belongs in the consumer, never here.
 *
 * Closed-vocabulary enforcement uses the two Data Machine core hooks:
 *  - `datamachine_taxonomy_tool_parameter` — narrows the AI tool schema to a
 *    string with an `enum` derived from the resolved vocabulary.
 *  - `datamachine_taxonomy_assign_value` — resolves the AI value to an
 *    existing term ID (or the declared default) *before* Data Machine's
 *    generic term-creation path runs.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Event_Type_Taxonomy {

	/** Taxonomy slug. */
	public const TAXONOMY = 'event_type';

	/** Term meta key holding the Schema.org `@type` a term maps to. */
	public const SCHEMA_TYPE_META_KEY = '_schema_type';

	/** Option key holding the hash of the vocabulary that was last seeded. */
	private const SEEDED_OPTION = 'data_machine_events_event_type_seeded';

	/**
	 * Vocabulary hashes already reconciled in this request.
	 *
	 * @var array<string,bool>
	 */
	private static array $seeded = array();

	/** Guard so the enforcement filters are only hooked once. */
	private static bool $filters_registered = false;

	public static function register(): void {
		self::register_event_type_taxonomy();
		self::register_enforcement_filters();
		self::maybe_seed_vocabulary();
	}

	private static function register_event_type_taxonomy(): void {
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			register_taxonomy_for_object_type( self::TAXONOMY, Event_Post_Type::POST_TYPE );
		} else {
			register_taxonomy(
				self::TAXONOMY,
				array( Event_Post_Type::POST_TYPE ),
				array(
					'hierarchical'      => false,
					'public'            => true,
					'labels'            => array(
						'name'          => _x( 'Event Types', 'taxonomy general name', 'data-machine-events' ),
						'singular_name' => _x( 'Event Type', 'taxonomy singular name', 'data-machine-events' ),
						'search_items'  => __( 'Search Event Types', 'data-machine-events' ),
						'all_items'     => __( 'All Event Types', 'data-machine-events' ),
						'edit_item'     => __( 'Edit Event Type', 'data-machine-events' ),
						'update_item'   => __( 'Update Event Type', 'data-machine-events' ),
						'add_new_item'  => __( 'Add New Event Type', 'data-machine-events' ),
						'new_item_name' => __( 'New Event Type Name', 'data-machine-events' ),
						'menu_name'     => __( 'Event Types', 'data-machine-events' ),
					),
					'show_ui'           => true,
					'show_in_menu'      => true,
					'show_admin_column' => true,
					'query_var'         => true,
					'rewrite'           => array( 'slug' => 'event-type' ),
					'show_in_rest'      => true,
				)
			);
		}

		register_taxonomy_for_object_type( self::TAXONOMY, Event_Post_Type::POST_TYPE );

		register_term_meta(
			self::TAXONOMY,
			self::SCHEMA_TYPE_META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'description'       => 'Schema.org @type this event type maps to.',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => false,
			)
		);
	}

	private static function register_enforcement_filters(): void {
		if ( self::$filters_registered ) {
			return;
		}
		self::$filters_registered = true;

		add_filter( 'datamachine_taxonomy_tool_parameter', array( __CLASS__, 'filter_tool_parameter' ), 10, 4 );
		add_filter( 'datamachine_taxonomy_assign_value', array( __CLASS__, 'filter_assign_value' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Vocabulary
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve the active event-type vocabulary.
	 *
	 * @return array<int,array{name:string,slug:string,schema_type:string,default:bool}>
	 */
	public static function get_vocabulary(): array {
		$default = EventSchemaProvider::default_event_type_vocabulary();

		/**
		 * Filters the closed vocabulary backing the `event_type` taxonomy.
		 *
		 * Each entry declares a term name and the Schema.org `@type` it maps
		 * to. Consumers may replace the list entirely to express editorial
		 * event formats that have no Schema.org equivalent, as long as every
		 * entry still maps to a valid `@type`.
		 *
		 * Accepted entry shapes:
		 *  - `array( 'name' => 'Music Event', 'schema_type' => 'MusicEvent' )`
		 *    (optional `slug` and `default` keys)
		 *  - `'Music Event' => 'MusicEvent'` (name keyed to its `@type`)
		 *
		 * @since 0.9.2
		 *
		 * @param mixed $vocabulary Default Schema.org vocabulary, self-mapped.
		 */
		$vocabulary = apply_filters( 'data_machine_events_event_type_vocabulary', $default );

		$normalized = self::normalize_vocabulary( is_array( $vocabulary ) ? $vocabulary : array() );

		return empty( $normalized ) ? self::normalize_vocabulary( $default ) : $normalized;
	}

	/**
	 * Coerce arbitrary vocabulary declarations into the canonical entry shape.
	 *
	 * @param array $vocabulary Raw vocabulary declaration.
	 * @return array<int,array{name:string,slug:string,schema_type:string,default:bool}>
	 */
	private static function normalize_vocabulary( array $vocabulary ): array {
		$entries     = array();
		$seen        = array();
		$has_default = false;

		foreach ( $vocabulary as $key => $value ) {
			if ( is_string( $value ) ) {
				// Shape: 'Term Name' => 'SchemaType'.
				$entry = array(
					'name'        => is_string( $key ) ? $key : $value,
					'schema_type' => $value,
				);
			} elseif ( is_array( $value ) ) {
				$entry = $value;
				if ( empty( $entry['name'] ) && is_string( $key ) ) {
					$entry['name'] = $key;
				}
			} else {
				continue;
			}

			$name        = trim( (string) ( $entry['name'] ?? '' ) );
			$schema_type = trim( (string) ( $entry['schema_type'] ?? '' ) );

			if ( '' === $name || '' === $schema_type ) {
				continue;
			}

			$slug = trim( (string) ( $entry['slug'] ?? '' ) );
			if ( '' === $slug ) {
				$slug = sanitize_title( $name );
			}
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;

			$is_default  = ! $has_default && ! empty( $entry['default'] );
			$has_default = $has_default || $is_default;

			$entries[] = array(
				'name'        => $name,
				'slug'        => $slug,
				'schema_type' => $schema_type,
				'default'     => $is_default,
			);
		}

		if ( ! $has_default && ! empty( $entries ) ) {
			$entries[0]['default'] = true;
		}

		return $entries;
	}

	/**
	 * Term names exposed to the AI as the closed `enum`.
	 *
	 * @return string[]
	 */
	public static function get_vocabulary_names(): array {
		return array_map(
			static fn( array $entry ): string => $entry['name'],
			self::get_vocabulary()
		);
	}

	/**
	 * The vocabulary entry used when a supplied value cannot be resolved.
	 *
	 * @return array{name:string,slug:string,schema_type:string,default:bool}|null
	 */
	public static function get_default_entry(): ?array {
		foreach ( self::get_vocabulary() as $entry ) {
			if ( ! empty( $entry['default'] ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Match an arbitrary value against the vocabulary.
	 *
	 * Matches on term name, slug, or Schema.org `@type`, case-insensitively.
	 * Numeric values are resolved through the terms table first so a term ID
	 * supplied by a pre-selected pipeline configuration still resolves.
	 *
	 * @param mixed $value Candidate value.
	 * @return array{name:string,slug:string,schema_type:string,default:bool}|null
	 */
	public static function find_vocabulary_entry( $value ): ?array {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( trim( $value ) ) ) ) {
			$term = taxonomy_exists( self::TAXONOMY ) ? get_term( (int) $value, self::TAXONOMY ) : null;
			if ( $term instanceof \WP_Term ) {
				$value = $term->slug;
			}
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$needle = strtolower( trim( $value ) );
		if ( '' === $needle ) {
			return null;
		}

		$slug_needle = sanitize_title( $value );

		foreach ( self::get_vocabulary() as $entry ) {
			$candidates = array(
				strtolower( $entry['name'] ),
				strtolower( $entry['slug'] ),
				strtolower( $entry['schema_type'] ),
			);

			if ( in_array( $needle, $candidates, true ) ) {
				return $entry;
			}

			if ( '' !== $slug_needle && $slug_needle === $entry['slug'] ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Resolve a value to the Schema.org `@type` it represents.
	 *
	 * This is the single validation primitive replacing the raw
	 * `in_array( $value, EventSchemaProvider::EVENT_TYPES )` checks: it honors
	 * the active vocabulary (including consumer-supplied editorial terms) and
	 * always answers with a Schema.org type, never an editorial label.
	 *
	 * @param mixed $value Candidate value.
	 * @return string Schema.org `@type`, or '' when the value is not in the vocabulary.
	 */
	public static function resolve_schema_type( $value ): string {
		$entry = self::find_vocabulary_entry( $value );

		return $entry ? $entry['schema_type'] : '';
	}

	/**
	 * Whether a value belongs to the active vocabulary.
	 *
	 * @param mixed $value Candidate value.
	 */
	public static function is_valid_value( $value ): bool {
		return null !== self::find_vocabulary_entry( $value );
	}

	/* ---------------------------------------------------------------------
	 * Term seeding
	 * ------------------------------------------------------------------ */

	/**
	 * Seed the taxonomy from the active vocabulary when it has changed.
	 */
	public static function maybe_seed_vocabulary(): void {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}

		$vocabulary = self::get_vocabulary();
		$hash       = self::vocabulary_hash( $vocabulary );

		if ( isset( self::$seeded[ $hash ] ) ) {
			return;
		}

		if ( get_option( self::SEEDED_OPTION ) === $hash ) {
			self::$seeded[ $hash ] = true;
			return;
		}

		self::seed_vocabulary( $vocabulary );
	}

	/**
	 * Create any missing vocabulary terms and (re)stamp their `_schema_type`.
	 *
	 * Only vocabulary terms are ever created here. AI-supplied values never
	 * reach this path — see {@see self::filter_assign_value()}.
	 *
	 * @param array|null $vocabulary Optional pre-resolved vocabulary.
	 * @return array<string,int> Term IDs keyed by vocabulary slug.
	 */
	public static function seed_vocabulary( ?array $vocabulary = null ): array {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return array();
		}

		$vocabulary = null === $vocabulary ? self::get_vocabulary() : $vocabulary;
		$term_ids   = array();

		foreach ( $vocabulary as $entry ) {
			$term_id = self::ensure_term( $entry );
			if ( $term_id > 0 ) {
				$term_ids[ $entry['slug'] ] = $term_id;
			}
		}

		$hash                  = self::vocabulary_hash( $vocabulary );
		self::$seeded[ $hash ] = true;
		update_option( self::SEEDED_OPTION, $hash, false );

		return $term_ids;
	}

	/**
	 * Ensure a single vocabulary term exists and carries its `_schema_type`.
	 *
	 * @param array{name:string,slug:string,schema_type:string} $entry Vocabulary entry.
	 * @return int Term ID, or 0 on failure.
	 */
	private static function ensure_term( array $entry ): int {
		$term = get_term_by( 'slug', $entry['slug'], self::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			$term = get_term_by( 'name', $entry['name'], self::TAXONOMY );
		}

		if ( $term instanceof \WP_Term ) {
			$term_id = (int) $term->term_id;
		} else {
			$created = wp_insert_term( $entry['name'], self::TAXONOMY, array( 'slug' => $entry['slug'] ) );
			if ( is_wp_error( $created ) ) {
				$existing = $created->get_error_data( 'term_exists' );
				if ( ! $existing ) {
					do_action(
						'datamachine_log',
						'warning',
						'Failed to seed event_type vocabulary term',
						array(
							'term'  => $entry['name'],
							'error' => $created->get_error_message(),
						)
					);
					return 0;
				}
				$term_id = (int) ( is_array( $existing ) ? ( $existing['term_id'] ?? 0 ) : $existing );
			} else {
				$term_id = (int) $created['term_id'];
			}
		}

		if ( $term_id <= 0 ) {
			return 0;
		}

		if ( (string) get_term_meta( $term_id, self::SCHEMA_TYPE_META_KEY, true ) !== $entry['schema_type'] ) {
			update_term_meta( $term_id, self::SCHEMA_TYPE_META_KEY, $entry['schema_type'] );
		}

		return $term_id;
	}

	/**
	 * Resolve a value to an existing vocabulary term ID.
	 *
	 * Never creates a term for an unrecognized value: unknown input either
	 * falls back to the declared default entry or resolves to 0.
	 *
	 * @param mixed $value               Candidate value.
	 * @param bool  $fallback_to_default Whether unknown input resolves to the default term.
	 * @return int Term ID, or 0 when unresolvable.
	 */
	public static function resolve_term_id( $value, bool $fallback_to_default = true ): int {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return 0;
		}

		$entry = self::find_vocabulary_entry( $value );
		if ( null === $entry && $fallback_to_default ) {
			$entry = self::get_default_entry();
		}

		if ( null === $entry ) {
			return 0;
		}

		$term = get_term_by( 'slug', $entry['slug'], self::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			// The vocabulary changed (or was never seeded on this install).
			// Reconcile now rather than silently dropping the assignment.
			self::seed_vocabulary();
			$term = get_term_by( 'slug', $entry['slug'], self::TAXONOMY );
		}

		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * Read the Schema.org `@type` an event's assigned term maps to.
	 *
	 * This is the authoritative JSON-LD `@type` source; the block attribute is
	 * derived output kept for events that predate the taxonomy.
	 *
	 * @param int $post_id Event post ID.
	 * @return string Schema.org `@type`, or '' when no term is assigned.
	 */
	public static function get_schema_type_for_post( int $post_id ): string {
		if ( $post_id <= 0 || ! taxonomy_exists( self::TAXONOMY ) ) {
			return '';
		}

		$terms = get_the_terms( $post_id, self::TAXONOMY );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		foreach ( $terms as $term ) {
			$schema_type = (string) get_term_meta( $term->term_id, self::SCHEMA_TYPE_META_KEY, true );
			if ( '' !== $schema_type ) {
				return $schema_type;
			}

			// Terms created before the meta existed still resolve through the
			// vocabulary, so JSON-LD never regresses to a bare "Event".
			$entry = self::find_vocabulary_entry( $term->slug );
			if ( $entry ) {
				return $entry['schema_type'];
			}
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * Data Machine closed-vocabulary enforcement
	 * ------------------------------------------------------------------ */

	/**
	 * Narrow the AI tool schema for `event_type` to the closed vocabulary.
	 *
	 * Data Machine's generic default exposes a flat taxonomy as a free-form
	 * array of strings whose terms are created on demand. `event_type` is a
	 * single-valued closed vocabulary, so the parameter becomes a string with
	 * an `enum` derived from the resolved vocabulary.
	 *
	 * @param mixed $param_def      Generic JSON Schema fragment.
	 * @param mixed $taxonomy       Taxonomy object being exposed.
	 * @param mixed $handler_config Handler configuration (unused; part of the hook signature).
	 * @param mixed $post_type      Post type in scope (unused; part of the hook signature).
	 * @return mixed
	 */
	public static function filter_tool_parameter( $param_def, $taxonomy, $handler_config = array(), $post_type = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! is_object( $taxonomy ) || self::TAXONOMY !== ( $taxonomy->name ?? '' ) ) {
			return $param_def;
		}

		$names = self::get_vocabulary_names();
		if ( empty( $names ) ) {
			return $param_def;
		}

		if ( ! is_array( $param_def ) ) {
			$param_def = array();
		}

		unset( $param_def['items'] );

		$param_def['type']        = 'string';
		$param_def['enum']        = array_values( $names );
		$param_def['description'] = sprintf(
			'The format of this event. Choose exactly one value from the allowed list: %s. This is a closed vocabulary — never invent a value, never combine values, and never return anything outside the list. If none of them clearly fit, choose "%s".',
			implode( ', ', $names ),
			(string) ( self::get_default_entry()['name'] ?? $names[0] )
		);

		return $param_def;
	}

	/**
	 * Resolve the AI-supplied `event_type` value before term creation.
	 *
	 * Runs ahead of Data Machine's `processTerms()`/`findOrCreateTerm()` pass,
	 * so an invented value can never reach `wp_insert_term()`. Returns an
	 * existing term ID (the matched vocabulary term, or the declared default)
	 * or '' to skip assignment entirely.
	 *
	 * @param mixed  $value    AI-supplied taxonomy value.
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $post_id  Post receiving the assignment.
	 * @return mixed
	 */
	public static function filter_assign_value( $value, $taxonomy, $post_id ) {
		if ( self::TAXONOMY !== $taxonomy ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return '';
		}

		$matched = self::find_vocabulary_entry( $value );
		if ( null === $matched ) {
			do_action(
				'datamachine_log',
				'warning',
				'Rejected out-of-vocabulary event_type value; falling back to the default term',
				array(
					'post_id' => (int) $post_id,
					'value'   => (string) $value,
				)
			);
		}

		$term_id = self::resolve_term_id( $value );

		return $term_id > 0 ? (string) $term_id : '';
	}

	/**
	 * Stable hash of a resolved vocabulary, used as the seeding guard.
	 *
	 * @param array $vocabulary Resolved vocabulary.
	 */
	private static function vocabulary_hash( array $vocabulary ): string {
		return md5( (string) wp_json_encode( $vocabulary ) );
	}
}
