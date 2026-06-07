<?php
/**
 * Date Formatter Utility
 *
 * Centralized date/time formatting for all display contexts.
 * Uses WordPress native functions to respect timezone and format settings.
 *
 * @package DataMachine\Core\Admin
 */

namespace DataMachine\Core\Admin;

use DataMachine\Core\JobStatus;

defined( 'ABSPATH' ) || exit;

class DateFormatter {

	private static ?string $date_format = null;
	private static ?string $time_format = null;

	private static function get_date_format(): string {
		if ( null === self::$date_format ) {
			self::$date_format = get_option( 'date_format' );
		}
		return self::$date_format;
	}

	private static function get_time_format(): string {
		if ( null === self::$time_format ) {
			self::$time_format = get_option( 'time_format' );
		}
		return self::$time_format;
	}

	/**
	 * Format a MySQL datetime string for display.
	 *
	 * Uses WordPress timezone and date/time format settings.
	 * Returns only the formatted timestamp - status display is handled by frontend.
	 *
	 * @param string|null $mysql_datetime MySQL datetime string (Y-m-d H:i:s)
	 * @param string|null $status Unused, kept for backward compatibility
	 * @return string Formatted datetime string
	 */
	public static function format_for_display( ?string $mysql_datetime): string {
		if ( empty( $mysql_datetime ) || '0000-00-00 00:00:00' === $mysql_datetime ) {
			return __( 'Never', 'data-machine' );
		}

		try {
			$timestamp = ( new \DateTime( $mysql_datetime, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return __( 'Invalid date', 'data-machine' );
		}

		$date_format = self::get_date_format();
		$time_format = self::get_time_format();

		return wp_date( "{$date_format} {$time_format}", $timestamp );
	}

	/**
	 * Format a MySQL datetime string for display (date only, no time).
	 *
	 * @param string|null $mysql_datetime MySQL datetime string
	 * @return string Formatted date string or "Never"
	 */
	public static function format_date_only( ?string $mysql_datetime ): string {
		if ( empty( $mysql_datetime ) || '0000-00-00 00:00:00' === $mysql_datetime ) {
			return __( 'Never', 'data-machine' );
		}

		try {
			$timestamp = ( new \DateTime( $mysql_datetime, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return __( 'Invalid date', 'data-machine' );
		}

		$date_format = self::get_date_format();
		return wp_date( $date_format, $timestamp );
	}

	/**
	 * Format a MySQL datetime string for display (time only, no date).
	 *
	 * @param string|null $mysql_datetime MySQL datetime string
	 * @return string Formatted time string or empty string
	 */
	public static function format_time_only( ?string $mysql_datetime ): string {
		if ( empty( $mysql_datetime ) || '0000-00-00 00:00:00' === $mysql_datetime ) {
			return '';
		}

		try {
			$timestamp = ( new \DateTime( $mysql_datetime, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return '';
		}

		$time_format = self::get_time_format();
		return wp_date( $time_format, $timestamp );
	}

	/**
	 * Format a Unix timestamp for display.
	 *
	 * @param int|null $timestamp Unix timestamp
	 * @return string Formatted datetime string or "Never"
	 */
	public static function format_timestamp( ?int $timestamp ): string {
		if ( empty( $timestamp ) ) {
			return __( 'Never', 'data-machine' );
		}

		$date_format = self::get_date_format();
		$time_format = self::get_time_format();

		return wp_date( "{$date_format} {$time_format}", $timestamp );
	}

	/**
	 * Format a MySQL datetime string for REST API responses.
	 *
	 * Returns ISO 8601 format with UTC indicator (Z suffix) for proper
	 * JavaScript Date parsing regardless of browser timezone.
	 *
	 * @param string|null $mysql_datetime MySQL datetime string (Y-m-d H:i:s in UTC)
	 * @return string|null ISO 8601 datetime string or null if invalid
	 */
	public static function format_for_api( ?string $mysql_datetime ): ?string {
		if ( empty( $mysql_datetime ) || '0000-00-00 00:00:00' === $mysql_datetime ) {
			return null;
		}
		try {
			$datetime = new \DateTime( $mysql_datetime, new \DateTimeZone( 'UTC' ) );
			return $datetime->format( 'Y-m-d\TH:i:s\Z' );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
