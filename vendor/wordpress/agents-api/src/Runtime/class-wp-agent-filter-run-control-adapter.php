<?php
/**
 * Filter-backed generic run-control adapter.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

class WP_Agent_Filter_Run_Control_Adapter implements WP_Agent_Run_Control_Adapter {

	/**
	 * @param non-empty-string $status_filter             Filter that returns a get-run handler.
	 * @param non-empty-string $events_filter             Filter that returns a list-events handler.
	 * @param non-empty-string $cancel_filter             Filter that returns a cancel handler.
	 * @param non-empty-string $invalid_status_code       Error code for invalid get-run results.
	 * @param non-empty-string $invalid_events_code       Error code for invalid event results.
	 * @param non-empty-string $invalid_cancel_code       Error code for invalid cancel results.
	 * @param non-empty-string $events_no_handler_code    Error code when no event handler is registered.
	 * @param non-empty-string $events_no_handler_message Error message when no event handler is registered.
	 */
	public function __construct(
		private string $status_filter,
		private string $events_filter,
		private string $cancel_filter,
		private string $invalid_status_code,
		private string $invalid_events_code,
		private string $invalid_cancel_code,
		private string $events_no_handler_code,
		private string $events_no_handler_message
	) {}

	/**
	 * @param array<string,mixed> $input Run-control request input.
	 * @return array<string,mixed>|\WP_Error|null
	 */
	public function get_run( array $input ) {
		$handler = apply_filters( $this->status_filter, null, $input );
		if ( ! is_callable( $handler ) ) {
			return null;
		}

		return WP_Agent_Run_Control::normalize_run_result( call_user_func( $handler, $input ), $this->invalid_status_code );
	}

	/**
	 * @param array<string,mixed> $input Run-control request input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function list_events( array $input ) {
		$handler = apply_filters( $this->events_filter, null, $input );
		if ( ! is_callable( $handler ) ) {
			return new \WP_Error( $this->events_no_handler_code, $this->events_no_handler_message );
		}

		return WP_Agent_Run_Control::normalize_events_result( call_user_func( $handler, $input ), $this->invalid_events_code );
	}

	/**
	 * @param array<string,mixed> $input Run-control request input.
	 * @return array<string,mixed>|\WP_Error|null
	 */
	public function cancel_run( array $input ) {
		$handler = apply_filters( $this->cancel_filter, null, $input );
		if ( ! is_callable( $handler ) ) {
			return null;
		}

		return WP_Agent_Run_Control::normalize_cancel_result( call_user_func( $handler, $input ), $this->invalid_cancel_code );
	}
}
