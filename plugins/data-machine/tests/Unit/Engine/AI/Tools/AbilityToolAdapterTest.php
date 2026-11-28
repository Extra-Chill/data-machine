<?php
/**
 * Tests for canonical ability execution through AbilityToolAdapter.
 *
 * @package DataMachine\Tests\Unit\Engine\AI\Tools
 */

namespace DataMachine\Tests\Unit\Engine\AI\Tools;

use DataMachine\Engine\AI\Tools\AbilityToolAdapter;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

class Instrumented_Ability extends WP_Ability {

	/** @var string[] */
	public static array $events = array();

	public static int $execute_calls = 0;

	public function normalize_input( $input = null ) {
		self::$events[] = 'normalize';
		$input          = parent::normalize_input( $input );

		if ( is_array( $input ) && isset( $input['message'] ) && is_string( $input['message'] ) ) {
			$input['message'] = strtoupper( $input['message'] );
		}

		return $input;
	}

	public function validate_input( $input = null ) {
		self::$events[] = 'validate';
		return parent::validate_input( $input );
	}

	public function check_permissions( $input = null ) {
		self::$events[] = 'permissions';
		return parent::check_permissions( $input );
	}

	public function execute( $input = null ) {
		++self::$execute_calls;
		return parent::execute( $input );
	}
}

class AbilityToolAdapterTest extends WP_UnitTestCase {

	private const ABILITY_SLUG = 'datamachine/adapter-contract-test';

	private int $permission_calls = 0;

	private int $callback_calls = 0;

	private mixed $permission_input = null;

	private mixed $callback_input = null;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();

		$this->unregisterAbility();
		Instrumented_Ability::$events        = array();
		Instrumented_Ability::$execute_calls = 0;
	}

	public function tear_down(): void {
		$this->unregisterAbility();
		parent::tear_down();
	}

	public function test_execute_uses_core_order_once_and_preserves_success_envelope(): void {
		$this->registerAbility(
			function ( $input ): bool {
				++$this->permission_calls;
				$this->permission_input = $input;
				return true;
			},
			function ( $input ): array {
				++$this->callback_calls;
				$this->callback_input = $input;
				Instrumented_Ability::$events[] = 'execute_callback';
				return array(
					'visible'  => 'success',
					'_internal' => 'hidden',
				);
			}
		);

		$result = AbilityToolAdapter::execute(
			'adapter_contract_tool',
			array(
				'action'  => 'run',
				'message' => 'normalized by core',
			),
			array(
				'ability_map'                => array( 'run' => self::ABILITY_SLUG ),
				'strip_action_parameter'     => true,
				'strip_internal_result_keys' => true,
			)
		);

		$this->assertSame( 1, Instrumented_Ability::$execute_calls );
		$this->assertSame( 1, $this->permission_calls );
		$this->assertSame( 1, $this->callback_calls );
		$this->assertSame( array( 'normalize', 'validate', 'permissions', 'execute_callback' ), Instrumented_Ability::$events );
		$this->assertSame( array( 'message' => 'NORMALIZED BY CORE' ), $this->permission_input );
		$this->assertSame( $this->permission_input, $this->callback_input );
		$this->assertSame(
			array(
				'success'   => true,
				'tool_name' => 'adapter_contract_tool',
				'metadata'  => array( 'ability' => self::ABILITY_SLUG ),
				'result'    => array( 'visible' => 'success' ),
			),
			$result
		);
	}

	public function test_invalid_input_stops_before_permissions(): void {
		$this->registerAbility(
			function (): bool {
				++$this->permission_calls;
				return true;
			},
			function (): array {
				++$this->callback_calls;
				return array( 'visible' => 'unexpected' );
			}
		);

		$result = AbilityToolAdapter::execute(
			'adapter_contract_tool',
			array( 'message' => 42 ),
			array( 'execution_ability' => self::ABILITY_SLUG )
		);

		$this->assertSame( array( 'normalize', 'validate' ), Instrumented_Ability::$events );
		$this->assertSame( 1, Instrumented_Ability::$execute_calls );
		$this->assertSame( 0, $this->permission_calls );
		$this->assertSame( 0, $this->callback_calls );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'ability_invalid_input', $result['metadata']['wp_error_code'] );
	}

	public function test_permission_error_uses_core_generic_denial_without_leaking_details(): void {
		$this->registerAbility(
			function (): WP_Error {
				++$this->permission_calls;
				return new WP_Error( 'secret_denial', 'Sensitive permission details.' );
			},
			function (): array {
				++$this->callback_calls;
				return array( 'visible' => 'unexpected' );
			}
		);

		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = AbilityToolAdapter::execute(
			'adapter_contract_tool',
			array( 'message' => 'denied' ),
			array( 'execution_ability' => self::ABILITY_SLUG )
		);

		$this->assertSame( array( 'normalize', 'validate', 'permissions' ), Instrumented_Ability::$events );
		$this->assertSame( 1, Instrumented_Ability::$execute_calls );
		$this->assertSame( 1, $this->permission_calls );
		$this->assertSame( 0, $this->callback_calls );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'ability_invalid_permissions', $result['metadata']['wp_error_code'] );
		$this->assertStringNotContainsString( 'Sensitive permission details.', $result['error'] );
	}

	private function registerAbility( callable $permission_callback, callable $execute_callback ): void {
		$ability = \WP_Abilities_Registry::get_instance()->register(
			self::ABILITY_SLUG,
			array(
				'label'               => 'Adapter contract test',
				'description'         => 'Exercises the canonical ability execution boundary.',
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'message' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $permission_callback,
				'execute_callback'    => $execute_callback,
				'ability_class'       => Instrumented_Ability::class,
			)
		);

		$this->assertInstanceOf( Instrumented_Ability::class, $ability );
	}

	private function unregisterAbility(): void {
		$registry = \WP_Abilities_Registry::get_instance();
		if ( $registry->is_registered( self::ABILITY_SLUG ) ) {
			$registry->unregister( self::ABILITY_SLUG );
		}
	}
}
