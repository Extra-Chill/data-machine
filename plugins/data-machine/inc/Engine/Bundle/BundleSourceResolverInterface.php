<?php
/**
 * Bundle source resolver contract.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes provider-specific bundle source URLs into fetchable URLs.
 */
interface BundleSourceResolverInterface {

	/**
	 * Normalize a remote source URL for download.
	 *
	 * Return null when this resolver does not own the source.
	 *
	 * @param string $source  Original source URL.
	 * @param array  $context Resolution context.
	 * @return string|null Fetch URL or null when unsupported.
	 */
	public function normalize( string $source, array $context = array() ): ?string;

	/**
	 * Whether this resolver accepts a normalized fetch URL without a file suffix.
	 *
	 * @param string $fetch_url Normalized fetch URL.
	 * @return bool
	 */
	public function accepts_fetch_url( string $fetch_url ): bool;

	/**
	 * Expected archive extension for a normalized fetch URL, if provider-specific.
	 *
	 * @param string $fetch_url Normalized fetch URL.
	 * @return string Empty string when the extension should be read from the URL.
	 */
	public function expected_extension( string $fetch_url ): string;

	/**
	 * Parse a source revision from the fetch response.
	 *
	 * @param string $fetch_url Normalized fetch URL.
	 * @param string $etag      Response ETag header.
	 * @return string|null
	 */
	public function revision_from_etag( string $fetch_url, string $etag ): ?string;
}
