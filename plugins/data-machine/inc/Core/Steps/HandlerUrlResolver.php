<?php
/**
 * Handler URL Resolver
 *
 * Maps a source URL to the handler that claims it. Handlers opt in by adding
 * a `url_claim` callable to the `$meta` array passed to
 * HandlerRegistrationTrait::registerHandler():
 *
 *     'url_claim'          => fn( string $url ): ?array, // normalized handler_config, or null.
 *     'url_claim_priority' => 10,                       // optional, lower wins.
 *
 * Claims must only parse the URL. They must not perform remote I/O; checking
 * that an account or page exists is left to the consumer or to the handler's
 * own config validation.
 *
 * @package DataMachine\Core\Steps
 */

namespace DataMachine\Core\Steps;

defined( 'ABSPATH' ) || exit;

final class HandlerUrlResolver {

	/**
	 * Priority used when a handler declares a claim without a priority.
	 */
	public const DEFAULT_PRIORITY = 10;

	/**
	 * Resolve the best handler claim for a URL.
	 *
	 * @param string      $url       Source URL.
	 * @param string|null $step_type Optional step type. Only handlers visible to
	 *                               this step type (via the `datamachine_handlers`
	 *                               filter) are considered.
	 * @return array{handler_slug: string, handler_config: array, priority: int}|null
	 */
	public static function resolve( string $url, ?string $step_type = null ): ?array {
		$candidates = self::candidates( $url, $step_type );
		return $candidates[0] ?? null;
	}

	/**
	 * Return every handler claim for a URL, best first.
	 *
	 * Ordered by priority (ascending), then handler registration order.
	 *
	 * @param string      $url       Source URL.
	 * @param string|null $step_type Optional step type filter.
	 * @return array<int, array{handler_slug: string, handler_config: array, priority: int}>
	 */
	public static function candidates( string $url, ?string $step_type = null ): array {
		$url = trim( $url );
		if ( ! self::is_parseable_url( $url ) ) {
			return array();
		}

		$step_type = ( null !== $step_type && '' !== trim( $step_type ) ) ? trim( $step_type ) : null;
		$handlers  = apply_filters( 'datamachine_handlers', array(), $step_type );
		if ( ! is_array( $handlers ) ) {
			return array();
		}

		$claims = array();
		$order  = 0;
		foreach ( $handlers as $slug => $handler ) {
			$position = $order++;
			if ( ! is_string( $slug ) || '' === $slug || ! is_array( $handler ) ) {
				continue;
			}

			$meta  = is_array( $handler['meta'] ?? null ) ? $handler['meta'] : array();
			$claim = $meta['url_claim'] ?? null;
			if ( ! is_callable( $claim ) ) {
				continue;
			}

			try {
				$config = call_user_func( $claim, $url );
			} catch ( \Throwable $e ) {
				// A faulty claim must not break resolution for other handlers.
				continue;
			}

			if ( ! is_array( $config ) ) {
				continue;
			}

			$priority = $meta['url_claim_priority'] ?? self::DEFAULT_PRIORITY;
			$priority = is_numeric( $priority ) ? (int) $priority : self::DEFAULT_PRIORITY;

			$claims[] = array(
				'handler_slug'   => $slug,
				'handler_config' => $config,
				'priority'       => $priority,
				'_order'         => $position,
			);
		}

		usort(
			$claims,
			static function ( array $a, array $b ): int {
				return array( $a['priority'], $a['_order'] ) <=> array( $b['priority'], $b['_order'] );
			}
		);

		return array_map(
			static function ( array $claim ): array {
				unset( $claim['_order'] );
				return $claim;
			},
			$claims
		);
	}

	/**
	 * Whether the URL has at least a scheme and a host.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function is_parseable_url( string $url ): bool {
		if ( '' === $url || preg_match( '/\s/', $url ) ) {
			return false;
		}

		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) ) {
			return false;
		}

		return ! empty( $parts['scheme'] ) && ! empty( $parts['host'] );
	}
}
