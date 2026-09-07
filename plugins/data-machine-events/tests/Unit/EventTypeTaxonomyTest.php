<?php
/**
 * Event Type Taxonomy Tests
 *
 * Covers the closed-vocabulary `event_type` taxonomy introduced in #761:
 * registration shape, Schema.org seeding, the vocabulary filter, and — the
 * core guarantee — that an AI response containing an invented value creates
 * no term.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachine\Core\WordPress\TaxonomyHandler;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Event_Type_Taxonomy;
use DataMachineEvents\Core\EventSchemaProvider;

class EventTypeTaxonomyTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		delete_option( 'data_machine_events_event_type_seeded' );
		Event_Type_Taxonomy::register();
		Event_Type_Taxonomy::seed_vocabulary();
	}

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_event_type_vocabulary' );
		parent::tearDown();
	}

	/** Delete every term in the taxonomy so creation can be observed cleanly. */
	private function clear_terms(): void {
		$terms = get_terms(
			array(
				'taxonomy'   => Event_Type_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			wp_delete_term( $term->term_id, Event_Type_Taxonomy::TAXONOMY );
		}
	}

	/** @return string[] */
	private function term_names(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Event_Type_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);

		return is_wp_error( $terms ) ? array() : wp_list_pluck( $terms, 'name' );
	}

	private function make_event(): int {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Event Type Test ' . uniqid(),
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		return (int) $post_id;
	}

	public function test_taxonomy_is_registered_public_flat_and_rest_visible(): void {
		$this->assertTrue( taxonomy_exists( Event_Type_Taxonomy::TAXONOMY ) );

		$taxonomy = get_taxonomy( Event_Type_Taxonomy::TAXONOMY );
		$this->assertInstanceOf( \WP_Taxonomy::class, $taxonomy );
		$this->assertTrue( $taxonomy->public );
		$this->assertFalse( $taxonomy->hierarchical );
		$this->assertTrue( $taxonomy->show_in_rest );
		$this->assertContains(
			Event_Type_Taxonomy::TAXONOMY,
			get_object_taxonomies( Event_Post_Type::POST_TYPE )
		);
	}

	public function test_default_vocabulary_seeds_schema_org_terms_with_self_mapped_meta(): void {
		$names = $this->term_names();

		foreach ( EventSchemaProvider::EVENT_TYPES as $type ) {
			$this->assertContains( $type, $names, "Missing seeded Schema.org term: {$type}" );

			$term = get_term_by( 'name', $type, Event_Type_Taxonomy::TAXONOMY );
			$this->assertInstanceOf( \WP_Term::class, $term );
			$this->assertSame(
				$type,
				(string) get_term_meta( $term->term_id, Event_Type_Taxonomy::SCHEMA_TYPE_META_KEY, true ),
				"Seeded term {$type} must carry its own Schema.org @type as _schema_type."
			);
		}
	}

	public function test_vocabulary_filter_fully_replaces_the_default_vocabulary(): void {
		add_filter(
			'data_machine_events_event_type_vocabulary',
			static fn(): array => array(
				array(
					'name'        => 'Quiz Night',
					'schema_type' => 'Event',
					'default'     => true,
				),
				array(
					'name'        => 'Jam Session',
					'schema_type' => 'MusicEvent',
				),
			)
		);

		$this->assertSame(
			array( 'Quiz Night', 'Jam Session' ),
			Event_Type_Taxonomy::get_vocabulary_names()
		);
		$this->assertSame( 'Quiz Night', Event_Type_Taxonomy::get_default_entry()['name'] );
		$this->assertSame( 'MusicEvent', Event_Type_Taxonomy::resolve_schema_type( 'Jam Session' ) );

		// A consumer term maps to a valid Schema.org @type, and the default
		// Schema.org names are no longer part of the vocabulary.
		$this->assertSame( '', Event_Type_Taxonomy::resolve_schema_type( 'ComedyEvent' ) );
	}

	public function test_tool_parameter_filter_emits_string_with_vocabulary_enum(): void {
		$handler_config = array(
			'taxonomy_' . Event_Type_Taxonomy::TAXONOMY . '_selection' => 'ai_decides',
		);

		$parameters = TaxonomyHandler::getTaxonomyToolParameters(
			$handler_config,
			Event_Post_Type::POST_TYPE
		);

		$this->assertArrayHasKey( Event_Type_Taxonomy::TAXONOMY, $parameters );
		$param = $parameters[ Event_Type_Taxonomy::TAXONOMY ];

		$this->assertSame( 'string', $param['type'], 'event_type is single-valued, not an array of free-form terms.' );
		$this->assertArrayNotHasKey( 'items', $param );
		$this->assertSame( Event_Type_Taxonomy::get_vocabulary_names(), $param['enum'] );
		$this->assertStringContainsString( 'never invent', strtolower( $param['description'] ) );
	}

	public function test_tool_parameter_enum_tracks_the_filtered_vocabulary(): void {
		add_filter(
			'data_machine_events_event_type_vocabulary',
			static fn(): array => array(
				array(
					'name'        => 'Quiz Night',
					'schema_type' => 'Event',
				),
			)
		);

		$parameters = TaxonomyHandler::getTaxonomyToolParameters(
			array( 'taxonomy_' . Event_Type_Taxonomy::TAXONOMY . '_selection' => 'ai_decides' ),
			Event_Post_Type::POST_TYPE
		);

		$this->assertSame( array( 'Quiz Night' ), $parameters[ Event_Type_Taxonomy::TAXONOMY ]['enum'] );
	}

	public function test_ai_supplied_vocabulary_value_assigns_the_existing_term(): void {
		$post_id  = $this->make_event();
		$before   = count( $this->term_names() );
		$assigned = ( new TaxonomyHandler() )->assignTaxonomy(
			$post_id,
			Event_Type_Taxonomy::TAXONOMY,
			'ComedyEvent'
		);

		$this->assertTrue( $assigned['success'] );

		$terms = wp_get_object_terms( $post_id, Event_Type_Taxonomy::TAXONOMY );
		$this->assertCount( 1, $terms );
		$this->assertSame( 'ComedyEvent', $terms[0]->name );
		$this->assertCount( $before, $this->term_names(), 'Assigning a known value must not create terms.' );

		wp_delete_post( $post_id, true );
	}

	/**
	 * The core guarantee of #761: an AI response containing an invented
	 * event_type value creates NO term.
	 */
	public function test_invented_ai_value_creates_no_term(): void {
		$post_id = $this->make_event();
		$before  = $this->term_names();

		$handler = new TaxonomyHandler();

		foreach ( array( 'Karaoke Night', 'Silent Disco Extravaganza', 'ConcertEvent', '' ) as $invented ) {
			$handler->assignTaxonomy( $post_id, Event_Type_Taxonomy::TAXONOMY, $invented );

			$this->assertSame(
				$before,
				$this->term_names(),
				"An invented event_type value ({$invented}) must never create a term."
			);
			$this->assertNull(
				get_term_by( 'name', $invented, Event_Type_Taxonomy::TAXONOMY ) ?: null,
				"No term named {$invented} may exist."
			);
		}

		wp_delete_post( $post_id, true );
	}

	public function test_invented_ai_value_falls_back_to_the_default_term(): void {
		$post_id = $this->make_event();

		( new TaxonomyHandler() )->assignTaxonomy(
			$post_id,
			Event_Type_Taxonomy::TAXONOMY,
			'Karaoke Night'
		);

		$terms = wp_get_object_terms( $post_id, Event_Type_Taxonomy::TAXONOMY );
		$this->assertCount( 1, $terms );
		$this->assertSame( 'Event', $terms[0]->name, 'Unrecognized values resolve to the declared default term.' );

		wp_delete_post( $post_id, true );
	}

	public function test_empty_ai_value_skips_assignment_without_creating_terms(): void {
		$post_id = $this->make_event();
		$before  = $this->term_names();

		$result = ( new TaxonomyHandler() )->assignTaxonomy(
			$post_id,
			Event_Type_Taxonomy::TAXONOMY,
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), wp_get_object_terms( $post_id, Event_Type_Taxonomy::TAXONOMY ) );
		$this->assertSame( $before, $this->term_names() );

		wp_delete_post( $post_id, true );
	}

	public function test_schema_type_meta_drives_json_ld_type(): void {
		$post_id = $this->make_event();

		$term = get_term_by( 'name', 'ComedyEvent', Event_Type_Taxonomy::TAXONOMY );
		wp_set_object_terms( $post_id, array( (int) $term->term_id ), Event_Type_Taxonomy::TAXONOMY );

		// The stale block attribute must lose to the assigned term.
		$schema = EventSchemaProvider::generateSchemaOrg(
			array( 'eventType' => 'MusicEvent' ),
			array(),
			array(),
			$post_id
		);

		$this->assertSame( 'ComedyEvent', $schema['@type'] );

		wp_delete_post( $post_id, true );
	}

	public function test_consumer_term_resolves_json_ld_to_its_schema_type(): void {
		add_filter(
			'data_machine_events_event_type_vocabulary',
			static fn(): array => array(
				array(
					'name'        => 'Quiz Night',
					'schema_type' => 'Event',
					'default'     => true,
				),
				array(
					'name'        => 'Jam Session',
					'schema_type' => 'MusicEvent',
				),
			)
		);
		Event_Type_Taxonomy::seed_vocabulary();

		$post_id = $this->make_event();
		$term    = get_term_by( 'slug', 'jam-session', Event_Type_Taxonomy::TAXONOMY );
		$this->assertInstanceOf( \WP_Term::class, $term );
		wp_set_object_terms( $post_id, array( (int) $term->term_id ), Event_Type_Taxonomy::TAXONOMY );

		$schema = EventSchemaProvider::generateSchemaOrg( array(), array(), array(), $post_id );

		$this->assertSame(
			'MusicEvent',
			$schema['@type'],
			'JSON-LD must emit the Schema.org @type the consumer term maps to, never the editorial label.'
		);

		wp_delete_post( $post_id, true );
	}

	public function test_json_ld_falls_back_to_the_block_attribute_for_untagged_events(): void {
		$post_id = $this->make_event();

		$schema = EventSchemaProvider::generateSchemaOrg(
			array( 'eventType' => 'Festival' ),
			array(),
			array(),
			$post_id
		);
		$this->assertSame( 'Festival', $schema['@type'] );

		$junk = EventSchemaProvider::generateSchemaOrg(
			array( 'eventType' => 'Karaoke Night' ),
			array(),
			array(),
			$post_id
		);
		$this->assertSame( 'Event', $junk['@type'], 'An out-of-vocabulary legacy attribute must not leak into JSON-LD.' );

		wp_delete_post( $post_id, true );
	}

	public function test_resolve_term_id_never_creates_a_term_for_unknown_input(): void {
		$this->clear_terms();
		$this->assertSame( array(), $this->term_names() );

		// Seeding is the only path that may create terms; resolution of a
		// vocabulary member reconciles the missing seed rather than inventing
		// a term from AI input.
		$term_id = Event_Type_Taxonomy::resolve_term_id( 'Karaoke Night' );
		$this->assertGreaterThan( 0, $term_id );
		$this->assertSame( 'Event', get_term( $term_id, Event_Type_Taxonomy::TAXONOMY )->name );
		$this->assertNull( get_term_by( 'name', 'Karaoke Night', Event_Type_Taxonomy::TAXONOMY ) ?: null );

		$this->assertSame(
			0,
			Event_Type_Taxonomy::resolve_term_id( 'Karaoke Night', false ),
			'Without the default fallback, unknown input resolves to nothing at all.'
		);
	}

	public function test_vocabulary_matching_is_case_and_slug_tolerant(): void {
		$this->assertSame( 'MusicEvent', Event_Type_Taxonomy::resolve_schema_type( 'musicevent' ) );
		$this->assertSame( 'MusicEvent', Event_Type_Taxonomy::resolve_schema_type( '  MUSICEVENT ' ) );
		$this->assertTrue( Event_Type_Taxonomy::is_valid_value( 'Festival' ) );
		$this->assertFalse( Event_Type_Taxonomy::is_valid_value( 'Concert' ) );

		// A single-element array (the shape DM's flat-taxonomy default emits)
		// still resolves, so a mis-shaped AI response degrades gracefully.
		$this->assertTrue( Event_Type_Taxonomy::is_valid_value( array( 'MusicEvent' ) ) );
	}

	/**
	 * Layer purity (#437 / #478): this plugin ships Schema.org vocabulary only.
	 *
	 * Asserted structurally rather than by grepping the whole plugin, because
	 * unrelated pre-existing prose legitimately mentions editorial words (the
	 * junk deny-list placeholders `'trivia, karaoke, brunch, bingo'`, the
	 * SingleRecurring handler description, etc.). Those are examples in UI
	 * copy, not vocabulary term literals. The invariant that matters is that
	 * every term this plugin itself declares is a Schema.org `@type`.
	 */
	public function test_default_vocabulary_contains_only_schema_org_terms(): void {
		$non_schema = array();

		foreach ( EventSchemaProvider::default_event_type_vocabulary() as $entry ) {
			if ( ! in_array( $entry['name'], EventSchemaProvider::EVENT_TYPES, true ) ) {
				$non_schema[] = 'name: ' . $entry['name'];
			}
			if ( ! in_array( $entry['schema_type'], EventSchemaProvider::EVENT_TYPES, true ) ) {
				$non_schema[] = 'schema_type: ' . $entry['schema_type'];
			}
			$this->assertSame(
				$entry['name'],
				$entry['schema_type'],
				'Each default term is self-mapped to its own Schema.org @type.'
			);
		}

		$this->assertSame(
			array(),
			$non_schema,
			"Site-specific editorial vocabulary belongs in the consumer, not this plugin:\n" . implode( "\n", $non_schema )
		);
	}

	/**
	 * No editorial term literal may appear in the files that own the
	 * vocabulary and its enforcement.
	 */
	public function test_no_editorial_term_literals_in_vocabulary_owning_files(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array(
			'inc/Core/Event_Type_Taxonomy.php',
			'inc/Core/EventSchemaProvider.php',
			'inc/Steps/Upsert/Events/EventTaxonomyAssigner.php',
		);

		$violations = array();
		foreach ( $files as $relative ) {
			$contents = (string) file_get_contents( $root . '/' . $relative );
			if ( preg_match( '/\b(karaoke|trivia|open mic|dance party)\b/i', $contents ) ) {
				$violations[] = $relative;
			}
		}

		$this->assertSame( array(), $violations, implode( "\n", $violations ) );
	}
}
