<?php
/**
 * Runtime Task Ability.
 *
 * @package DataMachine\Abilities\Runtime
 */

namespace DataMachine\Abilities\Runtime;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatches a runtime task through an ability-shaped contract.
 */
class RuntimeTaskAbility {

	public const RESULT_SCHEMA = 'datamachine/runtime-task-result/v1';

	public function __construct() {
		$this->registerAbility();
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/run-runtime-task',
				array(
					'label'               => __( 'Run Runtime Task', 'data-machine' ),
					'description'         => __( 'Run a portable runtime task through a WordPress ability or external runner filter and return a normalized result envelope.', 'data-machine' ),
					'category'            => 'datamachine-agent',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'ability' ),
						'properties' => array(
							'ability'         => array(
								'type'        => 'string',
								'description' => __( 'Target ability name using namespace/name form.', 'data-machine' ),
							),
							'input'           => array(
								'type'        => 'object',
								'description' => __( 'Input forwarded to the selected runtime ability.', 'data-machine' ),
							),
							'timeout_seconds' => array(
								'type'        => 'integer',
								'description' => __( 'Advisory timeout forwarded to the selected runner.', 'data-machine' ),
							),
							'context'         => array(
								'type'        => 'object',
								'description' => __( 'Non-secret caller metadata for routing, diagnostics, and artifact policy.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'schema'      => array( 'type' => 'string' ),
							'status'      => array( 'type' => 'string' ),
							'task'        => array( 'type' => 'object' ),
							'result'      => array( 'type' => array( 'object', 'array', 'string', 'number', 'boolean', 'null' ) ),
							'artifacts'   => array( 'type' => 'array' ),
							'diagnostics' => array( 'type' => 'array' ),
							'metrics'     => array( 'type' => 'object' ),
							'metadata'    => array( 'type' => 'object' ),
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

	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Run a runtime task and normalize its result.
	 *
	 * @param array<string,mixed> $input Runtime task request.
	 * @return array<string,mixed>
	 */
	public function execute( array $input ): array {
		$started_at = microtime( true );
		$request    = $this->normalizeRequest( $input );

		if ( '' === $request['ability'] ) {
			return $this->failure( $request, 'runtime_task_ability_invalid', __( 'Runtime task ability must use namespace/name form.', 'data-machine' ), $started_at );
		}

		if ( 'datamachine/run-runtime-task' === $request['ability'] ) {
			return $this->failure( $request, 'runtime_task_recursion_denied', __( 'Runtime task dispatch cannot target itself.', 'data-machine' ), $started_at );
		}

		/**
		 * Allows an external runtime to execute the normalized task request.
		 *
		 * Returning null delegates to the local WordPress ability dispatch path.
		 * Runners should return their native result; this ability owns normalization.
		 *
		 * @param mixed               $result  Runtime result or null when unhandled.
		 * @param array<string,mixed> $request Normalized runtime task request.
		 * @param array<string,mixed> $input   Original ability input.
		 */
		$external_result = apply_filters( 'datamachine_runtime_task_execute', null, $request, $input );
		if ( null !== $external_result ) {
			return $this->normalizeResult( $request, $external_result, $started_at );
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $this->failure( $request, 'runtime_task_registry_unavailable', __( 'The WordPress Abilities API is not available for runtime task dispatch.', 'data-machine' ), $started_at );
		}

		$ability = wp_get_ability( $request['ability'] );
		if ( ! $ability || ! is_callable( array( $ability, 'execute' ) ) ) {
			return $this->failure( $request, 'runtime_task_ability_unavailable', __( 'The requested runtime task ability is not available.', 'data-machine' ), $started_at );
		}

		$result = $ability->execute( $request['input'] );

		return $this->normalizeResult( $request, $result, $started_at );
	}

	/**
	 * @param array<string,mixed> $input Runtime task request.
	 * @return array{ability:string,input:array<string,mixed>,timeout_seconds:int,context:array<string,mixed>}
	 */
	private function normalizeRequest( array $input ): array {
		$ability = trim( (string) ( $input['ability'] ?? $input['name'] ?? '' ) );
		if ( ! preg_match( '#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*$#', $ability ) ) {
			$ability = '';
		}

		return array(
			'ability'         => $ability,
			'input'           => is_array( $input['input'] ?? null ) ? $input['input'] : array(),
			'timeout_seconds' => max( 1, min( 3600, (int) ( $input['timeout_seconds'] ?? $input['timeout'] ?? 300 ) ) ),
			'context'         => is_array( $input['context'] ?? null ) ? $input['context'] : array(),
		);
	}

	/**
	 * @param array<string,mixed> $request Runtime task request.
	 * @param mixed               $result Runtime result.
	 * @return array<string,mixed>
	 */
	private function normalizeResult( array $request, mixed $result, float $started_at ): array {
		if ( is_wp_error( $result ) ) {
			return $this->failure(
				$request,
				$result->get_error_code(),
				$result->get_error_message(),
				$started_at,
				is_array( $result->get_error_data() ) ? $result->get_error_data() : array()
			);
		}

		$result_array = is_array( $result ) ? $result : array();
		$status       = $this->normalizeStatus( (string) ( $result_array['status'] ?? '' ) );
		$diagnostics  = is_array( $result_array['diagnostics'] ?? null ) ? array_values( $result_array['diagnostics'] ) : array();
		$artifacts    = $this->extractArtifacts( $result_array );

		return array(
			'schema'      => self::RESULT_SCHEMA,
			'status'      => $status,
			'task'        => $this->taskMetadata( $request ),
			'result'      => $result,
			'artifacts'   => $artifacts,
			'diagnostics' => $diagnostics,
			'metrics'     => $this->metrics( $started_at, is_array( $result_array['metrics'] ?? null ) ? $result_array['metrics'] : array() ),
			'metadata'    => array(
				'dispatch' => 'ability',
			),
		);
	}

	/**
	 * @param array<string,mixed> $request Runtime task request.
	 * @param array<string,mixed> $data Optional error context.
	 * @return array<string,mixed>
	 */
	private function failure( array $request, string $code, string $message, float $started_at, array $data = array() ): array {
		return array(
			'schema'      => self::RESULT_SCHEMA,
			'status'      => 'failed',
			'task'        => $this->taskMetadata( $request ),
			'result'      => null,
			'artifacts'   => array(),
			'diagnostics' => array(
				array_filter(
					array(
						'severity' => 'error',
						'code'     => $code,
						'message'  => $message,
						'context'  => $data,
					),
					static fn ( mixed $value ): bool => array() !== $value
				),
			),
			'metrics'     => $this->metrics( $started_at ),
			'metadata'    => array(
				'dispatch' => 'ability',
			),
		);
	}

	/**
	 * @param array<string,mixed> $request Runtime task request.
	 * @return array<string,mixed>
	 */
	private function taskMetadata( array $request ): array {
		return array(
			'ability'         => (string) ( $request['ability'] ?? '' ),
			'timeout_seconds' => (int) ( $request['timeout_seconds'] ?? 0 ),
			'context'         => is_array( $request['context'] ?? null ) ? $request['context'] : array(),
		);
	}

	/**
	 * @param array<string,mixed> $native_metrics Metrics from the runtime result.
	 * @return array<string,mixed>
	 */
	private function metrics( float $started_at, array $native_metrics = array() ): array {
		return array_replace(
			$native_metrics,
			array(
				'duration_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			)
		);
	}

	private function normalizeStatus( string $status ): string {
		$status = sanitize_key( $status );
		if ( in_array( $status, array( 'completed', 'succeeded', 'success', 'ok' ), true ) ) {
			return 'completed';
		}

		if ( in_array( $status, array( 'timed_out', 'timeout' ), true ) ) {
			return 'timed_out';
		}

		if ( in_array( $status, array( 'failed', 'error', 'cancelled', 'canceled' ), true ) ) {
			return 'failed';
		}

		return 'completed';
	}

	/**
	 * @param array<string,mixed> $result Runtime result array.
	 * @return array<int,mixed>
	 */
	private function extractArtifacts( array $result ): array {
		if ( is_array( $result['artifacts'] ?? null ) ) {
			return array_values( $result['artifacts'] );
		}

		if ( is_array( $result['artifact_refs'] ?? null ) ) {
			return array_map(
				static fn ( mixed $ref ): array => array( 'ref' => (string) $ref ),
				array_values( $result['artifact_refs'] )
			);
		}

		return array();
	}
}
