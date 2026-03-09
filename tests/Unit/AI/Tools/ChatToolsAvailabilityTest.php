<?php
/**
 * Tests for tool availability in chat context.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use WP_UnitTestCase;

class ChatToolsAvailabilityTest extends WP_UnitTestCase {

	private ToolPolicyResolver $resolver;

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new ToolPolicyResolver();
	}

	public function test_chat_tools_include_update_flow(): void {
		$tools = $this->resolver->resolve( [
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		] );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'update_flow', $tools );
		$this->assertIsArray( $tools['update_flow'] );
		$this->assertArrayHasKey( 'class', $tools['update_flow'] );
		$this->assertArrayHasKey( 'method', $tools['update_flow'] );
	}

	public function test_chat_tools_include_web_fetch(): void {
		$tools = $this->resolver->resolve( [
			'context' => ToolPolicyResolver::CONTEXT_CHAT,
		] );

		$this->assertIsArray( $tools );
		$this->assertArrayHasKey( 'web_fetch', $tools );
		$this->assertIsArray( $tools['web_fetch'] );
		$this->assertArrayHasKey( 'class', $tools['web_fetch'] );
		$this->assertArrayHasKey( 'method', $tools['web_fetch'] );
	}
}
