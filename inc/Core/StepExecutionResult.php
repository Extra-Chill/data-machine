<?php
/**
 * Step execution result classifier.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( StepResult::class ) ) {
	require_once __DIR__ . '/StepResult.php';
}

/**
 * Normalizes step execution status separately from DataPacket transport.
 */
class StepExecutionResult {

	private const STATUS_SUCCEEDED          = 'succeeded';
	private const STATUS_FAILED             = 'failed';
	private const STATUS_COMPLETED_NO_ITEMS = 'completed_no_items';
	private const STATUS_BLOCKED            = 'blocked';

	/** @var array<string,bool> */
	private const NON_SUCCESS_PACKET_TYPES = array(
		'ai_response' => true,
	);

	/**
	 * Normalize a step return value into the explicit execution result contract.
	 *
	 * Packet lists remain the legacy transport shape. Result-shaped arrays may
	 * carry status independently from packets:
	 *
	 * array(
	 *     'status'          => 'succeeded|failed|completed_no_items|blocked',
	 *     'packets'         => array(),
	 *     'reason'          => '...',
	 *     'error'           => '...',
	 *     'terminal_status' => null,
	 * )
	 *
	 * @param mixed  $step_output Step return value.
	 * @param string $step_type   Step type identifier.
	 * @return array{status: string, packets: array, reason: string, error: ?string, diagnostics: array, terminal_status: ?string, success: bool, packet_count: int, step_result: array}
	 */
	public static function fromStepOutput( $step_output, string $step_type = '' ): array {
		if ( self::isExplicitResult( $step_output ) ) {
			$packets = self::normalizePackets( $step_output['packets'] ?? ( $step_output['data_packets'] ?? array() ) );
			$status  = self::normalizeStatus( $step_output['status'] ?? '' );
			$reason  = is_scalar( $step_output['reason'] ?? null ) ? self::sanitizeReason( $step_output['reason'] ) : '';
			$error   = self::normalizeError( $step_output['error'] ?? ( $step_output['error_message'] ?? null ) );
			$context = self::envelopeContextFromOutput( $step_output );

			if ( '' === $status ) {
				$classified = self::classify( $packets, $step_type );
				$status     = $classified['status'];
				$reason     = '' !== $reason ? $reason : $classified['reason'];
			}

			if ( '' === $reason ) {
				$reason = self::defaultReasonForStatus( $status, $step_type );
			}

			$terminal_status = $step_output['terminal_status'] ?? null;
			$terminal_status = is_scalar( $terminal_status ) && '' !== trim( (string) $terminal_status ) ? trim( (string) $terminal_status ) : null;

			return self::buildResult( $status, $packets, $reason, $terminal_status, $error, array(), $context );
		}

		return self::classify( self::normalizePackets( $step_output ), $step_type );
	}

