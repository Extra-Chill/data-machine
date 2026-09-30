<?php
/**
 * Mailbox-ownership preflight tests for email ability permission callbacks (#3507).
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Fetch\FetchEmailAbility;
use DataMachine\Abilities\Publish\SendEmailAbility;
use DataMachine\Abilities\Publish\SendEmailQueuedAbility;
use DataMachine\Core\OAuth\BaseAuthProvider;
use DataMachine\Core\Steps\Fetch\Handlers\Email\EmailAuth;
use WP_UnitTestCase;

class EmailMailboxPermissionTest extends WP_UnitTestCase {

	private EmailAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		$this->auth = new EmailAuth();
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_auth_data' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function credentials( string $user ): array {
		return array(
			'imap_host'       => 'imap.example.test',
			'imap_port'       => 993,
			'imap_encryption' => 'ssl',
			'imap_user'       => $user,
			'imap_password'   => 'mail-secret-' . $user,
		);
	}

	private function grant_use_tools( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		$user->add_cap( 'datamachine_use_tools' );
	}

	public function test_owner_may_act_on_their_own_named_mailbox(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_use_tools( $owner_id );
		$this->auth->save_named_account( 'personal', $this->credentials( 'owner@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, $owner_id );

		wp_set_current_user( $owner_id );
		$ability = new FetchEmailAbility();

		$this->assertTrue( $ability->checkPermission( array( 'auth_ref' => 'email_imap:personal' ) ) );
	}

	public function test_non_owner_with_use_tools_is_denied(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_use_tools( $other_id );
		$this->auth->save_named_account( 'personal', $this->credentials( 'owner@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, $owner_id );

		wp_set_current_user( $other_id );
		$ability = new FetchEmailAbility();

		$this->assertFalse( $ability->checkPermission( array( 'auth_ref' => 'email_imap:personal' ) ) );
	}

	public function test_admin_does_not_bypass_another_users_named_mailbox(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->auth->save_named_account( 'personal', $this->credentials( 'owner@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, $owner_id );

		wp_set_current_user( $admin_id );
		$ability = new FetchEmailAbility();

		$this->assertFalse(
			$ability->checkPermission( array( 'auth_ref' => 'email_imap:personal' ) ),
			'Admins do not get a standing backdoor into another user\'s connected mailbox — matches the existing EmailAuth::can_access() contract this preflight reuses.'
		);
	}

	public function test_admin_retains_access_to_the_shared_default_mailbox(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->auth->save_config( $this->credentials( 'default@example.test' ) );

		wp_set_current_user( $admin_id );
		$ability = new FetchEmailAbility();

		$this->assertTrue( $ability->checkPermission( array( 'auth_ref' => 'email_imap:default' ) ) );
	}

	public function test_use_tools_only_user_is_denied_the_shared_default_mailbox(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_use_tools( $user_id );
		$this->auth->save_config( $this->credentials( 'default@example.test' ) );

		wp_set_current_user( $user_id );
		$ability = new FetchEmailAbility();

		$this->assertFalse(
			$ability->checkPermission( array( 'auth_ref' => 'email_imap:default' ) ),
			'email_imap:default (user_id 0 / site scope) is a deliberately shared operational mailbox gated by management capability via EmailAuth::can_use_default() — a narrower bar than a bare use_tools floor, and narrower than generic owns_resource() semantics for resource_user_id === 0 would provide.'
		);
	}

	public function test_missing_ref_falls_back_to_capability_floor(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_use_tools( $user_id );

		wp_set_current_user( $user_id );
		$ability = new FetchEmailAbility();

		// No auth_ref supplied — e.g. a chat-tool availability probe called
		// with no input. The capability floor alone governs; execute() still
		// enforces the concrete ref once one is chosen.
		$this->assertTrue( $ability->checkPermission( null ) );
	}

	public function test_unconfigured_ref_is_not_treated_as_a_permission_denial(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// No mailbox configured at all — EmailAuth::resolve_mailbox() fails
		// with auth_ref_unresolved, not email_mailbox_forbidden. checkPermission()
		// must let execute() report that specific, helpful error rather than
		// collapsing it into a generic 403.
		$ability = new FetchEmailAbility();

		$this->assertTrue( $ability->checkPermission( array( 'auth_ref' => 'email_imap:default' ) ) );
	}

	public function test_delete_ability_is_denied_for_a_mailbox_the_caller_does_not_own(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_use_tools( $other_id );
		$this->auth->save_named_account( 'personal', $this->credentials( 'owner@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, $owner_id );

		wp_set_current_user( $other_id );
		$ability = new \DataMachine\Abilities\Email\EmailAbilities();

		$this->assertFalse( $ability->checkDeletePermission( array( 'auth_ref' => 'email_imap:personal', 'uid' => 1 ) ) );
	}

	public function test_send_email_denies_direct_call_against_an_unowned_ref(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_use_tools( $other_id );
		$this->auth->save_named_account( 'personal', $this->credentials( 'owner@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, $owner_id );

		wp_set_current_user( $other_id );
		$ability = new SendEmailAbility();

		$this->assertFalse(
			$ability->checkPermission( array( 'to' => 'x@example.test', 'subject' => 'hi', 'auth_ref' => 'email_imap:personal' ) ),
			'Closes the exact hole #3507 reports: any use_tools holder could previously send as whatever mailbox happened to be configured.'
		);
	}

	public function test_send_email_with_mailbox_grant_defers_ownership_to_execute(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_use_tools( $other_id );
		$this->auth->save_named_account( 'personal', $this->credentials( 'owner@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, $owner_id );

		wp_set_current_user( $other_id );
		$ability = new SendEmailAbility();

		// Without a grant: the caller does not own this ref and is denied.
		$this->assertFalse( $ability->checkPermission( array( 'to' => 'x@example.test', 'subject' => 'hi', 'auth_ref' => 'email_imap:personal' ) ) );

		// With a `_mailbox_grant` present — the shape the queued-send worker
		// forwards on dispatch — checkPermission() defers the real
		// authorization decision to execute()'s independent HMAC signature
		// verification against the grant's own issuer identity, instead of
		// re-deriving ownership from ambient PermissionHelper context. That
		// context is empty during real Action Scheduler dispatch, so
		// re-deriving it here would incorrectly deny a previously-authorized
		// send. Only the capability floor applies in this branch.
		$this->assertTrue( $ability->checkPermission( array(
			'to'             => 'x@example.test',
			'subject'        => 'hi',
			'auth_ref'       => 'email_imap:personal',
			'_mailbox_grant' => array( 'signature' => 'placeholder-unverified-here' ),
		) ) );
	}

	public function test_send_email_queued_gates_ownership_at_queue_time_with_no_grant_special_case(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_use_tools( $owner_id );
		$this->grant_use_tools( $other_id );
		$this->auth->save_named_account( 'personal', $this->credentials( 'owner@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, $owner_id );

		wp_set_current_user( $other_id );
		$ability = new SendEmailQueuedAbility();

		// Queuing is always synchronous and always has live ambient context —
		// unlike send-email, send-email-queued never runs its own
		// checkPermission() inside the async worker, so it needs no
		// `_mailbox_grant` bypass.
		$this->assertFalse( $ability->checkPermission( array( 'to' => 'x@example.test', 'subject' => 'hi', 'auth_ref' => 'email_imap:personal' ) ) );

		wp_set_current_user( $owner_id );
		$this->assertTrue( $ability->checkPermission( array( 'to' => 'x@example.test', 'subject' => 'hi', 'auth_ref' => 'email_imap:personal' ) ) );
	}
}
