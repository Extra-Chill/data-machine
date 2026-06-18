<?php
/**
 * Tests that an opt-in ability-projected tool (the shape DMC uses for
 * workspace_write) resolves for a sandbox run when the runtime
 * declares it via allow_only / an allow-mode tool policy.
 *
 * Data Machine has no sandbox-specific knowledge: 'sandbox' is just an unknown
 * mode string that normalizes away, and the paired 'chat' mode carries tools.
 * This test projects a real, registered ability under an opt-in tool name
 * (mirroring datamachine-code/workspace-write) and asserts the resolver
 * surfaces it for ['sandbox','chat'] + allow_only.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use WP_UnitTestCase;

class SandboxOptInToolResolutionTest extends WP_UnitTestCase {

	private ToolPolicyResolver $resolver;

	public function set_up(): void {
		parent::set_up();

		datamachine_register_capabilities();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Project a real, registered ability under an opt-in tool name, exactly
		// the way data-machine-code projects workspace_write.
		datamachine_register_ability_tool(
			'probe_optin_tool',
			array(
				'ability'         => 'datamachine/get-wordpress-post',
				'modes'           => array( 'chat', 'pipeline' ),
				'requires_opt_in' => true,
			)
		);

		$this->resolver = new ToolPolicyResolver();
	}

	/**
	 * The argument shape a sandbox run passes through ChatOrchestrator.
	 */
	private function sandboxArgs(): array {
		return array(
			'modes'       => array( 'sandbox', 'chat' ),
			'interactive' => true,
			'allow_only'  => array( 'probe_optin_tool' ),
			'tool_policy' => array(
				'mode'  => 'allow',
				'tools' => array( 'probe_optin_tool' ),
			),
		);
	}

	public function test_opt_in_ability_tool_resolves_for_sandbox_run(): void {
		$tools = $this->resolver->resolve( $this->sandboxArgs() );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey(
			'probe_optin_tool',
			$tools,
			'Opt-in ability-projected tool must resolve for a sandbox+chat run when named via allow_only and an allow-mode tool policy.'
		);
	}

	public function test_opt_in_tool_excluded_without_allow(): void {
		$args                = $this->sandboxArgs();
		$args['allow_only']  = array();
		$args['tool_policy'] = null;

		$tools = $this->resolver->resolve( $args );

		$this->assertIsArray( $tools );
		$this->assertArrayNotHasKey(
			'probe_optin_tool',
			$tools,
			'Opt-in tool must stay hidden when not named in allow_only or an allow-mode policy.'
		);
	}
}
