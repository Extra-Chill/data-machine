<?php
/**
 * Tests for ToolExecutor required parameter validation.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\ToolExecutor;
use WP_UnitTestCase;
use ReflectionClass;

class ToolExecutorValidationTest extends WP_UnitTestCase {

	public function test_validate_required_parameters_returns_valid_when_all_present(): void {
		$tool_parameters = array(
			'query' => 'test search',
		);

		$tool_def = array(
			'parameters' => array(
				'query' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);

		$result = $this->invokeValidateMethod( $tool_parameters, $tool_def );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['missing'] );
		$this->assertContains( 'query', $result['required'] );
	}

	public function test_validate_required_parameters_returns_invalid_when_missing(): void {
		$tool_parameters = array();

		$tool_def = array(
			'parameters' => array(
				'query' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);

		$result = $this->invokeValidateMethod( $tool_parameters, $tool_def );

		$this->assertFalse( $result['valid'] );
		$this->assertContains( 'query', $result['missing'] );
	}

	public function test_validate_handles_empty_string_as_missing(): void {
		$tool_parameters = array(
			'query' => '',
		);

		$tool_def = array(
			'parameters' => array(
				'query' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);

		$result = $this->invokeValidateMethod( $tool_parameters, $tool_def );

		$this->assertFalse( $result['valid'] );
		$this->assertContains( 'query', $result['missing'] );
	}

	public function test_validate_ignores_optional_parameters(): void {
		$tool_parameters = array(
			'query' => 'test',
		);

		$tool_def = array(
			'parameters' => array(
				'query'      => array(
					'type'     => 'string',
					'required' => true,
				),
				'post_types' => array(
					'type'     => 'array',
					'required' => false,
				),
			),
		);

		$result = $this->invokeValidateMethod( $tool_parameters, $tool_def );

		$this->assertTrue( $result['valid'] );
		$this->assertCount( 1, $result['required'] );
		$this->assertContains( 'query', $result['required'] );
	}

	public function test_validate_handles_multiple_required_parameters(): void {
		$tool_parameters = array(
			'filter_by' => 'handler',
		);

		$tool_def = array(
			'parameters' => array(
				'filter_by'    => array(
					'type'     => 'string',
					'required' => true,
				),
				'filter_value' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);

		$result = $this->invokeValidateMethod( $tool_parameters, $tool_def );

		$this->assertFalse( $result['valid'] );
		$this->assertCount( 2, $result['required'] );
		$this->assertCount( 1, $result['missing'] );
		$this->assertContains( 'filter_value', $result['missing'] );
	}

	public function test_validate_handles_empty_parameters_definition(): void {
		$tool_parameters = array();

		$tool_def = array(
			'parameters' => array(),
		);

		$result = $this->invokeValidateMethod( $tool_parameters, $tool_def );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['required'] );
		$this->assertEmpty( $result['missing'] );
	}

	public function test_validate_handles_missing_parameters_key(): void {
		$tool_parameters = array();
		$tool_def        = array();

		$result = $this->invokeValidateMethod( $tool_parameters, $tool_def );

		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['required'] );
	}

	public function test_execute_tool_returns_error_for_missing_required_params(): void {
		$available_tools = array(
			'local_search' => array(
				'class'      => TestToolHandler::class,
				'parameters' => array(
					'query' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
		);

		$result = ToolExecutor::executeTool(
			'local_search',
			array(),
			$available_tools,
			array()
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'requires the following parameters', $result['error'] );
		$this->assertStringContainsString( 'query', $result['error'] );
	}

	public function test_execute_tool_succeeds_with_required_params_present(): void {
		$available_tools = array(
			'test_tool' => array(
				'class'      => TestToolHandler::class,
				'parameters' => array(
					'query' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
		);

		$result = ToolExecutor::executeTool(
			'test_tool',
			array( 'query' => 'test search' ),
			$available_tools,
			array()
		);

		$this->assertTrue( $result['success'] );
	}

	public function test_execute_tool_returns_error_when_class_key_missing(): void {
		$available_tools = array(
			'broken_tool' => array(
				// Missing 'class' key - simulates unresolved callable
				'parameters' => array(
					'query' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			),
		);

		$result = ToolExecutor::executeTool(
			'broken_tool',
			array( 'query' => 'test' ),
			$available_tools,
			array()
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'missing required', $result['error'] );
		$this->assertStringContainsString( 'class', $result['error'] );
	}

	public function test_resolve_tools_invokes_callables(): void {
		$callable_invoked = false;
		$tools            = array(
			'callable_tool' => function () use ( &$callable_invoked ) {
				$callable_invoked = true;
				return array(
					'class'       => TestToolHandler::class,
					'description' => 'Test tool from callable',
					'parameters'  => array(),
				);
			},
			'array_tool'    => array(
				'class'       => TestToolHandler::class,
				'description' => 'Test tool from array',
				'parameters'  => array(),
			),
		);

		$resolved = $this->invokeResolveMethod( $tools );

		$this->assertTrue( $callable_invoked, 'Callable should be invoked' );
		$this->assertIsArray( $resolved['callable_tool'] );
		$this->assertEquals( TestToolHandler::class, $resolved['callable_tool']['class'] );
		$this->assertIsArray( $resolved['array_tool'] );
		$this->assertEquals( TestToolHandler::class, $resolved['array_tool']['class'] );
	}

	public function test_resolve_tools_handles_non_array_results(): void {
		$tools = array(
			'invalid_tool' => 'not an array or callable',
		);

		$resolved = $this->invokeResolveMethod( $tools );

		$this->assertIsArray( $resolved['invalid_tool'] );
		$this->assertEmpty( $resolved['invalid_tool'] );
	}

	private function invokeValidateMethod( array $tool_parameters, array $tool_def ): array {
		$reflection = new ReflectionClass( ToolExecutor::class );
		$method     = $reflection->getMethod( 'validateRequiredParameters' );
		$method->setAccessible( true );

		return $method->invoke( null, $tool_parameters, $tool_def );
	}

	private function invokeResolveMethod( array $tools ): array {
		$reflection = new ReflectionClass( ToolExecutor::class );
		$method     = $reflection->getMethod( 'resolveTools' );
		$method->setAccessible( true );

		return $method->invoke( null, $tools );
	}
}

class TestToolHandler {
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		return array(
			'success'   => true,
			'data'      => array( 'query' => $parameters['query'] ?? '' ),
			'tool_name' => 'test_tool',
		);
	}
}
