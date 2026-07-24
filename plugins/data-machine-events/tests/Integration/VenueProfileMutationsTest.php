<?php
/**
 * Canonical venue profile mutation integration coverage.
 *
 * @package DataMachineEvents\Tests\Integration
 */

namespace DataMachineEvents\Tests\Integration;

use DataMachineEvents\Admin\Settings_Page;
use DataMachineEvents\Core\VenueProfileMutations;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class VenueProfileMutationsTest extends WP_UnitTestCase {
	/** @var int[] */
	private array $term_ids = array();

	/** @var int[] */
	private array $blog_ids = array();

	private bool $had_settings = false;
	private mixed $settings_before = null;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		$this->had_settings    = false !== get_option( Settings_Page::OPTION_KEY, false );
		$this->settings_before = get_option( Settings_Page::OPTION_KEY, null );
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}

	public function tearDown(): void {
		global $wpdb;
		while ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
			restore_current_blog();
		}
		foreach ( array_reverse( $this->term_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		foreach ( array_reverse( $this->blog_ids ) as $blog_id ) {
			wpmu_delete_blog( $blog_id, true );
		}
		if ( $this->had_settings ) {
			update_option( Settings_Page::OPTION_KEY, $this->settings_before );
		} else {
			delete_option( Settings_Page::OPTION_KEY );
		}
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tearDown();
	}

	public function test_stale_profile_write_detects_another_canonical_writer(): void {
		$term_id = $this->venue( 'Competing Writer' );
		$profile = VenueProfileMutations::read( $term_id );
		$this->assertNotWPError( $profile );

		$system = VenueProfileMutations::updateSystem( $term_id, array( 'phone' => '843-555-0100' ) );
		$this->assertNotWPError( $system );

		$stale = VenueProfileMutations::updateProfile( $term_id, array( 'website' => 'https://venue.example' ), $profile['revision'] );
		$this->assertWPError( $stale );
		$this->assertSame( 'venue_revision_conflict', $stale->get_error_code() );
		$this->assertSame( '', get_term_meta( $term_id, '_venue_website', true ) );
	}

	public function test_profile_boundary_excludes_and_ignores_system_fields(): void {
		$term_id = $this->venue( 'Bounded Profile' );
		update_term_meta( $term_id, '_venue_coordinates', '32.7765,-79.9311' );
		update_term_meta( $term_id, '_venue_timezone', 'America/New_York' );
		$profile = VenueProfileMutations::read( $term_id );

		$this->assertArrayNotHasKey( 'coordinates', $profile );
		$this->assertArrayNotHasKey( 'timezone', $profile );
		$result = VenueProfileMutations::updateProfile(
			$term_id,
			array(
				'phone'       => '843-555-0101',
				'coordinates' => '1,2',
				'timezone'    => 'UTC',
			),
			$profile['revision']
		);
		$this->assertNotWPError( $result );
		$this->assertSame( '32.7765,-79.9311', get_term_meta( $term_id, '_venue_coordinates', true ) );
		$this->assertSame( 'America/New_York', get_term_meta( $term_id, '_venue_timezone', true ) );
	}

	public function test_duplicate_meta_hooks_cache_and_canonical_hook_follow_core_semantics(): void {
		$term_id = $this->venue( 'Duplicate Meta' );
		add_term_meta( $term_id, '_venue_phone', 'canonical' );
		add_term_meta( $term_id, '_venue_phone', 'stale' );
		get_term_meta( $term_id );
		$profile = VenueProfileMutations::read( $term_id );
		$updates = 0;
		$mutated = 0;

		$meta_hook = static function ( $meta_id, $object_id, $meta_key ) use ( $term_id, &$updates ): void {
			if ( $term_id === (int) $object_id && '_venue_phone' === $meta_key ) {
				++$updates;
			}
		};
		$owner_hook = static function () use ( &$mutated ): void {
			++$mutated;
		};
		add_action( 'updated_term_meta', $meta_hook, 10, 3 );
		add_action( 'data_machine_events_venue_mutated', $owner_hook );

		$result = VenueProfileMutations::updateProfile( $term_id, array( 'phone' => 'canonical' ), $profile['revision'] );

		remove_action( 'updated_term_meta', $meta_hook, 10 );
		remove_action( 'data_machine_events_venue_mutated', $owner_hook );
		$this->assertNotWPError( $result );
		$this->assertSame( 2, $updates, 'Core must update and fire hooks for both duplicate rows.' );
		$this->assertSame( 1, $mutated, 'The durable owner hook must fire exactly once.' );
		$this->assertSame( array( 'canonical', 'canonical' ), get_term_meta( $term_id, '_venue_phone', false ) );
		$this->assertSame( 'canonical', get_term_meta( $term_id, '_venue_phone', true ) );
	}

	public function test_preexisting_transaction_is_rejected_before_lock_acquisition(): void {
		global $wpdb;
		$term_id = $this->venue( 'Transaction Ordering' );
		$profile = VenueProfileMutations::read( $term_id );
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d FOR UPDATE", $term_id ) );
		$result = VenueProfileMutations::updateProfile( $term_id, array( 'phone' => 'blocked' ), $profile['revision'] );
		$wpdb->query( 'ROLLBACK' );

		$this->assertWPError( $result );
		$this->assertSame( 'venue_transaction_unsupported', $result->get_error_code() );
		$this->assertSame( '', get_term_meta( $term_id, '_venue_phone', true ) );
	}

	public function test_same_venue_hook_reentrancy_is_rejected(): void {
		$term_id = $this->venue( 'Reentrant Mutation' );
		$profile = VenueProfileMutations::read( $term_id );
		$nested  = null;
		$hook    = static function ( $check, $object_id, $meta_key ) use ( $term_id, &$nested ) {
			if ( $term_id === (int) $object_id && '_venue_phone' === $meta_key && null === $nested ) {
				$nested = VenueProfileMutations::updateSystem( $term_id, array( 'website' => 'https://nested.example' ) );
			}
			return $check;
		};
		add_filter( 'update_term_metadata', $hook, 10, 3 );
		$result = VenueProfileMutations::updateProfile( $term_id, array( 'phone' => 'outer' ), $profile['revision'] );
		remove_filter( 'update_term_metadata', $hook, 10 );

		$this->assertNotWPError( $result );
		$this->assertWPError( $nested );
		$this->assertSame( 'venue_mutation_reentrant', $nested->get_error_code() );
		$this->assertSame( '', get_term_meta( $term_id, '_venue_website', true ) );
	}

	public function test_address_relocation_replaces_coordinates_and_timezone_atomically(): void {
		$term_id = $this->venue( 'Relocation' );
		update_term_meta( $term_id, '_venue_address', '100 King Street' );
		update_term_meta( $term_id, '_venue_city', 'Charleston' );
		update_term_meta( $term_id, '_venue_state', 'SC' );
		update_term_meta( $term_id, '_venue_coordinates', '32.7765,-79.9311' );
		update_term_meta( $term_id, '_venue_timezone', 'America/New_York' );
		update_option( Settings_Page::OPTION_KEY, array( 'geonames_username' => 'integration-test' ) );

		$http = static function ( $preempt, $args, $url ) {
			if ( str_contains( $url, 'nominatim.openstreetmap.org' ) ) {
				return self::httpResponse( array( array( 'lat' => '34.0522', 'lon' => '-118.2437' ) ) );
			}
			if ( str_contains( $url, 'geonames.org' ) ) {
				return self::httpResponse( array( 'timezoneId' => 'America/Los_Angeles' ) );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		$profile = VenueProfileMutations::read( $term_id );
		$result  = VenueProfileMutations::updateProfile(
			$term_id,
			array(
				'address' => '800 West Olympic Boulevard',
				'city'    => 'Los Angeles',
				'state'   => 'CA',
			),
			$profile['revision']
		);
		remove_filter( 'pre_http_request', $http, 10 );

		$this->assertNotWPError( $result );
		$this->assertSame( '34.0522,-118.2437', get_term_meta( $term_id, '_venue_coordinates', true ) );
		$this->assertSame( 'America/Los_Angeles', get_term_meta( $term_id, '_venue_timezone', true ) );
	}

	public function test_failed_meta_filter_rolls_back_all_fields_and_suppresses_owner_hook(): void {
		$term_id = $this->venue( 'Rollback' );
		update_term_meta( $term_id, '_venue_phone', 'before' );
		$profile = VenueProfileMutations::read( $term_id );
		$mutated = 0;
		$block   = static function ( $check, $object_id, $meta_key ) use ( $term_id ) {
			return $term_id === (int) $object_id && '_venue_website' === $meta_key ? false : $check;
		};
		$owner_hook = static function () use ( &$mutated ): void {
			++$mutated;
		};
		add_filter( 'update_term_metadata', $block, 10, 3 );
		add_action( 'data_machine_events_venue_mutated', $owner_hook );

		$result = VenueProfileMutations::updateProfile(
			$term_id,
			array(
				'phone'   => 'after',
				'website' => 'https://blocked.example',
			),
			$profile['revision']
		);

		remove_filter( 'update_term_metadata', $block, 10 );
		remove_action( 'data_machine_events_venue_mutated', $owner_hook );
		$this->assertWPError( $result );
		$this->assertSame( 'before', get_term_meta( $term_id, '_venue_phone', true ) );
		$this->assertSame( '', get_term_meta( $term_id, '_venue_website', true ) );
		$this->assertSame( 0, $mutated );
	}

	public function test_multisite_lock_and_revision_scope_are_blog_specific(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite scope requires the multisite WordPress test suite.' );
		}
		global $wpdb;
		$name     = 'Equivalent Multisite Venue ' . uniqid();
		$first_id = $this->venue( $name, false );
		update_term_meta( $first_id, '_venue_phone', 'same-value' );
		$first    = VenueProfileMutations::read( $first_id );
		$blog_id  = self::factory()->blog->create();
		$this->blog_ids[] = $blog_id;
		$first_lock = VenueProfileMutations::lockName( $first_id );
		switch_to_blog( $blog_id );
		Venue_Taxonomy::register();
		$second_id = $this->venue( $name, false );
		update_term_meta( $second_id, '_venue_phone', 'same-value' );
		$second    = VenueProfileMutations::read( $second_id );
		$second_lock = VenueProfileMutations::lockName( $second_id );
		$this->assertSame( $first_id, $second_id, 'Equivalent per-site fixtures must use the same term ID.' );
		$this->assertNotSame( $first_lock, $second_lock );

		$owner  = mysqli_init();
		$waiter = mysqli_init();
		$owner->real_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$waiter->real_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$this->assertSame( 1, $this->namedLock( $owner, 'GET_LOCK', $first_lock ) );
		$this->assertSame( 1, $this->namedLock( $waiter, 'GET_LOCK', $second_lock ) );
		$this->assertSame( 0, $this->namedLock( $waiter, 'GET_LOCK', $first_lock ) );
		$this->assertSame( 1, $this->namedLock( $waiter, 'RELEASE_LOCK', $second_lock ) );
		$this->assertSame( 1, $this->namedLock( $owner, 'RELEASE_LOCK', $first_lock ) );
		$owner->close();
		$waiter->close();
		wp_delete_term( $second_id, 'venue' );
		restore_current_blog();

		$this->assertNotSame( $first['revision'], $second['revision'] );
	}

	public function test_waiting_fill_empty_writer_rechecks_after_operator_edit(): void {
		if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'Real MySQL venue concurrency coverage requires mysqli and pcntl.' );
		}
		global $wpdb, $table_prefix;
		$term_id  = $this->venue( 'Fill Empty Race' );
		$lock_key = VenueProfileMutations::lockName( $term_id );
		$owner    = mysqli_init();
		$owner->real_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$this->assertSame( 1, $this->namedLock( $owner, 'GET_LOCK', $lock_key ) );

		$result_file = tempnam( sys_get_temp_dir(), 'dme-venue-race-' );
		$pid         = pcntl_fork();
		$this->assertNotSame( -1, $pid );
		if ( 0 === $pid ) {
			global $wpdb;
			$wpdb = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$wpdb->set_prefix( $table_prefix );
			$result = VenueProfileMutations::updateSystem(
				$term_id,
				array( 'phone' => 'stale-ingestion-value' ),
				VenueProfileMutations::STRATEGY_FILL_EMPTY
			);
			file_put_contents( $result_file, wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : $result ) );
			exit( 0 );
		}

		usleep( 300000 );
		update_term_meta( $term_id, '_venue_phone', 'operator-value' );
		$this->assertSame( 1, $this->namedLock( $owner, 'RELEASE_LOCK', $lock_key ) );
		pcntl_waitpid( $pid, $status );
		$result = json_decode( (string) file_get_contents( $result_file ), true );
		unlink( $result_file );
		$owner->close();

		wp_cache_delete( $term_id, 'term_meta' );
		$this->assertTrue( pcntl_wifexited( $status ) );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 'operator-value', get_term_meta( $term_id, '_venue_phone', true ) );
		$this->assertNotContains( 'phone', $result['updated_fields'] );
	}

	public function test_native_wordpress_edit_fails_when_serialization_is_unavailable(): void {
		if ( ! extension_loaded( 'mysqli' ) ) {
			$this->markTestSkipped( 'Native venue lock coverage requires mysqli.' );
		}
		$term_id  = $this->venue( 'Native Lock Failure' );
		$lock_key = VenueProfileMutations::lockName( $term_id );
		$owner    = mysqli_init();
		$owner->real_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$this->assertSame( 1, $this->namedLock( $owner, 'GET_LOCK', $lock_key ) );
		add_filter( 'data_machine_events_venue_lock_timeout', '__return_zero' );
		$rejected = false;
		try {
			wp_update_term( $term_id, 'venue', array( 'name' => 'Should Not Persist' ) );
		} catch ( \WPDieException ) {
			$rejected = true;
		} finally {
			remove_filter( 'data_machine_events_venue_lock_timeout', '__return_zero' );
			$this->namedLock( $owner, 'RELEASE_LOCK', $lock_key );
			$owner->close();
		}

		$this->assertTrue( $rejected );
		$this->assertStringStartsWith( 'Native Lock Failure', get_term( $term_id, 'venue' )->name );
	}

	public function test_uncertain_commit_requires_a_fresh_read(): void {
		$term_id = $this->venue( 'Commit Uncertain' );
		$profile = VenueProfileMutations::read( $term_id );
		$result  = VenueProfileCommitUncertain::updateProfile( $term_id, array( 'phone' => 'committed-or-not' ), $profile['revision'] );

		$this->assertWPError( $result );
		$this->assertSame( 'venue_commit_uncertain', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['connection_closed'] );
		$this->assertTrue( $result->get_error_data()['connection_recovered'] );
		$this->assertNotWPError( VenueProfileMutations::read( $term_id ) );
	}

	public function test_uncertain_rollback_reports_the_original_failure_code(): void {
		$term_id = $this->venue( 'Rollback Uncertain' );
		$profile = VenueProfileMutations::read( $term_id );
		$block   = static function ( $check, $object_id, $meta_key ) use ( $term_id ) {
			return $term_id === (int) $object_id && '_venue_phone' === $meta_key ? false : $check;
		};
		add_filter( 'update_term_metadata', $block, 10, 3 );
		$result = VenueProfileRollbackUncertain::updateProfile( $term_id, array( 'phone' => 'blocked' ), $profile['revision'] );
		remove_filter( 'update_term_metadata', $block, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'venue_rollback_uncertain', $result->get_error_code() );
		$this->assertSame( 'venue_meta_update_failed', $result->get_error_data()['original_error'] );
		$this->assertTrue( $result->get_error_data()['connection_closed'] );
	}

	public function test_shared_term_is_rejected_before_mutation(): void {
		global $wpdb;
		$split_finished = get_option( 'finished_splitting_shared_terms' );
		update_option( 'finished_splitting_shared_terms', false );
		$term_id = $this->venue( 'Shared Term' );
		register_taxonomy( 'venue_shadow', 'post' );
		$wpdb->insert(
			$wpdb->term_taxonomy,
			array(
				'term_id'     => $term_id,
				'taxonomy'    => 'venue_shadow',
				'description' => '',
				'parent'      => 0,
				'count'       => 0,
			)
		);
		try {
			$this->assertTrue( wp_term_is_shared( $term_id ) );
			$result = VenueProfileMutations::updateSystem( $term_id, array( 'phone' => 'blocked' ) );
		} finally {
			$wpdb->delete( $wpdb->term_taxonomy, array( 'term_id' => $term_id, 'taxonomy' => 'venue_shadow' ) );
			update_option( 'finished_splitting_shared_terms', $split_finished );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'venue_shared_term_unsupported', $result->get_error_code() );
		$this->assertSame( '', get_term_meta( $term_id, '_venue_phone', true ) );
	}

	private function venue( string $prefix, bool $unique = true ): int {
		$result = wp_insert_term( $prefix . ( $unique ? ' ' . uniqid() : '' ), 'venue' );
		$this->assertNotWPError( $result );
		$term_id          = (int) $result['term_id'];
		$this->term_ids[] = $term_id;
		return $term_id;
	}

	private function namedLock( \mysqli $connection, string $operation, string $key ): int {
		$sql       = 'GET_LOCK' === $operation ? 'SELECT GET_LOCK(?, 0)' : 'SELECT RELEASE_LOCK(?)';
		$statement = $connection->prepare( $sql );
		$statement->bind_param( 's', $key );
		$statement->execute();
		return (int) $statement->get_result()->fetch_row()[0];
	}

	private static function httpResponse( array $body ): array {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}

class VenueProfileCommitUncertain extends VenueProfileMutations {
	protected static function query( string $sql ): int|bool {
		if ( 'COMMIT' === $sql ) {
			global $wpdb;
			$wpdb->close();
			return false;
		}
		return parent::query( $sql );
	}
}

class VenueProfileRollbackUncertain extends VenueProfileMutations {
	protected static function query( string $sql ): int|bool {
		if ( 'ROLLBACK' === $sql ) {
			global $wpdb;
			$wpdb->close();
			return false;
		}
		return parent::query( $sql );
	}
}
