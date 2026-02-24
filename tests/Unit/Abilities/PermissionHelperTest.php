<?php
/**
 * PermissionHelper Tests
 *
 * Tests for the centralized permission helper, including the
 * authenticated context mechanism for alternative auth flows.
 *
 * @package DataMachine\Tests\Unit\Abilities
 * @since 0.31.0
 * @see https://github.com/Extra-Chill/data-machine/issues/346
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\PermissionHelper;
use WP_UnitTestCase;

class PermissionHelperTest extends WP_UnitTestCase {

	/**
	 * Reset state after each test.
	 */
	public function tear_down(): void {
		// Ensure authenticated context is always reset.
		// Use reflection since there's no public reset method.
		$reflection = new \ReflectionClass( PermissionHelper::class );
		$property   = $reflection->getProperty( 'authenticated_context' );
		$property->setAccessible( true );
		$property->setValue( null, false );

		parent::tear_down();
	}

	public function test_can_manage_allows_admin_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( PermissionHelper::can_manage() );
	}

	public function test_can_manage_denies_unauthenticated(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( PermissionHelper::can_manage() );
	}

	public function test_can_manage_denies_subscriber(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( PermissionHelper::can_manage() );
	}

	public function test_can_manage_denies_editor(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( PermissionHelper::can_manage() );
	}

	public function test_authenticated_context_not_set_by_default(): void {
		$this->assertFalse( PermissionHelper::is_authenticated_context() );
	}

	public function test_run_as_authenticated_grants_permission(): void {
		wp_set_current_user( 0 );

		$result = PermissionHelper::run_as_authenticated(
			function () {
				return PermissionHelper::can_manage();
			}
		);

		$this->assertTrue( $result );
	}

	public function test_run_as_authenticated_resets_after_callback(): void {
		wp_set_current_user( 0 );

		PermissionHelper::run_as_authenticated(
			function () {
				// Context is elevated inside callback.
			}
		);

		// Context must be reset after callback completes.
		$this->assertFalse( PermissionHelper::is_authenticated_context() );
		$this->assertFalse( PermissionHelper::can_manage() );
	}

	public function test_run_as_authenticated_resets_on_exception(): void {
		wp_set_current_user( 0 );

		try {
			PermissionHelper::run_as_authenticated(
				function () {
					throw new \RuntimeException( 'Test exception' );
				}
			);
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		// Context must be reset even after exception.
		$this->assertFalse( PermissionHelper::is_authenticated_context() );
		$this->assertFalse( PermissionHelper::can_manage() );
	}

	public function test_run_as_authenticated_returns_callback_value(): void {
		$result = PermissionHelper::run_as_authenticated(
			function () {
				return 'test_value';
			}
		);

		$this->assertSame( 'test_value', $result );
	}

	public function test_is_authenticated_context_true_during_callback(): void {
		$was_authenticated = false;

		PermissionHelper::run_as_authenticated(
			function () use ( &$was_authenticated ) {
				$was_authenticated = PermissionHelper::is_authenticated_context();
			}
		);

		$this->assertTrue( $was_authenticated );
		$this->assertFalse( PermissionHelper::is_authenticated_context() );
	}

	public function test_run_as_authenticated_with_ability_execution(): void {
		wp_set_current_user( 0 );

		// Verify the ability would normally be denied.
		$ability = wp_get_ability( 'datamachine/get-jobs' );
		if ( ! $ability ) {
			$this->markTestSkipped( 'datamachine/get-jobs ability not registered.' );
		}

		$denied_result = $ability->execute( array() );
		$this->assertTrue( is_wp_error( $denied_result ) || ( is_array( $denied_result ) && ! ( $denied_result['success'] ?? true ) ) );

		// Now execute within authenticated context — should pass permission check.
		$result = PermissionHelper::run_as_authenticated(
			function () use ( $ability ) {
				return $ability->execute( array() );
			}
		);

		// The ability should execute (success or valid error from business logic, not permissions).
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals( 'ability_invalid_permissions', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
			$this->assertTrue( $result['success'] );
		}
	}

	public function test_nested_run_as_authenticated_resets_correctly(): void {
		wp_set_current_user( 0 );

		PermissionHelper::run_as_authenticated(
			function () {
				// Nested call.
				PermissionHelper::run_as_authenticated(
					function () {
						// Inner callback.
					}
				);
				// After inner returns, context should still be true
				// because outer hasn't finished its finally block yet.
				// However, the inner finally resets to false.
				// This is a known limitation of the simple boolean flag —
				// nesting is not supported and shouldn't be used.
			}
		);

		// After all calls complete, context must be reset.
		$this->assertFalse( PermissionHelper::is_authenticated_context() );
		$this->assertFalse( PermissionHelper::can_manage() );
	}
}
