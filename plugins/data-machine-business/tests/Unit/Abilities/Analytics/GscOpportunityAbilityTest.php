<?php
/**
 * Unit tests for GscOpportunityAbility's deterministic transformations.
 *
 * @package DataMachine\Tests\Unit\Abilities\Analytics
 */

namespace DataMachine\Tests\Unit\Abilities\Analytics;

use DataMachineBusiness\Abilities\Analytics\GscOpportunityAbility;
use WP_UnitTestCase;

class GscOpportunityAbilityTest extends WP_UnitTestCase {

	public function test_definition_box_classifier_preserves_song_intent(): void {
		$expected_ctr = GscOpportunityAbility::expected_ctr( 3.8 );

		$this->assertTrue( GscOpportunityAbility::is_definition_box( 'diggity meaning', 3.8, 0.00005, $expected_ctr, 5.0 ) );
		$this->assertFalse( GscOpportunityAbility::is_definition_box( 'no diggity meaning', 3.8, 0.02, $expected_ctr, 5.0 ) );
		$this->assertFalse( GscOpportunityAbility::is_definition_box( 'no diggity lyrics meaning', 3.8, 0.0005, $expected_ctr, 5.0 ) );
	}

	public function test_captured_bucket_is_independently_sorted_and_limited(): void {
		$actionable = GscOpportunityAbility::sort_and_limit(
			array(
				array(
					'target'             => 'small gap',
					'recoverable_clicks' => 10,
				),
				array(
					'target'             => 'large gap',
					'recoverable_clicks' => 100,
				),
			),
			'recoverable_clicks',
			1
		);
		$captured   = GscOpportunityAbility::sort_and_limit(
			array(
				array(
					'target'             => 'minor meaning',
					'impressions'        => 100,
					'recoverable_clicks' => 0,
				),
				array(
					'target'             => 'diggity meaning',
					'impressions'        => 354000,
					'recoverable_clicks' => 0,
				),
			),
			'impressions',
			1
		);

		$this->assertSame( 'large gap', $actionable[0]['target'] );
		$this->assertSame( 'diggity meaning', $captured[0]['target'] );
		$this->assertNotContains( 'diggity meaning', array_column( $actionable, 'target' ) );
	}

	public function test_captured_query_impressions_qualify_page_aggregate(): void {
		$page = 'https://extrachill.com/the-meaning-of-blackstreets-no-diggity/';
		$map  = GscOpportunityAbility::captured_impressions_by_page(
			array(
				array(
					'keys'        => array( 'diggity meaning', $page ),
					'impressions' => 340000,
					'ctr'         => 0.00005,
					'position'    => 3.8,
				),
				array(
					'keys'        => array( 'no diggity meaning', $page ),
					'impressions' => 14588,
					'ctr'         => 0.2427,
					'position'    => 3.8,
				),
			)
		);

		$this->assertSame( 340000, $map[ $page ] );
		$this->assertSame( 14588, 354588 - $map[ $page ] );
		$this->assertLessThan( 5000, (int) round( ( 354588 - $map[ $page ] ) * ( 0.10 - 0.0004 ) ) );
	}

	public function test_normal_page_has_no_captured_adjustment(): void {
		$page = 'https://extrachill.com/normal-article/';
		$map  = GscOpportunityAbility::captured_impressions_by_page(
			array(
				array(
					'keys'        => array( 'normal article', $page ),
					'impressions' => 10000,
					'ctr'         => 0.01,
					'position'    => 3.0,
				),
			)
		);

		$this->assertArrayNotHasKey( $page, $map );
		$this->assertSame( 900, (int) round( 10000 * ( 0.10 - 0.01 ) ) );
	}
}
