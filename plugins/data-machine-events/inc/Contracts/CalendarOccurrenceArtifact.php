<?php
/**
 * Canonical calendar occurrence contract artifact.
 *
 * @package DataMachineEvents\Contracts
 */

namespace DataMachineEvents\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Portable identity and integrity checks for the producer-owned fixture.
 */
final class CalendarOccurrenceArtifact {

	public const NAME = 'data-machine-events/calendar-occurrence';

	public const VERSION = 1;

	public const MANIFEST_RELATIVE_PATH = 'contracts/calendar-occurrence-v1.manifest.json';

	/**
	 * Load the checked-in artifact manifest.
	 *
	 * @return array<string,mixed>
	 */
	public static function manifest(): array {
		$path = DATA_MACHINE_EVENTS_PATH . self::MANIFEST_RELATIVE_PATH;
		if ( ! is_readable( $path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local checked-in artifact.
		$manifest = json_decode( (string) file_get_contents( $path ), true );

		return is_array( $manifest ) ? $manifest : array();
	}

	/**
	 * Verify a downstream pin against the producer-owned artifact.
	 *
	 * @param string $name    Expected artifact name.
	 * @param int    $version Expected artifact contract version.
	 * @param string $hash    Expected SHA-256 fixture hash.
	 * @return bool Whether identity, version, manifest hash, and bytes agree.
	 */
	public static function verify_pin( string $name, int $version, string $hash ): bool {
		$manifest = self::manifest();
		$file     = DATA_MACHINE_EVENTS_PATH . 'contracts/' . ( $manifest['file'] ?? '' );

		if ( self::NAME !== $name || self::VERSION !== $version ) {
			return false;
		}

		if ( self::NAME !== ( $manifest['name'] ?? '' ) || self::VERSION !== ( $manifest['version'] ?? 0 ) ) {
			return false;
		}

		if ( ! is_readable( $file ) || ! hash_equals( (string) ( $manifest['sha256'] ?? '' ), hash_file( 'sha256', $file ) ) ) {
			return false;
		}

		return hash_equals( (string) $manifest['sha256'], $hash );
	}
}
