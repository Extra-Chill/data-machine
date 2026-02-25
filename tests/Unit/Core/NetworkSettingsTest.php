<?php
/**
 * NetworkSettings Tests
 *
 * Tests for network-level settings storage and the resolve cascade.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\NetworkSettings;
use DataMachine\Core\PluginSettings;
use WP_UnitTestCase;

class NetworkSettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_site_option( NetworkSettings::OPTION_NAME );
		delete_option( 'datamachine_settings' );
		NetworkSettings::clearCache();
		PluginSettings::clearCache();
	}

	public function tear_down(): void {
		delete_site_option( NetworkSettings::OPTION_NAME );
		delete_option( 'datamachine_settings' );
		NetworkSettings::clearCache();
		PluginSettings::clearCache();
		parent::tear_down();
	}

	// --- NetworkSettings basic CRUD ---

	public function test_all_returns_empty_array_when_no_data(): void {
		$this->assertSame( array(), NetworkSettings::all() );
	}

	public function test_get_returns_default_when_no_data(): void {
		$this->assertSame( 'fallback', NetworkSettings::get( 'default_provider', 'fallback' ) );
	}

	public function test_get_rejects_non_network_keys(): void {
		update_site_option( NetworkSettings::OPTION_NAME, array( 'disabled_tools' => array( 'foo' ) ) );
		NetworkSettings::clearCache();

		$this->assertNull( NetworkSettings::get( 'disabled_tools' ) );
	}

	public function test_update_stores_network_keys(): void {
		NetworkSettings::update( array(
			'default_provider' => 'anthropic',
			'default_model'    => 'claude-sonnet-4-20250514',
		) );

		NetworkSettings::clearCache();

		$this->assertSame( 'anthropic', NetworkSettings::get( 'default_provider' ) );
		$this->assertSame( 'claude-sonnet-4-20250514', NetworkSettings::get( 'default_model' ) );
	}

	public function test_update_ignores_non_network_keys(): void {
		NetworkSettings::update( array(
			'default_provider'  => 'openai',
			'site_context_enabled' => true, // not a network key
		) );

		NetworkSettings::clearCache();

		$this->assertSame( 'openai', NetworkSettings::get( 'default_provider' ) );
		$this->assertNull( NetworkSettings::get( 'site_context_enabled' ) );
	}

	public function test_update_merges_with_existing(): void {
		NetworkSettings::update( array( 'default_provider' => 'openai' ) );
		NetworkSettings::update( array( 'default_model' => 'gpt-4o' ) );

		NetworkSettings::clearCache();

		$this->assertSame( 'openai', NetworkSettings::get( 'default_provider' ) );
		$this->assertSame( 'gpt-4o', NetworkSettings::get( 'default_model' ) );
	}

	public function test_update_returns_false_when_no_valid_keys(): void {
		$result = NetworkSettings::update( array( 'bogus_key' => 'value' ) );

		$this->assertFalse( $result );
	}

	public function test_is_network_key(): void {
		$this->assertTrue( NetworkSettings::isNetworkKey( 'default_provider' ) );
		$this->assertTrue( NetworkSettings::isNetworkKey( 'default_model' ) );
		$this->assertTrue( NetworkSettings::isNetworkKey( 'agent_models' ) );
		$this->assertFalse( NetworkSettings::isNetworkKey( 'disabled_tools' ) );
		$this->assertFalse( NetworkSettings::isNetworkKey( 'site_context_enabled' ) );
	}

	public function test_data_stored_via_site_option(): void {
		NetworkSettings::update( array( 'default_provider' => 'anthropic' ) );

		$raw = get_site_option( NetworkSettings::OPTION_NAME, array() );

		$this->assertArrayHasKey( 'default_provider', $raw );
		$this->assertSame( 'anthropic', $raw['default_provider'] );
	}

	public function test_agent_models_stored_at_network_level(): void {
		$agent_models = array(
			'chat'     => array( 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514' ),
			'pipeline' => array( 'provider' => 'openai', 'model' => 'gpt-4o-mini' ),
		);

		NetworkSettings::update( array( 'agent_models' => $agent_models ) );
		NetworkSettings::clearCache();

		$this->assertSame( $agent_models, NetworkSettings::get( 'agent_models' ) );
	}

	// --- PluginSettings::resolve() cascade ---

	public function test_resolve_returns_site_value_when_set(): void {
		update_option( 'datamachine_settings', array( 'default_provider' => 'openai' ) );
		PluginSettings::clearCache();

		NetworkSettings::update( array( 'default_provider' => 'anthropic' ) );

		$this->assertSame( 'openai', PluginSettings::resolve( 'default_provider' ) );
	}

	public function test_resolve_falls_back_to_network_when_site_empty(): void {
		update_option( 'datamachine_settings', array( 'default_provider' => '' ) );
		PluginSettings::clearCache();

		NetworkSettings::update( array( 'default_provider' => 'anthropic' ) );

		$this->assertSame( 'anthropic', PluginSettings::resolve( 'default_provider' ) );
	}

	public function test_resolve_falls_back_to_network_when_site_unset(): void {
		update_option( 'datamachine_settings', array() );
		PluginSettings::clearCache();

		NetworkSettings::update( array( 'default_provider' => 'gemini' ) );

		$this->assertSame( 'gemini', PluginSettings::resolve( 'default_provider' ) );
	}

	public function test_resolve_returns_default_when_both_empty(): void {
		update_option( 'datamachine_settings', array() );
		PluginSettings::clearCache();

		$this->assertSame( 'none', PluginSettings::resolve( 'default_provider', 'none' ) );
	}

	public function test_resolve_non_network_key_skips_cascade(): void {
		update_option( 'datamachine_settings', array() );
		PluginSettings::clearCache();

		// Even if somehow stored, resolve shouldn't look at network for non-network keys.
		$this->assertSame( 12, PluginSettings::resolve( 'max_turns', 12 ) );
	}

	// --- getAgentModel() cascade ---

	public function test_get_agent_model_site_override_wins(): void {
		update_option( 'datamachine_settings', array(
			'agent_models' => array(
				'chat' => array( 'provider' => 'openai', 'model' => 'gpt-4o' ),
			),
		) );
		PluginSettings::clearCache();

		NetworkSettings::update( array(
			'agent_models' => array(
				'chat' => array( 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514' ),
			),
		) );

		$result = PluginSettings::getAgentModel( 'chat' );

		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'gpt-4o', $result['model'] );
	}

	public function test_get_agent_model_falls_back_to_network_agent_models(): void {
		update_option( 'datamachine_settings', array() );
		PluginSettings::clearCache();

		NetworkSettings::update( array(
			'agent_models' => array(
				'pipeline' => array( 'provider' => 'openai', 'model' => 'gpt-4o-mini' ),
			),
		) );

		$result = PluginSettings::getAgentModel( 'pipeline' );

		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'gpt-4o-mini', $result['model'] );
	}

	public function test_get_agent_model_falls_back_to_network_global_defaults(): void {
		update_option( 'datamachine_settings', array() );
		PluginSettings::clearCache();

		NetworkSettings::update( array(
			'default_provider' => 'anthropic',
			'default_model'    => 'claude-sonnet-4-20250514',
		) );

		$result = PluginSettings::getAgentModel( 'system' );

		$this->assertSame( 'anthropic', $result['provider'] );
		$this->assertSame( 'claude-sonnet-4-20250514', $result['model'] );
	}

	public function test_get_agent_model_returns_empty_when_nothing_configured(): void {
		update_option( 'datamachine_settings', array() );
		PluginSettings::clearCache();

		$result = PluginSettings::getAgentModel( 'chat' );

		$this->assertSame( '', $result['provider'] );
		$this->assertSame( '', $result['model'] );
	}

	public function test_get_agent_model_mixed_cascade(): void {
		// Site has provider override for chat, but no model.
		// Network has model for chat.
		update_option( 'datamachine_settings', array(
			'agent_models' => array(
				'chat' => array( 'provider' => 'openai', 'model' => '' ),
			),
		) );
		PluginSettings::clearCache();

		NetworkSettings::update( array(
			'agent_models' => array(
				'chat' => array( 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514' ),
			),
		) );

		$result = PluginSettings::getAgentModel( 'chat' );

		// Provider from site, model from network.
		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'claude-sonnet-4-20250514', $result['model'] );
	}
}
