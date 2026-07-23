<?php
/**
 * Identity-backed upsert ability tests.
 *
 * @package DataMachine\Tests\Unit\Abilities\Content
 */

namespace DataMachine\Tests\Unit\Abilities\Content;

use DataMachine\Abilities\Content\UpsertPostAbility;
use DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations;
use WP_UnitTestCase;

class UpsertPostAbilityTest extends WP_UnitTestCase {

	private PostIdentityReservations $repository;

	public function set_up(): void {
		parent::set_up();
		PostIdentityReservations::create_table();
		$this->repository = new PostIdentityReservations();

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->repository->get_table_name() ) );
	}

	public function test_identity_less_create_behavior_is_unchanged(): void {
		$result = UpsertPostAbility::execute(
			array(
				'post_type' => 'post',
				'title'     => 'Identity-less',
				'content'   => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'created', $result['action'] );
		$this->assertSame( 'publish', get_post_status( $result['post_id'] ) );
	}

	public function test_first_identity_upsert_populates_shell_and_completes_reservation(): void {
		$result = UpsertPostAbility::execute( $this->input( 'complete-one', 'First title' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'created', $result['action'] );
		$this->assertSame( 'complete-one', get_post_meta( $result['post_id'], '_source', true ) );
		$this->assertSame( 'First title', get_post( $result['post_id'] )->post_title );
		$identity = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'complete-one' ) );
		$row      = $this->repository->get_reservation( $identity['identity_hash'] );
		$this->assertSame( 'complete', $row['state'] );
		$this->assertSame( (string) $result['post_id'], (string) $row['post_id'] );
		$this->assertNotEmpty( $row['completed_at'] );
	}

	public function test_retry_updates_same_linked_post(): void {
		$first = UpsertPostAbility::execute( $this->input( 'retry-one', 'First title' ) );
		$retry = UpsertPostAbility::execute( $this->input( 'retry-one', 'Second title' ) );

		$this->assertTrue( $retry['success'] );
		$this->assertSame( 'updated', $retry['action'] );
		$this->assertSame( $first['post_id'], $retry['post_id'] );
		$this->assertSame( 'Second title', get_post( $first['post_id'] )->post_title );

		global $wpdb;
		$reservation_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->repository->get_table_name() ) );
		$claimant_count    = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT post_id) FROM %i WHERE meta_key = %s AND meta_value = %s',
				$wpdb->postmeta,
				'_source',
				'retry-one'
			)
		);
		$identity = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'retry-one' ) );
		$this->assertSame( 1, $reservation_count );
		$this->assertSame( 1, $claimant_count );
		$this->assertSame( 'complete', $this->repository->get_reservation( $identity['identity_hash'] )['state'] );
	}

	public function test_ordinary_wordpress_failure_retains_link_then_retry_completes(): void {
		$fail = static fn() => true;
		add_filter( 'wp_insert_post_empty_content', $fail );
		$failed = UpsertPostAbility::execute( $this->input( 'write-failure', 'Will retry' ) );
		remove_filter( 'wp_insert_post_empty_content', $fail );

		$this->assertFalse( $failed['success'] );
		$identity = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'write-failure' ) );
		$row      = $this->repository->get_reservation( $identity['identity_hash'] );
		$this->assertSame( 'linked', $row['state'] );
		$this->assertNotEmpty( $row['post_id'] );
		$this->assertNotEmpty( $row['last_error_code'] );
		$this->assertStringNotContainsString( 'write-failure', (string) $row['last_error_message'] );
		$this->assertIdentityLockIsFree( 'write-failure' );

		$retry = UpsertPostAbility::execute( $this->input( 'write-failure', 'Will retry' ) );
		$this->assertTrue( $retry['success'] );
		$this->assertSame( (int) $row['post_id'], $retry['post_id'] );
		$this->assertSame( 'complete', $this->repository->get_reservation( $identity['identity_hash'] )['state'] );
	}

	public function test_linked_shell_retry_reconciles_full_post_behavior(): void {
		$reserved = $this->repository->reserve_and_resolve(
			'post',
			array( 'key' => '_source', 'value' => 'shell-retry' ),
			0,
			0,
			array(
				'post_author'    => get_current_user_id(),
				'comment_status' => get_default_comment_status( 'post' ),
				'ping_status'    => get_default_comment_status( 'post', 'pingback' ),
				'post_date'      => current_time( 'mysql' ),
				'post_date_gmt'  => current_time( 'mysql', true ),
				'guid'           => 'urn:uuid:' . wp_generate_uuid4(),
			)
		);
		$result   = UpsertPostAbility::execute(
			array_merge(
				$this->input( 'shell-retry', 'Populated shell' ),
				array(
					'meta_input' => array( '_extra' => 'value' ),
					'taxonomies' => array( 'category' => array( 'Shell Category' ) ),
				)
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( $reserved['post_id'], $result['post_id'] );
		$this->assertSame( 'value', get_post_meta( $result['post_id'], '_extra', true ) );
		$this->assertNotEmpty( wp_get_post_terms( $result['post_id'], 'category' ) );
	}

	public function test_content_hash_no_change_still_completes_same_reservation(): void {
		$input                 = $this->input( 'no-change', 'Stable title' );
		$input['content_hash'] = hash( 'sha256', $input['content'] );
		$first                 = UpsertPostAbility::execute( $input );
		$retry                 = UpsertPostAbility::execute( $input );

		$this->assertTrue( $retry['success'] );
		$this->assertSame( 'no_change', $retry['action'] );
		$this->assertSame( $first['post_id'], $retry['post_id'] );
		$identity = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'no-change' ) );
		$this->assertSame( 'complete', $this->repository->get_reservation( $identity['identity_hash'] )['state'] );
	}

	public function test_empty_partial_and_zero_identity_values_keep_slug_fallback_behavior(): void {
		$cases = array(
			array(),
			array( 'key' => '_source' ),
			array( 'value' => 'partial' ),
			array( 'key' => '', 'value' => 'empty-key' ),
			array( 'key' => '_source', 'value' => '' ),
			array( 'key' => '_source', 'value' => '0' ),
		);

		foreach ( $cases as $index => $identity_meta ) {
			$slug    = 'identity-fallback-' . $index;
			$post_id = self::factory()->post->create(
				array(
					'post_type' => 'post',
					'post_name' => $slug,
				)
			);
			$input                  = $this->input( 'unused-' . $index, 'Fallback' );
			$input['slug']          = $slug;
			$input['identity_meta'] = $identity_meta;
			$result                 = UpsertPostAbility::execute( $input );

			$this->assertTrue( $result['success'] );
			$this->assertSame( $post_id, $result['post_id'], 'Unusable identity_meta must retain slug lookup behavior.' );
		}

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->repository->get_table_name() ) );
		$this->assertSame( 0, $count );
	}

	public function test_explicit_post_bypasses_identity_reservation_and_metadata(): void {
		$explicit_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$identity_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$slug_id     = self::factory()->post->create( array( 'post_type' => 'post', 'post_name' => 'priority-slug' ) );
		update_post_meta( $identity_id, '_source', 'priority-explicit' );
		$identity_title = get_post( $identity_id )->post_title;
		$input            = $this->input( 'priority-explicit', 'Explicit wins' );
		$input['post_id'] = $explicit_id;
		$input['slug']    = 'priority-slug';

		$result = UpsertPostAbility::execute( $input );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $explicit_id, $result['post_id'] );
		$this->assertSame( 'Explicit wins', get_post( $explicit_id )->post_title );
		$this->assertSame( '', get_post_meta( $explicit_id, '_source', true ) );
		$this->assertSame( 'priority-explicit', get_post_meta( $identity_id, '_source', true ) );
		$this->assertSame( $identity_title, get_post( $identity_id )->post_title );
		$this->assertNotSame( 'Explicit wins', get_post( $slug_id )->post_title );

		$identity = PostIdentityReservations::normalize_identity( 'post', $input['identity_meta'] );
		$this->assertNull( $this->repository->get_reservation( $identity['identity_hash'] ) );
	}

	public function test_wrong_type_explicit_post_fails_before_reservation(): void {
		$page_id          = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$input            = $this->input( 'wrong-explicit-type', 'Wrong type' );
		$input['post_id'] = $page_id;

		$result = UpsertPostAbility::execute( $input );

		$this->assertFalse( $result['success'] );
		$identity = PostIdentityReservations::normalize_identity( 'post', $input['identity_meta'] );
		$this->assertNull( $this->repository->get_reservation( $identity['identity_hash'] ) );
	}

	public function test_legacy_identity_candidate_wins_over_slug_candidate(): void {
		$identity_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$slug_id     = self::factory()->post->create( array( 'post_type' => 'post', 'post_name' => 'identity-vs-slug' ) );
		update_post_meta( $identity_id, '_source', 'identity-vs-slug' );
		$input         = $this->input( 'identity-vs-slug', 'Identity wins' );
		$input['slug'] = 'identity-vs-slug';

		$result = UpsertPostAbility::execute( $input );

		$this->assertSame( $identity_id, $result['post_id'] );
		$this->assertNotSame( $slug_id, $result['post_id'] );
	}

	public function test_slug_candidate_is_adopted_when_identity_has_no_legacy_match(): void {
		$slug_id       = self::factory()->post->create( array( 'post_type' => 'post', 'post_name' => 'identity-miss-slug' ) );
		$input         = $this->input( 'identity-miss', 'Slug wins' );
		$input['slug'] = 'identity-miss-slug';

		$result = UpsertPostAbility::execute( $input );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $slug_id, $result['post_id'] );
		$this->assertSame( 'identity-miss', get_post_meta( $slug_id, '_source', true ) );
	}

	public function test_explicit_post_bypasses_an_existing_link_without_rebinding_it(): void {
		$first       = UpsertPostAbility::execute( $this->input( 'linked-conflict', 'First' ) );
		$other_id    = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$retry_input = $this->input( 'linked-conflict', 'Other' );
		$retry_input['post_id'] = $other_id;

		$result = UpsertPostAbility::execute( $retry_input );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $other_id, $result['post_id'] );
		$this->assertSame( '', get_post_meta( $other_id, '_source', true ) );
		$identity = PostIdentityReservations::normalize_identity( 'post', $retry_input['identity_meta'] );
		$row      = $this->repository->get_reservation( $identity['identity_hash'] );
		$this->assertSame( $first['post_id'], (int) $row['post_id'] );
		$this->assertSame( 'linked-conflict', get_post_meta( $first['post_id'], '_source', true ) );
	}

	public function test_identity_meta_round_trips_backslashes_and_quotes(): void {
		$value  = "C:\\Music\\O'Reilly";
		$input  = $this->input( $value, 'Slash semantics' );
		$result = UpsertPostAbility::execute( $input );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $value, get_post_meta( $result['post_id'], '_source', true ) );
		$retry = UpsertPostAbility::execute( $input );
		$this->assertSame( $result['post_id'], $retry['post_id'] );
	}

	public function test_registered_meta_sanitizer_is_part_of_canonical_identity(): void {
		register_post_meta(
			'post',
			'_canonical_identity',
			array( 'sanitize_callback' => static fn( $value ) => strtolower( (string) $value ) )
		);
		$input                         = $this->input( 'unused', 'Sanitized identity' );
		$input['identity_meta']['key']   = '_canonical_identity';
		$input['identity_meta']['value'] = 'Mixed-CASE';

		$result = UpsertPostAbility::execute( $input );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'mixed-case', get_post_meta( $result['post_id'], '_canonical_identity', true ) );
		$normalized = PostIdentityReservations::normalize_identity( 'post', $input['identity_meta'] );
		$this->assertSame( 'mixed-case', $normalized['meta_value'] );
	}

	public function test_unstable_registered_meta_sanitizer_fails_before_reservation(): void {
		register_post_meta(
			'post',
			'_unstable_identity',
			array( 'sanitize_callback' => static fn( $value ) => $value . 'x' )
		);
		$input                         = $this->input( 'unused', 'Unstable identity' );
		$input['identity_meta']['key']   = '_unstable_identity';
		$input['identity_meta']['value'] = 'value';

		$result = UpsertPostAbility::execute( $input );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'identity_meta_sanitizer_unstable', $result['error_code'] );
	}

	public function test_advisory_fence_spans_population_and_releases_on_success(): void {
		$identity = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'fenced-success' ) );
		$lock_name = $this->repository->lock_name( $identity['identity_hash'] );
		$observed = false;
		$observer = function () use ( $lock_name, &$observed ): void {
			global $wpdb;
			$owner   = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock_name ) );
			$current = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
			$observed = (string) $owner === (string) $current;
		};
		add_action( 'datamachine_upsert_post_identity_before_population', $observer );
		$result = UpsertPostAbility::execute( $this->input( 'fenced-success', 'Fenced' ) );
		remove_action( 'datamachine_upsert_post_identity_before_population', $observer );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $observed );
		$this->assertIdentityLockIsFree( 'fenced-success' );
	}

	public function test_population_exception_returns_retryable_failure_and_releases_fence(): void {
		$throw = static function (): void {
			throw new \RuntimeException( 'Injected population exception.' );
		};
		add_action( 'datamachine_upsert_post_identity_before_population', $throw );
		$result = UpsertPostAbility::execute( $this->input( 'fenced-exception', 'Exception' ) );
		remove_action( 'datamachine_upsert_post_identity_before_population', $throw );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'identity_population_exception', $result['error_code'] );
		$this->assertTrue( $result['error_data']['retryable'] );
		$this->assertStringNotContainsString( 'Injected population exception', $result['error'] );
		$this->assertIdentityLockIsFree( 'fenced-exception' );
	}

	public function test_record_error_exception_cannot_replace_population_failure(): void {
		$table = $this->repository->get_table_name();
		$throw_population = static function (): void {
			throw new \RuntimeException( 'Injected population exception.' );
		};
		$throw_diagnostic = static function ( string $query ) use ( $table ): string {
			if ( str_contains( $query, "UPDATE `{$table}` SET last_error_code") ) {
				throw new \RuntimeException( 'Injected record_error exception.' );
			}
			return $query;
		};
		add_action( 'datamachine_upsert_post_identity_before_population', $throw_population );
		add_filter( 'query', $throw_diagnostic );
		try {
			$result = UpsertPostAbility::execute( $this->input( 'diagnostic-exception', 'Diagnostic exception' ) );
		} finally {
			remove_filter( 'query', $throw_diagnostic );
			remove_action( 'datamachine_upsert_post_identity_before_population', $throw_population );
		}

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'identity_population_exception', $result['error_code'] );
		$this->assertTrue( $result['error_data']['retryable'] );
		$this->assertIdentityLockIsFree( 'diagnostic-exception' );
	}

	public function test_recursive_same_request_upsert_is_rejected_without_mysql_reentry(): void {
		$recursive_result = null;
		$callback         = function () use ( &$recursive_result ): void {
			$recursive_result = UpsertPostAbility::execute( $this->input( 'recursive-identity', 'Recursive inner' ) );
		};
		add_action( 'datamachine_upsert_post_identity_before_population', $callback );
		$outer = UpsertPostAbility::execute( $this->input( 'recursive-identity', 'Recursive outer' ) );
		remove_action( 'datamachine_upsert_post_identity_before_population', $callback );

		$this->assertTrue( $outer['success'] );
		$this->assertFalse( $recursive_result['success'] );
		$this->assertSame( 'identity_lock_reentrant', $recursive_result['error_code'] );
		$this->assertTrue( $recursive_result['error_data']['retryable'] );
		$this->assertIdentityLockIsFree( 'recursive-identity' );
	}

	public function test_release_ownership_loss_replaces_success_with_retryable_uncertainty(): void {
		$identity  = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'release-lost' ) );
		$lock_name = $this->repository->lock_name( $identity['identity_hash'] );
		$release   = static function () use ( $lock_name ): void {
			global $wpdb;
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		};
		add_action( 'datamachine_upsert_post_identity_before_population', $release );
		$result = UpsertPostAbility::execute( $this->input( 'release-lost', 'Release lost' ) );
		remove_action( 'datamachine_upsert_post_identity_before_population', $release );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'identity_lock_release_uncertain', $result['error_code'] );
		$this->assertTrue( $result['error_data']['retryable'] );
		$this->assertIdentityLockIsFree( 'release-lost' );
	}

	public function test_busy_advisory_fence_returns_retryable_ability_error(): void {
		$connection = $this->open_mysql_connection();
		if ( ! $connection instanceof \mysqli ) {
			$this->markTestSkipped( 'A direct test database connection is unavailable.' );
		}

		$identity  = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => 'fenced-busy' ) );
		$lock_name = $connection->real_escape_string( $this->repository->lock_name( $identity['identity_hash'] ) );
		$connection->query( "SELECT GET_LOCK('{$lock_name}', 0)" );
		try {
			$result = UpsertPostAbility::execute( $this->input( 'fenced-busy', 'Busy' ) );
			$this->assertFalse( $result['success'] );
			$this->assertSame( 'identity_lock_unavailable', $result['error_code'] );
			$this->assertTrue( $result['error_data']['retryable'] );
		} finally {
			$connection->query( "SELECT RELEASE_LOCK('{$lock_name}')" );
			$connection->close();
		}
	}

	public function test_preallocated_shell_preserves_create_defaults_but_uses_update_hooks(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
		$expected_comment = get_default_comment_status( 'post' );
		$expected_ping    = get_default_comment_status( 'post', 'pingback' );
		$updates          = array();
		$transitions      = array();
		$after_insert     = static function ( $post_id, $post, $update ) use ( &$updates ): void {
			unset( $post_id, $post );
			$updates[] = $update;
		};
		$transition = static function ( $new_status, $old_status ) use ( &$transitions ): void {
			$transitions[] = array( $old_status, $new_status );
		};
		add_action( 'wp_after_insert_post', $after_insert, 10, 3 );
		add_action( 'transition_post_status', $transition, 10, 2 );
		$result = UpsertPostAbility::execute( $this->input( 'hook-contract', 'Hook contract' ) );
		remove_action( 'wp_after_insert_post', $after_insert, 10 );
		remove_action( 'transition_post_status', $transition, 10 );

		$post = get_post( $result['post_id'] );
		$this->assertSame( 'created', $result['action'] );
		$this->assertSame( $user_id, (int) $post->post_author );
		$this->assertSame( $expected_comment, $post->comment_status );
		$this->assertSame( $expected_ping, $post->ping_status );
		$this->assertContains( true, $updates, 'First population is intentionally a WordPress update.' );
		$this->assertContains( array( 'draft', 'publish' ), $transitions );
	}

	private function input( string $value, string $title ): array {
		return array(
			'post_type'     => 'post',
			'title'         => $title,
			'content'       => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
			'identity_meta' => array(
				'key'   => '_source',
				'value' => $value,
			),
		);
	}

	private function assertIdentityLockIsFree( string $value ): void {
		global $wpdb;

		$identity = PostIdentityReservations::normalize_identity( 'post', array( 'key' => '_source', 'value' => $value ) );
		$is_free  = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $this->repository->lock_name( $identity['identity_hash'] ) ) );
		$this->assertSame( '1', (string) $is_free );
	}

	private function open_mysql_connection(): ?\mysqli {
		if ( ! class_exists( '\mysqli' ) ) {
			return null;
		}

		$host   = DB_HOST;
		$port   = null;
		$socket = null;
		if ( preg_match( '/^([^:]+):(\d+)$/', $host, $matches ) ) {
			$host = $matches[1];
			$port = (int) $matches[2];
		} elseif ( preg_match( '/^([^:]+):(.+)$/', $host, $matches ) ) {
			$host   = $matches[1];
			$socket = $matches[2];
		}

		$connection = \mysqli_init();
		if ( false === $connection || ! @$connection->real_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket ) ) {
			return null;
		}

		return $connection;
	}
}
