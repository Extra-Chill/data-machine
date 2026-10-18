<?php
/**
 * Email abilities are gated on a dedicated `use_email` action
 * (`datamachine_use_email`), not `use_tools`.
 *
 * Data Machine grants `datamachine_use_tools` to the author and editor roles
 * by default, so a `use_tools` gate let any author send mail as the site and
 * act on connected mailboxes.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\PermissionHelper;
use WP_UnitTestCase;

class EmailPermissionTest extends WP_UnitTestCase {

	private const ABILITIES = array(
		'datamachine/send-email',
		'datamachine/send-email-queued',
		'datamachine/email-forward',
		'datamachine/fetch-email',
		'datamachine/email-reply',
		'datamachine/email-delete',
		'datamachine/email-test-connection',
	);

	public function tear_down(): void {
		PermissionHelper::clear_agent_context();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function assert_abilities( bool $expected, string $message ): void {
		foreach ( self::ABILITIES as $name ) {
			$ability = wp_get_ability( $name );
			if ( null === $ability ) {
				continue;
			}
			$this->assertSame( $expected, true === $ability->check_permissions( array() ), "$message: $name" );
		}
	}

	public function test_author_with_use_tools_is_denied(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_user_by( 'id', $user_id )->add_cap( 'datamachine_use_tools' );
		wp_set_current_user( $user_id );

		$this->assertTrue( PermissionHelper::can( 'use_tools' ) );
		$this->assertFalse( PermissionHelper::can( 'use_email' ) );
		$this->assert_abilities( false, 'use_tools alone must not reach email' );
	}

	public function test_dedicated_cap_grants_email(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		get_user_by( 'id', $user_id )->add_cap( 'datamachine_use_email' );
		wp_set_current_user( $user_id );

		$this->assertTrue( PermissionHelper::can( 'use_email' ) );
		$this->assert_abilities( true, 'datamachine_use_email holder' );
	}

	public function test_management_implies_email(): void {
		$user_id = self::factory()->user->create();
		get_user_by( 'id', $user_id )->add_cap( 'datamachine_manage_flows' );
		wp_set_current_user( $user_id );

		$this->assertTrue( PermissionHelper::can( 'use_email' ) );
	}

	public function test_administrator_keeps_email(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assert_abilities( true, 'administrator' );
	}

	public function test_email_cap_does_not_grant_management(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		get_user_by( 'id', $user_id )->add_cap( 'datamachine_use_email' );
		wp_set_current_user( $user_id );

		$this->assertFalse( PermissionHelper::can_manage() );
	}
}
