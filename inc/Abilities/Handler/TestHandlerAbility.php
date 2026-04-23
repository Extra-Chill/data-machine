<?php
/**
 * Test Handler Ability
 *
 * Universal dry-run for any fetch handler. Resolves handler by slug,
 * applies config defaults, calls get_fetch_data(), and returns
 * packet summaries without side effects.
 *
 * @package DataMachine\Abilities\Handler
 * @since 0.55.3
 */

namespace DataMachine\Abilities\Handler;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Database\Flows\Flows;

defined( 'ABSPATH' ) || exit;

class TestHandlerAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->registerAbility();
		self::$registered = true;
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/test-handler',
				array(
					'label'               => __( 'Test Handler', 'data-machine' ),
					'description'         => __( 'Dry-run any fetch handler with a config and return packet summaries.', 'data-machine' ),
					'category'            => 'datamachine-pipeline',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'handler_slug' => array(
								'type'        => 'string',
								'description' => __( 'Handler slug to test (required unless flow_id provided)', 'data-machine' ),
							),
							'config'       => array(
								'type'        => 'object',
								'description' => __( 'Handler configuration overrides', 'data-machine' ),
							),
							'flow_id'      => array(
								'type'        => 'integer',
								'description' => __( 'Pull handler slug and config from an existing flow', 'data-machine' ),
							),
							'limit'        => array(
								'type'        => 'integer',
								'description' => __( 'Max packets to return (default 5)', 'data-machine' ),
								'default'     => 5,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'           => array( 'type' => 'boolean' ),
							'handler_slug'      => array( 'type' => 'string' ),
							'handler_label'     => array( 'type' => 'string' ),
							'config_used'       => array( 'type' => 'object' ),
							'packets'           => array( 'type' => 'array' ),
							'packet_count'      => array( 'type' => 'integer' ),
							'warnings'          => array( 'type' => 'array' ),
							'execution_time_ms' => array( 'type' => 'number' ),
							'error'             => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute the test handler ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with packet summaries.
	 */
	public function execute( array $input ): array {
		$handler_slug = $input['handler_slug'] ?? null;
		$config       = $input['config'] ?? array();
		$flow_id      = isset( $input['flow_id'] ) ? (int) $input['flow_id'] : null;
		$limit        = (int) ( $input['limit'] ?? 5 );
		$warnings     = array();

		// Resolve from flow if flow_id provided.
		if ( $flow_id ) {
			$resolved = $this->resolveFromFlow( $flow_id );

			if ( ! $resolved['success'] ) {
				return $resolved;
			}

			$handler_slug = $resolved['handler_slug'];

			// Flow config is the base; explicit config overrides.
			$config = array_merge( $resolved['config'], $config );
		}

		if ( empty( $handler_slug ) ) {
			return array(
				'success' => false,
				'error'   => 'handler_slug is required (provide it directly or via --flow)',
			);
		}

		$abilities = new HandlerAbilities();
		$info      = $abilities->getHandler( $handler_slug );

		if ( ! $info ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Handler "%s" not found. Use --list to see available handlers.', $handler_slug ),
			);
		}

		$handler_label = $info['label'] ?? $handler_slug;
		$handler_class = $info['class'] ?? null;

		if ( ! $handler_class || ! class_exists( $handler_class ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Handler class for "%s" not found or not loaded.', $handler_slug ),
			);
		}

		// Apply defaults to fill in missing config values.
		$config = $abilities->applyDefaults( $handler_slug, $config );

		// Inject required internal keys for direct execution.
		if ( ! isset( $config['flow_step_id'] ) ) {
			$config['flow_step_id'] = 'test_' . wp_generate_uuid4();
		}
		if ( ! isset( $config['flow_id'] ) ) {
			$config['flow_id'] = 'direct';
		}

		$handler  = new $handler_class();
		$start_ms = microtime( true );

		try {
			$packets = $handler->get_fetch_data( 'direct', $config, null );
		} catch ( \Throwable $e ) {
			return array(
				'success'           => false,
				'handler_slug'      => $handler_slug,
				'handler_label'     => $handler_label,
				'config_used'       => $config,
				'error'             => $e->getMessage(),
				'execution_time_ms' => round( ( microtime( true ) - $start_ms ) * 1000, 1 ),
			);
		}

		$elapsed_ms = round( ( microtime( true ) - $start_ms ) * 1000, 1 );

		if ( ! is_array( $packets ) ) {
			$packets = array();
		}

		$total_count = count( $packets );

		if ( $limit > 0 && $total_count > $limit ) {
			$packets    = array_slice( $packets, 0, $limit );
			$warnings[] = sprintf( 'Showing %d of %d packets (use --limit to see more).', $limit, $total_count );
		}

		// Convert DataPacket objects to summary arrays.
		$packet_summaries = array();
		foreach ( $packets as $packet ) {
			$packet_summaries[] = $this->summarizePacket( $packet );
		}

		return array(
			'success'           => true,
			'handler_slug'      => $handler_slug,
			'handler_label'     => $handler_label,
			'config_used'       => $config,
			'packets'           => $packet_summaries,
			'packet_count'      => $total_count,
			'warnings'          => $warnings,
			'execution_time_ms' => $elapsed_ms,
		);
	}

	/**
	 * Resolve handler slug and config from an existing flow.
	 *
	 * @param int $flow_id Flow ID.
	 * @return array Result with handler_slug and config, or error.
	 */
	private function resolveFromFlow( int $flow_id ): array {
		$db_flows = new Flows();
		$flow     = $db_flows->get_flow( $flow_id );

		if ( ! $flow ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found.', $flow_id ),
			);
		}

		$flow_config = $flow['flow_config'] ?? array();

		if ( empty( $flow_config ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d has no steps configured.', $flow_id ),
			);
		}

		// Find the first fetch or event_import step.
		$fetch_step_types = array( 'fetch', 'event_import' );

		foreach ( $flow_config as $step ) {
			$step_type = $step['step_type'] ?? '';

			if ( ! in_array( $step_type, $fetch_step_types, true ) ) {
				continue;
			}

			$handler_slugs = $step['handler_slugs'] ?? array();

			if ( empty( $handler_slugs ) ) {
				continue;
			}

			$slug            = $handler_slugs[0];
			$handler_configs = $step['handler_configs'] ?? array();
			$handler_config  = $handler_configs[ $slug ] ?? array();

			return array(
				'success'      => true,
				'handler_slug' => $slug,
				'config'       => $handler_config,
			);
		}

		return array(
			'success' => false,
			'error'   => sprintf( 'Flow %d has no fetch or event_import step with a handler.', $flow_id ),
		);
	}

	/**
	 * Convert a DataPacket to a summary array for output.
	 *
	 * @param mixed $packet DataPacket instance.
	 * @return array Summary with title, content_preview, metadata, source_url.
	 */
	private function summarizePacket( $packet ): array {
		// DataPacket uses addTo() to serialize — extract via a temporary array.
		$serialized = $packet->addTo( array() );
		$entry      = $serialized[0] ?? array();

		$data     = $entry['data'] ?? array();
		$metadata = $entry['metadata'] ?? array();

		$title   = $data['title'] ?? '';
		$body    = $data['body'] ?? '';
		$preview = mb_substr( $body, 0, 200 );

		if ( mb_strlen( $body ) > 200 ) {
			$preview .= '...';
		}

		return array(
			'title'           => $title,
			'content_preview' => $preview,
			'metadata'        => $metadata,
			'source_url'      => $metadata['source_url'] ?? '',
		);
	}
}
