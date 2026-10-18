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

	/**
	 * Abilities that act on a connected account, keyed by the policy action
	 * they must be gated on.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>}>
	 */
	public function provide_account_abilities(): array {
		return array(
			'instagram comment reply' => array( 'datamachine/instagram-comment-reply', 'publish', array() ),
			'facebook comment reply'  => array( 'datamachine/facebook-comment-reply', 'publish', array() ),
			'instagram delete'        => array( 'datamachine/instagram-delete', 'publish', array() ),
			'twitter update'          => array( 'datamachine/twitter-update', 'publish', array() ),
			'tumblr engage'           => array( 'datamachine/tumblr-engage', 'publish', array() ),
			'message send'            => array( 'datamachine/social-messages', 'publish', array( 'action' => 'send' ) ),
			'message list'            => array( 'datamachine/social-messages', 'edit', array( 'action' => 'conversations' ) ),
			'comments listing'        => array( 'datamachine/social-comments', 'edit', array() ),
			'facebook read'           => array( 'datamachine/facebook-read', 'edit', array() ),
			'instagram account'       => array( 'datamachine/instagram-account', 'edit', array() ),
		);
	}

	/**
	 * @dataProvider provide_account_abilities
	 */
	public function test_policy_allowed_user_reaches_account_ability_without_use_tools( string $name, string $expected_action, array $input ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$this->assertFalse( user_can( $user_id, 'datamachine_use_tools' ) );

		$seen = array();
		add_filter(
			'datamachine_socials_user_can',
			function ( bool $allowed, string $action ) use ( &$seen ): bool {
				$seen[] = $action;
				return true;
			},
			10,
			2
		);

		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, $name );
		$this->assertTrue( $ability->check_permissions( $input ), $name );
		$this->assertContains( $expected_action, $seen, $name );
	}

	/**
	 * @dataProvider provide_account_abilities
	 */
	public function test_policy_denied_user_is_refused_even_with_use_tools( string $name, string $expected_action, array $input ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->assertTrue( user_can( $user_id, 'datamachine_use_tools' ) );
		add_filter( 'datamachine_socials_user_can', '__return_false' );

		$this->assertFalse( wp_get_ability( $name )->check_permissions( $input ), $name );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function provide_strict_abilities(): array {
		return array(
			'ads promote'  => array( 'datamachine/facebook-ads-promote' ),
			'ads report'   => array( 'datamachine/facebook-ads-report' ),
			'reddit vote'  => array( 'datamachine/vote-reddit' ),
			'reddit reply' => array( 'datamachine/reply-reddit' ),
			'reddit fetch' => array( 'datamachine/fetch-reddit' ),
		);
	}

	/**
	 * @dataProvider provide_strict_abilities
	 */
	public function test_strict_ability_requires_use_tools_even_when_policy_allows( string $name ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		add_filter( 'datamachine_socials_user_can', '__return_true' );

		$this->assertFalse( wp_get_ability( $name )->check_permissions( array() ), $name );
	}

	/**
	 * Data Machine grants use_tools to authors by default, so use_tools alone
	 * must not open brand ads or Reddit to a user the socials policy denies.
	 *
	 * @dataProvider provide_strict_abilities
	 */
	public function test_strict_ability_requires_policy_even_with_use_tools( string $name ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
		get_userdata( $user_id )->add_cap( 'datamachine_use_tools' );
		add_filter( 'datamachine_socials_user_can', '__return_false' );

		$this->assertFalse( wp_get_ability( $name )->check_permissions( array() ), $name );
	}

	public function test_strict_ability_allows_policy_and_use_tools_holder(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( wp_get_ability( 'datamachine/vote-reddit' )->check_permissions( array() ) );
	}
}
