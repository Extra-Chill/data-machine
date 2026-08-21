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

	private string $default_code;
	private string $default_message;
	private int $default_status;

	/**
	 * @param callable|null $data_callback           Maps normalized successful result to response data.
	 * @param callable|null $extra_callback          Maps normalized successful result to top-level extras.
	 * @param string        $default_code            Default error code.
	 * @param string        $default_message         Default error message.
	 * @param int           $default_status          Default HTTP status.
	 */
	public function __construct( $data_callback = null, $extra_callback = null, string $default_code = 'ability_failed', string $default_message = 'Ability execution failed.', int $default_status = 500 ) {
		$this->data_callback   = $data_callback;
		$this->extra_callback  = $extra_callback;
		$this->default_code    = $default_code;
		$this->default_message = $default_message;
		$this->default_status  = $default_status;
	}

	/**
	 * Create a single-resource REST response spec.
	 *
	 * @param callable|null $data_callback           Maps normalized successful result to response data.
	 * @param callable|null $extra_callback          Maps normalized successful result to top-level extras.
	 * @param string        $default_code            Default error code.
	 * @param string        $default_message         Default error message.
	 * @param int           $default_status          Default HTTP status.
	 * @return self
	 */
	public static function item( $data_callback = null, $extra_callback = null, string $default_code = 'ability_failed', string $default_message = 'Ability execution failed.', int $default_status = 500 ): self {
		return new self( $data_callback, $extra_callback, $default_code, $default_message, $default_status );
	}

	/** Preserve a legacy REST code while retaining native ability diagnostics. */
	public static function legacy_error( \WP_Error $error, string $rest_code ): \WP_Error {
		$data                       = is_array( $error->get_error_data() ) ? $error->get_error_data() : array();
		$data['ability_error_code'] = $error->get_error_code();
		return new \WP_Error( $rest_code, $error->get_error_message(), $data );
	}

	/**
	 * Convert an ability result to a REST response or error.
	 *
	 * @param mixed $result Ability execution result.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function response( $result ) {
		// Native errors already carry the callback's machine code and status.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = null;
		if ( is_array( $result ) && $this->data_callback ) {
			$data = call_user_func( $this->data_callback, $result );
		}

		$extra = array();
		if ( is_array( $result ) && $this->extra_callback ) {
			$extra = call_user_func( $this->extra_callback, $result );
			if ( ! is_array( $extra ) ) {
				$extra = array();
			}
		}

		return AbilityResult::rest_item_response( $result, $data, $extra, $this->default_code, $this->default_message, $this->default_status );
	}
}
