<?php
/**
 * Unit tests for GoogleSearchConsoleAbilities request construction and scoping.
 *
 * Covers the enhancement where the datamachine/google-search-console ability
 * always queried the configured sc-domain: property and returned the whole
 * domain rollup regardless of which subsite it ran on. When neither an explicit
 * site_url nor url_filter is supplied and the current blog is a genuine
 * subdomain of the configured domain property, the ability now auto-applies a
 * url_filter of that subsite's URL prefix so page-level results are scoped to
 * the subsite. The main site (host equals the domain property) and URL-prefix
 * properties are left unscoped so the rollup behavior is preserved.
 *
 * @package DataMachine\Tests\Unit\Abilities\Analytics
 */

namespace DataMachine\Tests\Unit\Abilities\Analytics;

use DataMachineBusiness\Abilities\Analytics\GoogleSearchConsoleAbilities;
use WP_UnitTestCase;

class GoogleSearchConsoleAbilitiesTest extends WP_UnitTestCase {

	public function test_builds_default_search_analytics_request_body(): void {
		$this->assertSame(
			array(
				'startDate'  => '2026-06-01',
				'endDate'    => '2026-06-30',
				'dimensions' => array( 'query' ),
				'rowLimit'   => 25,
				'dataState'  => 'final',
				'type'       => 'web',
			),
			$this->build_request( array() )
		);
	}

	public function test_builds_exact_segmented_search_analytics_request_body(): void {
		$this->assertSame(
			array(
				'startDate'            => '2026-06-01',
				'endDate'              => '2026-06-30',
				'dimensions'           => array( 'query', 'page' ),
				'rowLimit'             => 100,
				'dataState'            => 'final',
				'type'                 => 'googleNews',
				'dimensionFilterGroups' => array(
					array(
						'groupType' => 'and',
						'filters'   => array(
							array(
								'dimension'  => 'page',
								'operator'   => 'contains',
								'expression' => '/music/',
							),
							array(
								'dimension'  => 'query',
								'operator'   => 'contains',
								'expression' => 'festival',
							),
							array(
								'dimension'  => 'country',
								'operator'   => 'equals',
								'expression' => 'usa',
							),
							array(
								'dimension'  => 'device',
								'operator'   => 'equals',
								'expression' => 'MOBILE',
							),
							array(
								'dimension'  => 'searchAppearance',
								'operator'   => 'equals',
								'expression' => 'AMP_BLUE_LINK',
							),
						),
					),
				),
			),
			$this->build_request(
				array(
					'search_type'       => 'googleNews',
					'url_filter'        => '/music/',
					'query_filter'      => 'festival',
					'country'           => 'USA',
					'device'            => 'mobile',
					'search_appearance' => 'amp_blue_link',
				),
				array( 'query', 'page' ),
				100
			)
		);
	}

	/**
	 * @dataProvider invalid_segmentation_provider
	 */
	public function test_rejects_invalid_segmentation_input( array $input, string $error_code ): void {
		$result = $this->build_request( $input );

		$this->assertWPError( $result );
		$this->assertSame( $error_code, $result->get_error_code() );
	}

	public static function invalid_segmentation_provider(): array {
		return array(
			'invalid search type'       => array( array( 'search_type' => 'all' ), 'invalid_gsc_search_type' ),
			'invalid country'           => array( array( 'country' => 'US' ), 'invalid_gsc_country' ),
			'invalid device'            => array( array( 'device' => 'TV' ), 'invalid_gsc_device' ),
			'invalid search appearance' => array( array( 'search_appearance' => 'AMP BLUE LINK' ), 'invalid_gsc_search_appearance' ),
		);
	}

	/**
	 * A genuine subdomain of the configured sc-domain: property gets scoped to
	 * its own trailing-slashed URL prefix.
	 */
	public function test_subdomain_gets_scoped_to_its_url_prefix(): void {
		$prefix = GoogleSearchConsoleAbilities::computeSubsiteUrlPrefix(
			'sc-domain:extrachill.com',
			'https://studio.extrachill.com'
		);

		$this->assertSame( 'https://studio.extrachill.com/', $prefix );
	}

