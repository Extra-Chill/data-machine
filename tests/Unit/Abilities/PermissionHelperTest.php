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
use DataMachine\Abilities\AbilityScopePermissionFilter;
use DataMachine\Abilities\ExecutionScope;
use DataMachine\Api\RestAccessGuard;
use WP_UnitTestCase;

class PermissionHelperTest extends WP_UnitTestCase {

	/**
	 * Reset state after each test.
	 */
	public function tear_down(): void {
		PermissionHelper::clear_agent_context();
		remove_filter( 'datamachine_cli_bypass_permissions', '__return_false' );
		wp_set_current_user( 0 );

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

	public function test_execution_scope_snapshots_current_permission_context(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$scope = ExecutionScope::current( 'manage_flows' );

		$this->assertSame( 'manage_flows', $scope->action() );
		$this->assertTrue( $scope->can_action() );
		$this->assertSame( $user_id, $scope->acting_user_id() );
		$this->assertNull( $scope->acting_agent_id() );
	}

	public function test_rest_access_guard_denies_action_with_standard_rest_error(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$result = RestAccessGuard::for_action( 'manage_flows' )->check_permission( 'Denied.' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_rest_access_guard_authorizes_agent_resource_through_permission_helper(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$allowed = RestAccessGuard::for_action( 'manage_flows' )->authorize_agent_resource( null, $user_id + 100, 'Denied.' );

		$this->assertTrue( $allowed );
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
			unset( $e );
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

	public function test_agent_token_context_uses_agents_api_capability_ceiling(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		PermissionHelper::set_agent_context(
			123,
			$user_id,
			array( 'datamachine_chat' ),
			456
		);

		$principal = PermissionHelper::get_execution_principal();

		$this->assertInstanceOf( \AgentsAPI\AI\WP_Agent_Execution_Principal::class, $principal );
		$this->assertSame( \AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_AGENT_TOKEN, $principal->auth_source );
		$this->assertTrue( PermissionHelper::can( 'chat' ) );
		$this->assertFalse( PermissionHelper::can( 'manage_agents' ) );
	}

	public function test_agent_token_context_accepts_structured_scope_payload(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		PermissionHelper::set_agent_context(
			123,
			$user_id,
			array(
				'scope'              => 'read_only',
				'label'              => 'Read-only',
				'ability_categories' => array( 'datamachine-content' ),
				'ability_allow'      => array( 'datamachine/wiki' ),
				'ability_deny'       => array( 'datamachine/delete-post' ),
				'capabilities'       => array( 'datamachine_chat' ),
			),
			456
		);

		$principal = PermissionHelper::get_execution_principal();

		$this->assertSame( array( 'datamachine_chat' ), $principal->capability_ceiling->allowed_capabilities );
		$this->assertTrue( PermissionHelper::can( 'chat' ) );
		$this->assertFalse( PermissionHelper::can( 'use_tools' ) );
	}

	public function test_structured_scope_enforces_ability_allow_deny_and_categories(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		PermissionHelper::set_agent_context(
			123,
			$user_id,
			array(
				'scope'              => 'content_collaborator',
				'label'              => 'Content collaborator',
				'ability_categories' => array( 'datamachine-content' ),
				'ability_allow'      => array( 'datamachine/wiki' ),
				'ability_deny'       => array( 'datamachine/delete-post' ),
				'capabilities'       => array( 'datamachine_chat' ),
			),
			456
		);

		$this->assertTrue( PermissionHelper::can_use_ability( 'datamachine/get-posts', 'datamachine-content' ) );
		$this->assertTrue( PermissionHelper::can_use_ability( 'datamachine/wiki', 'datamachine-system' ) );
		$this->assertFalse( PermissionHelper::can_use_ability( 'datamachine/delete-post', 'datamachine-content' ) );
		$this->assertFalse( PermissionHelper::can_use_ability( 'datamachine/update-settings', 'datamachine-settings' ) );
	}

	public function test_ability_scope_filter_denies_excluded_datamachine_ability(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$ability = new class() {
			public function get_category(): string {
				return 'datamachine-settings';
			}
		};

		PermissionHelper::set_agent_context(
			123,
			$user_id,
			array(
				'scope'              => 'content_only',
				'label'              => 'Content only',
				'ability_categories' => array( 'datamachine-content' ),
				'ability_allow'      => array(),
				'ability_deny'       => array(),
				'capabilities'       => array( 'datamachine_manage_settings' ),
			),
			456
		);

		$result = AbilityScopePermissionFilter::filter_permission_result( true, 'datamachine/update-settings', array(), $ability );

		$this->assertWPError( $result );
		$this->assertSame( 'datamachine_ability_scope_denied', $result->get_error_code() );
	}

	public function test_structured_scope_denies_direct_ability_execution(): void {
		if ( version_compare( get_bloginfo( 'version' ), '7.1-alpha', '<' ) ) {
			$this->markTestSkipped( 'Core ability permission lifecycle filter requires WordPress 7.1+.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$ability = wp_get_ability( 'datamachine/update-settings' );

		if ( ! $ability ) {
			$this->markTestSkipped( 'datamachine/update-settings ability not registered.' );
		}

		PermissionHelper::set_agent_context(
			123,
			$user_id,
			array(
				'scope'              => 'content_only',
				'label'              => 'Content only',
				'ability_categories' => array( 'datamachine-content' ),
				'ability_allow'      => array(),
				'ability_deny'       => array(),
				'capabilities'       => array( 'datamachine_manage_settings' ),
			),
			456
		);

		$result = $ability->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'datamachine_ability_scope_denied', $result->get_error_code() );
	}

	public function test_view_analytics_granted_to_manage_flows_holder(): void {
		$user_id = self::factory()->user->create();
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'datamachine_manage_flows' );
		wp_set_current_user( $user_id );

		// A manage_flows holder retains analytics read access — no regression.
		$this->assertTrue( PermissionHelper::can( 'view_analytics' ) );

		$user->remove_cap( 'datamachine_manage_flows' );
	}

	public function test_view_analytics_granted_to_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Administrators pass via manage_options — no regression.
		$this->assertTrue( PermissionHelper::can( 'view_analytics' ) );
	}

	public function test_dedicated_view_analytics_cap_does_not_grant_write_access(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'datamachine_view_analytics' );
		wp_set_current_user( $user_id );

		// Read-only analytics access granted...
		$this->assertTrue( PermissionHelper::can( 'view_analytics' ) );

		// ...but NOT the write/admin surface gated on manage_flows.
		$this->assertFalse( PermissionHelper::can( 'manage_flows' ) );
		$this->assertFalse( PermissionHelper::can_manage() );

		$user->remove_cap( 'datamachine_view_analytics' );
	}

	public function test_view_analytics_denied_to_unprivileged_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( PermissionHelper::can( 'view_analytics' ) );
	}

	public function test_user_session_agent_context_uses_owner_ceiling_without_token_id(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		PermissionHelper::set_agent_context( 123, $user_id );

		$principal = PermissionHelper::get_execution_principal();

		$this->assertInstanceOf( \AgentsAPI\AI\WP_Agent_Execution_Principal::class, $principal );
		$this->assertSame( \AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_USER, $principal->auth_source );
		$this->assertNull( $principal->token_id );
		$this->assertTrue( PermissionHelper::can( 'manage_agents' ) );
	}
}
