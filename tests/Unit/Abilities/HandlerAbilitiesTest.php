<?php
/**
 * HandlerAbilities Tests
 *
 * Tests for handler discovery and configuration abilities,
 * particularly the getConfigFields() base-field merge (#532).
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandlerSettings;
use DataMachine\Core\Steps\Settings\SettingsHandler;
use WP_UnitTestCase;

/**
 * Stub: fetch handler that properly merges common fields.
 */
class StubFetchSettings extends FetchHandlerSettings {
	public static function get_fields(): array {
		return array_merge(
			array(
				'feed_url' => array(
					'type'  => 'url',
					'label' => 'Feed URL',
				),
			),
			parent::get_common_fields()
		);
	}
}

/**
 * Stub: fetch handler that FORGETS to merge common fields (the safety-net case).
 */
class StubForgetfulFetchSettings extends FetchHandlerSettings {
	public static function get_fields(): array {
		return array(
			'api_key' => array(
				'type'  => 'text',
				'label' => 'API Key',
			),
		);
	}
}

/**
 * Stub: publish handler that properly merges common fields.
 */
class StubPublishSettings extends PublishHandlerSettings {
	public static function get_fields(): array {
		return array_merge(
			array(
				'channel_id' => array(
					'type'  => 'text',
					'label' => 'Channel ID',
				),
			),
			parent::get_common_fields()
		);
	}
}

/**
 * Stub: publish handler that FORGETS to merge common fields.
 */
class StubForgetfulPublishSettings extends PublishHandlerSettings {
	public static function get_fields(): array {
		return array(
			'webhook_url' => array(
				'type'  => 'url',
				'label' => 'Webhook URL',
			),
		);
	}
}

/**
 * Stub: non-fetch/non-publish handler (extends SettingsHandler directly).
 * Should NOT receive fetch or publish common fields.
 */
class StubDirectSettings extends SettingsHandler {
	public static function get_fields(): array {
		return array(
			'task_type' => array(
				'type'  => 'select',
				'label' => 'Task Type',
			),
		);
	}
}

class HandlerAbilitiesTest extends WP_UnitTestCase {

	private HandlerAbilities $handler_abilities;

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Clear caches between tests so filter additions take effect.
		HandlerAbilities::clearCache();

