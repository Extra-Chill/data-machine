<?php
/**
 * Tests for CallerContextDirective.
 *
 * @package DataMachine\Tests\Unit\AI
 */

namespace DataMachine\Tests\Unit\AI;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Auth\CallerContext;
use DataMachine\Engine\AI\Directives\CallerContextDirective;
use WP_UnitTestCase;

class CallerContextDirectiveTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		PermissionHelper::clear_agent_context();
	}

	public function tear_down(): void {
		PermissionHelper::clear_agent_context();
		parent::tear_down();
	}

	public function test_no_output_when_no_caller_context(): void {
		$outputs = CallerContextDirective::get_outputs( 'openai', array() );
		$this->assertSame( array(), $outputs );
	}

	public function test_no_output_when_caller_is_local(): void {
		// Top-of-chain (depth=0, no caller_site) is not cross-site.
		PermissionHelper::set_caller_context( new CallerContext( '', '', 'chain-id', 0 ) );
		$outputs = CallerContextDirective::get_outputs( 'openai', array() );
		$this->assertSame( array(), $outputs );
	}

	public function test_no_output_when_depth_set_but_no_caller_site(): void {
		// Depth alone doesn't qualify — caller_site is the A2A signal.
		PermissionHelper::set_caller_context( new CallerContext( '', 'some-agent', 'chain-id', 2 ) );
		$outputs = CallerContextDirective::get_outputs( 'openai', array() );
		$this->assertSame( array(), $outputs );
	}

	public function test_emits_system_text_for_cross_site_call(): void {
		PermissionHelper::set_caller_context( new CallerContext(
			'franklin.example',
			'franklin-bot',
			'chain-abc-123',
			1
		) );

		$outputs = CallerContextDirective::get_outputs( 'openai', array() );

		$this->assertCount( 1, $outputs );
		$this->assertSame( 'system_text', $outputs[0]['type'] );

		$content = $outputs[0]['content'];
		$this->assertStringContainsString( 'Cross-Site Caller Context', $content );
		$this->assertStringContainsString( 'franklin-bot', $content );
		$this->assertStringContainsString( 'franklin.example', $content );
		$this->assertStringContainsString( 'chain-abc-123', $content );
		$this->assertStringContainsString( 'Chain depth:** 1', $content );
	}

	public function test_content_marks_identity_as_authenticated(): void {
		// The directive must signal to the receiving agent that caller
		// identity is server-validated, not self-reported.
		PermissionHelper::set_caller_context( new CallerContext(
			'peer.example',
			'peer-bot',
			'xyz',
			1
		) );

		$outputs = CallerContextDirective::get_outputs( 'openai', array() );
		$this->assertStringContainsString( 'authenticated', $outputs[0]['content'] );
	}

	public function test_content_instructs_terse_peer_response(): void {
		PermissionHelper::set_caller_context( new CallerContext(
			'peer.example',
			'peer-bot',
			'xyz',
			1
		) );

		$outputs  = CallerContextDirective::get_outputs( 'openai', array() );
		$content  = $outputs[0]['content'];

		// Core peer-response protocol signals.
		$this->assertStringContainsString( 'peer agent', $content );
		$this->assertStringContainsString( 'terse', $content );
		$this->assertStringContainsString( 'structured', $content );
	}

	public function test_content_mentions_current_chain_depth_in_protocol(): void {
		PermissionHelper::set_caller_context( new CallerContext(
			'peer.example',
			'peer-bot',
			'xyz',
			3
		) );

		$outputs = CallerContextDirective::get_outputs( 'openai', array() );
		$content = $outputs[0]['content'];

		// Depth should appear both in the identity block and in the protocol block.
		$this->assertMatchesRegularExpression( '/Chain depth:\*\*\s*3/', $content );
		$this->assertMatchesRegularExpression( '/hop\s*3/i', $content );
	}

	public function test_caller_context_cleared_between_requests(): void {
		// First request: cross-site call produces output.
		PermissionHelper::set_caller_context( new CallerContext(
			'a.example', 'a-bot', 'chain-1', 1
		) );
		$first = CallerContextDirective::get_outputs( 'openai', array() );
		$this->assertCount( 1, $first );

		// Clearing context (as AgentAuthMiddleware does on request completion)
		// must make subsequent calls produce no output.
		PermissionHelper::clear_agent_context();
		$second = CallerContextDirective::get_outputs( 'openai', array() );
		$this->assertSame( array(), $second );
	}

	public function test_directive_registered_in_pipeline_at_priority_25(): void {
		// Guard against accidental removal of the bootstrap require.
		$directives = apply_filters( 'datamachine_directives', array() );

		$found = null;
		foreach ( $directives as $directive ) {
			if ( ( $directive['class'] ?? null ) === CallerContextDirective::class ) {
				$found = $directive;
				break;
			}
		}

		$this->assertNotNull( $found, 'CallerContextDirective must be registered via datamachine_directives filter.' );
		$this->assertSame( 25, $found['priority'] );
		$this->assertSame( array( 'all' ), $found['modes'] );
	}
}
