<?php
/**
 * ProcessedItemsAbilities Tests
 *
 * Tests for processed items clearing, checking, and history abilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\ProcessedItemsAbilities;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use WP_UnitTestCase;

class ProcessedItemsAbilitiesTest extends WP_UnitTestCase {

	private ProcessedItemsAbilities $abilities;
	private ProcessedItems $db_processed_items;
	private int $test_pipeline_id;
	private int $test_flow_id;
	private string $test_flow_step_id;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->abilities          = new ProcessedItemsAbilities();
		$this->db_processed_items = new ProcessedItems();

		$pipeline_ability       = wp_get_ability( 'datamachine/create-pipeline' );
		$flow_ability           = wp_get_ability( 'datamachine/create-flow' );

		$pipeline               = $pipeline_ability->execute( array( 'pipeline_name' => 'Test Pipeline for Processed Items' ) );
		$this->test_pipeline_id = $pipeline['pipeline_id'];

		$flow               = $flow_ability->execute( array( 'pipeline_id' => $this->test_pipeline_id, 'flow_name' => 'Test Flow for Processed Items' ) );
		$this->test_flow_id = $flow['flow_id'];

		$this->test_flow_step_id = '1_' . $this->test_flow_id;
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_clear_processed_items_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/clear-processed-items' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/clear-processed-items', $ability->get_name() );
	}

	public function test_check_processed_item_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/check-processed-item' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/check-processed-item', $ability->get_name() );
	}

	public function test_has_processed_history_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/has-processed-history' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/has-processed-history', $ability->get_name() );
	}

	public function test_clear_requires_clear_type(): void {
		$result = $this->abilities->executeClearProcessedItems( array() );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'clear_type is required', $result['error'] );
	}

	public function test_clear_requires_valid_clear_type(): void {
		$result = $this->abilities->executeClearProcessedItems(
			array(
				'clear_type' => 'invalid',
				'target_id'  => 1,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'must be either', $result['error'] );
	}

	public function test_clear_requires_target_id(): void {
		$result = $this->abilities->executeClearProcessedItems(
			array(
				'clear_type' => 'flow',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'target_id is required', $result['error'] );
	}

	public function test_clear_for_flow(): void {
		$this->db_processed_items->add_processed_item( $this->test_flow_step_id, 'rss', 'test-guid-1', 1 );
		$this->db_processed_items->add_processed_item( $this->test_flow_step_id, 'rss', 'test-guid-2', 1 );

		$result = $this->abilities->executeClearProcessedItems(
			array(
				'clear_type' => 'flow',
				'target_id'  => $this->test_flow_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'deleted_count', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertGreaterThanOrEqual( 0, $result['deleted_count'] );
		$this->assertStringContainsString( 'flow', $result['message'] );
	}

	public function test_clear_for_pipeline(): void {
		$this->db_processed_items->add_processed_item( $this->test_flow_step_id, 'rss', 'test-guid-pipeline-1', 1 );

		$result = $this->abilities->executeClearProcessedItems(
			array(
				'clear_type' => 'pipeline',
				'target_id'  => $this->test_pipeline_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'deleted_count', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertStringContainsString( 'pipeline', $result['message'] );
	}

	public function test_check_requires_flow_step_id(): void {
		$result = $this->abilities->executeCheckProcessedItem( array() );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'flow_step_id is required', $result['error'] );
	}

	public function test_check_requires_source_type(): void {
		$result = $this->abilities->executeCheckProcessedItem(
			array(
				'flow_step_id' => $this->test_flow_step_id,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'source_type is required', $result['error'] );
	}

	public function test_check_requires_item_identifier(): void {
		$result = $this->abilities->executeCheckProcessedItem(
			array(
				'flow_step_id' => $this->test_flow_step_id,
				'source_type'  => 'rss',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'item_identifier is required', $result['error'] );
	}

	public function test_check_unprocessed_item(): void {
		$result = $this->abilities->executeCheckProcessedItem(
			array(
				'flow_step_id'    => $this->test_flow_step_id,
				'source_type'     => 'rss',
				'item_identifier' => 'never-processed-guid',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'is_processed', $result );
		$this->assertFalse( $result['is_processed'] );
	}

	public function test_check_processed_item(): void {
		$this->db_processed_items->add_processed_item( $this->test_flow_step_id, 'rss', 'processed-guid', 1 );

		$result = $this->abilities->executeCheckProcessedItem(
			array(
				'flow_step_id'    => $this->test_flow_step_id,
				'source_type'     => 'rss',
				'item_identifier' => 'processed-guid',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'is_processed', $result );
		$this->assertTrue( $result['is_processed'] );
	}

	public function test_has_history_requires_flow_step_id(): void {
		$result = $this->abilities->executeHasProcessedHistory( array() );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'flow_step_id is required', $result['error'] );
	}

	public function test_has_history_returns_false_for_new_flow(): void {
		$unique_flow_step_id = '999_999999';

		$result = $this->abilities->executeHasProcessedHistory(
			array(
				'flow_step_id' => $unique_flow_step_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'has_history', $result );
		$this->assertFalse( $result['has_history'] );
	}

	public function test_has_history_returns_true_for_flow_with_history(): void {
		$this->db_processed_items->add_processed_item( $this->test_flow_step_id, 'rss', 'history-guid', 1 );

		$result = $this->abilities->executeHasProcessedHistory(
			array(
				'flow_step_id' => $this->test_flow_step_id,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'has_history', $result );
		$this->assertTrue( $result['has_history'] );
	}

	public function test_permission_callback_denies_unauthenticated(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability( 'datamachine/clear-processed-items' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'clear_type' => 'flow',
				'target_id'  => $this->test_flow_id,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function test_permission_callback_allows_admin(): void {
		$result = $this->abilities->checkPermission();
		$this->assertTrue( $result );
	}

	public function test_input_sanitization(): void {
		$result = $this->abilities->executeCheckProcessedItem(
			array(
				'flow_step_id'    => '<script>alert("xss")</script>1_2',
				'source_type'     => '<b>rss</b>',
				'item_identifier' => 'normal-guid',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'is_processed', $result );
	}

	// -----------------------------------------------------------------
	// processed-items-get-processed-at
	// -----------------------------------------------------------------

	public function test_get_processed_at_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/processed-items-get-processed-at' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/processed-items-get-processed-at', $ability->get_name() );
	}

	public function test_get_processed_at_returns_null_for_unknown_item(): void {
		$result = $this->abilities->executeGetProcessedAt(
			array(
				'flow_step_id'    => $this->test_flow_step_id,
				'source_type'     => 'rss',
				'item_identifier' => 'never-touched-guid',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'processed_at', $result );
		$this->assertNull( $result['processed_at'] );
	}

	public function test_get_processed_at_returns_timestamp_for_known_item(): void {
		$this->db_processed_items->add_processed_item( $this->test_flow_step_id, 'rss', 'known-guid', 1 );

		$result = $this->abilities->executeGetProcessedAt(
			array(
				'flow_step_id'    => $this->test_flow_step_id,
				'source_type'     => 'rss',
				'item_identifier' => 'known-guid',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertIsInt( $result['processed_at'] );
	}

	public function test_get_processed_at_requires_fields(): void {
		$result = $this->abilities->executeGetProcessedAt( array() );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'flow_step_id', $result['error'] );
	}

	// -----------------------------------------------------------------
	// processed-items-find-stale
	// -----------------------------------------------------------------

	public function test_find_stale_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/processed-items-find-stale' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/processed-items-find-stale', $ability->get_name() );
	}

	public function test_find_stale_requires_candidate_array(): void {
		$result = $this->abilities->executeFindStale(
			array(
				'flow_step_id'          => $this->test_flow_step_id,
				'source_type'           => 'rss',
				'candidate_identifiers' => 'not-an-array',
				'max_age_days'          => 7,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'candidate_identifiers', $result['error'] );
	}

	public function test_find_stale_requires_valid_max_age_days(): void {
		$result = $this->abilities->executeFindStale(
			array(
				'flow_step_id'          => $this->test_flow_step_id,
				'source_type'           => 'rss',
				'candidate_identifiers' => array( 'a' ),
				'max_age_days'          => 0,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'max_age_days', $result['error'] );
	}

	public function test_find_stale_returns_empty_for_fresh_candidates(): void {
		$this->db_processed_items->add_processed_item( $this->test_flow_step_id, 'rss', 'fresh-a', 1 );

		$result = $this->abilities->executeFindStale(
			array(
				'flow_step_id'          => $this->test_flow_step_id,
				'source_type'           => 'rss',
				'candidate_identifiers' => array( 'fresh-a' ),
				'max_age_days'          => 7,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['stale_ids'] );
		$this->assertSame( 0, $result['count'] );
	}

	// -----------------------------------------------------------------
	// processed-items-find-never-processed
	// -----------------------------------------------------------------

	public function test_find_never_processed_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/processed-items-find-never-processed' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/processed-items-find-never-processed', $ability->get_name() );
	}

	public function test_find_never_processed_requires_candidate_array(): void {
		$result = $this->abilities->executeFindNeverProcessed(
			array(
				'flow_step_id'          => $this->test_flow_step_id,
				'source_type'           => 'rss',
				'candidate_identifiers' => 'not-an-array',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'candidate_identifiers', $result['error'] );
	}

	public function test_find_never_processed_returns_unseen_subset(): void {
		$this->db_processed_items->add_processed_item( $this->test_flow_step_id, 'rss', 'already-seen', 1 );

		$result = $this->abilities->executeFindNeverProcessed(
			array(
				'flow_step_id'          => $this->test_flow_step_id,
				'source_type'           => 'rss',
				'candidate_identifiers' => array( 'already-seen', 'brand-new' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 'brand-new' ), $result['never_processed'] );
		$this->assertSame( 1, $result['count'] );
	}
}