	/**
	 * Classify legacy step output packets into an execution result.
	 *
	 * @param array  $data_packets Returned data packets.
	 * @param string $step_type    Step type identifier.
	 * @return array{status: string, packets: array, reason: string, error: ?string, diagnostics: array, terminal_status: ?string, success: bool, packet_count: int, step_result: array}
	 */
	public static function classify( array $data_packets, string $step_type = '' ): array {
		$data_packets = self::normalizePackets( $data_packets );
		$packet_count = count( $data_packets );

		if ( 0 === $packet_count ) {
			if ( in_array( $step_type, array( 'fetch', 'event_import' ), true ) ) {
				return self::buildResult( self::STATUS_COMPLETED_NO_ITEMS, array(), 'completed_no_items', JobStatus::COMPLETED_NO_ITEMS );
			}

			return self::buildResult( self::STATUS_FAILED, array(), 'empty_data_packet_returned', null );
		}

		$successful_handlers = self::collectSuccessfulHandlerTools( $data_packets );
		$has_success_packet  = false;
		$failed_tool_packet  = null;

		foreach ( $data_packets as $packet ) {
			$metadata = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
			$type     = (string) ( $packet['type'] ?? '' );

			if ( array_key_exists( 'step_execution_success', $metadata ) ) {
				if ( false === (bool) $metadata['step_execution_success'] ) {
					return self::buildResult( self::STATUS_FAILED, $data_packets, self::sanitizeReason( $metadata['failure_reason'] ?? 'step_execution_failed' ), null, self::errorFromMetadata( $metadata ), self::diagnosticsFromMetadata( $metadata ) );
				}

				$has_success_packet = true;
				continue;
			}

			if ( isset( $metadata['success'] ) && false === $metadata['success'] ) {
				return self::buildResult( self::STATUS_FAILED, $data_packets, self::sanitizeReason( $metadata['failure_reason'] ?? 'packet_failure' ), null, self::errorFromMetadata( $metadata ), self::diagnosticsFromMetadata( $metadata ) );
			}

			if ( isset( $metadata['tool_success'] ) && false === $metadata['tool_success'] ) {
				if ( ! empty( $metadata['tool_failure_non_fatal'] ) ) {
					continue;
				}

				$handler_tool = isset( $metadata['handler_tool'] ) ? (string) $metadata['handler_tool'] : '';
				if ( '' !== $handler_tool && isset( $successful_handlers[ $handler_tool ] ) ) {
					continue;
				}

				$failed_tool_packet ??= $packet;
				continue;
			}

			if ( ! isset( self::NON_SUCCESS_PACKET_TYPES[ $type ] ) ) {
				$has_success_packet = true;
			}
		}

		if ( ! $has_success_packet && null !== $failed_tool_packet ) {
			$metadata = is_array( $failed_tool_packet['metadata'] ?? null ) ? $failed_tool_packet['metadata'] : array();

			return self::buildResult( self::STATUS_FAILED, $data_packets, self::sanitizeReason( $metadata['failure_reason'] ?? 'tool_result_failed' ), null, self::errorFromMetadata( $metadata ), self::diagnosticsFromMetadata( $metadata ) );
		}

		return self::buildResult(
			$has_success_packet ? self::STATUS_SUCCEEDED : self::STATUS_FAILED,
			$data_packets,
			$has_success_packet ? 'completed' : self::fallbackReason( $step_type ),
			null
		);
	}

	private static function buildResult( string $status, array $packets, string $reason, ?string $terminal_status, ?string $error = null, array $diagnostics = array(), array $envelope_context = array() ): array {
		$status = self::normalizeStatus( $status );
		if ( '' === $status ) {
			$status = self::STATUS_FAILED;
		}

		$result = array(
			'status'          => $status,
			'packets'         => $packets,
			'reason'          => self::sanitizeReason( $reason ),
			'error'           => self::normalizeError( $error ),
			'diagnostics'     => $diagnostics,
			'terminal_status' => $terminal_status,
			'success'         => self::STATUS_SUCCEEDED === $status,
			'packet_count'    => count( $packets ),
		);

		$result['step_result'] = StepResult::fromExecutionResult( $result, $envelope_context );

		return $result;
	}

	private static function envelopeContextFromOutput( array $step_output ): array {
		return array_filter(
			array(
				'outputs'       => is_array( $step_output['outputs'] ?? null ) ? $step_output['outputs'] : null,
				'artifact_refs' => is_array( $step_output['artifact_refs'] ?? null ) ? $step_output['artifact_refs'] : ( is_array( $step_output['artifacts'] ?? null ) ? $step_output['artifacts'] : null ),
				'packet_refs'   => is_array( $step_output['packet_refs'] ?? null ) ? $step_output['packet_refs'] : null,
				'replay'        => is_array( $step_output['replay'] ?? null ) ? $step_output['replay'] : null,
			),
			fn( $value ) => null !== $value
		);
	}

	private static function errorFromMetadata( array $metadata ): ?string {
		$error = self::normalizeError( $metadata['error'] ?? ( $metadata['error_message'] ?? null ) );
		if ( null !== $error ) {
			return $error;
		}

		$envelope = is_array( $metadata['tool_result_envelope'] ?? null ) ? $metadata['tool_result_envelope'] : array();
		return self::normalizeError( $envelope['error'] ?? ( $envelope['error_message'] ?? ( $envelope['message'] ?? null ) ) );
	}

