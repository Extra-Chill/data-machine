<?php
/**
 * Smoke test for gated AGENTS.md composition (Extra-Chill/data-machine#2640).
 *
 * Verifies:
 *  1. The gate `datamachine_agents_md_enabled()` is constant-only and default-OFF.
 *  2. When OFF, the file/section registration helpers are no-ops.
 *  3. The `datamachine` section renders bounded routing and live-help discovery
 *     rather than an exhaustive command map.
 *  4. The CommandRegistry remains authoritative and excludes relocated commands.
 *
 * Run with: php tests/agents-md-gated-composition-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'STDERR' ) ) {
	define( 'STDERR', fopen( 'php://stderr', 'w' ) );
}

// --- Minimal WP shims used by the section/file helpers. ---------------------

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
		return true;
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}

$failures = array();

function datamachine_assert( bool $cond, string $message, array &$failures ): void {
	if ( $cond ) {
		fwrite( fopen( 'php://stdout', 'w' ), "PASS: {$message}\n" );
		return;
	}
	$failures[] = $message;
	fwrite( fopen( 'php://stdout', 'w' ), "FAIL: {$message}\n" );
}

// Load the CommandRegistry (pure map, no WP_CLI dependency) and the gated
// composition helpers.
require_once dirname( __DIR__ ) . '/inc/Cli/CommandRegistry.php';
require_once dirname( __DIR__ ) . '/inc/migrations/agents-md.php';

// --- 1. Gate is constant-only and default-OFF. -----------------------------

datamachine_assert(
	false === datamachine_agents_md_enabled(),
	'Gate is OFF when DATAMACHINE_COMPOSE_AGENTS_MD is undefined',
	$failures
);

// --- 2. When OFF, registration helpers are no-ops (no fatals, no calls). ----

$file_registered = false;
// MemoryFileRegistry is not loaded in this smoke; if the helper tried to call
// it while OFF, this would fatal. Reaching the next line proves the no-op.
datamachine_register_agents_md_file();
datamachine_register_agents_md_sections();
datamachine_assert( true, 'File + section registration helpers are no-ops while gate OFF', $failures );

// --- 3. Generated section is routing-oriented, not an exhaustive CLI map. ----

$rendered = datamachine_agents_md_render_datamachine_section();
datamachine_assert( str_contains( $rendered, '## Data Machine' ), 'Section has a Data Machine heading', $failures );
datamachine_assert( str_contains( $rendered, '**Default routing**' ), 'Section provides default routing', $failures );
datamachine_assert( str_contains( $rendered, 'datamachine memory --help' ), 'Section routes memory work', $failures );
datamachine_assert( str_contains( $rendered, 'datamachine jobs --help' ), 'Section routes job and evidence work', $failures );
datamachine_assert( str_contains( $rendered, 'datamachine --help' ), 'Section points to live command discovery', $failures );
datamachine_assert( ! str_contains( $rendered, 'artifact-content|artifacts|cleanup' ), 'Section omits exhaustive reflected subcommands', $failures );

// --- 4. CommandRegistry map: analytics is gone, real commands present. ------

$map = \DataMachine\Cli\CommandRegistry::map();

foreach ( array( 'memory', 'flows', 'pipelines', 'jobs', 'worker', 'posts', 'blocks', 'image', 'email', 'pending-actions', 'agent', 'system' ) as $advertised_root ) {
	datamachine_assert(
		isset( $map[ 'datamachine ' . $advertised_root ] ),
		"Advertised routing root `datamachine {$advertised_root}` is registered",
		$failures
	);
}

datamachine_assert(
	! array_key_exists( 'datamachine analytics', $map ),
	'CommandRegistry map does NOT contain a `datamachine analytics` entry (relocated to DMB)',
	$failures
);

$has_analytics_class = false;
foreach ( $map as $cmd => $class ) {
	if ( false !== stripos( $cmd, 'analytics' ) || false !== stripos( $class, 'Analytics' ) ) {
		$has_analytics_class = true;
	}
}
datamachine_assert( ! $has_analytics_class, 'No analytics command/class anywhere in the map', $failures );

datamachine_assert(
	isset( $map['datamachine memory'] ) && isset( $map['datamachine drain'] ) && isset( $map['datamachine retention'] ),
	'Map contains real core commands (memory, drain, retention)',
	$failures
);

// --- Report. ----------------------------------------------------------------

if ( empty( $failures ) ) {
	fwrite( fopen( 'php://stdout', 'w' ), "\nAll assertions passed.\n" );
	exit( 0 );
}

fwrite( fopen( 'php://stderr', 'w' ), "\n" . count( $failures ) . " assertion(s) failed.\n" );
exit( 1 );
