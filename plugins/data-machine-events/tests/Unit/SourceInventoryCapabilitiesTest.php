<?php
/**
 * Source inventory capability tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\EventFlyer\EventFlyer;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use WP_UnitTestCase;

class SourceInventoryCapabilitiesTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		new Ticketmaster();
		new EventFlyer();
		new UniversalWebScraper();
	}

	public function test_ticketmaster_source_reports_counted_search_capabilities(): void {
		$capabilities = apply_filters(
			'datamachine_source_inventory_capabilities',
			array(),
			array(
				'kind'     => 'event_import',
				'provider' => 'ticketmaster',
			)
		);

		$this->assertTrue( $capabilities['stable_ids'] );
		$this->assertTrue( $capabilities['has_total_count'] );
		$this->assertTrue( $capabilities['supports_time_windows'] );
		$this->assertSame( 20, $capabilities['max_pages'] );
	}

	public function test_event_flyer_source_reports_inventory_capabilities(): void {
		$capabilities = apply_filters(
			'datamachine_source_inventory_capabilities',
			array(),
			array(
				'handler_type' => 'event_flyer',
			)
		);

		$this->assertTrue( $capabilities['can_enumerate'] );
		$this->assertTrue( $capabilities['stable_ids'] );
		$this->assertSame( 'uploaded_files', $capabilities['inventory_source'] );
	}

	public function test_existing_source_capability_overrides_default(): void {
		$capabilities = apply_filters(
			'datamachine_source_inventory_capabilities',
			array( 'max_pages' => 5 ),
			array( 'provider' => 'universal-web-scraper' )
		);

		$this->assertTrue( $capabilities['supports_pagination'] );
		$this->assertSame( 5, $capabilities['max_pages'] );
	}

	public function test_unknown_source_is_unchanged(): void {
		$capabilities = apply_filters(
			'datamachine_source_inventory_capabilities',
			array( 'stable_ids' => false ),
			array( 'provider' => 'unknown' )
		);

		$this->assertSame( array( 'stable_ids' => false ), $capabilities );
	}
}
