<?php
/**
 * Bundle source resolver registry.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Registry for provider-specific bundle source resolvers.
 */
final class BundleSourceResolverRegistry {

	/** @var BundleSourceResolverInterface[]|null */
	private static ?array $source_resolvers = null;

	/** @var BundleSourceAuthResolverInterface[]|null */
	private static ?array $auth_resolvers = null;

	/** @return BundleSourceResolverInterface[] */
	public static function source_resolvers(): array {
		if ( null === self::$source_resolvers ) {
			self::$source_resolvers = array(
				new GitHubBundleSourceResolver(),
			);
		}

		$resolvers = function_exists( 'apply_filters' )
			? (array) apply_filters( 'datamachine_bundle_source_resolvers', self::$source_resolvers )
			: self::$source_resolvers;

		return array_values(
			array_filter(
				$resolvers,
				static fn( $resolver ) => $resolver instanceof BundleSourceResolverInterface
			)
		);
	}

	/** @return BundleSourceAuthResolverInterface[] */
	public static function auth_resolvers(): array {
		if ( null === self::$auth_resolvers ) {
			self::$auth_resolvers = array(
				new GitHubBundleSourceAuthResolver(),
			);
		}

		$resolvers = function_exists( 'apply_filters' )
			? (array) apply_filters( 'datamachine_bundle_source_auth_resolvers', self::$auth_resolvers )
			: self::$auth_resolvers;

		return array_values(
			array_filter(
				$resolvers,
				static fn( $resolver ) => $resolver instanceof BundleSourceAuthResolverInterface
			)
		);
	}
}
