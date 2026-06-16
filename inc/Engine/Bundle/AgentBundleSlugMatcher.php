<?php
/**
 * Symmetric portable-slug matching for live-origin agent bundles.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a normalized lookup of existing pipeline/flow rows that mirrors the
 * key the bundle (target) side uses.
 *
 * The bundle side already keys artifacts by
 * `PortableSlug::normalize( portable_slug ?: display_name )`. The existing /
 * live side historically keyed only by the stored `portable_slug`, so rows with
 * a NULL/empty `portable_slug` (every row on a live-origin agent exported into a
 * bundle) were keyed under `''` and never matched. This helper closes that
 * asymmetry: it keys each existing row under the SAME normalized slug the bundle
 * side computes, falling back to the normalized display name when the stored
 * `portable_slug` is empty.
 *
 * Pure and persistence-free so it can be unit-tested directly.
 */
final class AgentBundleSlugMatcher {

	/**
	 * Index a set of existing rows by their effective normalized slug.
	 *
	 * When two or more rows resolve to the SAME normalized slug (e.g. four live
	 * flows all named "Ticketmaster"), the slug is ambiguous: no single row can
	 * be bound to the incoming bundle artifact without guessing. Ambiguous slugs
	 * are omitted from the returned `matched` map and surfaced in `ambiguous`
	 * so callers can refuse to guess.
	 *
	 * @param array<int,array<string,mixed>> $rows      Existing pipeline or flow rows.
	 * @param string                         $name_key  Display-name field (pipeline_name / flow_name).
	 * @param string                         $fallback  PortableSlug fallback (pipeline / flow).
	 * @return array{
	 *     matched: array<string,array<string,mixed>>,
	 *     ambiguous: array<string,array<int,array<string,mixed>>>
	 * }
	 */
	public static function index_existing( array $rows, string $name_key, string $fallback ): array {
		$by_slug = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$slug = self::effective_slug( $row, $name_key, $fallback );
			if ( '' === $slug ) {
				continue;
			}

			$by_slug[ $slug ][] = $row;
		}

		$matched   = array();
		$ambiguous = array();
		foreach ( $by_slug as $slug => $candidates ) {
			if ( 1 === count( $candidates ) ) {
				$matched[ $slug ] = $candidates[0];
				continue;
			}

			$ambiguous[ $slug ] = $candidates;
		}

		return array(
			'matched'   => $matched,
			'ambiguous' => $ambiguous,
		);
	}

	/**
	 * Compute the effective normalized slug for a single existing row.
	 *
	 * Mirrors the bundle-side key: prefer the stored `portable_slug`, fall back
	 * to the normalized display name when it is empty.
	 *
	 * @param array<string,mixed> $row      Existing pipeline or flow row.
	 * @param string              $name_key Display-name field.
	 * @param string              $fallback PortableSlug fallback.
	 * @return string Normalized slug, or '' when neither slug nor name is usable.
	 */
	public static function effective_slug( array $row, string $name_key, string $fallback ): string {
		$stored = trim( (string) ( $row['portable_slug'] ?? '' ) );
		if ( '' !== $stored ) {
			return PortableSlug::normalize( $stored, $fallback );
		}

		$name = trim( (string) ( $row[ $name_key ] ?? '' ) );
		if ( '' === $name ) {
			return '';
		}

		return PortableSlug::normalize( $name, $fallback );
	}

	/**
	 * Compute the normalized slug the bundle (target) side uses for an artifact.
	 *
	 * @param array<string,mixed> $artifact Bundle pipeline or flow entry.
	 * @param string              $name_key Display-name field (pipeline_name / flow_name).
	 * @param string              $fallback PortableSlug fallback (pipeline / flow).
	 * @return string Normalized slug.
	 */
	public static function bundle_slug( array $artifact, string $name_key, string $fallback ): string {
		$candidate = (string) ( $artifact['portable_slug'] ?? ( $artifact[ $name_key ] ?? $fallback ) );

		return PortableSlug::normalize( $candidate, $fallback );
	}
}
