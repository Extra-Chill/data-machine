<?php
/**
 * Inserted-link marker and cumulative link record (#3954).
 *
 * @package DataMachine\Tests\Unit\Engine\AI\System\Tasks
 */

namespace DataMachine\Tests\Unit\Engine\AI\System\Tasks;

use DataMachine\Engine\AI\System\Tasks\InternalLinkingTask;
use WP_UnitTestCase;

final class InternalLinkingMarkerTest extends WP_UnitTestCase {

	public function test_marks_only_the_inserted_anchor(): void {
		$html = '<p>See <a href="https://example.com/a/">A</a> and <a class="x" href="https://example.com/b/">B</a>.</p>';
		$out  = InternalLinkingTask::markInsertedLink( $html, 'https://example.com/b/' );

		$this->assertStringContainsString( '<a href="https://example.com/a/">A</a>', $out );
		$this->assertStringContainsString( 'data-dm-link="internal"', $out );
		$this->assertSame( 1, substr_count( $out, 'data-dm-link' ) );

		$processor = new \WP_HTML_Tag_Processor( $out );
		$processor->next_tag( 'a' );
		$this->assertNull( $processor->get_attribute( 'data-dm-link' ) );
		$processor->next_tag( 'a' );
		$this->assertSame( 'internal', $processor->get_attribute( 'data-dm-link' ) );
		$this->assertSame( 'x', $processor->get_attribute( 'class' ) );
	}

	public function test_marker_survives_kses(): void {
		$out = InternalLinkingTask::markInsertedLink( '<p><a href="https://example.com/b/">B</a></p>', 'https://example.com/b/' );
		$this->assertStringContainsString( 'data-dm-link="internal"', wp_kses_post( $out ) );
	}

	public function test_matches_entity_encoded_href(): void {
		$url = 'https://example.com/b/?x=1&y=2';
		$out = InternalLinkingTask::markInsertedLink( '<p><a href="https://example.com/b/?x=1&amp;y=2">B</a></p>', $url );
		$this->assertStringContainsString( 'data-dm-link="internal"', $out );
	}

	public function test_link_record_accumulates_across_passes(): void {
		$first  = InternalLinkingTask::mergeLinkTracking(
			'',
			array(
				array(
					'url'     => 'https://example.com/a/',
					'post_id' => 1,
					'title'   => 'A',
				),
			),
			10,
			'2026-11-01 00:00:00'
		);
		$second = InternalLinkingTask::mergeLinkTracking(
			$first,
			array(
				array(
					'url'     => 'https://example.com/a/',
					'post_id' => 1,
					'title'   => 'A again',
				),
				array(
					'url'     => 'https://example.com/b/',
					'post_id' => 2,
					'title'   => 'B',
				),
			),
			11,
			'2026-11-02 00:00:00'
		);

		$this->assertSame( array( 'https://example.com/a/', 'https://example.com/b/' ), wp_list_pluck( $second['links'], 'url' ) );
		$this->assertSame( 'A', $second['links'][0]['title'] );
		$this->assertSame( 11, $second['job_id'] );
		$this->assertSame( array( 10, 11 ), $second['job_ids'] );
		$this->assertSame( '2026-11-02 00:00:00', $second['processed_at'] );
	}

	public function test_legacy_record_without_job_ids_keeps_its_job(): void {
		$legacy = array(
			'processed_at' => '2026-11-04 20:52:21',
			'links'        => array(
				array(
					'url'     => 'https://example.com/a/',
					'post_id' => 0,
					'title'   => 'A',
				),
			),
			'job_id'       => 1101382,
		);
		$merged = InternalLinkingTask::mergeLinkTracking( $legacy, array(), 5, '2026-11-15 00:00:00' );
		$this->assertCount( 1, $merged['links'] );
		$this->assertSame( array( 1101382, 5 ), $merged['job_ids'] );
	}
}
