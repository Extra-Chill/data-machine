<?php
/**
 * Tests for the Agents API caller context primitive.
 *
 * @package DataMachine\Tests\Unit\Auth
 */

namespace DataMachine\Tests\Unit\Auth;

use WP_UnitTestCase;

class CallerContextTest extends WP_UnitTestCase {

	public function test_default_construction(): void {
		$ctx = \WP_Agent_Caller_Context::top_of_chain( 'root-1' );
		$this->assertSame( '', $ctx->caller_agent_id );
		$this->assertSame( 0, $ctx->caller_user_id );
		$this->assertSame( \WP_Agent_Caller_Context::SELF_HOST, $ctx->caller_host );
		$this->assertSame( 'root-1', $ctx->chain_root_request_id );
		$this->assertSame( 0, $ctx->chain_depth );
		$this->assertFalse( $ctx->is_cross_site() );
	}

	public function test_from_request_with_full_headers(): void {
		$request = new \WP_REST_Request();
		$request->set_header( \WP_Agent_Caller_Context::HEADER_CALLER_HOST, 'https://chubes.net' );
		$request->set_header( \WP_Agent_Caller_Context::HEADER_CALLER_AGENT, 'franklin' );
		$request->set_header( \WP_Agent_Caller_Context::HEADER_CALLER_USER, '42' );
		$request->set_header( \WP_Agent_Caller_Context::HEADER_CHAIN_ROOT, 'abc-123' );
		$request->set_header( \WP_Agent_Caller_Context::HEADER_CHAIN_DEPTH, '2' );

		$ctx = \WP_Agent_Caller_Context::from_headers( $request );

		$this->assertSame( 'https://chubes.net', $ctx->caller_host );
		$this->assertSame( 'franklin', $ctx->caller_agent_id );
		$this->assertSame( 42, $ctx->caller_user_id );
		$this->assertSame( 'abc-123', $ctx->chain_root_request_id );
		$this->assertSame( 2, $ctx->chain_depth );
		$this->assertTrue( $ctx->is_cross_site() );
	}

	public function test_from_request_without_headers_generates_top_of_chain(): void {
		$request = new \WP_REST_Request();

		$ctx = \WP_Agent_Caller_Context::from_headers( $request );

		$this->assertNotEmpty( $ctx->chain_root_request_id, 'Missing chain root header auto-generates a UUID' );
		$this->assertSame( 0, $ctx->chain_depth );
		$this->assertFalse( $ctx->is_cross_site() );
	}

	public function test_from_request_negative_depth_fails_closed(): void {
		$request = new \WP_REST_Request();
		$request->set_header( \WP_Agent_Caller_Context::HEADER_CHAIN_DEPTH, '-5' );

		$this->expectException( \InvalidArgumentException::class );
		\WP_Agent_Caller_Context::from_headers( $request );
	}

	public function test_from_request_accepts_plain_header_array(): void {
		$headers = array(
			\WP_Agent_Caller_Context::HEADER_CALLER_HOST  => 'https://extrachill.com',
			\WP_Agent_Caller_Context::HEADER_CALLER_AGENT => 'sarai',
			\WP_Agent_Caller_Context::HEADER_CHAIN_ROOT   => 'chain-xyz',
			\WP_Agent_Caller_Context::HEADER_CHAIN_DEPTH  => '1',
		);

		$ctx = \WP_Agent_Caller_Context::from_headers( $headers );

		$this->assertSame( 'https://extrachill.com', $ctx->caller_host );
		$this->assertSame( 'sarai', $ctx->caller_agent_id );
		$this->assertSame( 'chain-xyz', $ctx->chain_root_request_id );
		$this->assertSame( 1, $ctx->chain_depth );
	}

