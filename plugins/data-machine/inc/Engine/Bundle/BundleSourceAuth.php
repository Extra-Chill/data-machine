<?php
/**
 * BundleSource download auth facade.
 *
 * Hooks registered auth resolvers into datamachine_bundle_source_download_args.
 * The built-in resolver preserves GitHub.com / GHE token behavior while other
 * providers can register resolvers via datamachine_bundle_source_auth_resolvers.
 *
 * Token resolution chain (first hit wins):
 *
 *   1. CLI-supplied token (passed via $context['cli_token']).
 *   2. Environment variable.
 *   3. PHP constant of the same name.
 *   4. WP option (github.com only): datamachine_bundle_source_github_token.
 *   5. datamachine_bundle_source_token_for_url filter fallback.
 *
 * The helper never persists a token. Errors that surface from
 * BundleSource::fetch_to_tempfile() include the URL but redact the
 * Authorization header — see {@see self::redact_args_for_log()}.
 *
 * @package DataMachine\Engine\Bundle
 * @since   0.10.4
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Token resolution + GitHub/GHE Authorization injection.
 */
final class BundleSourceAuth {

	/**
	 * Default env/constant name for github.com and raw.githubusercontent.com.
	 */
	public const GITHUB_TOKEN_ENV = 'DATAMACHINE_GITHUB_TOKEN';

	/**
	 * Build a {@see BundleSource::resolve()} context array from caller-
	 * supplied token / token_env values.
	 *
	 * Used by both the WP-CLI surface and the import-agent ability so the
	 * --token / --token-env (or `token` / `token_env` ability fields)
	 * always short-circuit the env/constant/option/filter chain identically.
	 *
	 * Returns an empty array when no usable token was found, leaving the
	 * default resolution chain in {@see self::token_for()} to fire.
	 *
	 * @param string|null $token       Literal token. Wins over $token_env.
	 * @param string|null $token_env   Env-var or PHP-constant name to read.
	 * @return array{cli_token?:string}
	 */
	public static function build_resolve_context( ?string $token, ?string $token_env ): array {
		$resolved = '';

		if ( null !== $token ) {
			$resolved = trim( $token );
		}

		if ( '' === $resolved && null !== $token_env ) {
			$env_var = trim( $token_env );
			if ( '' !== $env_var ) {
				$from_env = getenv( $env_var );
				if ( is_string( $from_env ) ) {
					$resolved = trim( $from_env );
				}
				if ( '' === $resolved && defined( $env_var ) ) {
					$constant_value = constant( $env_var );
					if ( is_string( $constant_value ) ) {
						$resolved = trim( $constant_value );
					}
				}
			}
		}

		if ( '' === $resolved ) {
			return array();
		}

		return array( 'cli_token' => $resolved );
	}

	/**
	 * WP option that stores a github.com token (lowest precedence).
	 */
	public const GITHUB_TOKEN_OPTION = 'datamachine_bundle_source_github_token';

	/**
	 * Hook the GitHub/GHE auto-injection callback into the bundle source
	 * download args filter. Idempotent.
	 */
	public static function register(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_filter( 'datamachine_bundle_source_download_args', array( self::class, 'inject_auth' ), 10, 3 );
	}

	/**
	 * Filter callback — apply registered auth resolvers to request args.
	 *
	 * @param array  $args      Request args.
	 * @param string $source    Original source string.
	 * @param string $fetch_url Normalized URL.
	 * @return array
	 */
	public static function inject_auth( array $args, string $source, string $fetch_url ): array {
		$context = is_array( $args['datamachine_bundle_source']['context'] ?? null )
			? (array) $args['datamachine_bundle_source']['context']
			: array();

		foreach ( BundleSourceResolverRegistry::auth_resolvers() as $resolver ) {
			$args = $resolver->apply( $args, $source, $fetch_url, $context );
		}

		return $args;
	}

	/**
	 * Filter callback — auto-attach Authorization for github.com /
	 * raw.githubusercontent.com / configured GHE hosts.
	 *
	 * @param array  $args      Request args.
	 * @param string $source    Original source string.
	 * @param string $fetch_url Normalized URL.
	 * @return array
	 */
	public static function inject_github_auth( array $args, string $source, string $fetch_url ): array {
		$context = is_array( $args['datamachine_bundle_source']['context'] ?? null )
			? (array) $args['datamachine_bundle_source']['context']
			: array();
		return ( new GitHubBundleSourceAuthResolver() )->apply( $args, $source, $fetch_url, $context );
	}

	/**
	 * Resolve a token for a given fetch URL.
	 *
	 * Resolution chain (first non-empty hit wins):
	 *   1. $context['cli_token']
	 *   2. Environment variable for the host
	 *   3. PHP constant of the same name
	 *   4. WP option (github.com only)
	 *   5. datamachine_bundle_source_token_for_url filter
	 *
	 * @param string $fetch_url Normalized URL.
	 * @param array  $context   Resolution context.
	 * @return string|null
	 */
	public static function token_for( string $fetch_url, array $context = array() ): ?string {
		foreach ( BundleSourceResolverRegistry::auth_resolvers() as $resolver ) {
			$token = $resolver->token_for( $fetch_url, $context );
			if ( null !== $token && '' !== $token ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Get configured GHE hosts.
	 *
	 * Filter shape: `[ '<host>' => '<env-var-or-constant-name>' ]`. The
	 * env/constant name is the symbol the resolution chain will read for
	 * tokens scoped to that host.
	 *
	 * @return array<string,string>
	 */
	public static function ghe_hosts(): array {
		/**
		 * Map of GHE host names to the env-var/constant that holds their
		 * token. Hosts are lower-cased before comparison.
		 *
		 * Example:
		 *
		 *     add_filter( 'datamachine_bundle_source_ghe_hosts', function ( $hosts ) {
		 *         $hosts['github.a8c.com'] = 'DATAMACHINE_A8C_GHE_TOKEN';
		 *         return $hosts;
		 *     } );
		 *
		 * @param array<string,string> $hosts Host → env/constant name map.
		 */
		$hosts = function_exists( 'apply_filters' ) ? (array) apply_filters( 'datamachine_bundle_source_ghe_hosts', array() ) : array();

		$normalized = array();
		foreach ( $hosts as $host => $env_var ) {
			$host    = strtolower( trim( (string) $host ) );
			$env_var = trim( (string) $env_var );
			if ( '' === $host || '' === $env_var ) {
				continue;
			}
			$normalized[ $host ] = $env_var;
		}

		return $normalized;
	}

	/**
	 * Redact sensitive headers in HTTP args before logging.
	 *
	 * @param array $args HTTP args.
	 * @return array
	 */
	public static function redact_args_for_log( array $args ): array {
		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			foreach ( $args['headers'] as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, 'authorization' ) ) {
					$args['headers'][ $name ] = '[redacted]';
				}
			}
		}

		unset( $args['datamachine_bundle_source'] );

		return $args;
	}
}