	/**
	 * The main site (host equals the domain-property host) is NOT scoped so the
	 * whole-domain rollup is preserved.
	 */
	public function test_main_site_is_not_scoped(): void {
		$this->assertSame(
			'',
			GoogleSearchConsoleAbilities::computeSubsiteUrlPrefix(
				'sc-domain:extrachill.com',
				'https://extrachill.com'
			)
		);
	}

	/**
	 * A www.-prefixed main site host still counts as the property root, not a
	 * subdomain — no scoping.
	 */
	public function test_www_main_site_is_not_scoped(): void {
		$this->assertSame(
			'',
			GoogleSearchConsoleAbilities::computeSubsiteUrlPrefix(
				'sc-domain:extrachill.com',
				'https://www.extrachill.com'
			)
		);
	}

	/**
	 * A URL-prefix property (https://...) already scopes itself — no page filter
	 * is derived even from a subdomain-looking home URL.
	 */
	public function test_url_prefix_property_is_never_scoped(): void {
		$this->assertSame(
			'',
			GoogleSearchConsoleAbilities::computeSubsiteUrlPrefix(
				'https://extrachill.com/',
				'https://studio.extrachill.com'
			)
		);
	}

	/**
	 * An unrelated host that merely ends with the domain string but is not a
	 * dot-delimited subdomain must not be scoped (guards against a naive
	 * suffix match, e.g. notextrachill.com).
	 */
	public function test_lookalike_host_is_not_scoped(): void {
		$this->assertSame(
			'',
			GoogleSearchConsoleAbilities::computeSubsiteUrlPrefix(
				'sc-domain:extrachill.com',
				'https://notextrachill.com'
			)
		);
	}

	/**
	 * A completely different domain is never scoped.
	 */
	public function test_foreign_domain_is_not_scoped(): void {
		$this->assertSame(
			'',
			GoogleSearchConsoleAbilities::computeSubsiteUrlPrefix(
				'sc-domain:extrachill.com',
				'https://example.com'
			)
		);
	}

	/**
	 * An empty sc-domain host yields no scoping (defensive).
	 */
	public function test_empty_domain_host_is_not_scoped(): void {
		$this->assertSame(
			'',
			GoogleSearchConsoleAbilities::computeSubsiteUrlPrefix(
				'sc-domain:',
				'https://studio.extrachill.com'
			)
		);
	}

	/**
	 * Host comparison is case-insensitive.
	 */
	public function test_scoping_is_case_insensitive(): void {
		$this->assertSame(
			'https://Studio.ExtraChill.com/',
			GoogleSearchConsoleAbilities::computeSubsiteUrlPrefix(
				'sc-domain:ExtraChill.com',
				'https://Studio.ExtraChill.com'
			)
		);
	}

	/**
	 * A deeper multi-label subdomain is still a genuine subdomain and gets
	 * scoped to its own prefix.
	 */
	public function test_deep_subdomain_is_scoped(): void {
		$this->assertSame(
			'https://a.b.extrachill.com/',
			GoogleSearchConsoleAbilities::computeSubsiteUrlPrefix(
				'sc-domain:extrachill.com',
				'https://a.b.extrachill.com'
			)
		);
	}

	/**
	 * Invoke the private request builder to verify the exact Google API payload.
	 *
	 * @return array|\WP_Error
	 */
	private function build_request( array $input, array $dimensions = array( 'query' ), int $limit = 25 ) {
		$method = new \ReflectionMethod( GoogleSearchConsoleAbilities::class, 'buildSearchAnalyticsRequest' );
		$method->setAccessible( true );

		return $method->invoke( null, $input, '2026-06-01', '2026-06-30', $dimensions, $limit );
	}
}
