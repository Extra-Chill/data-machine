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
	 * Compute the normalized HANDLER-identity key for a bundle flow.
	 *
	 * The display name is mutable: extrachill-event-bundles#5 renamed every
	 * colliding bundle flow `name` from the bare source label ("Dice.fm") to
	 * "<Source> — <City>" ("Dice.fm — Austin"), and the export-time global
	 * dedupe appends a numeric suffix to the slug ("dice-fm-101"). Neither the
	 * renamed name nor the deduped slug can meet the unchanged live `flow_name`
	 * ("Dice.fm"). The flow's SOURCE handler, however, is rename-proof: the first
	 * non-AI/non-output step's handler slug ("dice_fm", "ticketmaster",
	 * "bandsintown") is the stable per-source identity that survives every UI
	 * rename. Within a single city pipeline a given import source appears once
	 * (per-venue scrapers all share `universal_web_scraper`, but those resolve on
	 * the unique slug pass BEFORE this fallback is reached), so the handler key is
	 * unambiguous there. This is the bundle-side counterpart to
	 * {@see self::index_existing_by_handler()}.
	 *
	 * A bundle flow carries its steps in one of two equivalent shapes depending
	 * on how it was loaded: the on-disk document shape exposes an ordered
	 * `steps[]` list (each entry with `step_type` / `handler_slugs`), while the
	 * in-memory array-bundle shape produced by
	 * {@see AgentBundleArrayAdapter::to_array_bundle()} (what `adopt()` actually
	 * loads) carries the same data as a `flow_config` map keyed by step id. Both
	 * are scanned identically — the live row uses the very same `flow_config`
	 * shape — so the bundle and live sides always derive the same handler key.
	 *
	 * @param array<string,mixed> $flow     Bundle flow entry.
	 * @param string              $fallback PortableSlug fallback (flow).
	 * @return string Normalized handler key, or '' when no handler step exists.
	 */
	public static function bundle_handler_key( array $flow, string $fallback ): string {
		if ( is_array( $flow['steps'] ?? null ) && array() !== $flow['steps'] ) {
			// On-disk document shape: an ordered steps[] list.
			return self::handler_key_from_steps( $flow['steps'], $fallback, false );
		}

		// In-memory array-bundle shape: a flow_config map keyed by step id, the
		// same shape a live row carries.
		return self::handler_key_from_steps( self::flow_config_steps( $flow ), $fallback, true );
	}

	/**
	 * Index a set of existing flow rows by their normalized HANDLER identity.
	 *
	 * The live row keeps its source handler in `flow_config[<step_id>]` — the
	 * same `handler_slugs` / `handler_configs` shape the bundle flow carries in
	 * `steps[]`. This keys each row under the first non-AI/non-output step's
	 * normalized handler slug so a renamed/deduped bundle flow can still meet its
	 * live row on the rename-proof source identity. Like
	 * {@see self::index_existing_by_name()}, it must only ever be handed the live
	 * rows of a SINGLE matched pipeline, and it preserves the genuine-ambiguity
	 * guarantee: two rows with the same handler in one pipeline surface as
	 * `ambiguous` and are omitted from `matched`.
	 *
	 * @param array<int,array<string,mixed>> $rows     Existing flow rows for ONE pipeline.
	 * @param string                         $fallback PortableSlug fallback (flow).
	 * @return array{
	 *     matched: array<string,array<string,mixed>>,
	 *     ambiguous: array<string,array<int,array<string,mixed>>>
	 * }
	 */
	public static function index_existing_by_handler( array $rows, string $fallback ): array {
		$by_handler = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$steps = self::flow_config_steps( $row );
			$key   = self::handler_key_from_steps( $steps, $fallback, true );
			if ( '' === $key ) {
				continue;
			}

			$by_handler[ $key ][] = $row;
		}

		$matched   = array();
		$ambiguous = array();
		foreach ( $by_handler as $key => $candidates ) {
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
	 * Resolve a flow's import handler slug from an ordered list of steps.
	 *
	 * Both the bundle flow `steps[]` and the live `flow_config` express a step
	 * the same way: a `step_type`, an ordered position, and either a
	 * `handler_slugs` list or a `handler_configs` map keyed by handler slug. The
	 * SOURCE of a flow is its first non-AI / non-output step — the fetch/import
	 * handler that defines what the flow pulls. AI and output (upsert/publish)
	 * steps are skipped because they are shared boilerplate across every flow and
	 * carry no per-source identity.
	 *
	 * @param array<int,array<string,mixed>> $steps        Ordered step entries.
	 * @param string                         $fallback     PortableSlug fallback.
	 * @param bool                           $sort_by_order Sort steps by execution_order before scanning (live rows are unordered maps).
	 * @return string Normalized handler slug, or '' when none is present.
	 */
	private static function handler_key_from_steps( array $steps, string $fallback, bool $sort_by_order ): string {
		$steps = array_values(
			array_filter(
				$steps,
				static function ( $step ) {
					return is_array( $step );
				}
			)
		);

		if ( $sort_by_order ) {
			usort(
				$steps,
				static function ( array $a, array $b ): int {
					return (int) ( $a['execution_order'] ?? 0 ) <=> (int) ( $b['execution_order'] ?? 0 );
				}
			);
		}

		foreach ( $steps as $step ) {
			$type = (string) ( $step['step_type'] ?? '' );
			if ( in_array( $type, array( 'ai', 'upsert', 'publish' ), true ) ) {
				continue;
			}

			$slug = self::handler_slug_from_step( $step );
			if ( '' !== $slug ) {
				return PortableSlug::normalize( $slug, $fallback );
			}
		}

		return '';
	}

	/**
	 * Extract the handler slug from a single step entry.
	 *
	 * Prefers the explicit `handler_slugs` list, falling back to the first key of
	 * the `handler_configs` map (both sides store the handler config keyed by its
	 * slug). Returns '' when the step declares no handler.
	 *
	 * @param array<string,mixed> $step Step entry.
	 * @return string Raw handler slug, or '' when absent.
	 */
	private static function handler_slug_from_step( array $step ): string {
		$slugs = $step['handler_slugs'] ?? null;
		if ( is_array( $slugs ) && array() !== $slugs ) {
			$first = reset( $slugs );
			if ( is_string( $first ) && '' !== trim( $first ) ) {
				return trim( $first );
			}
		}

		$configs = $step['handler_configs'] ?? null;
		if ( is_array( $configs ) && array() !== $configs ) {
			$keys  = array_keys( $configs );
			$first = (string) reset( $keys );
			if ( '' !== trim( $first ) ) {
				return trim( $first );
			}
		}

		return '';
	}

	/**
	 * Normalize a live flow row's `flow_config` into a list of step arrays.
	 *
	 * `flow_config` is a map keyed by `<pipeline>_<uuid>_<flow>` step ids; this
	 * returns just the step value arrays so {@see self::handler_key_from_steps()}
	 * can scan them the same way it scans a bundle flow's `steps[]` list.
	 *
	 * @param array<string,mixed> $row Existing flow row.
	 * @return array<int,array<string,mixed>> Step value arrays.
	 */
	private static function flow_config_steps( array $row ): array {
		$config = $row['flow_config'] ?? null;
		if ( is_string( $config ) ) {
			$decoded = json_decode( $config, true );
			$config  = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $config ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$config,
				static function ( $step ) {
					return is_array( $step );
				}
			)
		);
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
