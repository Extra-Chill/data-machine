<?php
/**
 * Agents REST slug route handler tests.
 *
 * @package DataMachine\Tests\Unit\Api
 */

namespace DataMachine\Tests\Unit\Api;

use DataMachine\Api\Agents as AgentsRest;
use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use WP_REST_Request;
use WP_UnitTestCase;

class AgentsEndpointSlugTest extends WP_UnitTestCase {

	private AgentsRepository $repo;
	private AgentAccess $access_repo;
	private int $admin_user;
	private int $owner_user;
	private int $granted_user;

	public function set_up(): void {
		parent::set_up();
		datamachine_register_capabilities();

		$this->repo         = new AgentsRepository();
		$this->access_repo  = new AgentAccess();
		$this->admin_user   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->owner_user   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->granted_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->admin_user );
	}

	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agent_access" );

		parent::tear_down();
	}

	public function test_get_agent_accepts_slug_route_param(): void {
		$agent_id = $this->repo->create_if_missing( 'rest-slug-bot', 'REST Slug Bot', $this->owner_user );
		$request  = new WP_REST_Request( 'GET', '/datamachine/v1/agents/rest-slug-bot' );
		$request->set_param( 'agent', 'rest-slug-bot' );

		$response = AgentsRest::handle_get( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( $agent_id, $data['data']['agent_id'] );
		$this->assertSame( 'rest-slug-bot', $data['data']['agent_slug'] );
	}

	public function test_get_agent_keeps_numeric_id_route_compatibility(): void {
		$agent_id = $this->repo->create_if_missing( 'rest-id-bot', 'REST ID Bot', $this->owner_user );
		$request  = new WP_REST_Request( 'GET', '/datamachine/v1/agents/' . $agent_id );
		$request->set_param( 'agent_id', $agent_id );

		$response = AgentsRest::handle_get( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'rest-id-bot', $data['data']['agent_slug'] );
	}

	public function test_update_agent_accepts_slug_route_param(): void {
		$this->repo->create_if_missing( 'rest-update-bot', 'Before', $this->owner_user );

		$request = new WP_REST_Request( 'PATCH', '/datamachine/v1/agents/rest-update-bot' );
		$request->set_param( 'agent', 'rest-update-bot' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'agent_name' => 'After' ) ) );

		$response = AgentsRest::handle_update( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'After', $data['data']['agent_name'] );
	}

	public function test_list_access_accepts_slug_route_param(): void {
		$agent_id = $this->repo->create_if_missing( 'rest-access-bot', 'REST Access Bot', $this->owner_user );
		$this->access_repo->grant_access( new \WP_Agent_Access_Grant( (string) $agent_id, $this->granted_user, 'operator' ) );

		$request = new WP_REST_Request( 'GET', '/datamachine/v1/agents/rest-access-bot/access' );
		$request->set_param( 'agent', 'rest-access-bot' );

		$response = AgentsRest::handle_list_access( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertCount( 1, $data['data'] );
		$this->assertSame( $this->granted_user, (int) $data['data'][0]['user_id'] );
	}
}
