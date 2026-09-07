<?php
/**
 * Sparse handler-config patch derived-key tests (#3449).
 *
 * prepareHandlerConfigPatch() intersects sanitized config against the caller's
 * patch so complete-object sanitizers cannot clobber stored values with
 * defaults. Settings classes that synthesize keys the caller did not pass
 * (term ID resolution) declare them via derived_fields() so those keys survive
 * the intersect instead of being discarded after the side effect ran.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\Steps\Settings\SettingsHandler;
use WP_UnitTestCase;

class DerivedResolvingSettings extends SettingsHandler {
	public static function get_fields(): array {
		return array(
			'name'        => array(
				'type' => 'text',
			),
			'limit'       => array(
				'type'    => 'number',
				'default' => 10,
			),
			'resolved_id' => array(
				'type' => 'number',
			),
		);
	}

	public static function sanitize( array $raw_settings ): array {
		// Complete-object emitter mirroring SettingsHandler::sanitize(), plus a
		// term-ID-resolution style derivation the caller never passes.
		return array(
			'name'        => (string) ( $raw_settings['name'] ?? '' ),
			'limit'       => (int) ( $raw_settings['limit'] ?? 10 ),
			'resolved_id' => 42,
		);
	}

	public static function derived_fields(): array {
		return array( 'resolved_id' );
	}
}

class LegacyDerivingSettings extends SettingsHandler {
	public static function get_fields(): array {
		return array(
			'name'  => array(
				'type' => 'text',
			),
			'limit' => array(
				'type'    => 'number',
				'default' => 10,
			),
		);
	}

	public static function sanitize( array $raw_settings ): array {
		return array(
			'name'        => (string) ( $raw_settings['name'] ?? '' ),
			'limit'       => (int) ( $raw_settings['limit'] ?? 10 ),
			'resolved_id' => 42,
		);
	}

	// No derived_fields(): a pre-contract settings class. Its derived key is
	// dropped by the sparse-patch intersect, preserving legacy behaviour.
}

class FlowStepPatchDerivedKeysTest extends WP_UnitTestCase {

	private int $pipeline_id;
	private \Closure $handlers_filter;
	private \Closure $settings_filter;

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->handlers_filter = static function ( array $handlers, ?string $step_type ): array {
			if ( null === $step_type || 'event_import' === $step_type ) {
				$handlers['derived_api'] = array(
					'type'  => 'event_import',
					'label' => 'Derived API',
				);
			}
			if ( null === $step_type || 'fetch' === $step_type ) {
				$handlers['legacy_api'] = array(
					'type'  => 'fetch',
					'label' => 'Legacy API',
				);
			}

			return $handlers;
		};
		add_filter( 'datamachine_handlers', $this->handlers_filter, 10, 2 );

		$this->settings_filter = static function ( array $settings, ?string $handler_slug ): array {
			if ( null === $handler_slug || 'derived_api' === $handler_slug ) {
				$settings['derived_api'] = new DerivedResolvingSettings();
			}
			if ( null === $handler_slug || 'legacy_api' === $handler_slug ) {
				$settings['legacy_api'] = new LegacyDerivingSettings();
			}

			return $settings;
		};
		add_filter( 'datamachine_handler_settings', $this->settings_filter, 10, 2 );
		HandlerAbilities::clearCache();

		$pipelines         = new Pipelines();
		$this->pipeline_id = (int) $pipelines->create_pipeline(
			array(
				'pipeline_name'   => 'Derived Keys Patch Pipeline',
				'pipeline_config' => array(),
			)
		);

		$pipeline_config = array();
		foreach ( array( 'event_import', 'fetch' ) as $order => $step_type ) {
			$pipeline_step_id                     = $this->pipeline_id . '_' . $step_type;
			$pipeline_config[ $pipeline_step_id ] = array(
				'pipeline_step_id' => $pipeline_step_id,
				'step_type'        => $step_type,
				'execution_order'  => $order,
				'label'            => $step_type,
			);
		}
		$pipelines->update_pipeline( $this->pipeline_id, array( 'pipeline_config' => $pipeline_config ) );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_handlers', $this->handlers_filter, 10 );
		remove_filter( 'datamachine_handler_settings', $this->settings_filter, 10 );
		HandlerAbilities::clearCache();
		delete_option( 'datamachine_handler_defaults' );

		parent::tear_down();
	}

	public function test_create_flow_stores_sanitizer_derived_key(): void {
		$result = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id'  => $this->pipeline_id,
				'flow_name'    => 'Derived Create Flow',
				'step_configs' => array(
					'event_import' => array(
						'handler_slug'   => 'derived_api',
						'handler_config' => array( 'name' => 'x' ),
					),
				),
			)
		);

		$this->assertTrue( $result['success'] );

		$stored = $this->getStoredHandlerConfig( (int) $result['flow_id'], 'event_import' );
		$this->assertSame( 'x', $stored['name'] );
		$this->assertSame( 10, $stored['limit'] );
		$this->assertArrayHasKey( 'resolved_id', $stored, 'Derived key must survive the sparse-patch intersect on create.' );
		$this->assertSame( 42, $stored['resolved_id'] );
	}

	public function test_sparse_patch_keeps_derived_key_and_preserves_non_default_stored_value(): void {
		$flow_id = $this->createFlowWithDerivedHandler();
		$step_id = $this->getEventImportStepId( $flow_id );

		$patch_limit = wp_get_ability( 'datamachine/update-flow-step' )->execute(
			array(
				'flow_step_id'   => $step_id,
				'handler_config' => array( 'limit' => 99 ),
			)
		);
		$this->assertTrue( $patch_limit['success'] );

		$patch_name = wp_get_ability( 'datamachine/update-flow-step' )->execute(
			array(
				'flow_step_id'   => $step_id,
				'handler_config' => array( 'name' => 'y' ),
			)
		);
		$this->assertTrue( $patch_name['success'] );

		$stored = $this->getStoredHandlerConfig( $flow_id, 'event_import' );
		$this->assertSame( 'y', $stored['name'] );
		$this->assertSame( 99, $stored['limit'], 'Sparse patch of one key must not overwrite another stored key with its default.' );
		$this->assertSame( 42, $stored['resolved_id'], 'Derived key must survive a sparse patch that did not mention it.' );
	}

	public function test_settings_class_without_derived_fields_contract_drops_derived_key(): void {
		$result = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id'  => $this->pipeline_id,
				'flow_name'    => 'Legacy Create Flow',
				'step_configs' => array(
					'fetch' => array(
						'handler_slug'   => 'legacy_api',
						'handler_config' => array( 'name' => 'x' ),
					),
				),
			)
		);

		$this->assertTrue( $result['success'] );

		$stored = $this->getStoredHandlerConfig( (int) $result['flow_id'], 'fetch' );
		$this->assertSame( 'x', $stored['name'] );
		$this->assertArrayNotHasKey( 'resolved_id', $stored, 'Without derived_fields() the intersect keeps legacy sparse-patch behaviour.' );
	}

	public function test_full_object_update_keeps_all_patched_fields(): void {
		$flow_id = $this->createFlowWithDerivedHandler();
		$step_id = $this->getEventImportStepId( $flow_id );

		$result = wp_get_ability( 'datamachine/update-flow-step' )->execute(
			array(
				'flow_step_id'   => $step_id,
				'handler_config' => array(
					'name'  => 'full',
					'limit' => 25,
				),
			)
		);

		$this->assertTrue( $result['success'] );

		$stored = $this->getStoredHandlerConfig( $flow_id, 'event_import' );
		$this->assertSame( 'full', $stored['name'] );
		$this->assertSame( 25, $stored['limit'] );
		$this->assertSame( 42, $stored['resolved_id'] );
	}

	private function createFlowWithDerivedHandler(): int {
		$result = wp_get_ability( 'datamachine/create-flow' )->execute(
			array(
				'pipeline_id'  => $this->pipeline_id,
				'flow_name'    => 'Derived Patch Flow ' . uniqid(),
				'step_configs' => array(
					'event_import' => array(
						'handler_slug'   => 'derived_api',
						'handler_config' => array( 'name' => 'x' ),
					),
				),
			)
		);

		$this->assertTrue( $result['success'] );

		return (int) $result['flow_id'];
	}

	private function getEventImportStepId( int $flow_id ): string {
		$flow = ( new Flows() )->get_flow( $flow_id );
		foreach ( $flow['flow_config'] as $flow_step_id => $step ) {
			if ( 'event_import' === ( $step['step_type'] ?? '' ) ) {
				return (string) $flow_step_id;
			}
		}

		$this->fail( 'event_import flow step not found.' );
	}

	private function getStoredHandlerConfig( int $flow_id, string $step_type ): array {
		$flow      = ( new Flows() )->get_flow( $flow_id );
		$step_type = array_column( $flow['flow_config'], null, 'step_type' )[ $step_type ];

		return $step_type['handler_configs'][ $step_type['handler_slugs'][0] ];
	}
}
