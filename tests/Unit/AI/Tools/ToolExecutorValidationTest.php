<?php
/**
 * Tests for ToolExecutor required parameter validation.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters;
use AgentsAPI\AI\WP_Agent_Provider_Turn_Request;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;
use DataMachine\Engine\AI\Actions\PendingActionStore;
use DataMachine\Engine\AI\Tools\ToolExecutor;
use DataMachine\Engine\AI\Tools\ToolManager;
use WP_UnitTestCase;

class ToolExecutorValidationTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'agents_api_action_policy_providers' );
		parent::tear_down();
	}

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

	public function test_execute_tool_uses_agents_api_for_declared_client_context_bindings(): void {
		$available_tools = array(
			'bound_context_tool' => array(
				'class'                   => TestToolHandler::class,
				'method'                  => 'handle_tool_call',
				'parameters'              => array(
					'query' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'client_context_bindings' => array( 'query' => 'search_query' ),
			),
		);

		$result = ToolExecutor::executeTool(
			'bound_context_tool',
			array(),
			$available_tools,
			array(),
			'chat',
			0,
			array( 'search_query' => 'from client context' )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'from client context', $result['result']['data']['query'] ?? null );
	}

	public function test_execute_tool_prefers_host_payload_for_declared_context_bindings(): void {
		$available_tools = array(
			'bound_context_tool' => array(
				'class'                   => TestToolHandler::class,
				'method'                  => 'handle_tool_call',
				'parameters'              => array(
					'query' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'client_context_bindings' => array( 'query' => 'job_id' ),
			),
		);

		$result = ToolExecutor::executeTool(
			'bound_context_tool',
			array(),
			$available_tools,
			array( 'job_id' => 42 ),
			'chat',
			0,
			array( 'job_id' => 7 )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 42, $result['result']['data']['query'] ?? null );
	}

	public function test_execute_tool_applies_authoritative_caller_context_bindings(): void {
		$tools = $this->authoritativeCallerContextTools();

		$injected = ToolExecutor::executeTool(
			'caller_context_tool',
			array( 'query' => 'model query' ),
			$tools,
			array(
				'calling_user_id' => 42,
				'bound_query'     => 'context query',
			)
		);
		$override_attempt = ToolExecutor::executeTool(
			'caller_context_tool',
			array(
				'calling_user_id' => 999,
				'query'           => 'model query',
			),
			$tools,
			array(
				'calling_user_id' => 42,
				'bound_query'     => 'context query',
			)
		);

		$this->assertTrue( $injected['success'] );
		$this->assertSame( 42, $injected['result']['data']['calling_user_id'] ?? null );
		$this->assertSame( 'model query', $injected['result']['data']['query'] ?? null );
		$this->assertTrue( $override_attempt['success'] );
		$this->assertSame( 42, $override_attempt['result']['data']['calling_user_id'] ?? null );
		$this->assertSame( 'model query', $override_attempt['result']['data']['query'] ?? null );
	}

	public function test_execute_tool_preserves_authoritative_caller_context_zero(): void {
		$result = ToolExecutor::executeTool(
			'caller_context_tool',
			array(
				'calling_user_id' => 999,
				'query'           => 'model query',
			),
			$this->authoritativeCallerContextTools(),
			array(
				'calling_user_id' => 0,
				'bound_query'     => 'context query',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['result']['data']['calling_user_id'] ?? null );
	}

	public function test_execute_tool_fails_closed_without_authoritative_caller_context(): void {
		$result = ToolExecutor::executeTool(
			'caller_context_tool',
			array(
				'calling_user_id' => 999,
				'query'           => 'model query',
			),
			$this->authoritativeCallerContextTools(),
			array( 'bound_query' => 'context query' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'missing_required_parameters', $result['metadata']['error_type'] ?? null );
		$this->assertSame( array( 'calling_user_id' ), $result['metadata']['missing_parameters'] ?? array() );
	}

	public function test_caller_context_excludes_frontend_controlled_client_context(): void {
		$tools = array(
			'context_boundary_tool' => array(
				'class'              => TestToolHandler::class,
				'method'             => 'handle_tool_call',
				'parameters'         => array(
					'type'       => 'object',
					'required'   => array( 'frontend_user_id' ),
					'properties' => array(
						'trusted_user_id'  => array( 'type' => 'integer' ),
						'frontend_user_id' => array( 'type' => 'integer' ),
					),
				),
				'parameter_bindings' => array(
					'trusted_user_id'  => array(
						'source'        => 'caller_context',
						'path'          => 'client_context.calling_user_id',
						'authoritative' => true,
					),
					'frontend_user_id' => array(
						'source' => 'client_context',
						'path'   => 'calling_user_id',
					),
				),
			),
		);
		$client_context = array( 'calling_user_id' => 999 );

		$result = ToolExecutor::executeTool(
			'context_boundary_tool',
			array( 'trusted_user_id' => 777 ),
			$tools,
			array( 'client_context' => $client_context ),
			'chat',
			0,
			$client_context
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'trusted_user_id', $result['result']['data'] ?? array() );
		$this->assertSame( 999, $result['result']['data']['frontend_user_id'] ?? null );
	}

	public function test_provider_schema_excludes_authoritative_caller_context_parameters(): void {
		$request = new WP_Agent_Provider_Turn_Request(
			array( array( 'role' => 'user', 'content' => 'Inspect context.' ) ),
			$this->authoritativeCallerContextTools()
		);
		$schema = $request->toolDeclarations()['caller_context_tool']['parameters'] ?? array();

		$this->assertArrayNotHasKey( 'calling_user_id', $schema['properties'] ?? array() );
		$this->assertArrayHasKey( 'query', $schema['properties'] ?? array() );
		$this->assertSame( array( 'query' ), $schema['required'] ?? array() );
	}

	public function test_execute_tool_does_not_satisfy_required_params_from_ambient_context_keys(): void {
		$available_tools = array(
			'unbound_context_tool' => array(
				'class'      => TestToolHandler::class,
				'method'     => 'handle_tool_call',
				'parameters' => array(
					'query' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
		);

		$result = ToolExecutor::executeTool(
			'unbound_context_tool',
			array(),
			$available_tools,
			array(),
			'chat',
			0,
			array( 'query' => 'ambient context value' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'missing_required_parameters', $result['metadata']['error_type'] ?? null );
		$this->assertSame( array( 'query' ), $result['metadata']['missing_parameters'] ?? array() );
	}

	public function test_execute_tool_returns_error_for_missing_tool(): void {
		$result = ToolExecutor::executeTool(
			'missing_tool',
			array(),
			array(),
			array()
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'missing_tool', $result['tool_name'] );
		$this->assertSame( 'tool_not_found', $result['metadata']['error_type'] ?? null );
	}

	public function test_execute_tool_succeeds_with_required_params_present(): void {
		$available_tools = array(
			'test_tool' => array(
				'class'      => TestToolHandler::class,
				'method'     => 'handle_tool_call',
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

	public function test_execute_tool_passes_canonical_identity_to_action_policy_providers(): void {
		$workspace     = WP_Agent_Workspace_Scope::from_parts( 'site', 'workspace-a' );
		$seen_contexts = array();

		add_filter(
			'agents_api_action_policy_providers',
			function ( $providers ) use ( &$seen_contexts ) {
				$providers[] = new class( $seen_contexts ) implements \WP_Agent_Action_Policy_Provider {
					/** @var array<int,array<string,mixed>> */
					private array $seen_contexts;

					public function __construct( array &$seen_contexts ) {
						$this->seen_contexts =& $seen_contexts;
					}

					public function get_action_policy( array $context ): ?string {
						$this->seen_contexts[] = $context;
						$workspace            = $context['workspace'] ?? null;

						if (
							$workspace instanceof WP_Agent_Workspace_Scope
							&& 'workspace-a' === $workspace->workspace_id
							&& 7 === (int) ( $context['user_id'] ?? 0 )
							&& 9 === (int) ( $context['acting_user_id'] ?? 0 )
							&& 11 === (int) ( $context['agent_id'] ?? 0 )
							&& 'policy-agent' === (string) ( $context['agent_slug'] ?? '' )
						) {
							return 'forbidden';
						}

						return null;
					}
				};

				return $providers;
			},
			10,
			1
		);

		$result = ToolExecutor::executeTool(
			'test_tool',
			array( 'query' => 'test search' ),
			$this->availableTestTools(),
			array(
				'workspace'       => $workspace->to_array(),
				'user_id'         => 7,
				'calling_user_id' => 9,
				'agent_slug'      => 'Policy Agent',
				'session_id'      => 'session-123',
				'request_id'      => 'request-456',
				'job_id'          => 21,
				'flow_step_id'    => 34,
			),
			'chat',
			11,
			array( 'bridge_app' => 'unit-test' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'forbidden', $result['action_policy'] );
		$this->assertNotEmpty( $seen_contexts );

		$context = $seen_contexts[0];
		$this->assertInstanceOf( WP_Agent_Workspace_Scope::class, $context['workspace'] ?? null );
		$this->assertSame( 'workspace-a', $context['workspace']->workspace_id );
		$this->assertSame( 7, $context['user_id'] );
		$this->assertSame( 9, $context['acting_user_id'] );
		$this->assertSame( 11, $context['agent_id'] );
		$this->assertSame( 'policy-agent', $context['agent_slug'] );
		$this->assertSame( 'session-123', $context['session_id'] );
		$this->assertSame( 'request-456', $context['request_id'] );
		$this->assertSame( 21, $context['datamachine']['job_id'] ?? null );
		$this->assertSame( 34, $context['datamachine']['flow_step_id'] ?? null );
		$this->assertSame( 'unit-test', $context['client_context']['bridge_app'] ?? null );
	}

	public function test_execute_tool_policy_provider_can_distinguish_identity_scopes(): void {
		add_filter(
			'agents_api_action_policy_providers',
			function ( $providers ) {
				$providers[] = new class() implements \WP_Agent_Action_Policy_Provider {
					public function get_action_policy( array $context ): ?string {
						$workspace = $context['workspace'] ?? null;

						return $workspace instanceof WP_Agent_Workspace_Scope
							&& 'workspace-b' === $workspace->workspace_id
							&& 22 === (int) ( $context['acting_user_id'] ?? 0 )
							&& 'scoped-agent' === (string) ( $context['agent_slug'] ?? '' )
							? 'forbidden'
							: null;
					}
				};

				return $providers;
			},
			10,
			1
		);

		$allowed = ToolExecutor::executeTool(
			'test_tool',
			array( 'query' => 'test search' ),
			$this->availableTestTools(),
			array(
				'workspace'       => WP_Agent_Workspace_Scope::from_parts( 'site', 'workspace-a' )->to_array(),
				'calling_user_id' => 22,
				'agent_slug'      => 'scoped-agent',
			)
		);

		$forbidden = ToolExecutor::executeTool(
			'test_tool',
			array( 'query' => 'test search' ),
			$this->availableTestTools(),
			array(
				'workspace'       => WP_Agent_Workspace_Scope::from_parts( 'site', 'workspace-b' )->to_array(),
				'calling_user_id' => 22,
				'agent_slug'      => 'scoped-agent',
			)
		);

		$this->assertTrue( $allowed['success'] );
		$this->assertFalse( $forbidden['success'] );
		$this->assertSame( 'forbidden', $forbidden['action_policy'] );
	}

	public function test_execute_tool_policy_provider_receives_complete_input_across_all_policy_outcomes(): void {
		$seen_contexts = array();
		add_filter(
			'agents_api_action_policy_providers',
			static function ( $providers ) use ( &$seen_contexts ) {
				$providers[] = new class( $seen_contexts ) implements \WP_Agent_Action_Policy_Provider {
					/** @var array<int,array<string,mixed>> */
					private array $seen_contexts;

					public function __construct( array &$seen_contexts ) {
						$this->seen_contexts =& $seen_contexts;
					}

					public function get_action_policy( array $context ): ?string {
						$this->seen_contexts[] = $context;

						return match ( $context['input']['operation'] ?? '' ) {
							'read'  => 'direct',
							'write' => 'preview',
							'delete' => 'forbidden',
							default => null,
						};
					}
				};

				return $providers;
			},
			10,
			1
		);

		$tools = array(
			'input_sensitive_tool' => array(
				'class'                   => TestToolHandler::class,
				'method'                  => 'handle_tool_call',
				'action_kind'             => 'input_sensitive_action',
				'parameters'              => array(
					'operation' => array(
						'type'     => 'string',
						'required' => true,
					),
					'query'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'scope'     => array(
						'type'     => 'string',
						'required' => false,
					),
				),
				'client_context_bindings' => array( 'query' => 'bound_query' ),
				'parameter_defaults'      => array( 'scope' => 'all' ),
			),
		);

		$read = ToolExecutor::executeTool(
			'input_sensitive_tool',
			array( 'operation' => 'read' ),
			$tools,
			array(),
			'chat',
			0,
			array( 'bound_query' => 'record-1' )
		);
		$write = ToolExecutor::executeTool(
			'input_sensitive_tool',
			array( 'operation' => 'write' ),
			$tools,
			array(),
			'chat',
			0,
			array( 'bound_query' => 'record-2' )
		);
		$forbidden = ToolExecutor::executeTool(
			'input_sensitive_tool',
			array( 'operation' => 'delete' ),
			$tools,
			array(),
			'chat',
			0,
			array( 'bound_query' => 'record-3' )
		);

		$this->assertTrue( $read['success'] );
		$this->assertSame( 'record-1', $read['result']['data']['query'] ?? null );
		$this->assertTrue( $write['staged'] );
		$this->assertSame( 'preview', $write['action_policy'] );
		$this->assertFalse( $forbidden['success'] );
		$this->assertSame( 'forbidden', $forbidden['action_policy'] );

		$this->assertCount( 3, $seen_contexts );
		$this->assertSame(
			array(
				'scope'     => 'all',
				'query'     => 'record-1',
				'operation' => 'read',
			),
			$seen_contexts[0]['input'] ?? null
		);
		$this->assertSame(
			array(
				'scope'     => 'all',
				'query'     => 'record-2',
				'operation' => 'write',
			),
			$seen_contexts[1]['input'] ?? null
		);
		$this->assertSame(
			array(
				'scope'     => 'all',
				'query'     => 'record-3',
				'operation' => 'delete',
			),
			$seen_contexts[2]['input'] ?? null
		);

		$pending_action = PendingActionStore::get( $write['action_id'] );
		$this->assertSame(
			array(
				'scope'     => 'all',
				'query'     => 'record-2',
				'operation' => 'write',
			),
			$pending_action['apply_input'] ?? null
		);
		PendingActionStore::delete( $write['action_id'] );
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

		$tool_manager = new ToolManager();
		$resolved     = $tool_manager->resolveAllTools( $tools );

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

		$tool_manager = new ToolManager();
		$resolved     = $tool_manager->resolveAllTools( $tools );

		$this->assertIsArray( $resolved['invalid_tool'] );
		$this->assertEmpty( $resolved['invalid_tool'] );
	}

	private function availableTestTools(): array {
		return array(
			'test_tool' => array(
				'class'      => TestToolHandler::class,
				'method'     => 'handle_tool_call',
				'parameters' => array(
					'query' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
		);
	}

	private function authoritativeCallerContextTools(): array {
		return array(
			'caller_context_tool' => array(
				'name'               => 'caller_context_tool',
				'class'              => TestToolHandler::class,
				'method'             => 'handle_tool_call',
				'description'        => 'Inspect caller context.',
				'parameters'         => array(
					'type'       => 'object',
					'required'   => array( 'calling_user_id', 'query' ),
					'properties' => array(
						'calling_user_id' => array( 'type' => 'integer' ),
						'query'           => array( 'type' => 'string' ),
					),
				),
				'parameter_bindings' => array(
					'calling_user_id' => array(
						'source'        => 'caller_context',
						'path'          => 'calling_user_id',
						'authoritative' => true,
					),
					'query'           => array(
						'source' => 'caller_context',
						'path'   => 'bound_query',
					),
				),
			),
		);
	}

	private function invokeValidateMethod( array $tool_parameters, array $tool_def ): array {
		return WP_Agent_Tool_Parameters::validateRequiredParameters( $tool_parameters, $tool_def );
	}

}

class TestToolHandler {
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		return array(
			'success'   => true,
			'data'      => $parameters,
			'tool_name' => 'test_tool',
		);
	}
}
