<?php
/**
 * Smoke test for CliCommandIntrospector __invoke / __default support.
 *
 * Exercises both WP-CLI command shapes the shared reflector must handle:
 *
 *   1. A flat command whose only public method is `__invoke` (the dominant
 *      shape across the network — `wp extrachill venues`, `wp intelligence
 *      wiki brain`, every `datamachine analytics *` command). It must reflect
 *      to a single `__default` entry carrying the `__invoke` docblock's short
 *      description — NOT an empty array.
 *
 *   2. A multi-subcommand command using `@subcommand` annotations (DMC's
 *      WorkspaceCommand / GitHubCommand shape). It must reflect exactly as
 *      before — no regression — with magic methods still skipped.
 *
 * Run with: php tests/cli-command-introspector-invoke-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/Engine/AI/CliCommandIntrospector.php';

use DataMachine\Engine\AI\CliCommandIntrospector;

/**
 * Flat command: only a `__invoke` handler. Should reflect to `__default`.
 */
class Introspector_Invoke_Only_Stub {

	/**
	 * Discover music venues in a city using Google Places API.
	 *
	 * @param array $args Positional args.
	 */
	public function __invoke( $args ) {
		// no-op
	}

	/**
	 * Internal helper — must never surface as a subcommand.
	 */
	private function resolve( $thing ) {
		return $thing;
	}
}

/**
 * Multi-subcommand command using @subcommand annotations plus an underscore
 * method name. Mirrors DMC's WorkspaceCommand / GitHubCommand shape.
 */
class Introspector_Subcommand_Stub {

	/**
	 * Construct should never be reflected as a subcommand.
	 */
	public function __construct() {
	}

	/**
	 * Add a new worktree.
	 *
	 * @subcommand add
	 */
	public function add_worktree( $args ) {
	}

	/**
	 * List the registered worktrees.
	 *
	 * @subcommand list
	 */
	public function list_worktrees( $args ) {
	}

	/**
	 * Refresh agent context.
	 *
	 * No @subcommand annotation — name derives from the method name with
	 * underscores converted to hyphens (refresh_context => refresh-context).
	 */
	public function refresh_context( $args ) {
	}

	/**
	 * A static helper must never be a subcommand.
	 */
	public static function helper() {
	}
}

$failed = 0;
$total  = 0;

$assert = static function ( string $label, bool $condition ) use ( &$failed, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failed;
	echo "FAIL: {$label}\n";
};

// ---------------------------------------------------------------------------
// Shape 1: __invoke-only command => single __default entry.
// ---------------------------------------------------------------------------
$invoke = CliCommandIntrospector::describe_class( Introspector_Invoke_Only_Stub::class );

$assert( '__invoke command yields exactly one entry', 1 === count( $invoke ) );
$assert( '__invoke entry name is __default', '__default' === ( $invoke[0]['name'] ?? null ) );
$assert(
	'__invoke entry carries the __invoke docblock short description',
	'Discover music venues in a city using Google Places API.' === ( $invoke[0]['description'] ?? null )
);
$assert(
	'private helper does not leak as a subcommand',
	! in_array( 'resolve', array_column( $invoke, 'name' ), true )
);

// ---------------------------------------------------------------------------
// Shape 2: @subcommand command => named entries, no regression.
// ---------------------------------------------------------------------------
$subs  = CliCommandIntrospector::describe_class( Introspector_Subcommand_Stub::class );
$names = array_column( $subs, 'name' );

$assert( '@subcommand command yields three entries', 3 === count( $subs ) );
$assert( '@subcommand add is present', in_array( 'add', $names, true ) );
$assert( '@subcommand list is present', in_array( 'list', $names, true ) );
$assert(
	'unannotated method derives hyphenated name (refresh_context => refresh-context)',
	in_array( 'refresh-context', $names, true )
);
$assert( '__construct is not reflected as a subcommand', ! in_array( '__construct', $names, true ) );
$assert( '__default does not appear for an annotated command', ! in_array( '__default', $names, true ) );
$assert( 'static helper is not reflected as a subcommand', ! in_array( 'helper', $names, true ) );

$add = null;
foreach ( $subs as $sub ) {
	if ( 'add' === $sub['name'] ) {
		$add = $sub;
		break;
	}
}
$assert(
	'@subcommand entry keeps its docblock short description',
	null !== $add && 'Add a new worktree.' === $add['description']
);

// Entries are sorted by name for deterministic output.
$assert( 'subcommands are sorted by name', $names === array( 'add', 'list', 'refresh-context' ) );

// ---------------------------------------------------------------------------
// Namespace-map path surfaces __default for flat commands.
// ---------------------------------------------------------------------------
$map = CliCommandIntrospector::describe_namespace_map(
	'extrachill',
	array(
		'extrachill venues' => Introspector_Invoke_Only_Stub::class,
		'extrachill flow'   => Introspector_Subcommand_Stub::class,
	)
);

$assert( 'namespace-map echoes the namespace label', 'extrachill' === ( $map['namespace'] ?? null ) );
$assert( 'namespace-map returns one entry per command label', 2 === count( $map['commands'] ?? array() ) );

$venues = null;
$flow   = null;
foreach ( $map['commands'] as $command ) {
	if ( 'extrachill venues' === $command['command'] ) {
		$venues = $command;
	}
	if ( 'extrachill flow' === $command['command'] ) {
		$flow = $command;
	}
}
$assert(
	'flat command in namespace-map carries a __default subcommand',
	null !== $venues && '__default' === ( $venues['subcommands'][0]['name'] ?? null )
);
$assert(
	'multi-subcommand command in namespace-map preserves its grouped subcommands',
	null !== $flow && 3 === count( $flow['subcommands'] ?? array() )
);

// Missing class yields an empty array (unchanged contract).
$assert(
	'unknown class yields an empty subcommand list',
	array() === CliCommandIntrospector::describe_class( 'No_Such_Introspector_Class' )
);

if ( $failed > 0 ) {
	echo "=== cli-command-introspector-invoke-smoke: {$failed} FAIL of {$total} ===\n";
	exit( 1 );
}

echo "=== cli-command-introspector-invoke-smoke: ALL PASS ({$total}) ===\n";
