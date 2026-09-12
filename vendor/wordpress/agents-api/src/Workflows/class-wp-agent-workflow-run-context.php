<?php
/**
 * Mutable workflow execution context shared by workflow step executors.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

class WP_Agent_Workflow_Run_Context {

	/**
	 * @param array<mixed> $data Context data exposed to binding resolution and step handlers.
	 */
	public function __construct( private array $data ) {}

	/**
	 * Build the initial context for a workflow run.
	 *
	 * @param array<mixed> $inputs Caller-supplied workflow inputs.
	 */
	public static function from_inputs( array $inputs ): self {
		return new self(
			array(
				'inputs' => $inputs,
				'steps'  => array(),
				'vars'   => array(),
			)
		);
	}

	/**
	 * Return the context as the array shape expected by bindings and handlers.
	 *
	 * @return array<mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Return a copy of the context with scoped iteration variables merged in.
	 *
	 * @param array<mixed> $vars Variables to expose under `${vars.*}`.
	 */
	public function with_vars( array $vars ): self {
		$data         = $this->data;
		$data['vars'] = array_merge( (array) ( $data['vars'] ?? array() ), $vars );
		if ( ! isset( $data['steps'] ) || ! is_array( $data['steps'] ) ) {
			$data['steps'] = array();
		}

		return new self( $data );
	}

	/**
	 * Record a successful step output for subsequent `${steps.*}` bindings.
	 *
	 * @param array<mixed> $output Normalized step output.
	 */
	public function set_step_output( string $step_id, array $output ): void {
		if ( '' === $step_id ) {
			return;
		}
		if ( ! isset( $this->data['steps'] ) || ! is_array( $this->data['steps'] ) ) {
			$this->data['steps'] = array();
		}

		$this->data['steps'][ $step_id ] = array( 'output' => $output );
	}
}
