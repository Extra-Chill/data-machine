<?php
/**
 * REST ability result presentation spec.
 *
 * @package DataMachine\Api
 */

namespace DataMachine\Api;

use DataMachine\Core\AbilityResult;

defined( 'ABSPATH' ) || exit;

/**
 * Describes how an ability result should be exposed through REST.
 */
class RestResultSpec {

	/** @var callable|null */
	private $data_callback;

	/** @var callable|null */
	private $extra_callback;

	/** @var callable|null */
	private $failure_status_callback;

	private string $default_code;
	private string $default_message;
	private int $default_status;

	/**
	 * @param callable|null $data_callback           Maps normalized successful result to response data.
	 * @param callable|null $extra_callback          Maps normalized successful result to top-level extras.
	 * @param string        $default_code            Default error code.
	 * @param string        $default_message         Default error message.
	 * @param int           $default_status          Default HTTP status for legacy failures.
	 * @param callable|null $failure_status_callback Maps normalized failed result to HTTP status.
	 */
	public function __construct( $data_callback = null, $extra_callback = null, string $default_code = 'ability_failed', string $default_message = 'Ability execution failed.', int $default_status = 500, $failure_status_callback = null ) {
		$this->data_callback           = $data_callback;
		$this->extra_callback          = $extra_callback;
		$this->default_code            = $default_code;
		$this->default_message         = $default_message;
		$this->default_status          = $default_status;
		$this->failure_status_callback = $failure_status_callback;
	}

	/**
	 * Create a single-resource REST response spec.
	 *
	 * @param callable|null $data_callback           Maps normalized successful result to response data.
	 * @param callable|null $extra_callback          Maps normalized successful result to top-level extras.
	 * @param string        $default_code            Default error code.
	 * @param string        $default_message         Default error message.
	 * @param int           $default_status          Default HTTP status for legacy failures.
	 * @param callable|null $failure_status_callback Maps normalized failed result to HTTP status.
	 * @return self
	 */
	public static function item( $data_callback = null, $extra_callback = null, string $default_code = 'ability_failed', string $default_message = 'Ability execution failed.', int $default_status = 500, $failure_status_callback = null ): self {
		return new self( $data_callback, $extra_callback, $default_code, $default_message, $default_status, $failure_status_callback );
	}

	/**
	 * Convert an ability result to a REST response or error.
	 *
	 * @param mixed $result Ability execution result.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function response( $result ) {
		$normalized = AbilityResult::normalize( $result );

		if ( is_array( $normalized ) && isset( $normalized['success'] ) && ! $normalized['success'] && ! isset( $normalized['status'] ) && $this->failure_status_callback ) {
			$status = call_user_func( $this->failure_status_callback, $normalized );
			if ( null !== $status ) {
				$normalized['status'] = (int) $status;
			}
		}

		$data = null;
		if ( is_array( $normalized ) && ( ! isset( $normalized['success'] ) || $normalized['success'] ) && $this->data_callback ) {
			$data = call_user_func( $this->data_callback, $normalized );
		}

		$extra = array();
		if ( is_array( $normalized ) && ( ! isset( $normalized['success'] ) || $normalized['success'] ) && $this->extra_callback ) {
			$extra = call_user_func( $this->extra_callback, $normalized );
			if ( ! is_array( $extra ) ) {
				$extra = array();
			}
		}

		return AbilityResult::rest_item_response( $normalized, $data, $extra, $this->default_code, $this->default_message, $this->default_status );
	}
}
