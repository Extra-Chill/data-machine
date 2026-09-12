<?php
/**
 * Bundle source authentication resolver contract.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Applies provider-specific auth to bundle source downloads.
 */
interface BundleSourceAuthResolverInterface {

	/**
	 * Apply auth headers to request args when this resolver owns the fetch URL.
	 *
	 * @param array  $args      Request args.
	 * @param string $source    Original source URL.
	 * @param string $fetch_url Normalized fetch URL.
	 * @param array  $context   Resolution context.
	 * @return array
	 */
	public function apply( array $args, string $source, string $fetch_url, array $context = array() ): array;

	/**
	 * Resolve a token for the fetch URL, or null when unavailable.
	 *
	 * @param string $fetch_url Normalized fetch URL.
	 * @param array  $context   Resolution context.
	 * @return string|null
	 */
	public function token_for( string $fetch_url, array $context = array() ): ?string;
}
