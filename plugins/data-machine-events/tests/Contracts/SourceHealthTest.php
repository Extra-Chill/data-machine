<?php
namespace DataMachineEvents\Tests\Contracts;

use DataMachineEvents\Core\SourceHealth;
use PHPUnit\Framework\TestCase;

final class SourceHealthTest extends TestCase {
	private function engine( int $items, int $skipped = 0, array $counts = array() ): array {
		return array( 'step_results' => array( 'fetch' => array( 'fetch_stages' => array( 'source_materialized' => $items, 'processed_skipped' => $skipped ) ) ), 'run_metrics' => array( 'counts' => $counts ) );
	}

	public function test_quiet_dark_never_and_healthy(): void {
		$this->assertSame( 'quiet', SourceHealth::classify( array( 'upcoming_count' => 2 ) )['status'] );
		$this->assertSame( 'dark', SourceHealth::classify( array( 'lifetime_count' => 2 ) )['status'] );
		$this->assertSame( 'never_produced', SourceHealth::classify( array() )['status'] );
		$this->assertSame( 'healthy', SourceHealth::classify( array( 'with_items' => 2 ) )['status'] );
		$this->assertSame( 'failing', SourceHealth::classify( array( 'failed' => 2 ) )['status'] );
	}

	public function test_telemetry_refinement(): void {
		$api = SourceHealth::classify( array( 'handlers' => 'dice_fm' ), $this->engine( 0 ) );
		$this->assertSame( 'empty_source', $api['status'] );
		$this->assertFalse( $api['needs_attention'] );
		$this->assertTrue( SourceHealth::classify( array( 'handlers' => 'universal_web_scraper' ), $this->engine( 0 ) )['needs_attention'] );
		$this->assertSame( 'saturated', SourceHealth::classify( array(), $this->engine( 3, 3 ) )['status'] );
		foreach ( array( 'source_rejected', 'item_deferred' ) as $count ) {
			$engine = $this->engine( 1, 0, array( $count => 1 ) );
			$engine['source_rejection']['reason'] = 'missing start time and year';
			$result = SourceHealth::classify( array(), $engine );
			$this->assertSame( 'extraction_incomplete', $result['status'] );
			$this->assertSame( 'missing start time and year', $result['rejection_reason'] );
			$this->assertTrue( $result['needs_attention'] );
		}
	}

	public function test_only_explicit_all_child_content_rejections_are_filtered(): void {
		$engine = $this->engine( 2, 0, array( 'source_rejected' => 2 ) );
		$children = array( array( 'status' => 'completed_no_items:source-rejected', 'reason' => 'non-music event: cocktail competition' ) );
		$this->assertSame( 'filtered_non_music', SourceHealth::classify( array(), $engine, $children )['status'] );
		$children[] = array( 'status' => 'source_rejected', 'reason' => 'not a music event: surf film and art festival' );
		$this->assertSame( 'filtered_non_music', SourceHealth::classify( array(), $engine, $children )['status'] );
		$children[] = array( 'status' => 'source_rejected', 'reason' => 'missing date' );
		$this->assertSame( 'extraction_incomplete', SourceHealth::classify( array(), $engine, $children )['status'] );
	}

	public function test_root_only_non_music_rejection_is_filtered_not_incomplete(): void {
		$engine = $this->engine( 1, 0, array( 'source_rejected' => 1 ) );
		$engine['step_results']['fetch']['source_rejection_reason'] = 'non-music event: author meet-and-greet';
		$this->assertSame( 'filtered_non_music', SourceHealth::classify( array(), $engine )['status'] );
		$engine['step_results']['fetch']['source_rejection_reason'] = 'no start time and missing year';
		$this->assertSame( 'extraction_incomplete', SourceHealth::classify( array(), $engine )['status'] );
	}

	public function test_rejection_reason_ignores_lifecycle_tokens(): void {
		$engine = $this->engine( 1, 0, array( 'source_rejected' => 1 ) );
		$engine['step_results'] = array(
			'fetch' => array( 'reason' => 'completed', 'source_rejection_reason' => 'missing start time and year', 'fetch_stages' => array( 'source_materialized' => 1 ) ),
			'ai'    => array( 'reason' => 'completed', 'diagnostic_reason' => '' ),
		);
		$this->assertSame( 'missing start time and year', SourceHealth::classify( array(), $engine )['rejection_reason'] );

		$deferred = $this->engine( 1, 0, array( 'item_deferred' => 1 ) );
		$deferred['step_results']['ai'] = array( 'reason' => 'step_exception' );
		$deferred['tool_execution_summary'] = array( array( 'trace' => array( 'arguments_redacted' => array( 'reason' => 'no event date' ) ) ) );
		$this->assertSame( 'no event date', SourceHealth::classify( array(), $deferred )['rejection_reason'] );
	}

	/** @dataProvider exceptions */
	public function test_failures( string $message, string $class, string $bucket ): void {
		$this->assertSame( $class, SourceHealth::failure_class( $message ) );
		$result = SourceHealth::classify( array( 'latest_status' => 'failed', 'exception' => $message ), $this->engine( 0 ) );
		$this->assertSame( $bucket, $result['status'] );
		$this->assertTrue( $result['needs_attention'] );
	}

	public static function exceptions(): array {
		return array(
			array( 'Universal Web Scraper GET returned HTTP 403', 'http_403', 'blocked' ),
			array( 'GET returned HTTP 404', 'http_404', 'broken_url' ),
			array( 'HTTP 410 Gone', 'http_410', 'broken_url' ),
			array( 'HTTP 409', 'http_4xx', 'failing' ),
			array( 'HTTP 503', 'http_5xx', 'failing' ),
			array( 'cURL error 28: Operation timed out', 'network', 'failing' ),
			array( 'connection timeout', 'network', 'failing' ),
			array( 'Unknown extraction error', 'other', 'failing' ),
		);
	}

	public function test_summary_filter_and_priority(): void {
		$rows = array();
		foreach ( array( 'healthy', 'quiet', 'dark', 'blocked', 'extraction_incomplete' ) as $id => $status ) {
			$rows[] = array( 'flow_id' => $id + 1, 'status' => $status );
		}
		$result = SourceHealth::result( $rows );
		$this->assertSame( array( 'blocked', 'extraction_incomplete', 'dark', 'quiet', 'healthy' ), array_column( $result['flows'], 'status' ) );
		$filtered = SourceHealth::result( $rows, array( 'dark', 'blocked' ) );
		$this->assertCount( 2, $filtered['flows'] );
		$this->assertSame( $result['summary'], $filtered['summary'] );
	}
}
