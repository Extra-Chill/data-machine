<?php
/**
 * Pure-PHP smoke test for QueueableTrait::mergeQueuedConfigPatch().
 *
 * Run with: php tests/queueable-trait-smoke.php
 *
 * Covers the patch-merge logic that powers queueable fetch steps. The
 * pop-from-queue paths depend on WordPress hooks + DB and are exercised
 * via integration tests / live runs, not here.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ): string {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ): void {
		// no-op for tests
	}
}

require_once __DIR__ . '/../inc/Core/Steps/QueueableTrait.php';

use DataMachine\Core\Steps\QueueableTrait;

/**
 * Test harness that exposes the protected merge helper.
 *
 * The pop helpers depend on the WordPress action system and the
 * QueueAbility static method, so they're exercised via Fetch step
 * integration runs. This harness covers the pure-data merge logic
 * which is the substantive new behavior in this PR.
 */
class QueueableTraitMergeHarness {
	use QueueableTrait;

	public function publicMerge( array $config, array $patch ): array {
		return $this->mergeQueuedConfigPatch( $config, $patch );
	}
}

function dm_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

$harness = new QueueableTraitMergeHarness();

echo "=== queueable-trait-smoke ===\n";

// -----------------------------------------------------------------
echo "\n[1] empty patch is a no-op\n";
$cfg = array(
	'server'   => 'a8c',
	'provider' => 'mgs',
	'params'   => '{"query":"WooCommerce"}',
);
dm_assert(
	$cfg === $harness->publicMerge( $cfg, array() ),
	'config returned unchanged'
);

// -----------------------------------------------------------------
echo "\n[2] patch into JSON-string field merges decoded contents\n";
$cfg = array(
	'server'   => 'a8c',
	'provider' => 'mgs',
	'tool'     => 'search',
	'params'   => '{"query":"WooCommerce"}',
);
$patch = array(
	'params' => array(
		'after'  => '2017-03-01',
		'before' => '2017-04-01',
	),
);
$merged  = $harness->publicMerge( $cfg, $patch );
$decoded = json_decode( $merged['params'], true );

dm_assert( 'a8c' === $merged['server'], 'unrelated keys preserved' );
dm_assert(
	array(
		'query'  => 'WooCommerce',
		'after'  => '2017-03-01',
		'before' => '2017-04-01',
	) === $decoded,
	'patch keys merged into decoded params, original keys preserved'
);
dm_assert( is_string( $merged['params'] ), 'params remains a JSON string after merge' );

// -----------------------------------------------------------------
echo "\n[3] queued patch wins on key collision\n";
$cfg     = array( 'params' => '{"query":"WooCommerce","after":"2015-01-01"}' );
$patch   = array( 'params' => array( 'after' => '2020-01-01' ) );
$merged  = $harness->publicMerge( $cfg, $patch );
$decoded = json_decode( $merged['params'], true );

dm_assert( '2020-01-01' === $decoded['after'], 'queued value wins on collision' );
dm_assert( 'WooCommerce' === $decoded['query'], 'non-conflicting key preserved' );

// -----------------------------------------------------------------
echo "\n[4] top-level scalar in patch overlays config\n";
$cfg    = array(
	'server'    => 'a8c',
	'max_items' => 20,
);
$patch  = array( 'max_items' => 50 );
$merged = $harness->publicMerge( $cfg, $patch );

dm_assert( 50 === $merged['max_items'], 'scalar overlay applied' );
dm_assert( 'a8c' === $merged['server'], 'unrelated scalar preserved' );

// -----------------------------------------------------------------
echo "\n[5] new key in patch added to config\n";
$cfg    = array( 'server' => 'a8c' );
$patch  = array( 'after' => '2017-03-01' );
$merged = $harness->publicMerge( $cfg, $patch );

dm_assert( '2017-03-01' === $merged['after'], 'new key added' );
dm_assert( 'a8c' === $merged['server'], 'existing key preserved' );

// -----------------------------------------------------------------
echo "\n[6] assoc-array values deep-merge\n";
$cfg = array(
	'options' => array(
		'timeout' => 30,
		'retries' => 3,
	),
);
$patch = array(
	'options' => array(
		'retries' => 5,
		'verbose' => true,
	),
);
$merged = $harness->publicMerge( $cfg, $patch );

dm_assert(
	array(
		'timeout' => 30,
		'retries' => 5,
		'verbose' => true,
	) === $merged['options'],
	'recursive merge — timeout preserved, retries overwritten, verbose added'
);

// -----------------------------------------------------------------
echo "\n[7] numeric arrays concatenate (preserve list semantics)\n";
$cfg    = array( 'tags' => array( 'wc', 'release' ) );
$patch  = array( 'tags' => array( '2026-04', 'fse-checkout' ) );
$merged = $harness->publicMerge( $cfg, $patch );

dm_assert(
	array( 'wc', 'release', '2026-04', 'fse-checkout' ) === $merged['tags'],
	'numeric array concatenated rather than replaced'
);

// -----------------------------------------------------------------
echo "\n[8] malformed JSON string field overwritten by array patch\n";
$cfg    = array( 'params' => 'not-json-at-all' );
$patch  = array( 'params' => array( 'after' => '2017-03-01' ) );
$merged = $harness->publicMerge( $cfg, $patch );

dm_assert(
	array( 'after' => '2017-03-01' ) === $merged['params'],
	'array patch wins outright when string field is unparseable'
);

// -----------------------------------------------------------------
echo "\n[9] string patch value replaces string field\n";
$cfg    = array( 'tool' => 'search' );
$patch  = array( 'tool' => 'fetch_posts' );
$merged = $harness->publicMerge( $cfg, $patch );

dm_assert( 'fetch_posts' === $merged['tool'], 'string-to-string replacement' );

// -----------------------------------------------------------------
echo "\n[10] WooCommerce backfill real-world shape end-to-end\n";
$static_config = array(
	'server'    => 'a8c',
	'provider'  => 'mgs',
	'tool'      => 'search',
	'params'    => '{"query":"WooCommerce"}',
	'max_items' => 20,
);
$queued_patch = array(
	'params' => array(
		'after'  => '2017-03-01',
		'before' => '2017-04-01',
		'limit'  => 100,
	),
);
$merged = $harness->publicMerge( $static_config, $queued_patch );

dm_assert( 'a8c' === $merged['server'], 'server preserved' );
dm_assert( 'mgs' === $merged['provider'], 'provider preserved' );
dm_assert( 'search' === $merged['tool'], 'tool preserved' );
dm_assert( 20 === $merged['max_items'], 'max_items preserved' );
dm_assert( is_string( $merged['params'] ), 'params remains JSON string for handler' );
dm_assert(
	array(
		'query'  => 'WooCommerce',
		'after'  => '2017-03-01',
		'before' => '2017-04-01',
		'limit'  => 100,
	) === json_decode( $merged['params'], true ),
	'all four expected params present (query + 3 from queue)'
);

// -----------------------------------------------------------------
echo "\n[11] empty config + non-empty patch — patch becomes the config\n";
$merged = $harness->publicMerge( array(), array( 'after' => '2015-01-01', 'before' => '2015-02-01' ) );

dm_assert(
	array(
		'after'  => '2015-01-01',
		'before' => '2015-02-01',
	) === $merged,
	'patch fully populated empty config'
);

// -----------------------------------------------------------------
echo "\n[12] both empty\n";
dm_assert(
	array() === $harness->publicMerge( array(), array() ),
	'empty + empty = empty'
);

echo "\n=== queueable-trait-smoke: ALL PASS ===\n";
