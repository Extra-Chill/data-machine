<?php
/**
 * Agent bundle drift status helper.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Compares installed bundle metadata against an available manifest.
 */
final class AgentBundleDriftStatus {

	public const CURRENT       = 'current';
	public const NOT_INSTALLED = 'not_installed';
	public const WRONG_BUNDLE  = 'wrong_bundle';
	public const VERSION_DRIFT = 'version_drift';
	public const SOURCE_DRIFT  = 'source_drift';

	/**
	 * Compare installed metadata to an available bundle manifest.
	 *
	 * @param AgentBundleManifest $available Available bundle manifest.
	 * @param array|null          $installed Installed metadata snapshot.
	 * @return array{status:string,is_drifted:bool,available:array,installed:?array,differences:string[]}
	 */
	public static function compare( AgentBundleManifest $available, ?array $installed ): array {
		$available_metadata = $available->version_metadata();
		$installed_metadata = self::normalize_installed_metadata( $installed );

		if ( null === $installed_metadata ) {
			return self::result( self::NOT_INSTALLED, $available_metadata, null, array( 'bundle_slug' ) );
		}

		if ( $available_metadata['bundle_slug'] !== $installed_metadata['bundle_slug'] ) {
			return self::result( self::WRONG_BUNDLE, $available_metadata, $installed_metadata, array( 'bundle_slug' ) );
		}

		if ( $available_metadata['bundle_version'] !== $installed_metadata['bundle_version'] ) {
			return self::result( self::VERSION_DRIFT, $available_metadata, $installed_metadata, array( 'bundle_version' ) );
		}

		$source_differences = array();
		foreach ( array( 'source_ref', 'source_revision' ) as $field ) {
			if ( '' !== $available_metadata[ $field ] && '' !== $installed_metadata[ $field ] && $available_metadata[ $field ] !== $installed_metadata[ $field ] ) {
				$source_differences[] = $field;
			}
		}

		if ( ! empty( $source_differences ) ) {
			return self::result( self::SOURCE_DRIFT, $available_metadata, $installed_metadata, $source_differences );
		}

		return self::result( self::CURRENT, $available_metadata, $installed_metadata, array() );
	}

	/**
	 * Normalize installed metadata from agent_config or importer state.
	 *
	 * @param array|null $metadata Raw metadata.
	 * @return array|null
	 */
	public static function normalize_installed_metadata( ?array $metadata ): ?array {
		if ( empty( $metadata ) || ! is_array( $metadata ) ) {
			return null;
		}

		$bundle_slug    = isset( $metadata['bundle_slug'] ) ? PortableSlug::normalize( (string) $metadata['bundle_slug'], 'bundle' ) : '';
		$bundle_version = isset( $metadata['bundle_version'] ) ? trim( (string) $metadata['bundle_version'] ) : '';
		if ( '' === $bundle_slug || '' === $bundle_version ) {
			return null;
		}

		return array(
			'bundle_slug'     => $bundle_slug,
			'bundle_version'  => $bundle_version,
			'source_ref'      => isset( $metadata['source_ref'] ) ? trim( (string) $metadata['source_ref'] ) : '',
			'source_revision' => isset( $metadata['source_revision'] ) ? trim( (string) $metadata['source_revision'] ) : '',
		);
	}

	private static function result( string $status, array $available, ?array $installed, array $differences ): array {
		return array(
			'status'      => $status,
			'is_drifted'  => self::CURRENT !== $status,
			'available'   => $available,
			'installed'   => $installed,
			'differences' => $differences,
		);
	}
}
