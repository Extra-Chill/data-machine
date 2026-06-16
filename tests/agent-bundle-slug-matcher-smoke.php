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

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " agent bundle slug matcher assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} agent bundle slug matcher assertions passed.\n";
}
