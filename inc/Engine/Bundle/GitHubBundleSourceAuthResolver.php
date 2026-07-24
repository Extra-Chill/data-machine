<?php
/**
 * GitHub bundle source auth resolver.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Token resolution + GitHub/GHE Authorization injection.
 */
final class GitHubBundleSourceAuthResolver implements BundleSourceAuthResolverInterface {

	/**
	 * Apply GitHub/GHE auth headers when applicable.
	 *
	 * @param array  $args      Request args.
	 * @param string $source    Original source URL.
	 * @param string $fetch_url Normalized fetch URL.
	 * @param array  $context   Resolution context.
	 * @return array
	 */
	public function apply( array $args, string $source, string $fetch_url, array $context = array() ): array {
		unset( $source );

		$host = $this->host_for( $fetch_url );
		if ( '' === $host ) {
			return $args;
		}

		if ( ! empty( $args['headers']['Authorization'] ) || ! empty( $args['headers']['authorization'] ) ) {
			return $args;
		}

		$is_github = $this->is_github_host( $host );
		$is_ghe    = ! $is_github && array_key_exists( $host, BundleSourceAuth::ghe_hosts() );
		if ( ! $is_github && ! $is_ghe ) {
			return $args;
		}

		$token = $this->token_for( $fetch_url, $context );
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
	 * @param string $fetch_url Normalized fetch URL.
	 * @param array  $context   Resolution context.
	 * @return string|null
	 */
	public function token_for( string $fetch_url, array $context = array() ): ?string {
		$host = $this->host_for( $fetch_url );

		if ( ! empty( $context['cli_token'] ) ) {
			$cli_token = trim( (string) $context['cli_token'] );
			if ( '' !== $cli_token ) {
				return $cli_token;
			}
		}

		$env_var = $this->env_var_for_host( $host );

		if ( '' !== $env_var ) {
			$from_env = getenv( $env_var );
			if ( is_string( $from_env ) && '' !== trim( $from_env ) ) {
				return trim( $from_env );
			}
		}

		if ( '' !== $env_var && defined( $env_var ) ) {
			$from_const = constant( $env_var );
			if ( is_string( $from_const ) && '' !== trim( $from_const ) ) {
				return trim( $from_const );
			}
		}

		if ( $this->is_github_host( $host ) && function_exists( 'get_option' ) ) {
			$from_option = (string) get_option( BundleSourceAuth::GITHUB_TOKEN_OPTION, '' );
			if ( '' !== trim( $from_option ) ) {
				return trim( $from_option );
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'datamachine_bundle_source_token_for_url', null, $fetch_url, $host, $context );
			if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
				return trim( $filtered );
			}
		}

		return null;
	}

	private function host_for( string $url ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() is unavailable in standalone smoke contexts.
		$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}
		return strtolower( $host );
	}

	private function is_github_host( string $host ): bool {
		return 'github.com' === $host
			|| 'raw.githubusercontent.com' === $host
			|| 'api.github.com' === $host;
	}

	private function env_var_for_host( string $host ): string {
		if ( '' === $host ) {
			return '';
		}

		if ( $this->is_github_host( $host ) ) {
			return BundleSourceAuth::GITHUB_TOKEN_ENV;
		}

		$ghe_hosts = BundleSourceAuth::ghe_hosts();
		if ( array_key_exists( $host, $ghe_hosts ) ) {
			return $ghe_hosts[ $host ];
		}

		return '';
	}
}
