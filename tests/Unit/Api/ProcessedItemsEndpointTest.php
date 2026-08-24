<?php
/**
 * Processed items REST endpoint tests.
 *
 * @package DataMachine\Tests\Unit\Api
 */

namespace DataMachine\Tests\Unit\Api;

use DataMachine\Api\ProcessedItems;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

class ProcessedItemsEndpointTest extends WP_UnitTestCase {

	private const ABILITY = 'datamachine/clear-processed-items';

	public function set_up(): void {
		parent::set_up();
		datamachine_register_capabilities();
		$this->unregister_ability();
	}

	public function tear_down(): void {
		$this->unregister_ability();
		$this->restore_ability();
		parent::tear_down();
	}

	public function test_handle_clear_invokes_registered_ability_with_exact_input_and_projects_success(): void {
		$received = null;
		$this->register_ability(
			static function () {
				return true;
			},
			static function ( array $input ) use ( &$received ): array {
				$received = $input;
				return array(
					'success'       => true,
					'message'       => 'Cleared by ability',
					'deleted_count' => 3,
				);
			}
		);

		$request = new WP_REST_Request( 'DELETE', '/datamachine/v1/processed-items' );
		$request->set_param( 'clear_type', 'flow' );
		$request->set_param( 'target_id', '42' );

		$response = ProcessedItems::handle_clear( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'clear_type' => 'flow', 'target_id' => 42 ), $received );
		$this->assertSame(
			array(
				'success'       => true,
				'data'          => null,
				'message'       => 'Cleared by ability',
				'items_deleted' => 3,
			),
			$response->get_data()
		);
	}

	public function test_handle_clear_passes_through_ability_wp_error(): void {
		$error = new WP_Error( 'clear_failed', 'Native clear failure', array( 'status' => 409 ) );
		$this->register_ability( static function () { return true; }, static function () use ( $error ) { return $error; } );

		$request = new WP_REST_Request( 'DELETE', '/datamachine/v1/processed-items' );
		$request->set_param( 'clear_type', 'pipeline' );
		$request->set_param( 'target_id', 7 );

		$result = ProcessedItems::handle_clear( $request );

		$this->assertSame( $error, $result );
	}

	public function test_handle_clear_returns_safe_error_when_ability_is_missing(): void {
		$request = new WP_REST_Request( 'DELETE', '/datamachine/v1/processed-items' );
		$request->set_param( 'clear_type', 'flow' );
		$request->set_param( 'target_id', 1 );

		$result = ProcessedItems::handle_clear( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_not_found', $result->get_error_code() );
		$this->assertSame( 'Ability not found', $result->get_error_message() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_permission_behavior_is_unchanged(): void {
		wp_set_current_user( 0 );

		$result = ProcessedItems::check_permission( new WP_REST_Request( 'DELETE', '/datamachine/v1/processed-items' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_permission_allows_flow_managers(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( ProcessedItems::check_permission( new WP_REST_Request( 'DELETE', '/datamachine/v1/processed-items' ) ) );
	}

	private function register_ability( callable $permission_callback, callable $execute_callback ): void {
		\WP_Abilities_Registry::get_instance()->register(
			self::ABILITY,
			array(
				'label'               => 'Processed items endpoint test',
				'description'         => 'Test ability',
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'clear_type', 'target_id' ),
					'properties' => array(
						'clear_type' => array( 'type' => 'string' ),
						'target_id'  => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $permission_callback,
				'execute_callback'    => $execute_callback,
			)
		);
	}

	private function unregister_ability(): void {
		$registry = \WP_Abilities_Registry::get_instance();
		if ( $registry->is_registered( self::ABILITY ) ) {
			$registry->unregister( self::ABILITY );
		}
	}

	private function restore_ability(): void {
		$abilities = new \DataMachine\Abilities\ProcessedItemsAbilities();
		$method    = new \ReflectionMethod( $abilities, 'registerClearProcessedItems' );
		$method->invoke( $abilities );
	}
}
