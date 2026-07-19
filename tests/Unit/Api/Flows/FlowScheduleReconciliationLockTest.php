<?php
/**
 * Flow schedule reconciliation lock tests.
 *
 * @package DataMachine\Tests\Unit\Api\Flows
 */

namespace DataMachine\Tests\Unit\Api\Flows;

use DataMachine\Api\Flows\FlowScheduleReconciliationLock;
use WP_UnitTestCase;

class FlowScheduleReconciliationLockTest extends WP_UnitTestCase {

	private const OPTION_NAME = 'datamachine_flow_schedule_reconciliation_lock';

	public function tear_down(): void {
		delete_option( self::OPTION_NAME );
		parent::tear_down();
	}

	public function test_concurrent_acquire_is_rejected_and_owner_can_release(): void {
		$token = FlowScheduleReconciliationLock::acquire();
		$this->assertIsString( $token );

		$second = FlowScheduleReconciliationLock::acquire();
		$this->assertWPError( $second );
		$this->assertSame( 'flow_schedule_reconciliation_locked', $second->get_error_code() );
		$this->assertTrue( FlowScheduleReconciliationLock::release( $token ) );
		$this->assertFalse( get_option( self::OPTION_NAME, false ) );
	}

	public function test_stale_lock_takeover_is_atomic_and_release_is_token_safe(): void {
		$old_token = FlowScheduleReconciliationLock::acquire();
		$this->assertIsString( $old_token );

		$stale                = get_option( self::OPTION_NAME );
		$stale['acquired_at'] = time() - HOUR_IN_SECONDS;
		update_option( self::OPTION_NAME, $stale, false );

		$new_token = FlowScheduleReconciliationLock::acquire();
		$this->assertIsString( $new_token );
		$this->assertNotSame( $old_token, $new_token );
		$this->assertFalse( FlowScheduleReconciliationLock::release( $old_token ) );
		$this->assertTrue( FlowScheduleReconciliationLock::release( $new_token ) );
	}

	public function test_refresh_extends_only_the_owned_lease(): void {
		$token = FlowScheduleReconciliationLock::acquire();
		$this->assertIsString( $token );

		$stale                = get_option( self::OPTION_NAME );
		$stale['acquired_at'] = time() - HOUR_IN_SECONDS;
		update_option( self::OPTION_NAME, $stale, false );

		$this->assertFalse( FlowScheduleReconciliationLock::refresh( 'wrong-token' ) );
		$this->assertTrue( FlowScheduleReconciliationLock::refresh( $token ) );
		$refreshed = get_option( self::OPTION_NAME );
		$this->assertSame( $token, $refreshed['token'] );
		$this->assertGreaterThan( time() - 5, (int) $refreshed['acquired_at'] );
		$this->assertTrue( FlowScheduleReconciliationLock::release( $token ) );
	}
}