	private static function diagnosticsFromMetadata( array $metadata ): array {
		$diagnostics = array_filter(
			array(
				'tool_name'      => self::normalizeDiagnosticScalar( $metadata['tool_name'] ?? null ),
				'handler_tool'   => self::normalizeDiagnosticScalar( $metadata['handler_tool'] ?? null ),
				'failure_reason' => self::normalizeDiagnosticScalar( $metadata['failure_reason'] ?? null ),
				'error'          => self::errorFromMetadata( $metadata ),
			),
			fn( $value ) => null !== $value && '' !== $value
		);

		$envelope = is_array( $metadata['tool_result_envelope'] ?? null ) ? $metadata['tool_result_envelope'] : array();
		if ( array() !== $envelope ) {
			$diagnostics['tool_result'] = array_filter(
				array(
					'success' => isset( $envelope['success'] ) ? (bool) $envelope['success'] : null,
					'status'  => self::normalizeDiagnosticScalar( $envelope['status'] ?? null ),
					'code'    => self::normalizeDiagnosticScalar( $envelope['code'] ?? ( $envelope['error_code'] ?? null ) ),
					'message' => self::normalizeDiagnosticScalar( $envelope['message'] ?? ( $envelope['error_message'] ?? ( $envelope['error'] ?? null ) ) ),
				),
				fn( $value ) => null !== $value && '' !== $value
			);
		}

		return $diagnostics;
	}

	private static function normalizeDiagnosticScalar( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		return '' !== $value ? $value : null;
	}

	private static function normalizeError( $error ): ?string {
		if ( ! is_scalar( $error ) ) {
			return null;
		}

		$error = trim( (string) $error );
		return '' !== $error ? $error : null;
	}

	private static function isExplicitResult( $step_output ): bool {
		return is_array( $step_output ) && ( array_key_exists( 'status', $step_output ) || array_key_exists( 'packets', $step_output ) || array_key_exists( 'terminal_status', $step_output ) );
	}

	private static function normalizePackets( $packets ): array {
		if ( ! is_array( $packets ) ) {
			return array();
		}

		return array_values( $packets );
	}

	private static function normalizeStatus( $status ): string {
		$status = self::sanitizeReason( $status );

		return in_array( $status, array( self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_COMPLETED_NO_ITEMS, self::STATUS_BLOCKED ), true ) ? $status : '';
	}

	private static function defaultReasonForStatus( string $status, string $step_type ): string {
		switch ( $status ) {
			case self::STATUS_SUCCEEDED:
				return 'completed';
			case self::STATUS_COMPLETED_NO_ITEMS:
				return 'completed_no_items';
			case self::STATUS_BLOCKED:
				return 'blocked';
			case self::STATUS_FAILED:
			default:
				return self::fallbackReason( $step_type );
		}
	}

	/**
	 * Collect handler tools with at least one successful result packet.
	 *
	 * @param array $data_packets Returned data packets.
	 * @return array<string,bool>
	 */
	private static function collectSuccessfulHandlerTools( array $data_packets ): array {
		$handlers = array();

		foreach ( $data_packets as $packet ) {
			$metadata     = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
			$handler_tool = isset( $metadata['handler_tool'] ) ? (string) $metadata['handler_tool'] : '';

			if ( '' === $handler_tool ) {
				continue;
			}

			if ( 'ai_handler_complete' === ( $packet['type'] ?? '' ) || true === ( $metadata['tool_success'] ?? false ) ) {
				$handlers[ $handler_tool ] = true;
			}
		}

		return $handlers;
	}

	private static function fallbackReason( string $step_type ): string {
		return 'ai' === $step_type ? 'ai_response_without_tool_result' : 'step_output_not_successful';
	}

	private static function sanitizeReason( $reason ): string {
		$reason = is_scalar( $reason ) ? trim( (string) $reason ) : '';
		if ( '' === $reason ) {
			return 'step_execution_failed';
		}

		return function_exists( 'sanitize_key' ) ? sanitize_key( str_replace( ' ', '_', $reason ) ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( str_replace( ' ', '_', $reason ) ) );
	}
}
