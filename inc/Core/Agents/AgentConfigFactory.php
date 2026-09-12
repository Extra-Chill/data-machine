<?php
/**
 * Agent configuration normalization helpers.
 *
 * @package DataMachine\Core\Agents
 */

namespace DataMachine\Core\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Builds canonical persisted agent_config payloads.
 */
final class AgentConfigFactory {

	/**
	 * Normalize persisted agent_config into the canonical model shape.
	 *
	 * Canonical model defaults live at default_provider/default_model. Legacy
	 * scalar provider/model aliases are accepted as input but not persisted back.
	 *
	 * @param array<string,mixed> $config Raw agent config.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $config ): array {
		$config = self::normalize_model_defaults( $config );
		$config = self::normalize_mode_models( $config );

		return $config;
	}

	/** @param array<string,mixed> $config */
	private static function normalize_model_defaults( array $config ): array {
		$legacy_provider = is_scalar( $config['provider'] ?? null ) ? trim( (string) $config['provider'] ) : '';
		$legacy_model    = is_scalar( $config['model'] ?? null ) ? trim( (string) $config['model'] ) : '';

		if ( '' === trim( (string) ( $config['default_provider'] ?? '' ) ) && '' !== $legacy_provider ) {
			$config['default_provider'] = $legacy_provider;
		}

		if ( '' === trim( (string) ( $config['default_model'] ?? '' ) ) && '' !== $legacy_model ) {
			$config['default_model'] = $legacy_model;
		}

		if ( is_scalar( $config['provider'] ?? null ) ) {
			unset( $config['provider'] );
		}
		if ( is_scalar( $config['model'] ?? null ) ) {
			unset( $config['model'] );
		}

		return $config;
	}

	/** @param array<string,mixed> $config */
	private static function normalize_mode_models( array $config ): array {
		if ( ! is_array( $config['mode_models'] ?? null ) ) {
			return $config;
		}

		$mode_models = array();
		foreach ( $config['mode_models'] as $mode => $mode_config ) {
			if ( ! is_scalar( $mode ) || ! is_array( $mode_config ) ) {
				continue;
			}

			$mode = self::sanitize_key( (string) $mode );
			if ( '' === $mode ) {
				continue;
			}

			$provider = is_scalar( $mode_config['provider'] ?? null ) ? trim( (string) $mode_config['provider'] ) : '';
			$model    = is_scalar( $mode_config['model'] ?? null ) ? trim( (string) $mode_config['model'] ) : '';
			if ( '' === $provider && '' === $model ) {
				continue;
			}

			$mode_models[ $mode ] = array_filter(
				array(
					'provider' => $provider,
					'model'    => $model,
				),
				static fn( string $value ): bool => '' !== $value
			);
		}

		if ( empty( $mode_models ) ) {
			unset( $config['mode_models'] );
		} else {
			ksort( $mode_models, SORT_STRING );
			$config['mode_models'] = $mode_models;
		}

		return $config;
	}

	private static function sanitize_key( string $key ): string {
		if ( \function_exists( 'sanitize_key' ) ) {
			return \sanitize_key( $key );
		}

		$sanitized = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key );
		return strtolower( is_string( $sanitized ) ? $sanitized : '' );
	}
}
