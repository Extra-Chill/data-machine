<?php
/**
 * Social publish authorization tests.
 *
 * @package DataMachineSocials\Tests\Unit
 */

namespace DataMachineSocials\Tests\Unit;

use DataMachine\Abilities\PermissionHelper;
use DataMachineSocials\PublishAuthorization;
use WP_UnitTestCase;

final class PublishAuthorizationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		remove_all_filters( 'datamachine_cli_bypass_permissions' );
		remove_all_filters( 'datamachine_socials_user_can' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_administrator_can_publish(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( PublishAuthorization::can_publish() );
	}

	public function test_filter_can_explicitly_allow_non_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		add_filter(
			'datamachine_socials_user_can',
			function ( bool $allowed, string $action, int $acting_user_id ) use ( $user_id ): bool {
				$this->assertFalse( $allowed );
				$this->assertSame( 'publish', $action );
				$this->assertSame( $user_id, $acting_user_id );
				return true;
			},
			10,
			3
		);

		$this->assertTrue( PublishAuthorization::can_publish() );
	}

	public function test_filter_can_deny_user_with_publish_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		add_filter( 'datamachine_socials_user_can', '__return_false' );

		$this->assertFalse( PublishAuthorization::can_publish() );
	}

	public function test_anonymous_execution_is_denied(): void {
		$this->assertFalse( PublishAuthorization::can_publish() );
	}

	public function test_pre_authenticated_non_user_execution_is_allowed(): void {
		$allowed = PermissionHelper::run_as_authenticated(
			fn(): bool => PublishAuthorization::can_publish()
		);

		$this->assertTrue( $allowed );
	}

	public function test_pre_authenticated_agent_execution_preserves_capability_ceiling(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$allowed  = PermissionHelper::run_as_agent_context(
			123,
			$owner_id,
			fn(): bool => PublishAuthorization::can_publish()
		);

		$this->assertTrue( $allowed );
	}

	public function test_scheduler_non_user_execution_is_allowed(): void {
		$allowed = false;
		add_action(
			'action_scheduler_run_queue',
			function () use ( &$allowed ): void {
				$allowed = PublishAuthorization::can_publish();
			}
		);

		do_action( 'action_scheduler_run_queue' );

		$this->assertTrue( $allowed );
	}

	public function test_rest_visible_specialized_publish_ability_uses_socials_policy(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		add_filter(
			'datamachine_socials_user_can',
			fn( bool $allowed, string $action, int $acting_user_id ): bool => 'publish' === $action && $user_id === $acting_user_id,
			10,
			3
		);

		$ability = wp_get_ability( 'datamachine/youtube-upload' );

		$this->assertNotNull( $ability );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] );
		$this->assertTrue( $ability->check_permissions( array( 'title' => 'Authorized upload' ) ) );
	}
}
