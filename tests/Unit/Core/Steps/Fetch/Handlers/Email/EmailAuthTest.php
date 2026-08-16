<?php
/**
 * Named mailbox and delegation tests.
 *
 * @package DataMachine\Tests\Unit
 */

namespace DataMachine\Tests\Unit\Core\Steps\Fetch\Handlers\Email;

use DataMachine\Core\OAuth\BaseAuthProvider;
use DataMachine\Core\Steps\Fetch\Handlers\Email\EmailAuth;
use WP_UnitTestCase;

class EmailAuthTest extends WP_UnitTestCase {
	private EmailAuth $auth;

	public function set_up(): void {
		parent::set_up();
		delete_site_option( 'datamachine_auth_data' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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

	public function test_default_ref_preserves_legacy_config_compatibility(): void {
		$this->auth->save_config( $this->credentials( 'legacy@example.test' ) );

		$resolved = $this->auth->resolve_mailbox( 'email_imap:default', 'read' );
		$this->assertFalse( is_wp_error( $resolved ) );
		$this->assertSame( 'legacy@example.test', $resolved['credentials']['imap_user'] );
		$this->assertSame( array( 'type' => 'site', 'id' => 0 ), $resolved['owner'] );
	}

	public function test_named_site_user_and_agent_mailboxes_resolve_exactly(): void {
		$this->auth->save_named_account( 'site-box', $this->credentials( 'site@example.test' ) );
		$this->auth->save_named_account( 'user-box', $this->credentials( 'user@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, 42 );
		$this->auth->save_named_account( 'agent-box', $this->credentials( 'agent@example.test' ), BaseAuthProvider::AUTH_SCOPE_AGENT, 303 );

		$site  = $this->auth->resolve_mailbox( 'site-box', 'read' );
		$user  = $this->auth->resolve_mailbox_for_principal( 'user-box', 'read', array( 'user_id' => 42 ) );
		$agent = $this->auth->resolve_mailbox_for_principal( 'agent-box', 'read', array( 'agent_id' => 303 ) );

		$this->assertSame( 'site@example.test', $site['credentials']['imap_user'] );
		$this->assertSame( 'user@example.test', $user['credentials']['imap_user'] );
		$this->assertSame( 'agent@example.test', $agent['credentials']['imap_user'] );
	}

	public function test_agent_delegation_is_operation_scoped_and_has_no_default_fallback(): void {
		$this->auth->save_config( $this->credentials( 'default@example.test' ) );
		$this->auth->save_named_account( 'personal', $this->credentials( 'owner@example.test' ), BaseAuthProvider::AUTH_SCOPE_USER, 42 );
		$this->assertTrue( $this->auth->grant_agent( 'personal', BaseAuthProvider::AUTH_SCOPE_USER, 42, 303, array( 'read', 'search', 'draft' ) ) );

		$this->assertFalse( is_wp_error( $this->auth->resolve_mailbox_for_principal( 'personal', array( 'read', 'search' ), array( 'agent_id' => 303 ) ) ) );
		$this->assertFalse( is_wp_error( $this->auth->resolve_mailbox_for_principal( 'personal', 'draft', array( 'agent_id' => 303 ) ) ) );
		$this->assertWPError( $this->auth->resolve_mailbox_for_principal( 'personal', 'send', array( 'agent_id' => 303 ) ) );
		$this->assertWPError( $this->auth->resolve_mailbox_for_principal( 'personal', 'delete', array( 'agent_id' => 303 ) ) );
		$this->assertWPError( $this->auth->resolve_mailbox_for_principal( 'personal', 'read', array( 'agent_id' => 404 ) ) );
		$this->assertWPError( $this->auth->resolve_mailbox_for_principal( 'default', 'read', array( 'agent_id' => 404 ) ) );
	}

	public function test_missing_named_mailbox_never_falls_back_and_audit_is_secret_free(): void {
		$this->auth->save_config( $this->credentials( 'default@example.test' ) );
		$audit = array();
		add_action( 'datamachine_email_operation_audit', static function ( array $metadata ) use ( &$audit ): void {
			$audit[] = $metadata;
		} );

		$result = $this->auth->resolve_mailbox_for_principal( 'missing', 'read', array( 'agent_id' => 303, 'flow_id' => 9, 'job_id' => 10 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'email_imap:missing', $audit[0]['mailbox_ref'] );
		$this->assertSame( array( 'type' => 'agent', 'id' => 303 ), $audit[0]['acting_principal'] );
		$this->assertSame( 9, $audit[0]['flow_id'] );
		$this->assertStringNotContainsString( 'mail-secret', wp_json_encode( $audit ) );
	}

	public function test_imap_password_is_encrypted_and_export_redacts_connection_identity(): void {
		$this->auth->save_named_account( 'secure', $this->credentials( 'secure@example.test' ) );
		$raw = get_site_option( 'datamachine_auth_data', array() );

		$this->assertStringStartsWith( 'dm:enc:v1:', $raw['email_imap']['accounts']['secure']['imap_password'] );
		$this->assertSame( array( 'folder' => 'INBOX' ), $this->auth->strip_auth_config_secrets( array_merge( $this->credentials( 'secure@example.test' ), array( 'folder' => 'INBOX' ) ) ) );
	}
}