		$this->handler_abilities = new HandlerAbilities();
	}

	public function tear_down(): void {
		// Remove all test filters to avoid cross-test contamination.
		remove_all_filters( 'datamachine_handler_settings' );
		HandlerAbilities::clearCache();

		parent::tear_down();
	}

	/**
	 * Helper: register a stub handler settings class on the filter.
	 */
	private function register_stub_handler( string $slug, string $settings_class ): void {
		add_filter(
			'datamachine_handler_settings',
			function ( $all_settings, $handler_slug = null ) use ( $slug, $settings_class ) {
				if ( null === $handler_slug || $handler_slug === $slug ) {
					$all_settings[ $slug ] = new $settings_class();
				}
				return $all_settings;
			},
			10,
			2
		);
	}

	// ---------------------------------------------------------------
	// Fetch handler: proper merge
	// ---------------------------------------------------------------

	public function test_fetch_handler_includes_handler_specific_fields(): void {
		$this->register_stub_handler( 'stub-fetch', StubFetchSettings::class );

		$fields = $this->handler_abilities->getConfigFields( 'stub-fetch' );

		$this->assertArrayHasKey( 'feed_url', $fields );
	}

	public function test_fetch_handler_includes_common_fields(): void {
		$this->register_stub_handler( 'stub-fetch', StubFetchSettings::class );

		$fields = $this->handler_abilities->getConfigFields( 'stub-fetch' );

		$this->assertArrayHasKey( 'max_items', $fields );
		$this->assertArrayHasKey( 'search', $fields );
		$this->assertArrayHasKey( 'exclude_keywords', $fields );
		$this->assertArrayHasKey( 'timeframe_limit', $fields );
	}

	// ---------------------------------------------------------------
	// Fetch handler: safety net (forgot to call parent::get_common_fields)
	// ---------------------------------------------------------------

	public function test_forgetful_fetch_handler_still_gets_common_fields(): void {
		$this->register_stub_handler( 'stub-forgetful-fetch', StubForgetfulFetchSettings::class );

		$fields = $this->handler_abilities->getConfigFields( 'stub-forgetful-fetch' );

		// Handler-specific field is present.
		$this->assertArrayHasKey( 'api_key', $fields );

		// Base common fields are merged in by the safety net.
		$this->assertArrayHasKey( 'max_items', $fields, 'max_items should be merged via safety net' );
		$this->assertArrayHasKey( 'search', $fields, 'search should be merged via safety net' );
		$this->assertArrayHasKey( 'exclude_keywords', $fields, 'exclude_keywords should be merged via safety net' );
		$this->assertArrayHasKey( 'timeframe_limit', $fields, 'timeframe_limit should be merged via safety net' );
	}

	public function test_forgetful_fetch_handler_specific_fields_take_priority(): void {
		$this->register_stub_handler( 'stub-forgetful-fetch', StubForgetfulFetchSettings::class );

		$fields = $this->handler_abilities->getConfigFields( 'stub-forgetful-fetch' );

		// Handler-specific field must survive the merge — it should not be overwritten.
		$this->assertSame( 'API Key', $fields['api_key']['label'] );
	}

	// ---------------------------------------------------------------
	// Publish handler: proper merge
	// ---------------------------------------------------------------

	public function test_publish_handler_includes_common_fields(): void {
		$this->register_stub_handler( 'stub-publish', StubPublishSettings::class );

		$fields = $this->handler_abilities->getConfigFields( 'stub-publish' );

		$this->assertArrayHasKey( 'channel_id', $fields );
		$this->assertArrayHasKey( 'link_handling', $fields );
		$this->assertArrayHasKey( 'include_images', $fields );
	}

	// ---------------------------------------------------------------
	// Publish handler: safety net
	// ---------------------------------------------------------------

	public function test_forgetful_publish_handler_still_gets_common_fields(): void {
		$this->register_stub_handler( 'stub-forgetful-publish', StubForgetfulPublishSettings::class );

		$fields = $this->handler_abilities->getConfigFields( 'stub-forgetful-publish' );

		$this->assertArrayHasKey( 'webhook_url', $fields );
		$this->assertArrayHasKey( 'link_handling', $fields, 'link_handling should be merged via safety net' );
		$this->assertArrayHasKey( 'include_images', $fields, 'include_images should be merged via safety net' );
	}

	// ---------------------------------------------------------------
	// Non-fetch/non-publish handler (direct SettingsHandler)
	// ---------------------------------------------------------------

	public function test_direct_settings_handler_does_not_get_fetch_fields(): void {
		$this->register_stub_handler( 'stub-direct', StubDirectSettings::class );

		$fields = $this->handler_abilities->getConfigFields( 'stub-direct' );

		$this->assertArrayHasKey( 'task_type', $fields );
		$this->assertArrayNotHasKey( 'max_items', $fields, 'Non-fetch handler should not receive fetch common fields' );
		$this->assertArrayNotHasKey( 'link_handling', $fields, 'Non-publish handler should not receive publish common fields' );
	}

	// ---------------------------------------------------------------
	// Unknown handler
	// ---------------------------------------------------------------

	public function test_unknown_handler_returns_empty_array(): void {
		$fields = $this->handler_abilities->getConfigFields( 'nonexistent-handler' );

		$this->assertSame( array(), $fields );
	}

	// ---------------------------------------------------------------
	// Caching
	// ---------------------------------------------------------------

	public function test_config_fields_are_cached(): void {
		$this->register_stub_handler( 'stub-fetch', StubFetchSettings::class );

		$first  = $this->handler_abilities->getConfigFields( 'stub-fetch' );
		$second = $this->handler_abilities->getConfigFields( 'stub-fetch' );

		$this->assertSame( $first, $second );
	}

	public function test_clear_cache_resets_config_fields(): void {
		$this->register_stub_handler( 'stub-fetch', StubFetchSettings::class );

		$before = $this->handler_abilities->getConfigFields( 'stub-fetch' );
		$this->assertNotEmpty( $before );

		HandlerAbilities::clearCache();

		// Remove the filter so the handler is no longer registered.
		remove_all_filters( 'datamachine_handler_settings' );

		$after = $this->handler_abilities->getConfigFields( 'stub-fetch' );
		$this->assertSame( array(), $after );
	}
}
