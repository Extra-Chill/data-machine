<?php
/**
 * Tests for scoped workspace tool exposure via handler configuration.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use WP_UnitTestCase;

class WorkspaceScopedToolsTest extends WP_UnitTestCase {

	private ToolPolicyResolver $resolver;

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new ToolPolicyResolver();
	}

	/**
	 * Ensure workspace fetch tools are only exposed when workspace fetch handler is adjacent.
	 */
	public function test_fetch_scoped_workspace_tools_are_exposed_when_workspace_handler_present(): void {
		$previous_step = array(
			'handler_slugs'   => array( 'workspace' ),
			'handler_configs' => array(
				'workspace' => array(
					'repo'  => 'data-machine',
					'paths' => array( 'inc', 'docs' ),
				),
			),
		);

		$tools = $this->resolver->resolve( [
			'context'              => ToolPolicyResolver::CONTEXT_PIPELINE,
			'previous_step_config' => $previous_step,
		] );

		$this->assertArrayHasKey( 'workspace_fetch_ls', $tools );
		$this->assertArrayHasKey( 'workspace_fetch_read', $tools );
	}

	/**
	 * Ensure workspace publish mutate tools are scoped by handler flags.
	 */
	public function test_publish_scoped_workspace_tools_respect_handler_flags(): void {
		$next_step = array(
			'handler_slugs'   => array( 'workspace_publish' ),
			'handler_configs' => array(
				'workspace_publish' => array(
					'repo'           => 'extrachill-docs',
					'writable_paths' => array( 'ec_docs' ),
					'commit_enabled' => true,
					'push_enabled'   => false,
				),
			),
		);

		$tools = $this->resolver->resolve( [
			'context'          => ToolPolicyResolver::CONTEXT_PIPELINE,
			'next_step_config' => $next_step,
		] );

		$this->assertArrayHasKey( 'workspace_write', $tools );
		$this->assertArrayHasKey( 'workspace_edit', $tools );
		$this->assertArrayHasKey( 'workspace_git_add', $tools );
		$this->assertArrayHasKey( 'workspace_git_commit', $tools );
		$this->assertArrayNotHasKey( 'workspace_git_push', $tools );
	}
}
