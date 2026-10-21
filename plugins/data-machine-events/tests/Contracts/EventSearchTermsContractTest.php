<?php
/**
 * Event search term-name matching contract.
 *
 * @package DataMachineEvents\Tests\Contracts
 */

namespace DataMachineEvents\Tests\Contracts;

use DataMachineEvents\Core\EventSearchTerms;
use PHPUnit\Framework\TestCase;

final class EventSearchTermsContractTest extends TestCase {

	public function test_needle_ignores_case_spacing_and_punctuation(): void {
		$this->assertSame( 'lofi', EventSearchTerms::needle( 'Lo-Fi' ) );
		$this->assertSame( 'lofi', EventSearchTerms::needle( 'lo fi' ) );
		$this->assertSame( 'lofi', EventSearchTerms::needle( 'LoFi' ) );
		$this->assertSame( 'pourhouse', EventSearchTerms::needle( 'Pour House' ) );
		$this->assertSame( 'arobinsonblues', EventSearchTerms::needle( 'A &amp; Robinson Blues' ) );
	}

	/**
	 * @dataProvider name_cases
	 */
	public function test_name_matches_from_a_word_boundary( string $name, string $search, bool $expected ): void {
		$this->assertSame( $expected, EventSearchTerms::nameMatches( $name, EventSearchTerms::needle( $search ) ) );
	}

	public static function name_cases(): array {
		return array(
			'hyphenated venue'           => array( 'Lo-Fi Brewing', 'lofi', true ),
			'spaced search'              => array( 'Lo-Fi Brewing', 'lo fi', true ),
			'full name'                  => array( 'Lo-Fi Brewing', 'Lo-Fi Brewing', true ),
			'later word'                 => array( 'Charleston Pour House', 'pour house', true ),
			'joined word'                => array( 'Hest Lofi', 'lofi', true ),
			'prefix of word'             => array( 'Lofidels', 'lofi', true ),
			'letters across a break'     => array( 'Pablo Fierro', 'lofi', false ),
			'letters inside a word'      => array( 'Festival of India', 'lofi', false ),
			'unrelated'                  => array( 'The Royal American', 'lofi', false ),
			'empty needle never matches' => array( 'Lo-Fi Brewing', '---', false ),
		);
	}

	public function test_like_pattern_is_an_ordered_character_superset(): void {
		$this->assertSame( '%l%o%f%i%', EventSearchTerms::likePattern( 'lofi' ) );
	}

	public function test_widen_search_sql_ors_term_matches_and_keeps_password_guard(): void {
		$search = " AND (((wp_posts.post_title LIKE '{x}lofi{x}') OR (wp_posts.post_content LIKE '{x}lofi{x}'))) "
			. " AND (wp_posts.post_password = '') ";

		$widened = EventSearchTerms::widenSearchSql( $search, array( 7, 9, 7 ), 'wp_posts', 'wp_term_relationships' );

		$this->assertStringContainsString( "OR wp_posts.ID IN ( SELECT object_id FROM wp_term_relationships WHERE term_taxonomy_id IN (7,9) )", $widened );
		$this->assertStringContainsString( "post_title LIKE '{x}lofi{x}'", $widened );
		$this->assertMatchesRegularExpression( "/\) \) +AND \(wp_posts\.post_password = ''\) *$/", $widened );
		$this->assertSame( 1, substr_count( $widened, 'post_password' ) );
	}

	public function test_widen_search_sql_is_a_noop_without_terms(): void {
		$search = " AND ((wp_posts.post_title LIKE 'x')) ";
		$this->assertSame( $search, EventSearchTerms::widenSearchSql( $search, array(), 'wp_posts', 'wp_term_relationships' ) );
		$this->assertSame( '', EventSearchTerms::widenSearchSql( '', array( 3 ), 'wp_posts', 'wp_term_relationships' ) );
	}
}
