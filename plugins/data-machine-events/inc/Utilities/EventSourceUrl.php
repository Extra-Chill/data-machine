<?php
/**
 * Validation for public event attribution URLs.
 *
 * @package DataMachineEvents\Utilities
 */

namespace DataMachineEvents\Utilities;

use function DataMachineEvents\Core\data_machine_events_is_gated_ticket_url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventSourceUrl {

	public const META_KEY = '_datamachine_event_source_url';

	/**
	 * Return an absolute web URL safe to expose independently of ticket links.
	 *
	 * @param mixed $value Candidate source URL.
	 */
	public static function sanitize( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '#^https?://#i', trim( $value ) ) ) {
			return '';
		}

		$url   = esc_url_raw( trim( $value ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || data_machine_events_is_gated_ticket_url( $url ) ) {
			return '';
		}

		return $url;
	}
}
