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
	private const MIN_RAW_BYTE_LIMIT     = 4096;
	private const MAX_RAW_PACKET_LIMIT   = 100;
	private const MAX_REPORT_PATHS       = 8;
	private const MAX_SANITIZE_DEPTH     = 32;
	private const TRANSPORT_ENTRY_BUDGET = 132;

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
						'type'       => 'object',
						'properties' => array(
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
								'description' => __( 'Maximum serialized bytes for the complete raw response (default 1048576, range 4096-5242880). Whole packets are omitted rather than partially truncated.', 'data-machine' ),
								'default'     => self::DEFAULT_RAW_BYTE_LIMIT,
								'minimum'     => self::MIN_RAW_BYTE_LIMIT,
								'maximum'     => self::MAX_RAW_BYTE_LIMIT,
							),
						),
					),
					'output_schema'       => self::getOutputSchema(),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Return the registered output schema for runtime and validation tests.
	 */
	public static function getOutputSchema(): array {
		return array(
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
								'enum' => array( 'compact', 'raw' ),
							),
							'limits'            => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array( 'packet_count', 'bytes' ),
								'properties'           => array(
									'packet_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_RAW_PACKET_LIMIT ),
									'bytes'        => array( 'type' => 'integer', 'minimum' => self::MIN_RAW_BYTE_LIMIT, 'maximum' => self::MAX_RAW_BYTE_LIMIT ),
								),
							),
							'truncation'        => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array( 'truncated', 'reasons', 'materialized_packet_count', 'returned_packet_count', 'omitted_packet_count', 'returned_bytes', 'materialization_limited', 'redacted_fields', 'binary_fields', 'omitted_fields', 'omitted_field_count' ),
								'properties'           => array(
									'truncated'             => array( 'type' => 'boolean' ),
									'reasons'               => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'packet_limit', 'byte_limit', 'response_limit', 'config_limit', 'binary_content', 'invalid_utf8', 'unsupported_type', 'json_encode_failure' ) ) ),
									'materialized_packet_count' => array( 'type' => 'integer' ),
									'returned_packet_count' => array( 'type' => 'integer' ),
									'omitted_packet_count'  => array( 'type' => 'integer' ),
									'returned_bytes'        => array( 'type' => 'integer' ),
									'materialization_limited' => array( 'type' => 'boolean' ),
									'redacted_fields'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
									'binary_fields'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
									'omitted_fields'        => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'required'   => array( 'path', 'reason' ),
											'properties' => array(
												'path'   => array( 'type' => 'string' ),
												'reason' => array( 'type' => 'string' ),
											),
										),
									),
									'omitted_field_count'   => array( 'type' => 'integer' ),
								),
							),
						),
						'oneOf'      => array(
							array(
								'type'       => 'object',
								'required'   => array( 'success', 'error' ),
								'properties' => array( 'success' => array( 'type' => 'boolean', 'enum' => array( false ) ) ),
							),
							array(
								'type'       => 'object',
								'required'   => array( 'success', 'output_mode', 'packets', 'packet_count', 'warnings', 'execution_time_ms' ),
								'properties' => array( 'success' => array( 'type' => 'boolean', 'enum' => array( true ) ), 'output_mode' => array( 'type' => 'string', 'enum' => array( 'compact' ) ) ),
							),
							array(
								'type'       => 'object',
								'required'   => array( 'success', 'output_mode', 'packets', 'packet_count', 'warnings', 'execution_time_ms', 'limits', 'truncation' ),
								'properties' => array( 'success' => array( 'type' => 'boolean', 'enum' => array( true ) ), 'output_mode' => array( 'type' => 'string', 'enum' => array( 'raw' ) ) ),
							),
						),
					);
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
		$packet_limit = max( 1, min( self::MAX_RAW_PACKET_LIMIT, $limit > 0 ? $limit : 5 ) );
		$byte_limit   = max( self::MIN_RAW_BYTE_LIMIT, min( self::MAX_RAW_BYTE_LIMIT, $byte_limit ) );

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

		$materialization_limited = false;
		if ( 'raw' === $output_mode ) {
			$configured_max = (int) ( $config['max_items'] ?? 0 );
			if ( $configured_max <= 0 || $configured_max > $packet_limit ) {
				$config['max_items'] = $packet_limit;
			}
			$materialization_limited = true;
		}

		$config_report = $this->newSanitizationReport();
		$config_budget = 'raw' === $output_mode ? min( 65536, max( 512, intdiv( $byte_limit, 4 ) ) ) : 65536;
		$config_used   = null;
		$config_status = $this->sanitizeBoundedValue( $config, 'config', $config_budget, $config_report, $config_used );
		if ( 'ok' !== $config_status ) {
			$config_used = array( '_omitted' => 'byte_limit' );
			$this->recordOmission( $config_report, 'config', 'config_limit' );
		}

		$start_ms = microtime( true );

		try {
			$handler = new $handler_class();
			$packets = $handler->get_fetch_data( 'direct', $config, null );
		} catch ( \Throwable $e ) {
			return array(
				'success'           => false,
				'handler_slug'      => $this->boundOutputString( (string) $handler_slug, 256 ),
				'handler_label'     => $this->boundOutputString( (string) $handler_label, 256 ),
				'config_used'       => $config_used,
				'error'             => 'Handler execution failed.',
				'execution_time_ms' => round( ( microtime( true ) - $start_ms ) * 1000, 1 ),
			);
		}

		$elapsed_ms = round( ( microtime( true ) - $start_ms ) * 1000, 1 );

		if ( ! is_array( $packets ) ) {
			$packets = array();
		}

		$total_count = count( $packets );

		if ( 'raw' === $output_mode ) {
			$base = array(
				'success'           => true,
				'handler_slug'      => $handler_slug,
				'handler_label'     => $handler_label,
				'config_used'       => $config_used,
				'execution_time_ms' => $elapsed_ms,
				'output_mode'       => 'raw',
			);

			return $this->buildRawResponse( $packets, $packet_limit, $byte_limit, $base, $config_report, $materialization_limited );
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
			'config_used'       => $config_used,
			'packets'           => $packet_summaries,
			'packet_count'      => $total_count,
			'warnings'          => $warnings,
			'execution_time_ms' => $elapsed_ms,
			'output_mode'       => 'compact',
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
	 * Build a raw response while keeping every intermediate representation bounded.
	 *
	 * @param array $packets                 Materialized DataPacket instances.
	 * @param int   $packet_limit            Maximum packets to return.
	 * @param int   $byte_limit              Maximum complete response bytes.
	 * @param array $base                    Already-sanitized response fields.
	 * @param array $report                  Config sanitization report.
	 * @param bool  $materialization_limited Whether max_items bounded handler execution.
	 * @return array JSON-safe raw response.
	 */
	private function buildRawResponse( array $packets, int $packet_limit, int $byte_limit, array $base, array $report, bool $materialization_limited ): array {
		$total_count = count( $packets );
		$returned    = array();

		$base['handler_slug']  = $this->boundOutputString( (string) ( $base['handler_slug'] ?? '' ), 256 );
		$base['handler_label'] = $this->boundOutputString( (string) ( $base['handler_label'] ?? '' ), 256 );

		$empty_response = $this->composeRawResponse( $base, array(), $total_count, $packet_limit, $byte_limit, $report, $materialization_limited );
		$envelope_bytes = $this->transportSize( $empty_response, true );
		$packet_budget  = max( 0, $byte_limit - $envelope_bytes - 1024 );

		foreach ( $packets as $index => $packet ) {
			if ( $index >= $packet_limit ) {
				$this->addReason( $report, 'packet_limit' );
				break;
			}

			try {
				$serialized = $packet->addTo( array() );
				$entry      = $serialized[0] ?? null;
			} catch ( \Throwable $e ) {
				$this->recordOmission( $report, 'packets.' . $index, 'unsupported_type' );
				continue;
			}

			$packet_output = null;
			$status        = $this->sanitizeBoundedValue( $entry, 'packets.' . $index, $packet_budget, $report, $packet_output );
			if ( 'limit' === $status ) {
				$this->addReason( $report, 'byte_limit' );
				break;
			}
			if ( 'ok' !== $status ) {
				$this->recordOmission( $report, 'packets.' . $index, 'unsupported_type' );
				continue;
			}

			$returned[] = $packet_output;
		}

		if ( $materialization_limited && $total_count >= $packet_limit ) {
			$this->addReason( $report, 'packet_limit' );
		}

		$config_collapsed = false;
		$report_collapsed = false;
		do {
			$response = $this->composeRawResponse( $base, $returned, $total_count, $packet_limit, $byte_limit, $report, $materialization_limited );
			$bytes    = $this->stabilizeReturnedBytes( $response );

			if ( $bytes <= $byte_limit ) {
				return $response;
			}

			$this->addReason( $report, 'response_limit' );
			if ( ! empty( $returned ) ) {
				array_pop( $returned );
				continue;
			}

			if ( ! $config_collapsed ) {
				$base['config_used'] = array( '_omitted' => 'response_limit' );
				$this->recordOmission( $report, 'config', 'config_limit' );
				$config_collapsed = true;
				continue;
			}

			if ( ! $report_collapsed ) {
				$report['redacted_fields'] = array();
				$report['binary_fields']   = array();
				$report['omitted_fields']  = array();
				$base['handler_label']     = '';
				$report_collapsed          = true;
				continue;
			}

			$response = array(
				'success'           => true,
				'handler_slug'      => '',
				'handler_label'     => '',
				'config_used'       => array( '_omitted' => 'response_limit' ),
				'packets'           => array(),
				'packet_count'      => $total_count,
				'warnings'          => array( 'Raw response metadata exceeded byte_limit; all optional output was omitted.' ),
				'execution_time_ms' => $base['execution_time_ms'] ?? 0,
				'output_mode'       => 'raw',
				'limits'            => array( 'packet_count' => $packet_limit, 'bytes' => $byte_limit ),
				'truncation'        => array(
					'truncated'                 => true,
					'reasons'                   => array( 'response_limit' ),
					'materialized_packet_count' => $total_count,
					'returned_packet_count'     => 0,
					'omitted_packet_count'      => $total_count,
					'returned_bytes'            => 0,
					'materialization_limited'   => $materialization_limited,
					'redacted_fields'           => array(),
					'binary_fields'             => array(),
					'omitted_fields'            => array(),
					'omitted_field_count'       => $report['omitted_field_count'],
				),
			);
			$this->stabilizeReturnedBytes( $response );
			return $response;
		} while ( true );
	}

	/**
	 * Compose the complete response from bounded, JSON-safe values.
	 */
	private function composeRawResponse( array $base, array $packets, int $total_count, int $packet_limit, int $byte_limit, array $report, bool $materialization_limited ): array {
		$reasons = array_values( array_unique( $report['reasons'] ) );
		$response = $base;

		$response['packets']      = $packets;
		$response['packet_count'] = $total_count;
		$response['warnings']     = empty( $reasons ) ? array() : array( 'Raw output omitted or redacted data; inspect truncation metadata.' );
		$response['limits']       = array(
			'packet_count' => $packet_limit,
			'bytes'        => $byte_limit,
		);
		$response['truncation']   = array(
			'truncated'                 => ! empty( $reasons ),
			'reasons'                   => $reasons,
			'materialized_packet_count' => $total_count,
			'returned_packet_count'     => count( $packets ),
			'omitted_packet_count'      => max( 0, $total_count - count( $packets ) ),
			'returned_bytes'            => 0,
			'materialization_limited'   => $materialization_limited,
			'redacted_fields'           => $report['redacted_fields'],
			'binary_fields'             => $report['binary_fields'],
			'omitted_fields'            => $report['omitted_fields'],
			'omitted_field_count'       => $report['omitted_field_count'],
		);

		return $response;
	}

	/**
	 * Recursively sanitize a value without traversing beyond the remaining JSON budget.
	 *
	 * @return string ok, omit, or limit.
	 */
	private function sanitizeBoundedValue( $value, string $path, int &$remaining, array &$report, &$output, int $depth = 0 ): string {
		if ( $depth > self::MAX_SANITIZE_DEPTH ) {
			$this->recordOmission( $report, $path, 'unsupported_type' );
			return 'omit';
		}

		if ( is_resource( $value ) || is_object( $value ) ) {
			$this->recordOmission( $report, $path, 'unsupported_type' );
			return 'omit';
		}

		if ( is_string( $value ) ) {
			if ( strlen( $value ) + 2 > $remaining ) {
				return 'limit';
			}
			if ( false !== strpos( $value, "\0" ) ) {
				$this->recordBinaryOmission( $report, $path );
				return 'omit';
			}
			if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
				$this->recordOmission( $report, $path, 'invalid_utf8' );
				return 'omit';
			}
		}

		if ( ! is_array( $value ) ) {
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === $encoded ) {
				$this->recordOmission( $report, $path, 'json_encode_failure' );
				return 'omit';
			}
			$bytes = $this->transportSize( $value, false );
			if ( $bytes > $remaining ) {
				return 'limit';
			}
			$remaining -= $bytes;
			$output     = $value;
			return 'ok';
		}

		if ( $remaining < 2 ) {
			return 'limit';
		}

		$is_list   = array_is_list( $value );
		$remaining -= 2;
		$output     = array();
		$first      = true;

		foreach ( $value as $key => $child ) {
			if ( $remaining < self::TRANSPORT_ENTRY_BUDGET ) {
				return 'limit';
			}
			$remaining -= self::TRANSPORT_ENTRY_BUDGET;

			$key_bytes  = 0;
			$child_path = $path;

			if ( ! $is_list ) {
				$key_string = (string) $key;
				if ( ! mb_check_encoding( $key_string, 'UTF-8' ) || false !== strpos( $key_string, "\0" ) ) {
					$this->recordOmission( $report, $path . '.[invalid-key]', 'invalid_utf8' );
					continue;
				}
				if ( strlen( $key_string ) + 3 > $remaining ) {
					return 'limit';
				}
				$key_encoded = wp_json_encode( $key_string, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( false === $key_encoded ) {
					$this->recordOmission( $report, $path . '.[invalid-key]', 'json_encode_failure' );
					continue;
				}
				$key_bytes = strlen( $key_encoded ) + 1;
			}
			$child_path = $path . '.' . $key;

			$prefix_bytes = $key_bytes + ( $first ? 0 : 1 );
			if ( $prefix_bytes > $remaining ) {
				return 'limit';
			}

			if ( ! $is_list && $this->isSensitiveKey( (string) $key ) ) {
				$child = '[redacted]';
				$this->recordPath( $report['redacted_fields'], $child_path );
			}

			$remaining -= $prefix_bytes;
			$child_output = null;
			$status       = $this->sanitizeBoundedValue( $child, $child_path, $remaining, $report, $child_output, $depth + 1 );
			if ( 'limit' === $status ) {
				return 'limit';
			}
			if ( 'omit' === $status ) {
				$remaining += $prefix_bytes;
				continue;
			}

			if ( $is_list ) {
				$output[] = $child_output;
			} else {
				$output[ $key ] = $child_output;
			}
			$first = false;
		}

		return 'ok';
	}

	private function isSensitiveKey( string $key ): bool {
		$key = strtolower( str_replace( '-', '_', $key ) );
		return in_array(
			$key,
			array( 'api_key', 'apikey', 'authorization', 'auth_token', 'access_token', 'refresh_token', 'bearer', 'cookie', 'credential', 'credentials', 'nonce', 'password', 'secret', 'client_secret', 'signature', 'token' ),
			true
		);
	}

	private function newSanitizationReport(): array {
		return array(
			'reasons'             => array(),
			'redacted_fields'     => array(),
			'binary_fields'       => array(),
			'omitted_fields'      => array(),
			'omitted_field_count' => 0,
		);
	}

	private function recordBinaryOmission( array &$report, string $path ): void {
		$this->recordPath( $report['binary_fields'], $path );
		$this->recordOmission( $report, $path, 'binary_content' );
	}

	private function recordOmission( array &$report, string $path, string $reason ): void {
		++$report['omitted_field_count'];
		$this->addReason( $report, $reason );
		if ( count( $report['omitted_fields'] ) < self::MAX_REPORT_PATHS ) {
			$report['omitted_fields'][] = array(
				'path'   => $this->boundOutputString( $path, 120 ),
				'reason' => $reason,
			);
		}
	}

	private function recordPath( array &$paths, string $path ): void {
		if ( count( $paths ) < self::MAX_REPORT_PATHS ) {
			$paths[] = $this->boundOutputString( $path, 120 );
		}
	}

	private function addReason( array &$report, string $reason ): void {
		if ( ! in_array( $reason, $report['reasons'], true ) ) {
			$report['reasons'][] = $reason;
		}
	}

	private function boundOutputString( string $value, int $max_bytes ): string {
		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return '';
		}
		if ( strlen( $value ) <= $max_bytes ) {
			return $value;
		}
		return mb_strcut( $value, 0, max( 0, $max_bytes - 3 ), 'UTF-8' ) . '...';
	}

	private function transportSize( $value, bool $complete_response ): int {
		$rest = wp_json_encode( $value );
		$cli  = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $rest || false === $cli ) {
			return PHP_INT_MAX;
		}

		$cli_bytes = strlen( $cli ) + ( $complete_response ? 1 : 0 );
		return max( strlen( $rest ), $cli_bytes );
	}

	private function stabilizeReturnedBytes( array &$response ): int {
		for ( $iteration = 0; $iteration < 4; ++$iteration ) {
			$bytes = $this->transportSize( $response, true );
			if ( $response['truncation']['returned_bytes'] === $bytes ) {
				return $bytes;
			}
			$response['truncation']['returned_bytes'] = $bytes;
		}
		return $this->transportSize( $response, true );
	}
}
