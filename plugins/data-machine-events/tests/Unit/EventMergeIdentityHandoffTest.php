<?php
/**
 * Source identities survive merge and permanent deletion (#4013).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations;
use DataMachineEvents\Abilities\MergeEventPostsAbilities;
use DataMachineEvents\Core\DuplicateDetection\EventMergeHelper;
use DataMachineEvents\Core\EventOwnership;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;

require_once __DIR__ . '/EventUpsertTestCase.php';

class EventMergeIdentityHandoffTest extends EventUpsertTestCase {

	private function parameters( string $name ): array {
		return array(
			'title'           => 'Handoff ' . $name,
			'venue'           => 'Handoff Room ' . $name,
			'startDate'       => self::future_year() . '-11-20',
			'startTime'       => '20:00',
			'source_identity' => 'handoff:' . $name,
		);
	}

	private function createEvent( array $parameters ): int {
		$result = $this->invoke_upsert( $parameters );
		$this->assertTrue( $result['success'] ?? false, wp_json_encode( $result ) );
		return (int) $result['data']['post_id'];
	}

	/** Seed a real repository row even when the upsert test boundary is stubbed. */
	private function reserve( int $post_id, string $source_identity ): array {
		global $wpdb;
		$identity = PostIdentityReservations::normalize_identity(
			'data_machine_events',
			array(
'key' => EventUpsert::SOURCE_IDENTITY_META_KEY,
'value' => $source_identity
)
		);
		$now      = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Regression fixture.
		$wpdb->insert(
			$wpdb->prefix . PostIdentityReservations::TABLE_NAME,
			array(
				'identity_hash'   => $identity['identity_hash'],
				'post_type_hash'  => $identity['post_type_hash'],
				'meta_key_hash'   => $identity['meta_key_hash'],
				'meta_value_hash' => $identity['meta_value_hash'],
				'post_id'         => $post_id,
				'state'           => 'complete',
				'last_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		return $identity;
	}

	public function test_deleted_loser_source_updates_winner_without_creating_post(): void {
		$suffix   = uniqid();
		$winner   = $this->createEvent( $this->parameters( 'winner-' . $suffix ) );
		$params   = $this->parameters( 'loser-' . $suffix );
		$loser    = $this->createEvent( $params );
		$primary  = get_post_meta( $winner, EventUpsert::SOURCE_IDENTITY_META_KEY, true );
		$identity = $this->reserve( $loser, $params['source_identity'] );
		EventOwnership::linkSourceIdentity( $loser, 'secondary-' . $suffix );

		$this->assertTrue( EventMergeHelper::merge( $winner, $loser )['success'] );
		$this->assertTrue( EventOwnership::linksSourceIdentity( $winner, 'secondary-' . $suffix ) );
		wp_delete_post( $loser, true );
		$repository = new PostIdentityReservations();
		$repository->release_deleted_post( $loser );
		$this->assertSame( $winner, (int) $repository->get_reservation( $identity['identity_hash'] )['post_id'] );

		$before                = (array) wp_count_posts( 'data_machine_events' );
		$params['description'] = 'Updated after emptying trash.';
		$result                = $this->invoke_upsert( $params );
		$this->assertTrue( $result['success'] ?? false, wp_json_encode( $result ) );
		$this->assertSame( $winner, (int) $result['data']['post_id'] );
		$this->assertSame( $before, (array) wp_count_posts( 'data_machine_events' ) );
		$this->assertSame( $primary, get_post_meta( $winner, EventUpsert::SOURCE_IDENTITY_META_KEY, true ) );
		$this->assertStringContainsString( 'Updated after emptying trash.', get_post_field( 'post_content', $winner ) );
	}

	public function test_failed_trash_rolls_back_only_new_links_and_reservations(): void {
		$suffix   = uniqid();
		$winner   = $this->createEvent( $this->parameters( 'rollback-winner-' . $suffix ) );
		$params   = $this->parameters( 'rollback-loser-' . $suffix );
		$loser    = $this->createEvent( $params );
		$identity = $this->reserve( $loser, $params['source_identity'] );
		EventOwnership::linkSourceIdentity( $winner, 'already-linked' );
		EventOwnership::linkSourceIdentity( $loser, 'already-linked' );
		$block = static fn( $trash, $post ) => $loser === $post->ID ? false : $trash;
		add_filter( 'pre_trash_post', $block, 10, 2 );
		try {
			$this->assertFalse( EventMergeHelper::merge( $winner, $loser )['success'] );
		} finally {
			remove_filter( 'pre_trash_post', $block, 10 );
		}
		$this->assertSame( array( 'already-linked' ), get_post_meta( $winner, EventOwnership::LINKED_SOURCE_IDENTITY_META_KEY, false ) );
		$repository = new PostIdentityReservations();
		$this->assertSame( $loser, (int) $repository->get_reservation( $identity['identity_hash'] )['post_id'] );
	}

	public function test_fuzzy_match_preserves_primary_and_links_second_source(): void {
		$params                    = $this->parameters( 'fuzzy-' . uniqid() );
		$post_id                   = $this->createEvent( $params );
		$primary                   = $params['source_identity'];
		$params['source_identity'] = 'second-' . uniqid();
		$result                    = $this->invoke_upsert( $params );
		$this->assertTrue( $result['success'] ?? false, wp_json_encode( $result ) );
		$this->assertSame( $post_id, (int) $result['data']['post_id'] );
		$this->assertSame( $primary, get_post_meta( $post_id, EventUpsert::SOURCE_IDENTITY_META_KEY, true ) );
		$this->assertTrue( EventOwnership::linksSourceIdentity( $post_id, $params['source_identity'] ) );
	}

	public function test_backfill_is_bounded_dry_run_by_default_and_idempotent(): void {
		$suffix   = uniqid();
		$winner   = $this->createEvent( $this->parameters( 'backfill-winner-' . $suffix ) );
		$params   = $this->parameters( 'backfill-loser-' . $suffix );
		$loser    = $this->createEvent( $params );
		$identity = $this->reserve( $loser, $params['source_identity'] );
		update_post_meta( $loser, EventMergeHelper::MERGED_INTO_META_KEY, $winner );
		wp_trash_post( $loser );
		$ability = new MergeEventPostsAbilities();
		$input   = array(
'after_id' => $loser - 1,
'limit' => 1
);
		$dry     = $ability->backfill( $input );
		$this->assertSame( $loser, $dry['next_after_id'] );
		$this->assertCount( 1, $dry['results'] );
		$this->assertFalse( EventOwnership::linksSourceIdentity( $winner, $params['source_identity'] ) );
		$input['dry_run'] = false;
		$ability->backfill( $input );
		$ability->backfill( $input );
		$this->assertSame( array( $params['source_identity'] ), get_post_meta( $winner, EventOwnership::LINKED_SOURCE_IDENTITY_META_KEY, false ) );
		$repository = new PostIdentityReservations();
		$this->assertSame( $winner, (int) $repository->get_reservation( $identity['identity_hash'] )['post_id'] );
	}
}
