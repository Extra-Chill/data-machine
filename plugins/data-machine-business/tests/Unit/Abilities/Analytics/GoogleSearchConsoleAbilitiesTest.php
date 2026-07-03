<?php
/**
 * Unit tests for GoogleSearchConsoleAbilities subsite auto-scoping.
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
}
