<?php
/**
 * Tests for CallerContext value object.
 *
 * @package DataMachine\Tests\Unit\Auth
 */

namespace DataMachine\Tests\Unit\Auth;

use DataMachine\Core\Auth\CallerContext;
use WP_UnitTestCase;

class CallerContextTest extends WP_UnitTestCase {

	public function test_default_construction(): void {
		$ctx = new CallerContext();
		$this->assertSame( '', $ctx->callerSite() );
		$this->assertSame( '', $ctx->callerAgent() );
		$this->assertSame( '', $ctx->chainId() );
		$this->assertSame( 0, $ctx->chainDepth() );
		$this->assertFalse( $ctx->isCrossSite() );
	}

	public function test_from_request_with_full_headers(): void {
		$request = new \WP_REST_Request();
		$request->set_header( CallerContext::HEADER_CALLER_SITE, 'chubes.net' );
		$request->set_header( CallerContext::HEADER_CALLER_AGENT, 'franklin' );
		$request->set_header( CallerContext::HEADER_CHAIN_ID, 'abc-123' );
		$request->set_header( CallerContext::HEADER_CHAIN_DEPTH, '2' );

		$ctx = CallerContext::fromRequest( $request );

		$this->assertSame( 'chubes.net', $ctx->callerSite() );
		$this->assertSame( 'franklin', $ctx->callerAgent() );
		$this->assertSame( 'abc-123', $ctx->chainId() );
		$this->assertSame( 2, $ctx->chainDepth() );
		$this->assertTrue( $ctx->isCrossSite() );
	}

	public function test_from_request_missing_chain_id_generates_one(): void {
		$request = new \WP_REST_Request();

		$ctx = CallerContext::fromRequest( $request );

		$this->assertNotEmpty( $ctx->chainId(), 'Missing chain_id header auto-generates a UUID' );
		$this->assertSame( 0, $ctx->chainDepth() );
		$this->assertFalse( $ctx->isCrossSite() );
	}

	public function test_from_request_negative_depth_clamped_to_zero(): void {
		$request = new \WP_REST_Request();
		$request->set_header( CallerContext::HEADER_CHAIN_DEPTH, '-5' );

		$ctx = CallerContext::fromRequest( $request );

		$this->assertSame( 0, $ctx->chainDepth() );
	}

	public function test_from_request_accepts_plain_header_array(): void {
		$headers = array(
			CallerContext::HEADER_CALLER_SITE  => 'extrachill.com',
			CallerContext::HEADER_CALLER_AGENT => 'sarai',
			CallerContext::HEADER_CHAIN_ID     => 'chain-xyz',
			CallerContext::HEADER_CHAIN_DEPTH  => '1',
		);

		$ctx = CallerContext::fromRequest( $headers );

		$this->assertSame( 'extrachill.com', $ctx->callerSite() );
		$this->assertSame( 'sarai', $ctx->callerAgent() );
		$this->assertSame( 'chain-xyz', $ctx->chainId() );
		$this->assertSame( 1, $ctx->chainDepth() );
	}

	public function test_from_request_header_lookup_is_case_insensitive(): void {
		$headers = array(
			'x-datamachine-caller-site'  => 'chubes.net',
			'X-DATAMACHINE-CHAIN-ID'     => 'mixed-case',
			'x-datamachine-chain-depth'  => '3',
		);

		$ctx = CallerContext::fromRequest( $headers );

		$this->assertSame( 'chubes.net', $ctx->callerSite() );
		$this->assertSame( 'mixed-case', $ctx->chainId() );
		$this->assertSame( 3, $ctx->chainDepth() );
	}

	public function test_is_cross_site_requires_both_depth_and_site(): void {
		// Depth without site — not cross-site.
		$a = new CallerContext( '', 'some-agent', 'cid', 2 );
		$this->assertFalse( $a->isCrossSite() );

		// Site without depth — not cross-site (depth 0 = top of chain).
		$b = new CallerContext( 'chubes.net', 'some-agent', 'cid', 0 );
		$this->assertFalse( $b->isCrossSite() );

		// Both — cross-site.
		$c = new CallerContext( 'chubes.net', 'some-agent', 'cid', 1 );
		$this->assertTrue( $c->isCrossSite() );
	}

	public function test_for_outbound_fresh_chain(): void {
		$ctx = CallerContext::forOutbound( 'intelligence-chubes4.local', 'franklin' );

		$this->assertSame( 'intelligence-chubes4.local', $ctx->callerSite() );
		$this->assertSame( 'franklin', $ctx->callerAgent() );
		$this->assertNotEmpty( $ctx->chainId(), 'Fresh chain generates a UUID' );
		$this->assertSame( 1, $ctx->chainDepth(), 'Top-of-chain outbound is depth 1' );
	}

	public function test_for_outbound_propagates_inbound_chain(): void {
		$inbound = new CallerContext( 'chubes.net', 'chubes-bot', 'original-chain', 2 );
		$ctx     = CallerContext::forOutbound( 'intelligence-chubes4.local', 'franklin', $inbound );

		$this->assertSame( 'original-chain', $ctx->chainId(), 'Chain ID propagates from inbound' );
		$this->assertSame( 3, $ctx->chainDepth(), 'Depth increments by 1 for each hop' );
		$this->assertSame( 'intelligence-chubes4.local', $ctx->callerSite(), 'Site reflects current site, not inbound' );
		$this->assertSame( 'franklin', $ctx->callerAgent(), 'Agent reflects current site, not inbound' );
	}

	public function test_to_outbound_headers_includes_required_fields(): void {
		$ctx     = new CallerContext( 'chubes.net', 'franklin', 'cid-1', 2 );
		$headers = $ctx->toOutboundHeaders();

		$this->assertSame( 'chubes.net', $headers[ CallerContext::HEADER_CALLER_SITE ] );
		$this->assertSame( 'franklin', $headers[ CallerContext::HEADER_CALLER_AGENT ] );
		$this->assertSame( 'cid-1', $headers[ CallerContext::HEADER_CHAIN_ID ] );
		$this->assertSame( '2', $headers[ CallerContext::HEADER_CHAIN_DEPTH ] );
	}

	public function test_to_outbound_headers_omits_empty_identity_fields(): void {
		$ctx     = new CallerContext( '', '', 'cid-1', 0 );
		$headers = $ctx->toOutboundHeaders();

		$this->assertArrayNotHasKey( CallerContext::HEADER_CALLER_SITE, $headers );
		$this->assertArrayNotHasKey( CallerContext::HEADER_CALLER_AGENT, $headers );
		$this->assertArrayHasKey( CallerContext::HEADER_CHAIN_ID, $headers );
		$this->assertArrayHasKey( CallerContext::HEADER_CHAIN_DEPTH, $headers );
	}

	public function test_to_log_context_shape(): void {
		$ctx = new CallerContext( 'chubes.net', 'franklin', 'cid-1', 2 );
		$log = $ctx->toLogContext();

		$this->assertSame( 'chubes.net', $log['caller_site'] );
		$this->assertSame( 'franklin', $log['caller_agent'] );
		$this->assertSame( 'cid-1', $log['chain_id'] );
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
