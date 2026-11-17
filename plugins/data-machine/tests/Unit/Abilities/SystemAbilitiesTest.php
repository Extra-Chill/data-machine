<?php
/**
 * SystemAbilities Tests
 *
 * Tests for system infrastructure abilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\SystemAbilities;
use DataMachine\Core\PluginSettings;
use WP_UnitTestCase;

class SystemAbilitiesTest extends WP_UnitTestCase {

	private SystemAbilities $system_abilities;
	private int $test_user_id;

	public function set_up(): void {
		parent::set_up();

		$this->test_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->test_user_id );

		$this->system_abilities = new SystemAbilities();
	}

	public function tear_down(): void {
		PluginSettings::clearCache();
		parent::tear_down();
	}

	public function test_health_check_preserves_extension_wp_error_details(): void {
		add_filter(
			'datamachine_system_health_checks',
			static function ( array $checks ): array {
				$checks['extension-test'] = array(
					'label'    => 'Extension Test',
					'callback' => static fn(): \WP_Error => new \WP_Error(
						'extension_unavailable',
						'Extension service is unavailable.',
						array(
							'status'      => 503,
							'diagnostics' => array( 'provider' => 'test-provider' ),
						)
					),
					'default'  => false,
				);
				return $checks;
			}
		);

		$result = $this->system_abilities->executeHealthCheck( array( 'types' => array( 'extension-test' ) ) );
		$health = $result['results']['extension-test']['result'];

		$this->assertFalse( $health['success'] );
		$this->assertSame( 'extension-test', $health['check_type'] );
		$this->assertSame( 'extension_unavailable', $health['error_code'] );
		$this->assertSame( 503, $health['status'] );
		$this->assertSame( array( 'provider' => 'test-provider' ), $health['diagnostics'] );
	}

	public function test_health_check_catches_extension_throwable(): void {
		add_filter(
			'datamachine_system_health_checks',
			static function ( array $checks ): array {
				$checks['throwing-extension'] = array(
					'label'    => 'Throwing Extension',
					'callback' => static fn() => throw new \RuntimeException( 'Extension callback exploded.' ),
					'default'  => false,
				);
				return $checks;
			}
		);

		$result = $this->system_abilities->executeHealthCheck( array( 'types' => array( 'throwing-extension' ) ) );
		$health = $result['results']['throwing-extension']['result'];

		$this->assertFalse( $health['success'] );
		$this->assertSame( 'throwing-extension', $health['check_type'] );
		$this->assertSame( 'health_check_callback_exception', $health['error_code'] );
		$this->assertSame( 500, $health['status'] );
		$this->assertSame( \RuntimeException::class, $health['diagnostics']['exception'] );
		$this->assertStringContainsString( 'Throwing Extension: error', $result['summary'] );
	}
}
