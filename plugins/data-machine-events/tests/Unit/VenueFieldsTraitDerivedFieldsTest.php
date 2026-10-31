<?php
/**
 * VenueFieldsTrait derived_fields() contract.
 *
 * Data Machine's sparse-patch write path keeps sanitizer-derived keys only
 * when the settings class declares them (data-machine #3449). Every settings
 * class using VenueFieldsTrait must therefore expose `venue` as derived.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\SingleRecurring\SingleRecurringSettings;
use DataMachineEvents\Steps\EventImport\Handlers\EventFlyer\EventFlyerSettings;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraperSettings;

class VenueFieldsTraitDerivedFieldsTest extends WP_UnitTestCase {

	/**
	 * @return array<string, array{0: class-string}>
	 */
	public function venue_settings_classes(): array {
		return array(
			'single_recurring'      => array( SingleRecurringSettings::class ),
			'event_flyer'           => array( EventFlyerSettings::class ),
			'universal_web_scraper' => array( UniversalWebScraperSettings::class ),
		);
	}

	/**
	 * @dataProvider venue_settings_classes
	 */
	public function test_settings_class_declares_venue_as_derived( string $class ): void {
		$this->assertTrue( method_exists( $class, 'derived_fields' ), "$class must expose derived_fields()" );
		$this->assertContains( 'venue', $class::derived_fields() );
	}

	public function test_derived_fields_are_declared_config_fields(): void {
		foreach ( SingleRecurringSettings::derived_fields() as $key ) {
			$this->assertArrayHasKey( $key, SingleRecurringSettings::get_defaults(), "Derived key '$key' must also be a declared config field or applyDefaults() will drop it" );
		}
	}
}
