<?php
/**
 * Event source identity compatibility tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Core\DuplicateDetection\EventDuplicateStrategy;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachineEvents\Utilities\EventSourceIdentity;
use WP_UnitTestCase;

class EventSourceIdentityTest extends WP_UnitTestCase {

	private string $flow_step_id;
	private string $source_type = 'universal_web_scraper';
	private array $post_ids = array();
	private array $term_ids = array();

	public function setUp(): void {
		parent::setUp();

		$this->flow_step_id = 'identity-transition-' . wp_generate_uuid4();
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		( new ProcessedItems() )->create_table();
		( new PostIdentityIndex() )->create_table();
	}

	public function tearDown(): void {
		global $wpdb;

		foreach ( $this->post_ids as $post_id ) {
			$wpdb->delete( EventDatesTable::table_name(), array( 'post_id' => $post_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'datamachine_post_identity', array( 'post_id' => $post_id ), array( '%d' ) );
			wp_delete_post( $post_id, true );
		}
		foreach ( $this->term_ids as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		$wpdb->delete( $wpdb->prefix . ProcessedItems::TABLE_NAME, array( 'flow_step_id' => $this->flow_step_id ), array( '%s' ) );

		parent::tearDown();
	}

	public function test_processed_legacy_hash_advances_when_canonical_event_is_missing(): void {
		$event      = $this->event();
		$current    = $this->currentIdentifier( $event );
		$legacy     = EventIdentifierGenerator::generateLegacy( $event['title'], $event['startDate'], $event['venue'] );
		$context    = $this->context();
		$repository = new ProcessedItems();

		$repository->add_processed_item( $this->flow_step_id, $this->source_type, $legacy, 1001 );
		$resolved = EventSourceIdentity::resolve( $event, $context );

		$this->assertSame( $current, $resolved['item_identifier'], 'Missing canonical events must escape the old date-only processed hash.' );
		$this->assertFalse( $repository->has_item_been_processed( $this->flow_step_id, $this->source_type, $current ), 'Identity resolution must never mark the new hash before successful ingestion.' );

		$repository->add_processed_item( $this->flow_step_id, $this->source_type, $current, 1002 );
		$state = $context->classifySourceItems( array( $current ) );
		$this->assertFalse( $state['classifications'][0]['selected'], 'A successfully migrated current hash must not replay again.' );
	}

	public function test_processed_legacy_hash_does_not_replay_existing_canonical_event(): void {
		$event      = $this->event();
		$legacy     = EventIdentifierGenerator::generateLegacy( $event['title'], $event['startDate'], $event['venue'] );
		$context    = $this->context();
		$repository = new ProcessedItems();

		$this->seedCanonicalEvent( $event );
		$repository->add_processed_item( $this->flow_step_id, $this->source_type, $legacy, 2001 );
		$resolved = EventSourceIdentity::resolve( $event, $context );

		$this->assertSame( $legacy, $resolved['item_identifier'], 'Existing canonical events must retain the processed legacy identity during transition.' );
		$state = $context->classifySourceItems( array( $resolved['item_identifier'] ) );
		$this->assertFalse( $state['classifications'][0]['selected'], 'The retained legacy identity must be filtered instead of replaying an existing event.' );
	}

	public function test_late_show_advances_when_early_show_owns_legacy_hash(): void {
		$early      = $this->event();
		$late       = $early;
		$late['startTime'] = '21:30';
		$current    = $this->currentIdentifier( $late );
		$legacy     = EventIdentifierGenerator::generateLegacy( $late['title'], $late['startDate'], $late['venue'] );
		$context    = $this->context();
		$repository = new ProcessedItems();

		$this->seedCanonicalEvent( $early );
		$repository->add_processed_item( $this->flow_step_id, $this->source_type, $legacy, 3001 );
		$resolved = EventSourceIdentity::resolve( $late, $context );

		$this->assertSame( $current, $resolved['item_identifier'], 'A canonical early show must not strand a distinct late show behind their shared legacy date-only hash.' );
		$this->assertFalse( $repository->has_item_been_processed( $this->flow_step_id, $this->source_type, $current ) );
	}

	private function event(): array {
		return array(
			'title'         => 'Motown Throwdown ' . $this->flow_step_id,
			'startDate'     => '2026-04-26',
			'startTime'     => '13:30',
			'venue'         => 'Charleston Pour House ' . $this->flow_step_id,
			'venueTimezone' => 'America/New_York',
		);
	}

	private function context(): ExecutionContext {
		return ExecutionContext::fromFlow( 219, 219, $this->flow_step_id, null, $this->source_type );
	}

	private function currentIdentifier( array $event ): string {
		return EventIdentifierGenerator::generate( $event['title'], $event['startDate'], $event['venue'], $event['startTime'], $event['venueTimezone'] );
	}

	private function seedCanonicalEvent( array $event ): void {
		$term = wp_insert_term( $event['venue'], 'venue' );
		$this->assertNotWPError( $term );
		$term_id          = (int) $term['term_id'];
		$this->term_ids[] = $term_id;

		$post_id = wp_insert_post(
			array(
				'post_title'  => $event['title'],
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		$this->post_ids[] = $post_id;
		wp_set_object_terms( $post_id, array( $term_id ), 'venue' );
		EventDatesTable::upsert( $post_id, $event['startDate'] . ' ' . $event['startTime'] . ':00' );

		( new PostIdentityIndex() )->upsert(
			$post_id,
			array(
				'post_type'     => Event_Post_Type::POST_TYPE,
				'event_date'    => $event['startDate'],
				'venue_term_id' => $term_id,
				'title_hash'    => EventDuplicateStrategy::computeTitleHash( $event['title'] ),
			)
		);
	}
}
