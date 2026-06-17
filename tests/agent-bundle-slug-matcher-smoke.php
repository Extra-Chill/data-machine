<?php
/**
 * Pure-PHP smoke test for symmetric portable-slug matching.
 *
 * Verifies the matching asymmetry fix behind `agent adopt` / `agent upgrade`:
 * live-origin rows with portable_slug NULL must resolve to the SAME normalized
 * key the bundle side computes (via the display-name fallback), and duplicate
 * names must surface as ambiguous instead of silently mismatching.
 *
 * Run with: php tests/agent-bundle-slug-matcher-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/Engine/Bundle/PortableSlug.php';
require_once __DIR__ . '/../inc/Engine/Bundle/AgentBundleSlugMatcher.php';

use DataMachine\Engine\Bundle\AgentBundleSlugMatcher;

$failures = array();
$passes   = 0;

function agent_bundle_slug_matcher_assert( bool $condition, string $name, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
}

echo "agent-bundle-slug-matcher-smoke\n";

// ---- 1. NULL portable_slug rows resolve via the display-name fallback. -----
$live_pipelines = array(
	array( 'pipeline_id' => 101, 'pipeline_name' => 'Ticketmaster Columbus', 'portable_slug' => null ),
	array( 'pipeline_id' => 102, 'pipeline_name' => 'Dice FM Austin', 'portable_slug' => '' ),
);

$index = AgentBundleSlugMatcher::index_existing( $live_pipelines, 'pipeline_name', 'pipeline' );

agent_bundle_slug_matcher_assert(
	isset( $index['matched']['ticketmaster-columbus'] ),
	'NULL portable_slug pipeline resolves by normalized name',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	101 === ( $index['matched']['ticketmaster-columbus']['pipeline_id'] ?? 0 ),
	'matched row is the correct live pipeline',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	isset( $index['matched']['dice-fm-austin'] ),
	'empty-string portable_slug pipeline resolves by normalized name',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	empty( $index['ambiguous'] ),
	'distinct names produce no ambiguity',
	$failures,
	$passes
);

// ---- 2. Bundle side computes the SAME key the existing side does. ----------
$bundle_pipeline = array( 'original_id' => 9, 'pipeline_name' => 'Ticketmaster Columbus', 'portable_slug' => null );
$bundle_key      = AgentBundleSlugMatcher::bundle_slug( $bundle_pipeline, 'pipeline_name', 'pipeline' );

agent_bundle_slug_matcher_assert(
	'ticketmaster-columbus' === $bundle_key,
	'bundle-side slug matches existing-side slug (symmetric)',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	isset( $index['matched'][ $bundle_key ] ),
	'a live-origin bundle artifact now resolves to its live row',
	$failures,
	$passes
);

// ---- 3. Stored portable_slug wins over the name fallback. -------------------
$with_slug = array(
	array( 'pipeline_id' => 200, 'pipeline_name' => 'Human Readable Name', 'portable_slug' => 'stable-slug' ),
);
$slug_index = AgentBundleSlugMatcher::index_existing( $with_slug, 'pipeline_name', 'pipeline' );
agent_bundle_slug_matcher_assert(
	isset( $slug_index['matched']['stable-slug'] ) && ! isset( $slug_index['matched']['human-readable-name'] ),
	'stored portable_slug takes precedence over display name',
	$failures,
	$passes
);

// ---- 4. Duplicate names surface as ambiguous, never silently mismatched. ---
$dupes = array(
	array( 'flow_id' => 1, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
	array( 'flow_id' => 2, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
	array( 'flow_id' => 3, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
	array( 'flow_id' => 4, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
	array( 'flow_id' => 5, 'flow_name' => 'Unique Flow', 'portable_slug' => null ),
);
$flow_index = AgentBundleSlugMatcher::index_existing( $dupes, 'flow_name', 'flow' );

agent_bundle_slug_matcher_assert(
	isset( $flow_index['ambiguous']['ticketmaster'] ),
	'four flows named "Ticketmaster" surface as ambiguous',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	4 === count( $flow_index['ambiguous']['ticketmaster'] ),
	'all four colliding rows are recorded for the ambiguous slug',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	! isset( $flow_index['matched']['ticketmaster'] ),
	'ambiguous slug is NOT placed in the matched map (no guessing)',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	isset( $flow_index['matched']['unique-flow'] ),
	'unambiguous sibling still matches alongside ambiguous ones',
	$failures,
	$passes
);

// ---- 5. Rows with neither slug nor name are skipped, not keyed under ''. ----
$blank = AgentBundleSlugMatcher::index_existing(
	array( array( 'pipeline_id' => 1, 'pipeline_name' => '', 'portable_slug' => null ) ),
	'pipeline_name',
	'pipeline'
);
agent_bundle_slug_matcher_assert(
	empty( $blank['matched'] ) && empty( $blank['ambiguous'] ),
	'row with no slug and no name is skipped (never keyed under empty string)',
	$failures,
	$passes
);

// ---- 6. Acceptance: an all-NULL-portable_slug agent yields 0 would-create. --
// Mirrors the adopt() matching loop on a fixture that reproduces the events-bot
// shape (every live row portable_slug NULL). Before the fix, the existing map
// keyed by '' and EVERY bundle artifact was classified new_artifact (would
// INSERT a duplicate). After the fix, all resolve to matched / 0 unmatched.
$live_all_null = array(
	array( 'pipeline_id' => 11, 'pipeline_name' => 'Ticketmaster Columbus', 'portable_slug' => null ),
	array( 'pipeline_id' => 12, 'pipeline_name' => 'Dice FM Austin', 'portable_slug' => null ),
	array( 'pipeline_id' => 13, 'pipeline_name' => 'Bandsintown Nashville', 'portable_slug' => null ),
);
$bundle_pipelines = array(
	array( 'original_id' => 1, 'pipeline_name' => 'Ticketmaster Columbus', 'portable_slug' => null ),
	array( 'original_id' => 2, 'pipeline_name' => 'Dice FM Austin', 'portable_slug' => null ),
	array( 'original_id' => 3, 'pipeline_name' => 'Bandsintown Nashville', 'portable_slug' => null ),
);

$accept_index = AgentBundleSlugMatcher::index_existing( $live_all_null, 'pipeline_name', 'pipeline' );
$would_create = 0;
$matched_cnt  = 0;
foreach ( $bundle_pipelines as $bp ) {
	$key = AgentBundleSlugMatcher::bundle_slug( $bp, 'pipeline_name', 'pipeline' );
	if ( isset( $accept_index['matched'][ $key ] ) ) {
		++$matched_cnt;
	} else {
		++$would_create;
	}
}

agent_bundle_slug_matcher_assert(
	3 === $matched_cnt,
	'all-NULL agent: every bundle pipeline matches a live row',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	0 === $would_create,
	'all-NULL agent: adopt dry-run would create 0 new artifacts (no duplication)',
	$failures,
	$passes
);

// ---- 7. bundle_slug() prefers the artifact's UNIQUE slug over display name. -
// Mirrors events-bot: hundreds of flows share the display name "Ticketmaster"
// but each bundle flow artifact carries a DISTINCT unique `slug`
// (ticketmaster, ticketmaster-4, ...). Keying on the unique slug instead of the
// non-unique name means they resolve to distinct keys → 0 ambiguous, all match.
$same_name_flows = array(
	array( 'original_id' => 1, 'flow_name' => 'Ticketmaster', 'slug' => 'ticketmaster' ),
	array( 'original_id' => 2, 'flow_name' => 'Ticketmaster', 'slug' => 'ticketmaster-4' ),
	array( 'original_id' => 3, 'flow_name' => 'Ticketmaster', 'slug' => 'ticketmaster-9' ),
	array( 'original_id' => 4, 'flow_name' => 'Dice.fm', 'slug' => 'dice-fm' ),
	array( 'original_id' => 5, 'flow_name' => 'Dice.fm', 'slug' => 'dice-fm-2' ),
);

$bundle_slugs = array();
foreach ( $same_name_flows as $bundle_flow ) {
	$bundle_slugs[] = AgentBundleSlugMatcher::bundle_slug( $bundle_flow, 'flow_name', 'flow' );
}

agent_bundle_slug_matcher_assert(
	array( 'ticketmaster', 'ticketmaster-4', 'ticketmaster-9', 'dice-fm', 'dice-fm-2' ) === $bundle_slugs,
	'same-named flows with distinct slugs resolve to distinct bundle slugs',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	count( array_unique( $bundle_slugs ) ) === count( $bundle_slugs ),
	'no two same-named flows collapse onto the same bundle slug (0 ambiguous)',
	$failures,
	$passes
);

// The matching live rows: NULL portable_slug, duplicate flow_name, but the
// adopt backfill will write each unique bundle slug into portable_slug. Index
// the POST-backfill rows (portable_slug = the unique bundle slug) and confirm
// every bundle artifact resolves to exactly one live row.
$post_backfill_live = array();
foreach ( $same_name_flows as $i => $bundle_flow ) {
	$post_backfill_live[] = array(
		'flow_id'       => 100 + $i,
		'flow_name'     => $bundle_flow['flow_name'],
		'portable_slug' => $bundle_slugs[ $i ],
	);
}
$post_index   = AgentBundleSlugMatcher::index_existing( $post_backfill_live, 'flow_name', 'flow' );
$matched_all  = 0;
$ambiguous_ct = count( $post_index['ambiguous'] );
foreach ( $bundle_slugs as $key ) {
	if ( isset( $post_index['matched'][ $key ] ) ) {
		++$matched_all;
	}
}
agent_bundle_slug_matcher_assert(
	5 === $matched_all && 0 === $ambiguous_ct,
	'events-bot shape: all 5 same-named flows match their unique slug, 0 ambiguous',
	$failures,
	$passes
);

// ---- 8. Degenerate fallback: NO slug + duplicate names still refuses. -------
// Preserves #2668's four-"Ticketmaster" guarantee for the genuinely slug-less
// case — when there is nothing unique to key on, adopt must still refuse.
$slugless_dupes = array(
	array( 'flow_id' => 1, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
	array( 'flow_id' => 2, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
	array( 'flow_id' => 3, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
	array( 'flow_id' => 4, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
);
$slugless_index = AgentBundleSlugMatcher::index_existing( $slugless_dupes, 'flow_name', 'flow' );
agent_bundle_slug_matcher_assert(
	isset( $slugless_index['ambiguous']['ticketmaster'] ) && ! isset( $slugless_index['matched']['ticketmaster'] ),
	'degenerate (no slug + duplicate names) still refuses as ambiguous',
	$failures,
	$passes
);
// And the slug-less bundle artifact (no portable_slug, no slug) keys on name.
$slugless_artifact = AgentBundleSlugMatcher::bundle_slug(
	array( 'flow_name' => 'Ticketmaster' ),
	'flow_name',
	'flow'
);
agent_bundle_slug_matcher_assert(
	'ticketmaster' === $slugless_artifact,
	'slug-less bundle artifact falls back to the normalized display name',
	$failures,
	$passes
);

// ---- 9. bundle_slug() precedence: portable_slug > slug > display_name. ------
$precedence_all = AgentBundleSlugMatcher::bundle_slug(
	array( 'flow_name' => 'Display Name', 'slug' => 'from-slug', 'portable_slug' => 'from-portable' ),
	'flow_name',
	'flow'
);
agent_bundle_slug_matcher_assert(
	'from-portable' === $precedence_all,
	'precedence: portable_slug wins over slug and display name',
	$failures,
	$passes
);
$precedence_slug = AgentBundleSlugMatcher::bundle_slug(
	array( 'flow_name' => 'Display Name', 'slug' => 'from-slug', 'portable_slug' => '' ),
	'flow_name',
	'flow'
);
agent_bundle_slug_matcher_assert(
	'from-slug' === $precedence_slug,
	'precedence: slug wins over display name when portable_slug is empty',
	$failures,
	$passes
);
$precedence_name = AgentBundleSlugMatcher::bundle_slug(
	array( 'flow_name' => 'Display Name', 'slug' => '', 'portable_slug' => '' ),
	'flow_name',
	'flow'
);
agent_bundle_slug_matcher_assert(
	'display-name' === $precedence_name,
	'precedence: display name used only when both portable_slug and slug are empty',
	$failures,
	$passes
);

// ---- 10. Pipeline-scoped fallback: source label is unique within a pipeline.
// Reproduces the events-bot live-origin shape end to end: N city pipelines,
// each with a same-named "Ticketmaster" AND "Dice.fm" live flow (portable_slug
// NULL, flow_name the bare source label). The bundle flow carries the UNIQUE
// slug ("ticketmaster-63"), which the global slug pass can never match against a
// slugless live row. The pipeline-scoped fallback re-keys both sides on the
// normalized source label WITHIN the bounded pipeline, where it is unique.
//
// Mirror of the adopt() matching loop: pipelines match first, then each bundle
// flow is bounded to its matched live pipeline; the unique-slug pass misses, so
// the name-key fallback resolves it.
$scoped_pipelines = array(
	101 => array(
		array( 'flow_id' => 1, 'pipeline_id' => 101, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
		array( 'flow_id' => 2, 'pipeline_id' => 101, 'flow_name' => 'Dice.fm', 'portable_slug' => null ),
	),
	102 => array(
		array( 'flow_id' => 3, 'pipeline_id' => 102, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
		array( 'flow_id' => 4, 'pipeline_id' => 102, 'flow_name' => 'Dice.fm', 'portable_slug' => null ),
	),
	103 => array(
		array( 'flow_id' => 5, 'pipeline_id' => 103, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
		array( 'flow_id' => 6, 'pipeline_id' => 103, 'flow_name' => 'Dice.fm', 'portable_slug' => null ),
	),
);

// Bundle flows: each carries the UNIQUE deduped slug in portable_slug (exactly
// what AgentBundleArrayAdapter writes) and the bare source label in flow_name.
// original_pipeline_id maps to a matched live pipeline (id == live id here).
$scoped_bundle_flows = array(
	array( 'original_pipeline_id' => 101, 'flow_name' => 'Ticketmaster', 'portable_slug' => 'ticketmaster' ),
	array( 'original_pipeline_id' => 101, 'flow_name' => 'Dice.fm', 'portable_slug' => 'dice-fm' ),
	array( 'original_pipeline_id' => 102, 'flow_name' => 'Ticketmaster', 'portable_slug' => 'ticketmaster-2' ),
	array( 'original_pipeline_id' => 102, 'flow_name' => 'Dice.fm', 'portable_slug' => 'dice-fm-2' ),
	array( 'original_pipeline_id' => 103, 'flow_name' => 'Ticketmaster', 'portable_slug' => 'ticketmaster-3' ),
	array( 'original_pipeline_id' => 103, 'flow_name' => 'Dice.fm', 'portable_slug' => 'dice-fm-3' ),
);

$scoped_matched   = 0;
$scoped_unmatched = 0;
$scoped_ambiguous = 0;
$matched_flow_ids = array();
foreach ( $scoped_bundle_flows as $bundle_flow ) {
	$live_pipeline = (int) $bundle_flow['original_pipeline_id'];
	$slug_key      = AgentBundleSlugMatcher::bundle_slug( $bundle_flow, 'flow_name', 'flow' );
	$scoped_rows   = $scoped_pipelines[ $live_pipeline ] ?? array();

	// Global unique-slug pass: misses, because live rows have no slug.
	$slug_index = AgentBundleSlugMatcher::index_existing( $scoped_rows, 'flow_name', 'flow' );
	$live       = $slug_index['matched'][ $slug_key ] ?? null;

	// Pipeline-scoped fallback: re-key both sides on the source label.
	if ( null === $live ) {
		$name_key   = AgentBundleSlugMatcher::bundle_name_key( $bundle_flow, 'flow_name', 'flow' );
		$name_index = AgentBundleSlugMatcher::index_existing_by_name( $scoped_rows, 'flow_name', 'flow' );
		if ( isset( $name_index['ambiguous'][ $name_key ] ) ) {
			++$scoped_ambiguous;
			continue;
		}
		$live = $name_index['matched'][ $name_key ] ?? null;
	}

	if ( null === $live ) {
		++$scoped_unmatched;
		continue;
	}

	++$scoped_matched;
	$matched_flow_ids[ $slug_key ] = (int) $live['flow_id'];
}

agent_bundle_slug_matcher_assert(
	6 === $scoped_matched && 0 === $scoped_unmatched && 0 === $scoped_ambiguous,
	'pipeline-scoped: all same-named live-origin flows match, 0 unmatched, 0 ambiguous',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	1 === ( $matched_flow_ids['ticketmaster'] ?? 0 )
		&& 3 === ( $matched_flow_ids['ticketmaster-2'] ?? 0 )
		&& 5 === ( $matched_flow_ids['ticketmaster-3'] ?? 0 ),
	'pipeline-scoped: each Ticketmaster slug binds to the row in its OWN pipeline',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	2 === ( $matched_flow_ids['dice-fm'] ?? 0 )
		&& 4 === ( $matched_flow_ids['dice-fm-2'] ?? 0 )
		&& 6 === ( $matched_flow_ids['dice-fm-3'] ?? 0 ),
	'pipeline-scoped: each Dice.fm slug binds to the row in its OWN pipeline',
	$failures,
	$passes
);

// ---- 11. Pipeline-scoped fallback STILL refuses a genuine in-pipeline tie. --
// Two live flows with the SAME normalized source label inside ONE pipeline:
// there is no unique signal, so the name fallback must surface ambiguous and
// NEVER guess — preserving the #2668 guarantee at the per-pipeline scope.
$tie_rows = array(
	array( 'flow_id' => 11, 'pipeline_id' => 200, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
	array( 'flow_id' => 12, 'pipeline_id' => 200, 'flow_name' => 'Ticketmaster', 'portable_slug' => null ),
);
$tie_bundle = array( 'original_pipeline_id' => 200, 'flow_name' => 'Ticketmaster', 'portable_slug' => 'ticketmaster-77' );

$tie_slug_key   = AgentBundleSlugMatcher::bundle_slug( $tie_bundle, 'flow_name', 'flow' );
$tie_slug_index = AgentBundleSlugMatcher::index_existing( $tie_rows, 'flow_name', 'flow' );
$tie_live       = $tie_slug_index['matched'][ $tie_slug_key ] ?? null;
$tie_refused    = false;
if ( null === $tie_live ) {
	$tie_name_key   = AgentBundleSlugMatcher::bundle_name_key( $tie_bundle, 'flow_name', 'flow' );
	$tie_name_index = AgentBundleSlugMatcher::index_existing_by_name( $tie_rows, 'flow_name', 'flow' );
	$tie_refused    = isset( $tie_name_index['ambiguous'][ $tie_name_key ] )
		&& ! isset( $tie_name_index['matched'][ $tie_name_key ] );
}
agent_bundle_slug_matcher_assert(
	$tie_refused,
	'pipeline-scoped: two same-named flows in ONE pipeline stay ambiguous (no guess)',
	$failures,
	$passes
);

// ---- 12. index_existing_by_name ignores portable_slug (keys on label only). -
// Distinct from index_existing(): a row WITH a stored portable_slug is still
// keyed under its normalized name here, because the per-pipeline fallback's only
// stable cross-side signal is the source label.
$name_only = AgentBundleSlugMatcher::index_existing_by_name(
	array(
		array( 'flow_id' => 1, 'flow_name' => 'Ticketmaster', 'portable_slug' => 'some-stored-slug' ),
		array( 'flow_id' => 2, 'flow_name' => 'Dice.fm', 'portable_slug' => null ),
	),
	'flow_name',
	'flow'
);
agent_bundle_slug_matcher_assert(
	isset( $name_only['matched']['ticketmaster'] )
		&& 1 === ( $name_only['matched']['ticketmaster']['flow_id'] ?? 0 )
		&& isset( $name_only['matched']['dice-fm'] ),
	'index_existing_by_name keys on normalized name regardless of portable_slug',
	$failures,
	$passes
);

// ---- 13. bundle_name_key keys on the source label, never the unique slug. ---
$label_key = AgentBundleSlugMatcher::bundle_name_key(
	array( 'flow_name' => 'Ticketmaster', 'portable_slug' => 'ticketmaster-63', 'slug' => 'ticketmaster-63' ),
	'flow_name',
	'flow'
);
agent_bundle_slug_matcher_assert(
	'ticketmaster' === $label_key,
	'bundle_name_key resolves the source label, ignoring the unique slug',
	$failures,
	$passes
);

// ---- 14. Handler-identity fallback: rename-proof in-pipeline match. ---------
// Reproduces the events-bot live-origin bundle shape that the slug AND name
// fallbacks both miss (#2683). The in-memory bundle adopt loads carries each
// flow's steps in `flow_config` (keyed by step id), its `flow_name` RENAMED to
// "<Source> — <City>" (event-bundles#5) and its `portable_slug` deduped
// ("dice-fm-101") — so neither the unique-slug pass nor the name fallback can
// meet the live row, whose `flow_name` is still the bare source label
// ("Dice.fm"). The import HANDLER is the only rename-proof identity, present on
// BOTH sides as `flow_config[<step>].handler_slugs[0]`.
$handler_live_rows = array(
	// Pipeline 9 (Austin): one Dice.fm source flow + one Ticketmaster source flow.
	array(
		'flow_id'       => 26,
		'pipeline_id'   => 9,
		'flow_name'     => 'Dice.fm',
		'portable_slug' => null,
		'flow_config'   => array(
			'9_a_26' => array( 'step_type' => 'event_import', 'execution_order' => 0, 'handler_slugs' => array( 'dice_fm' ) ),
			'9_b_26' => array( 'step_type' => 'ai', 'execution_order' => 1 ),
			'9_c_26' => array( 'step_type' => 'upsert', 'execution_order' => 2, 'handler_slugs' => array( 'upsert_event' ) ),
		),
	),
	array(
		'flow_id'       => 27,
		'pipeline_id'   => 9,
		'flow_name'     => 'Ticketmaster',
		'portable_slug' => null,
		'flow_config'   => array(
			'9_d_27' => array( 'step_type' => 'event_import', 'execution_order' => 0, 'handler_slugs' => array( 'ticketmaster' ) ),
			'9_e_27' => array( 'step_type' => 'ai', 'execution_order' => 1 ),
		),
	),
);

// Bundle flow (array-bundle shape): renamed name, deduped slug, no `steps`, the
// handler lives in `flow_config` exactly like the live row.
$handler_bundle_flow = array(
	'original_pipeline_id' => 9,
	'flow_name'            => 'Dice.fm — Austin',
	'portable_slug'        => 'dice-fm-101',
	'flow_config'          => array(
		'1_bundle_step_0_70' => array( 'step_type' => 'event_import', 'execution_order' => 0, 'handler_slugs' => array( 'dice_fm' ) ),
		'1_bundle_step_1_70' => array( 'step_type' => 'ai', 'execution_order' => 1 ),
		'1_bundle_step_2_70' => array( 'step_type' => 'upsert', 'execution_order' => 2, 'handler_slugs' => array( 'upsert_event' ) ),
	),
);

$h_slug_key = AgentBundleSlugMatcher::bundle_slug( $handler_bundle_flow, 'flow_name', 'flow' );
$h_name_key = AgentBundleSlugMatcher::bundle_name_key( $handler_bundle_flow, 'flow_name', 'flow' );
$h_handler  = AgentBundleSlugMatcher::bundle_handler_key( $handler_bundle_flow, 'flow' );

agent_bundle_slug_matcher_assert(
	'dice-fm-101' === $h_slug_key && 'dice-fm-austin' === $h_name_key && 'dice-fm' === $h_handler,
	'events-bot shape: slug=dice-fm-101, name=dice-fm-austin, handler=dice-fm (rename-proof)',
	$failures,
	$passes
);

// Slug pass misses (live row has NULL slug -> keyed on "dice-fm").
$h_slug_index = AgentBundleSlugMatcher::index_existing( $handler_live_rows, 'flow_name', 'flow' );
agent_bundle_slug_matcher_assert(
	! isset( $h_slug_index['matched'][ $h_slug_key ] ),
	'events-bot shape: deduped slug "dice-fm-101" misses the slug pass (live keyed on "dice-fm")',
	$failures,
	$passes
);
// Name pass misses (bundle name "dice-fm-austin" vs live "dice-fm").
$h_name_index = AgentBundleSlugMatcher::index_existing_by_name( $handler_live_rows, 'flow_name', 'flow' );
agent_bundle_slug_matcher_assert(
	! isset( $h_name_index['matched'][ $h_name_key ] ),
	'events-bot shape: renamed name "dice-fm-austin" misses the name pass (live "dice-fm")',
	$failures,
	$passes
);
// Handler pass matches the correct row, unambiguously.
$h_index = AgentBundleSlugMatcher::index_existing_by_handler( $handler_live_rows, 'flow' );
agent_bundle_slug_matcher_assert(
	isset( $h_index['matched']['dice-fm'] )
		&& 26 === ( $h_index['matched']['dice-fm']['flow_id'] ?? 0 )
		&& empty( $h_index['ambiguous'] ),
	'events-bot shape: handler fallback binds "dice-fm" to its live row (flow_id 26), 0 ambiguous',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	isset( $h_index['matched']['ticketmaster'] ) && 27 === ( $h_index['matched']['ticketmaster']['flow_id'] ?? 0 ),
	'events-bot shape: the sibling Ticketmaster source flow binds to flow_id 27 by handler',
	$failures,
	$passes
);

// ---- 15. Handler key ignores AI/output steps (source step only). -----------
// The first non-AI / non-upsert / non-publish step defines the source. An
// upsert-only or ai-only flow yields no handler key and falls back to name.
$ai_upsert_only = array(
	'flow_config' => array(
		's0' => array( 'step_type' => 'ai', 'execution_order' => 0 ),
		's1' => array( 'step_type' => 'upsert', 'execution_order' => 1, 'handler_slugs' => array( 'upsert_event' ) ),
	),
);
agent_bundle_slug_matcher_assert(
	'' === AgentBundleSlugMatcher::bundle_handler_key( $ai_upsert_only, 'flow' ),
	'handler key is empty when a flow has only ai/upsert steps (no source handler)',
	$failures,
	$passes
);

// ---- 16. Handler key reads BOTH bundle shapes symmetrically. ---------------
// On-disk document shape (steps[] ordered list) and in-memory array-bundle
// shape (flow_config map) must derive the SAME key from the same flow.
$doc_shape = array(
	'steps' => array(
		array( 'step_type' => 'event_import', 'step_position' => 0, 'handler_slugs' => array( 'dice_fm' ) ),
		array( 'step_type' => 'ai', 'step_position' => 1 ),
	),
);
$array_shape = array(
	'flow_config' => array(
		'x1' => array( 'step_type' => 'ai', 'execution_order' => 1 ),
		'x0' => array( 'step_type' => 'event_import', 'execution_order' => 0, 'handler_slugs' => array( 'dice_fm' ) ),
	),
);
agent_bundle_slug_matcher_assert(
	'dice-fm' === AgentBundleSlugMatcher::bundle_handler_key( $doc_shape, 'flow' )
		&& 'dice-fm' === AgentBundleSlugMatcher::bundle_handler_key( $array_shape, 'flow' ),
	'handler key is identical for steps[] (document) and flow_config (array-bundle) shapes',
	$failures,
	$passes
);
agent_bundle_slug_matcher_assert(
	'dice-fm' === AgentBundleSlugMatcher::bundle_handler_key(
		array( 'flow_config' => array( 'x' => array( 'step_type' => 'event_import', 'handler_configs' => array( 'dice_fm' => array() ) ) ) ),
		'flow'
	),
	'handler key falls back to the handler_configs map key when handler_slugs is absent',
	$failures,
	$passes
);

// ---- 17. Handler fallback STILL refuses a genuine in-pipeline source tie. ---
// Two flows with the SAME source handler in ONE pipeline (e.g. two Dice.fm
// searches) and no slug/name signal to split them: the handler pass must
// surface ambiguous and NEVER guess — preserving #2668 at the source-identity
// scope.
$handler_tie_rows = array(
	array(
		'flow_id'       => 51,
		'pipeline_id'   => 200,
		'flow_name'     => 'Dice.fm Rock',
		'portable_slug' => null,
		'flow_config'   => array( 's' => array( 'step_type' => 'event_import', 'execution_order' => 0, 'handler_slugs' => array( 'dice_fm' ) ) ),
	),
	array(
		'flow_id'       => 52,
		'pipeline_id'   => 200,
		'flow_name'     => 'Dice.fm Jazz',
		'portable_slug' => null,
		'flow_config'   => array( 's' => array( 'step_type' => 'event_import', 'execution_order' => 0, 'handler_slugs' => array( 'dice_fm' ) ) ),
	),
);
$tie_handler_index = AgentBundleSlugMatcher::index_existing_by_handler( $handler_tie_rows, 'flow' );
agent_bundle_slug_matcher_assert(
	isset( $tie_handler_index['ambiguous']['dice-fm'] ) && ! isset( $tie_handler_index['matched']['dice-fm'] ),
	'handler fallback: two same-source flows in ONE pipeline stay ambiguous (no guess)',
	$failures,
	$passes
);

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " agent bundle slug matcher assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} agent bundle slug matcher assertions passed.\n";
}
