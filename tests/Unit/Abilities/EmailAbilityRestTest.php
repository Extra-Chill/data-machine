<?php
/**
 * Email and internal-links ability REST-runner tests.
 *
 * Covers the ability routes left behind after the datamachine/v1 email and
 * internal-links wrapper routes were deleted for #3456: the point is REST
 * visibility and permission wiring, not live IMAP.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use WP_REST_Request;
use WP_UnitTestCase;

class EmailAbilityRestTest extends WP_UnitTestCase {

	private function act_as_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	private function act_as_subscriber(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
	}

	private function run_ability( string $slug, array $input ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/datamachine/' . $slug . '/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );

		return rest_do_request( $request );
	}

	public function test_rest_visible_get_orphaned_posts_round_trip(): void {
		$this->act_as_admin();

		$response = $this->run_ability(
			'get-orphaned-posts',
			array(
				'post_type' => 'post',
				'limit'     => 10,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Ability run failed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 0, $data['orphaned_count'] );
		$this->assertSame( array(), $data['orphaned_posts'] );
		$this->assertArrayHasKey( 'total_scanned', $data );
	}

	public function test_rest_visible_email_test_connection_resolves_to_ability(): void {
		$this->act_as_admin();

		// No mailbox is configured in the test environment, so the ability
		// fails fast with an email_* WP_Error (HTTP 400). The assertions
		// prove the REST route resolves to the ability and the admin passes
		// its permission gate — anything 401/403/404 here would mean the
		// route or the permission wiring is broken, not the mailbox.
		$response = $this->run_ability( 'email-test-connection', array() );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertStringStartsWith( 'email_', (string) ( $data['code'] ?? '' ) );
	}

	public function test_rest_visible_email_ability_denies_user_without_capability(): void {
		$this->act_as_subscriber();

		$response = $this->run_ability( 'email-test-connection', array() );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_rest_visible_internal_links_ability_denies_user_without_capability(): void {
		$this->act_as_subscriber();

		$response = $this->run_ability( 'get-orphaned-posts', array( 'post_type' => 'post' ) );

		$this->assertSame( 403, $response->get_status() );
	}
}
