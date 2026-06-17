<?php
/**
 * PluginSettings Tests
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\PluginSettings;
use WP_UnitTestCase;

class PluginSettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'datamachine_settings' );
		PluginSettings::clearCache();
	}

	public function tear_down(): void {
		delete_option( 'datamachine_settings' );
		PluginSettings::clearCache();
		parent::tear_down();
	}

	public function test_update_merges_patch_and_clears_cache(): void {
		update_option( 'datamachine_settings', array( 'default_provider' => 'openai' ) );
		$this->assertSame( 'openai', PluginSettings::get( 'default_provider' ) );

		$updated = PluginSettings::update( array( 'default_model' => 'gpt-4o' ) );

		$this->assertTrue( $updated );
		$this->assertSame( 'gpt-4o', PluginSettings::get( 'default_model' ) );
		$this->assertSame(
			array(
				'default_provider' => 'openai',
				'default_model'    => 'gpt-4o',
			),
			get_option( 'datamachine_settings', array() )
		);
	}
}
