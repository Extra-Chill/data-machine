<?php
/**
 * Built-in GitHub.com / GHE auth helper for BundleSource downloads.
 *
 * Hooks into datamachine_bundle_source_download_args to attach an
 * Authorization: Bearer header when:
 *
 *   - The fetch URL host is github.com or raw.githubusercontent.com, OR
 *   - The fetch URL host matches an entry in the
 *     datamachine_bundle_source_ghe_hosts filter map.
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

		add_filter(
			'datamachine_bundle_source_download_args',
			array( self::class, 'inject_github_auth' ),
			10,
			3
		);
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
		unset( $source );

		$host = self::host_for( $fetch_url );
		if ( '' === $host ) {
			return $args;
		}

		// Already-configured Authorization header? Respect it; some
		// callers manage their own token chain via the filter directly.
		if ( ! empty( $args['headers']['Authorization'] ) || ! empty( $args['headers']['authorization'] ) ) {
			return $args;
		}

		$context   = is_array( $args['datamachine_bundle_source']['context'] ?? null )
			? (array) $args['datamachine_bundle_source']['context']
			: array();
		$is_github = self::is_github_host( $host );
		$is_ghe    = ! $is_github && array_key_exists( $host, self::ghe_hosts() );

		if ( ! $is_github && ! $is_ghe ) {
			return $args;
		}

		$token = self::token_for( $fetch_url, $context );
		if ( null === $token || '' === $token ) {
			return $args;
		}

		if ( ! is_array( $args['headers'] ?? null ) ) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'Bearer ' . $token;

		return $args;
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
		$host = self::host_for( $fetch_url );

		// 1. CLI-supplied token short-circuits everything.
		if ( ! empty( $context['cli_token'] ) ) {
			$cli_token = trim( (string) $context['cli_token'] );
			if ( '' !== $cli_token ) {
				return $cli_token;
			}
		}

		$env_var = self::env_var_for_host( $host );

		// 2. Environment variable.
		if ( '' !== $env_var ) {
			$from_env = getenv( $env_var );
			if ( is_string( $from_env ) && '' !== trim( $from_env ) ) {
				return trim( $from_env );
			}
		}

		// 3. PHP constant of the same name.
		if ( '' !== $env_var && defined( $env_var ) ) {
			$from_const = constant( $env_var );
			if ( is_string( $from_const ) && '' !== trim( $from_const ) ) {
				return trim( $from_const );
			}
		}

		// 4. WP option (github.com only).
		if ( self::is_github_host( $host ) && function_exists( 'get_option' ) ) {
			$from_option = (string) get_option( self::GITHUB_TOKEN_OPTION, '' );
			if ( '' !== trim( $from_option ) ) {
				return trim( $from_option );
			}
		}

		/**
		 * Filter fallback for callers that store secrets elsewhere
		 * (sigillo, AWS Secrets Manager, etc.).
		 *
		 * Return null/empty to leave the resolution unresolved.
		 *
		 * @param string|null $token     Current candidate token (always null when this fires).
		 * @param string      $fetch_url Normalized URL.
		 * @param string      $host      Lower-cased host.
		 * @param array       $context   Resolution context.
		 */
		$filtered = apply_filters( 'datamachine_bundle_source_token_for_url', null, $fetch_url, $host, $context );
		if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
			return trim( $filtered );
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
		$hosts = (array) apply_filters( 'datamachine_bundle_source_ghe_hosts', array() );

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

	/**
	 * Lower-cased host portion of a URL, or '' on parse failure.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_for( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}
		return strtolower( $host );
	}

	/**
	 * Is the given host one of the built-in github.com hosts?
	 *
	 * @param string $host Lower-cased host.
	 * @return bool
	 */
	private static function is_github_host( string $host ): bool {
		return 'github.com' === $host || 'raw.githubusercontent.com' === $host;
	}

	/**
	 * Resolve the env var / constant name to read for tokens scoped to
	 * a given host. Returns '' when the host has no configured slot.
	 *
	 * @param string $host Lower-cased host.
	 * @return string
	 */
	private static function env_var_for_host( string $host ): string {
		if ( '' === $host ) {
			return '';
		}

		if ( self::is_github_host( $host ) ) {
			return self::GITHUB_TOKEN_ENV;
		}

		$ghe_hosts = self::ghe_hosts();
		if ( array_key_exists( $host, $ghe_hosts ) ) {
			return $ghe_hosts[ $host ];
		}

		return '';
	}
}
