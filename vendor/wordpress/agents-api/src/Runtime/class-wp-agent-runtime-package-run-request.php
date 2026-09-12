<?php
/**
 * Runtime package run request value object.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, JSON-friendly request for running a portable agent package.
 *
 * Agents API defines the neutral contract; consumers provide package loading,
 * workflow materialization, storage, and execution adapters.
 *
 * @since 0.3.0
 */
final class WP_Agent_Runtime_Package_Run_Request {

	/**
	 * @param array<string,mixed> $package       Portable package descriptor.
	 * @param array<string,mixed> $workflow      Workflow selector/spec.
	 * @param array<string,mixed> $input         Runtime input supplied to the workflow.
	 * @param array<string,mixed> $options       Execution options such as budgets.
	 * @param array<string,mixed> $metadata      Caller metadata for observability.
	 * @param array<string,mixed> $replay        Replay/materialization hints.
	 */
	public function __construct(
		private array $package,
		private array $workflow,
		private array $input = array(),
		private array $options = array(),
		private array $metadata = array(),
		private array $replay = array()
	) {}

	/**
	 * Build from the canonical ability/filter input.
	 *
	 * @param array<mixed> $input Raw input.
	 * @return self|\WP_Error
	 */
	public static function from_array( array $input ) {
		$package  = self::array_value( $input['package'] ?? array() );
		$workflow = self::array_value( $input['workflow'] ?? array() );

		$package_source = self::string_value( $package['source'] ?? '' );
		$package_slug   = self::string_value( $package['slug'] ?? $package['id'] ?? '' );
		if ( '' === $package_source && '' === $package_slug ) {
			return new \WP_Error(
				'agents_runtime_package_run_missing_package',
				'Runtime package run requests require package.source or package.slug.'
			);
		}

		$workflow_id   = self::string_value( $workflow['id'] ?? '' );
		$workflow_spec = self::array_value( $workflow['spec'] ?? array() );
		if ( '' === $workflow_id && empty( $workflow_spec ) ) {
			return new \WP_Error(
				'agents_runtime_package_run_missing_workflow',
				'Runtime package run requests require workflow.id or workflow.spec.'
			);
		}

		return new self(
			$package,
			$workflow,
			self::array_value( $input['input'] ?? array() ),
			self::array_value( $input['options'] ?? array() ),
			self::array_value( $input['metadata'] ?? array() ),
			self::array_value( $input['replay'] ?? array() )
		);
	}

	/** @return array<string,mixed> */
	public function get_package(): array {
		return $this->package;
	}

	/** @return array<string,mixed> */
	public function get_workflow(): array {
		return $this->workflow;
	}

	/** @return array<string,mixed> */
	public function get_input(): array {
		return $this->input;
	}

	/** @return array<string,mixed> */
	public function get_options(): array {
		return $this->options;
	}

	/** @return array<string,mixed> */
	public function get_metadata(): array {
		return $this->metadata;
	}

	/** @return array<string,mixed> */
	public function get_replay(): array {
		return $this->replay;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return array(
			'package'  => $this->package,
			'workflow' => $this->workflow,
			'input'    => $this->input,
			'options'  => $this->options,
			'metadata' => $this->metadata,
			'replay'   => $this->replay,
		);
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** @return array<string,mixed> */
	private static function array_value( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $item;
			}
		}
		return $normalized;
	}
}
