<?php
/**
 * Agents family ability REST-runner tests.
 *
 * Covers the agent CRUD and token abilities the admin consumes
 * after the datamachine/v1 wrapper routes were deleted for #3456.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use WP_REST_Request;
use WP_UnitTestCase;

class AgentsAbilityRestTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		datamachine_register_capabilities();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agent_access" );

		parent::tear_down();
	}

	private function run_ability( string $slug, array $input = array(), string $method = 'POST' ): array {
		$request = new WP_REST_Request( $method, '/wp-abilities/v1/abilities/datamachine/' . $slug . '/run' );
		$this->set_input( $request, $input, $method );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'Ability run failed: ' . wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	private function run_ability_status( string $slug, array $input = array(), string $method = 'POST' ): int {
		$request = new WP_REST_Request( $method, '/wp-abilities/v1/abilities/datamachine/' . $slug . '/run' );
		$this->set_input( $request, $input, $method );

		return rest_do_request( $request )->get_status();
	}

	/**
	 * Abilities annotated readonly require GET, which carries input as the
	 * `input` query parameter per the core run-controller contract.
	 */
	private function set_input( WP_REST_Request $request, array $input, string $method ): void {
		if ( 'GET' === $method ) {
			$request->set_query_params( array( 'input' => $input ) );
			return;
		}

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
	}

	public function test_rest_visible_agent_crud_round_trip(): void {
		// Create without owner_id — the ability defaults the owner to the
		// acting user (default moved from the retired POST /agents route).
		$created = $this->run_ability(
			'create-agent',
			array(
				'agent_slug' => 'agents-ability-rest-bot',
				'agent_name' => 'Agents Ability Rest Bot',
			)
		);
		$this->assertTrue( $created['success'] );
		$this->assertNotEmpty( $created['agent_id'] );
		$this->assertSame( get_current_user_id(), $created['owner_id'] );
		$agent_id = (int) $created['agent_id'];

		$single = $this->run_ability( 'get-agent', array( 'agent_id' => $agent_id ), 'GET' );
		$this->assertTrue( $single['success'] );
		$this->assertSame( 'agents-ability-rest-bot', $single['agent']['agent_slug'] );
		$this->assertArrayHasKey( 'access', $single['agent'] );

		$updated = $this->run_ability(
			'update-agent',
			array(
				'agent_id'     => $agent_id,
				'agent_name'   => 'Renamed Bot',
				'agent_config' => array( 'description' => 'Updated by ability test' ),
			)
		);
		$this->assertTrue( $updated['success'] );
		$this->assertSame( 'Renamed Bot', $updated['agent']['agent_name'] );
		$this->assertSame( 'Updated by ability test', $updated['agent']['agent_config']['description'] );

		$listed = $this->run_ability( 'list-agents', array( 'include_role' => true ) );
		$this->assertTrue( $listed['success'] );
		$this->assertNotEmpty( $listed['agents'] );
		$mine = null;
		foreach ( $listed['agents'] as $agent ) {
			if ( (int) $agent['agent_id'] === $agent_id ) {
				$mine = $agent;
			}
		}
		$this->assertNotNull( $mine );
		$this->assertSame( 'admin', $mine['user_role'] );
		$this->assertTrue( $mine['is_owner'] );

		$deleted = $this->run_ability(
			'delete-agent',
			array(
				'agent_id'     => $agent_id,
				'delete_files' => true,
			)
		);
		$this->assertTrue( $deleted['success'] );
		$this->assertTrue( $deleted['files_deleted'] );

		$this->assertSame( 404, $this->run_ability_status( 'get-agent', array( 'agent_id' => $agent_id ), 'GET' ) );
	}

	public function test_rest_visible_get_agent_me_resolves_owner_default_and_site(): void {
		$created = $this->run_ability( 'create-agent', array( 'agent_slug' => 'agents-ability-me-bot' ) );
		$this->assertTrue( $created['success'] );

		$me = $this->run_ability( 'get-agent', array( 'me' => true ), 'GET' );
		$this->assertTrue( $me['success'] );
		$this->assertSame( 'agents-ability-me-bot', $me['agent']['agent_slug'] );
		$this->assertArrayHasKey( 'site', $me );
		$this->assertNotEmpty( $me['site']['site_url'] );
		$this->assertNotEmpty( $me['site']['site_name'] );
	}

	public function test_rest_visible_token_create_list_revoke_round_trip(): void {
		$created  = $this->run_ability( 'create-agent', array( 'agent_slug' => 'agents-ability-token-bot' ) );
		$agent_id = (int) $created['agent_id'];

		$token = $this->run_ability(
			'create-agent-token',
			array(
				'agent_id' => $agent_id,
				'label'    => 'ability-rest-client',
			)
		);
		$this->assertTrue( $token['success'] );
		$this->assertNotEmpty( $token['raw_token'] );
		$this->assertNotEmpty( $token['token_prefix'] );
		$token_id = (int) $token['token_id'];

		$listed = $this->run_ability( 'list-agent-tokens', array( 'agent_id' => $agent_id ) );
		$this->assertTrue( $listed['success'] );
		$this->assertCount( 1, $listed['tokens'] );
		$this->assertSame( 'ability-rest-client', $listed['tokens'][0]['label'] );

		$revoked = $this->run_ability(
			'revoke-agent-token',
			array(
				'agent_id' => $agent_id,
				'token_id' => $token_id,
			)
		);
		$this->assertTrue( $revoked['success'] );

		$this->assertSame(
			404,
			$this->run_ability_status(
				'revoke-agent-token',
				array(
					'agent_id' => $agent_id,
					'token_id' => $token_id,
				)
			)
		);
	}
}
