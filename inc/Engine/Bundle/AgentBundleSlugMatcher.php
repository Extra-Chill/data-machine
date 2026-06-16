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
 * The bundle side keys artifacts by
 * `PortableSlug::normalize( portable_slug ?: slug ?: display_name )` — the
 * artifact's UNIQUE `slug` is preferred over the non-unique display name, the
 * same identity the upgrade planner keys its ledger on. The existing / live
 * side historically keyed only by the stored `portable_slug`, so rows with a
 * NULL/empty `portable_slug` (every row on a live-origin agent exported into a
 * bundle) were keyed under `''` and never matched. This helper closes that
 * asymmetry: it keys each existing row under the SAME normalized slug the bundle
 * side computes, falling back to the normalized display name when the stored
 * `portable_slug` is empty. Once adopt backfills the bundle's unique slug into
 * `portable_slug`, both sides agree on the unique identity.
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
	 * Index a set of existing rows by their normalized display NAME (label).
	 *
	 * Where {@see self::index_existing()} keys on the row IDENTITY (stored
	 * `portable_slug` first, name only as a fallback), this keys purely on the
	 * normalized display name and ignores `portable_slug` entirely. It exists
	 * for the pipeline-scoped flow fallback in adopt: a live-origin flow row has
	 * no slug column, so its only stable identity WITHIN an already-matched
	 * parent pipeline is its source label (`flow_name` = "Ticketmaster",
	 * "Dice.fm"). Within one city pipeline that label is unique, so keying on it
	 * is unambiguous; across the whole agent it is not, which is why callers must
	 * only ever hand this the live rows of a SINGLE matched pipeline.
	 *
	 * The ambiguity guarantee is preserved: when two rows in the bounded set
	 * resolve to the same normalized name (a genuine in-pipeline collision), the
	 * name is surfaced in `ambiguous` and omitted from `matched` so the caller
	 * still refuses to guess.
	 *
	 * @param array<int,array<string,mixed>> $rows     Existing rows for ONE pipeline.
	 * @param string                         $name_key Display-name field (flow_name).
	 * @param string                         $fallback PortableSlug fallback (flow).
	 * @return array{
	 *     matched: array<string,array<string,mixed>>,
	 *     ambiguous: array<string,array<int,array<string,mixed>>>
	 * }
	 */
	public static function index_existing_by_name( array $rows, string $name_key, string $fallback ): array {
		$by_name = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = trim( (string) ( $row[ $name_key ] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}

			$key = PortableSlug::normalize( $name, $fallback );
			if ( '' === $key ) {
				continue;
			}

			$by_name[ $key ][] = $row;
		}

		$matched   = array();
		$ambiguous = array();
		foreach ( $by_name as $key => $candidates ) {
			if ( 1 === count( $candidates ) ) {
				$matched[ $key ] = $candidates[0];
				continue;
			}

			$ambiguous[ $key ] = $candidates;
		}

		return array(
			'matched'   => $matched,
			'ambiguous' => $ambiguous,
		);
	}

	/**
	 * Compute the normalized source-label key for a bundle artifact.
	 *
	 * Unlike {@see self::bundle_slug()} (which prefers the artifact's UNIQUE
	 * `slug`/`portable_slug`), this keys purely on the display name — the source
	 * label ("Ticketmaster", "Dice.fm"). It is the bundle-side counterpart to
	 * {@see self::index_existing_by_name()} and is used only for the
	 * pipeline-scoped flow fallback: within a single matched pipeline the source
	 * label uniquely identifies the flow, and the live row carries no slug to key
	 * on, so both sides must meet on the normalized label. The unique slug is
	 * then backfilled onto the matched live row by the caller.
	 *
	 * @param array<string,mixed> $artifact Bundle flow entry.
	 * @param string              $name_key Display-name field (flow_name).
	 * @param string              $fallback PortableSlug fallback (flow).
	 * @return string Normalized source-label key.
	 */
	public static function bundle_name_key( array $artifact, string $name_key, string $fallback ): string {
		$candidate = (string) ( $artifact[ $name_key ] ?? $fallback );

		return PortableSlug::normalize( $candidate, $fallback );
	}

	/**
	 * Compute the effective normalized slug for a single existing row.
	 *
	 * The stored `portable_slug` is the row's IDENTITY; the display name is only
	 * a LABEL (and for flows it is non-unique by design). So this prefers the
	 * stored `portable_slug` and only falls back to the normalized display name
	 * when it is empty. After a correct {@see AgentBundleAbilityService::adopt()}
	 * backfills `portable_slug` from the bundle artifact's unique
	 * {@see self::bundle_slug()} value, this and `bundle_slug()` resolve to the
	 * same unique identity, so the round-trip matches deterministically.
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
	 * Precedence: `portable_slug` -> `slug` -> display name. The bundle flow /
	 * pipeline file carries a UNIQUE `slug` (the portable identity, e.g.
	 * `ticketmaster`, `ticketmaster-4`) that the upgrade planner already keys
	 * the ledger on. The display name is a NON-UNIQUE label — for flows it is
	 * the source/handler ("Ticketmaster", "Dice.fm"), shared by hundreds of
	 * rows — so keying on it collapses distinct artifacts into one slug and
	 * makes adopt refuse them as ambiguous. Preferring the artifact's own
	 * `slug` before the display name keeps the bundle side keyed on the same
	 * unique identity the ledger uses, so same-named flows match deterministically.
	 *
	 * @param array<string,mixed> $artifact Bundle pipeline or flow entry.
	 * @param string              $name_key Display-name field (pipeline_name / flow_name).
	 * @param string              $fallback PortableSlug fallback (pipeline / flow).
	 * @return string Normalized slug.
	 */
	public static function bundle_slug( array $artifact, string $name_key, string $fallback ): string {
		$portable = trim( (string) ( $artifact['portable_slug'] ?? '' ) );
		if ( '' !== $portable ) {
			return PortableSlug::normalize( $portable, $fallback );
		}

		$slug = trim( (string) ( $artifact['slug'] ?? '' ) );
		if ( '' !== $slug ) {
			return PortableSlug::normalize( $slug, $fallback );
		}

		$candidate = (string) ( $artifact[ $name_key ] ?? $fallback );

		return PortableSlug::normalize( $candidate, $fallback );
	}
}
