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
use DataMachine\Core\Steps\FlowStepConfig;

defined( 'ABSPATH' ) || exit;

class TestHandlerAbility {
	private const DEFAULT_RAW_BYTE_LIMIT = 1048576;
	private const MAX_RAW_BYTE_LIMIT     = 5242880;
	private const MAX_RAW_PACKET_LIMIT   = 100;

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
					'description'         => __( 'Dry-run any fetch handler with a config and return compact summaries or bounded raw packets.', 'data-machine' ),
					'category'            => 'datamachine-pipeline',
					'input_schema'        => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'handler_slug' => array(
								'type'        => 'string',
								'description' => __( 'Handler slug to test (required unless flow_id provided)', 'data-machine' ),
							),
							'config'       => array(
								'type'                 => 'object',
								'description'          => __( 'Handler configuration overrides', 'data-machine' ),
								'additionalProperties' => true,
							),
							'flow_id'      => array(
								'type'        => 'integer',
								'description' => __( 'Pull handler slug and config from an existing flow', 'data-machine' ),
							),
							'limit'        => array(
								'type'        => 'integer',
								'description' => __( 'Max packets to return (default 5). Compact mode accepts 0 for all packets; raw mode is always bounded to 1-100 packets.', 'data-machine' ),
								'default'     => 5,
								'minimum'     => 0,
							),
							'output_mode'  => array(
								'type'        => 'string',
								'description' => __( 'Packet output mode. Compact returns the backward-compatible preview; raw returns complete text/JSON packet envelopes within explicit limits.', 'data-machine' ),
								'enum'        => array( 'compact', 'raw' ),
								'default'     => 'compact',
							),
							'byte_limit'   => array(
								'type'        => 'integer',
								'description' => __( 'Maximum serialized packet bytes returned in raw mode (default 1048576, maximum 5242880). Whole packets are omitted rather than partially truncated.', 'data-machine' ),
								'default'     => self::DEFAULT_RAW_BYTE_LIMIT,
								'minimum'     => 1,
								'maximum'     => self::MAX_RAW_BYTE_LIMIT,
							),
						),
					),
					'output_schema'       => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'success'           => array( 'type' => 'boolean' ),
							'handler_slug'      => array( 'type' => 'string' ),
							'handler_label'     => array( 'type' => 'string' ),
							'config_used'       => array( 'type' => 'object', 'additionalProperties' => true ),
							'packets'           => array(
								'type'        => 'array',
								'description' => __( 'Compact packet summaries, or raw packet envelopes containing type, timestamp, data, and metadata.', 'data-machine' ),
								'items'       => array(
									'type'                 => 'object',
									'additionalProperties' => true,
									'properties'           => array(
										'title'           => array( 'type' => 'string' ),
										'content_preview' => array( 'type' => 'string' ),
										'source_url'      => array( 'type' => 'string' ),
										'type'            => array( 'type' => 'string' ),
										'timestamp'       => array( 'type' => 'integer' ),
										'data'            => array( 'type' => 'object' ),
										'metadata'        => array( 'type' => 'object' ),
									),
								),
							),
							'packet_count'      => array( 'type' => 'integer' ),
							'warnings'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
							'execution_time_ms' => array( 'type' => 'number' ),
							'error'             => array( 'type' => 'string' ),
							'output_mode'       => array(
								'type' => 'string',
								'enum' => array( 'raw' ),
							),
							'limits'            => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array( 'packet_count', 'bytes' ),
								'properties'           => array(
									'packet_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_RAW_PACKET_LIMIT ),
									'bytes'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_RAW_BYTE_LIMIT ),
								),
							),
							'truncation'        => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array( 'truncated', 'reasons', 'original_packet_count', 'returned_packet_count', 'omitted_packet_count', 'returned_bytes', 'redacted_fields', 'binary_fields' ),
								'properties'           => array(
									'truncated'             => array( 'type' => 'boolean' ),
									'reasons'               => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'packet_limit', 'byte_limit', 'binary_content' ) ) ),
									'original_packet_count' => array( 'type' => 'integer' ),
									'returned_packet_count' => array( 'type' => 'integer' ),
									'omitted_packet_count'  => array( 'type' => 'integer' ),
									'returned_bytes'        => array( 'type' => 'integer' ),
									'redacted_fields'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
									'binary_fields'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
								),
							),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
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
		$output_mode  = 'raw' === ( $input['output_mode'] ?? 'compact' ) ? 'raw' : 'compact';
		$byte_limit   = (int) ( $input['byte_limit'] ?? self::DEFAULT_RAW_BYTE_LIMIT );
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

		if ( 'raw' === $output_mode ) {
			$packet_limit = max( 1, min( self::MAX_RAW_PACKET_LIMIT, $limit > 0 ? $limit : 5 ) );
			$byte_limit   = max( 1, min( self::MAX_RAW_BYTE_LIMIT, $byte_limit ) );
			$raw_output   = $this->formatRawPackets( $packets, $packet_limit, $byte_limit );
			$config_used  = $this->sanitizeRawValue( $config, 'config', $raw_output['redacted_fields'], $raw_output['binary_fields'] );

			$raw_output['truncation']['redacted_fields'] = $raw_output['redacted_fields'];
			$raw_output['truncation']['binary_fields']   = $raw_output['binary_fields'];
			if ( ! empty( $raw_output['binary_fields'] ) && ! in_array( 'binary_content', $raw_output['truncation']['reasons'], true ) ) {
				$raw_output['truncation']['reasons'][] = 'binary_content';
				$raw_output['truncation']['truncated'] = true;
			}

			if ( $raw_output['truncation']['truncated'] ) {
				$warnings[] = sprintf(
					'Raw packet output truncated: returned %d of %d packets (%d of %d byte limit).',
					$raw_output['truncation']['returned_packet_count'],
					$total_count,
					$raw_output['truncation']['returned_bytes'],
					$byte_limit
				);
			}

			return array(
				'success'           => true,
				'handler_slug'      => $handler_slug,
				'handler_label'     => $handler_label,
				'config_used'       => $config_used,
				'packets'           => $raw_output['packets'],
				'packet_count'      => $total_count,
				'warnings'          => $warnings,
				'execution_time_ms' => $elapsed_ms,
				'output_mode'       => 'raw',
				'limits'            => array(
					'packet_count' => $packet_limit,
					'bytes'        => $byte_limit,
				),
				'truncation'        => $raw_output['truncation'],
			);
		}

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

			$slug = FlowStepConfig::getEffectiveSlug( $step );

			if ( empty( $slug ) ) {
				continue;
			}

			$handler_config = FlowStepConfig::getPrimaryHandlerConfig( $step );

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

	/**
	 * Serialize complete text packets without cutting through a packet body.
	 *
	 * @param array $packets      DataPacket instances.
	 * @param int   $packet_limit Maximum packets to return.
	 * @param int   $byte_limit   Maximum serialized packet bytes to return.
	 * @return array Raw packets and explicit truncation metadata.
	 */
	private function formatRawPackets( array $packets, int $packet_limit, int $byte_limit ): array {
		$returned        = array();
		$returned_bytes  = 0;
		$reasons         = array();
		$redacted_fields = array();
		$binary_fields   = array();
		$total_count     = count( $packets );

		foreach ( array_slice( $packets, 0, $packet_limit ) as $index => $packet ) {
			$serialized = $packet->addTo( array() );
			$entry      = $serialized[0] ?? array();
			$entry      = $this->sanitizeRawValue( $entry, 'packets.' . $index, $redacted_fields, $binary_fields );
			$encoded    = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$bytes      = strlen( (string) $encoded );

			if ( $returned_bytes + $bytes > $byte_limit ) {
				$reasons[] = 'byte_limit';
				break;
			}

			$returned[]      = $entry;
			$returned_bytes += $bytes;
		}

		if ( $total_count > $packet_limit ) {
			$reasons[] = 'packet_limit';
		}
		if ( ! empty( $binary_fields ) ) {
			$reasons[] = 'binary_content';
		}

		$reasons        = array_values( array_unique( $reasons ) );
		$returned_count = count( $returned );

		return array(
			'packets'         => $returned,
			'redacted_fields' => $redacted_fields,
			'binary_fields'   => $binary_fields,
			'truncation'      => array(
				'truncated'             => ! empty( $reasons ),
				'reasons'               => $reasons,
				'original_packet_count' => $total_count,
				'returned_packet_count' => $returned_count,
				'omitted_packet_count'  => $total_count - $returned_count,
				'returned_bytes'        => $returned_bytes,
				'redacted_fields'       => $redacted_fields,
				'binary_fields'         => $binary_fields,
			),
		);
	}

	/**
	 * Apply the artifact-output secret and binary policy recursively.
	 *
	 * @param mixed    $value           Value to sanitize.
	 * @param string   $path            Dot path used in output metadata.
	 * @param string[] $redacted_fields Redacted paths, passed by reference.
	 * @param string[] $binary_fields   Binary paths, passed by reference.
	 * @return mixed Sanitized value.
	 */
	private function sanitizeRawValue( $value, string $path, array &$redacted_fields, array &$binary_fields ) {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $child ) {
				$child_path = $path . '.' . $key;
				if ( preg_match( '/(api[_-]?key|auth|bearer|cookie|credential|nonce|password|secret|signature|token)/i', (string) $key ) ) {
					$sanitized[ $key ]  = '[redacted]';
					$redacted_fields[] = $child_path;
					continue;
				}

				$sanitized[ $key ] = $this->sanitizeRawValue( $child, $child_path, $redacted_fields, $binary_fields );
			}

			return $sanitized;
		}

		if ( is_object( $value ) ) {
			return $this->sanitizeRawValue( get_object_vars( $value ), $path, $redacted_fields, $binary_fields );
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( false !== strpos( $value, "\0" ) || ! mb_check_encoding( $value, 'UTF-8' ) ) {
			$binary_fields[] = $path;
			return '[binary omitted]';
		}

		$decoded = json_decode( $value, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			$sanitized_json = $this->sanitizeRawValue( $decoded, $path, $redacted_fields, $binary_fields );
			if ( $sanitized_json !== $decoded ) {
				return wp_json_encode( $sanitized_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		}

		$redacted = preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/\-]+=*/i', 'Bearer [redacted]', $value );
		$redacted = preg_replace( '/\b(api[_-]?key|token|secret|password)\b\s*[:=]\s*\S+/i', '$1: [redacted]', $redacted ?? $value );
		if ( $redacted !== $value ) {
			$redacted_fields[] = $path;
		}

		return $redacted;
	}
}