	public function test_from_request_header_lookup_is_case_insensitive(): void {
		$headers = array(
			'x-agents-api-caller-agent' => 'franklin',
			'X-AGENTS-API-CALLER-HOST'  => 'https://chubes.net',
			'X-AGENTS-API-CHAIN-ROOT'   => 'mixed-case',
			'x-agents-api-chain-depth'  => '3',
		);

		$ctx = \WP_Agent_Caller_Context::from_headers( $headers );

		$this->assertSame( 'https://chubes.net', $ctx->caller_host );
		$this->assertSame( 'mixed-case', $ctx->chain_root_request_id );
		$this->assertSame( 3, $ctx->chain_depth );
	}

	public function test_is_cross_site_requires_remote_host(): void {
		$local = \WP_Agent_Caller_Context::top_of_chain( 'cid' );
		$this->assertFalse( $local->is_cross_site() );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'caller_host chained context (chain_depth > 0) must specify a remote caller host' );
		new \WP_Agent_Caller_Context( 'some-agent', 0, \WP_Agent_Caller_Context::SELF_HOST, 2, 'cid' );
	}

	public function test_is_cross_site_accepts_remote_host(): void {
		$remote = new \WP_Agent_Caller_Context( 'some-agent', 0, 'https://chubes.net', 1, 'cid' );
		$this->assertTrue( $remote->is_cross_site() );
	}

	public function test_outbound_fresh_chain_shape(): void {
		$top = \WP_Agent_Caller_Context::top_of_chain( 'fresh-root' );
		$ctx = new \WP_Agent_Caller_Context( 'franklin', 7, 'https://intelligence-chubes4.local', 1, $top->chain_root_request_id );

		$this->assertSame( 'https://intelligence-chubes4.local', $ctx->caller_host );
		$this->assertSame( 'franklin', $ctx->caller_agent_id );
		$this->assertSame( 7, $ctx->caller_user_id );
		$this->assertSame( 'fresh-root', $ctx->chain_root_request_id );
		$this->assertSame( 1, $ctx->chain_depth, 'Top-of-chain outbound is depth 1' );
	}

	public function test_outbound_propagates_inbound_chain(): void {
		$inbound = new \WP_Agent_Caller_Context( 'chubes-bot', 9, 'https://chubes.net', 2, 'original-chain' );
		$ctx     = new \WP_Agent_Caller_Context( 'franklin', 7, 'https://intelligence-chubes4.local', $inbound->chain_depth + 1, $inbound->chain_root_request_id );

		$this->assertSame( 'original-chain', $ctx->chain_root_request_id, 'Chain root propagates from inbound' );
		$this->assertSame( 3, $ctx->chain_depth, 'Depth increments by 1 for each hop' );
		$this->assertSame( 'https://intelligence-chubes4.local', $ctx->caller_host, 'Host reflects current site, not inbound' );
		$this->assertSame( 'franklin', $ctx->caller_agent_id, 'Agent reflects current site, not inbound' );
	}

	public function test_to_outbound_headers_includes_required_fields(): void {
		$ctx     = new \WP_Agent_Caller_Context( 'franklin', 7, 'https://chubes.net', 2, 'cid-1' );
		$headers = $ctx->to_headers();

		$this->assertSame( 'https://chubes.net', $headers[ \WP_Agent_Caller_Context::HEADER_CALLER_HOST ] );
		$this->assertSame( 'franklin', $headers[ \WP_Agent_Caller_Context::HEADER_CALLER_AGENT ] );
		$this->assertSame( '7', $headers[ \WP_Agent_Caller_Context::HEADER_CALLER_USER ] );
		$this->assertSame( 'cid-1', $headers[ \WP_Agent_Caller_Context::HEADER_CHAIN_ROOT ] );
		$this->assertSame( '2', $headers[ \WP_Agent_Caller_Context::HEADER_CHAIN_DEPTH ] );
	}

	public function test_remote_agent_client_emits_canonical_outbound_headers(): void {
		update_option(
			\DataMachine\Core\Auth\AgentAuthCallback::OPTION_KEY,
			array(
				'remote.example/sarai' => array(
					'token' => 'datamachine_test_token',
				),
			),
			false
		);

		$captured = null;
		$filter   = static function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );

		try {
			\DataMachine\Core\Auth\RemoteAgentClient::request( 'remote.example', 'sarai', 'POST', '/wp-json/datamachine/v1/chat' );
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
			delete_option( \DataMachine\Core\Auth\AgentAuthCallback::OPTION_KEY );
		}

		$this->assertIsArray( $captured );
		$headers = $captured['args']['headers'];

		$this->assertSame( 'Bearer datamachine_test_token', $headers['Authorization'] );
		$this->assertArrayHasKey( \WP_Agent_Caller_Context::HEADER_CALLER_AGENT, $headers );
		$this->assertArrayHasKey( \WP_Agent_Caller_Context::HEADER_CALLER_USER, $headers );
		$this->assertArrayHasKey( \WP_Agent_Caller_Context::HEADER_CALLER_HOST, $headers );
		$this->assertArrayHasKey( \WP_Agent_Caller_Context::HEADER_CHAIN_ROOT, $headers );
		$this->assertSame( '1', $headers[ \WP_Agent_Caller_Context::HEADER_CHAIN_DEPTH ] );
		$this->assertArrayNotHasKey( 'X-Datamachine-Caller-Site', $headers );
		$this->assertArrayNotHasKey( 'X-Datamachine-Chain-Id', $headers );
	}

	public function test_top_of_chain_headers_include_canonical_self_shape(): void {
		$ctx     = \WP_Agent_Caller_Context::top_of_chain( 'cid-1' );
		$headers = $ctx->to_headers();

		$this->assertSame( '', $headers[ \WP_Agent_Caller_Context::HEADER_CALLER_AGENT ] );
		$this->assertSame( '0', $headers[ \WP_Agent_Caller_Context::HEADER_CALLER_USER ] );
		$this->assertSame( \WP_Agent_Caller_Context::SELF_HOST, $headers[ \WP_Agent_Caller_Context::HEADER_CALLER_HOST ] );
		$this->assertSame( 'cid-1', $headers[ \WP_Agent_Caller_Context::HEADER_CHAIN_ROOT ] );
		$this->assertSame( '0', $headers[ \WP_Agent_Caller_Context::HEADER_CHAIN_DEPTH ] );
	}

	public function test_to_log_context_shape(): void {
		$ctx = new \WP_Agent_Caller_Context( 'franklin', 7, 'https://chubes.net', 2, 'cid-1' );
		$log = $ctx->to_array();

		$this->assertSame( 'https://chubes.net', $log['caller_host'] );
		$this->assertSame( 'franklin', $log['caller_agent_id'] );
		$this->assertSame( 'cid-1', $log['chain_root_request_id'] );
		$this->assertSame( 2, $log['chain_depth'] );
	}

	public function test_chain_depth_budget_registered_at_boot(): void {
		$this->assertTrue(
			\DataMachine\Engine\AI\IterationBudgetRegistry::is_registered( 'chain_depth' ),
			'chain_depth budget should be registered at boot by inc/bootstrap.php'
		);

		$config = \DataMachine\Engine\AI\IterationBudgetRegistry::get_config( 'chain_depth' );
		$this->assertSame( 'max_chain_depth', $config['setting'] );
		$this->assertSame( 1, $config['min'] );
		$this->assertSame( 10, $config['max'] );
	}

	public function test_chain_depth_budget_enforces_ceiling(): void {
		// At depth 2, budget ceiling default 3: still has room.
		$budget = \DataMachine\Engine\AI\IterationBudgetRegistry::create( 'chain_depth', 2 );
		$this->assertFalse( $budget->exceeded() );

		// At depth 3, budget at ceiling: exceeded.
		$budget = \DataMachine\Engine\AI\IterationBudgetRegistry::create( 'chain_depth', 3 );
		$this->assertTrue( $budget->exceeded() );

		// At depth 7, budget well over ceiling: still exceeded.
		$budget = \DataMachine\Engine\AI\IterationBudgetRegistry::create( 'chain_depth', 7 );
		$this->assertTrue( $budget->exceeded() );
	}
}
