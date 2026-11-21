<?php
/**
 * Auth ref resolver filter seam.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves symbolic auth refs without exporting raw credentials.
 */
final class AuthRefResolver {

	/**
	 * Build a standard unresolved envelope.
	 */
	public static function unresolved( AuthRef $ref, string $warning = '' ): array {
		return array(
			'resolved' => false,
			'ref'      => $ref->ref(),
			'provider' => $ref->provider(),
			'account'  => $ref->account(),
			'warning'  => '' !== $warning ? $warning : 'No local auth resolver handled this auth ref.',
		);
	}

	/**
	 * Resolve an auth ref through registered resolver objects and filters.
	 *
	 * @param  AuthRef|string $ref     Auth ref object or provider:account string.
	 * @param  array          $context Import/export context.
	 * @return array{resolved:bool, ref:string, provider:string, account:string, config?:array, warning?:string}
	 */
	public static function resolve( AuthRef|string $ref, array $context = array() ): array {
		$auth_ref = is_string( $ref ) ? AuthRef::from_string( $ref ) : $ref;
		$result   = self::unresolved( $auth_ref );

		/**
		 * Register auth ref resolver objects.
		 *
		 * Resolver objects must implement AuthRefResolverInterface and must not
		 * return raw secret values.
		 *
		 * @param array $resolvers Resolver objects.
		 */
		$resolvers = apply_filters( 'datamachine_auth_ref_resolvers', array(), $context );
		foreach ( $resolvers as $resolver ) {
			if ( ! $resolver instanceof AuthRefResolverInterface ) {
				continue;
			}

			$candidate = self::sanitize_resolution( $resolver->resolve( $auth_ref, $context ), $auth_ref );
			if ( ! empty( $candidate['resolved'] ) ) {
				$result = $candidate;
				break;
			}
			if ( ! empty( $candidate['warning'] ) ) {
				$result = $candidate;
			}
		}

		/**
		 * Filter final auth ref resolution.
		 *
		 * @param array   $result  Resolution envelope.
		 * @param AuthRef $auth_ref Auth ref value object.
		 * @param array   $context Import/export context.
		 */
		return self::sanitize_resolution( apply_filters( 'datamachine_resolve_auth_ref', $result, $auth_ref, $context ), $auth_ref );
	}

	private static function sanitize_resolution( array $result, AuthRef $ref ): array {
		$sanitized = array(
			'resolved' => ! empty( $result['resolved'] ),
			'ref'      => $ref->ref(),
			'provider' => $ref->provider(),
			'account'  => $ref->account(),
		);

		if ( isset( $result['config'] ) && is_array( $result['config'] ) ) {
			$sanitized['config'] = self::strip_secret_keys( $result['config'] );
		}
		if ( isset( $result['warning'] ) && '' !== trim( (string) $result['warning'] ) ) {
			$sanitized['warning'] = trim( (string) $result['warning'] );
		}

		return $sanitized;
	}

	private static function strip_secret_keys( array $config ): array {
		$clean = array();
		foreach ( $config as $key => $value ) {
			$key = (string) $key;
			if ( preg_match( '/(secret|token|password|credential|key)/i', $key ) ) {
				continue;
			}
			$clean[ $key ] = is_array( $value ) ? self::strip_secret_keys( $value ) : $value;
		}

		return $clean;
	}
}
