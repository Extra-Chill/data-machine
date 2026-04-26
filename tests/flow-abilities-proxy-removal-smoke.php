<?php
/**
 * Pure-PHP smoke test for the FlowAbilities proxy removal (#1298).
 *
 * Run with: php tests/flow-abilities-proxy-removal-smoke.php
 *
 * Pre-#1298 a hand-maintained proxy class at `inc/Abilities/FlowAbilities.php`
 * wrapped 9 underlying Flow ability classes with one-line delegating
 * methods like:
 *
 *     public function executeQueueMode( array $input ): array {
 *         if ( ! isset( $this->queue ) ) {
 *             $this->queue = new QueueAbility();
 *         }
 *         return $this->queue->executeQueueMode( $input );
 *     }
 *
 * Every new ability method on a wrapped class needed a manual proxy.
 * Drift was silent (PHP doesn't catch missing proxy methods at parse
 * time; CLI smoke tests skipped the proxy path; the React save path
 * fataled at runtime when `executeQueueMode` was added without the
 * proxy entry).
 *
 * #1298 deletes the proxy entirely. Every consumer now calls
 * `wp_get_ability( 'datamachine/<slug>' )->execute( $input )` —
 * matching the pattern other CLI commands (EmailCommand, etc.) and the
 * REST/MCP/chat layers already use.
 *
 * This smoke locks the post-delete state by greping the source tree:
 *
 *   1. The proxy file is gone.
 *   2. No production PHP file references the FlowAbilities class.
 *   3. data-machine.php instantiates each underlying ability class
 *      directly so the registration trigger still fires.
 *   4. CLI files use `wp_get_ability()` for every Flow ability slug
 *      they exercise — no `new FlowAbilities()` calls remain.
 *   5. Test files still cover the same surface (no Flow ability slug
 *      was orphaned by the proxy delete).
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

/**
 * Assert helper.
 *
 * @param string $name      Test case name.
 * @param bool   $condition Pass/fail.
 */
function assert_proxy_removed( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		return;
	}
	echo "  FAIL: {$name}\n";
	++$failed;
}

/**
 * Recursively grep for a substring across PHP files in a directory.
 *
 * @param string $directory Absolute path to walk.
 * @param string $needle    Substring to find.
 * @return array<int, string> Matching file paths (relative to repo root).
 */
function grep_php_files( string $directory, string $needle ): array {
	$root  = dirname( __DIR__ );
	$hits  = array();
	$rii   = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $rii as $file ) {
		if ( $file->isDir() ) {
			continue;
		}
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		// Skip vendor / node_modules / build artefacts.
		$path = $file->getPathname();
		if (
			false !== strpos( $path, '/vendor/' )
			|| false !== strpos( $path, '/node_modules/' )
			|| false !== strpos( $path, '/build/' )
		) {
			continue;
		}
		$contents = (string) file_get_contents( $path );
		if ( false !== strpos( $contents, $needle ) ) {
			$hits[] = ltrim( str_replace( $root, '', $path ), '/' );
		}
	}
	sort( $hits );
	return $hits;
}

$root_dir = dirname( __DIR__ );

echo "=== FlowAbilities Proxy Removal Smoke (#1298) ===\n";

// ---------------------------------------------------------------
// SECTION 1: proxy file is gone.
// ---------------------------------------------------------------

echo "\n[file:1] inc/Abilities/FlowAbilities.php is deleted\n";
assert_proxy_removed(
	'proxy file no longer exists',
	! file_exists( $root_dir . '/inc/Abilities/FlowAbilities.php' )
);

// ---------------------------------------------------------------
// SECTION 2: no production PHP file references the class.
// ---------------------------------------------------------------

echo "\n[code:1] No production PHP file references the FlowAbilities class\n";
$prod_hits = grep_php_files( $root_dir . '/inc', 'FlowAbilities' );
// FlowStepAbilities is a separate class; reject any FlowAbilities reference
// that isn't part of the longer FlowStepAbilities name. Use a regex pattern
// for precise matching.
$bad_prod_hits = array();
foreach ( $prod_hits as $hit ) {
	$contents = (string) file_get_contents( $root_dir . '/' . $hit );
	if ( preg_match( '/(?<!Step)(?<![A-Za-z])FlowAbilities/', $contents ) ) {
		$bad_prod_hits[] = $hit;
	}
}
assert_proxy_removed(
	'inc/ contains no `FlowAbilities` (excluding `FlowStepAbilities`)',
	array() === $bad_prod_hits
);

// ---------------------------------------------------------------
// SECTION 3: data-machine.php instantiates underlying ability classes.
// ---------------------------------------------------------------

echo "\n[bootstrap:1] data-machine.php registers each Flow ability class\n";
$plugin_main = (string) file_get_contents( $root_dir . '/data-machine.php' );
$expected_classes = array(
	'GetFlowsAbility',
	'CreateFlowAbility',
	'UpdateFlowAbility',
	'DeleteFlowAbility',
	'DuplicateFlowAbility',
	'PauseFlowAbility',
	'ResumeFlowAbility',
	'QueueAbility',
	'WebhookTriggerAbility',
);
foreach ( $expected_classes as $class ) {
	assert_proxy_removed(
		"new \\DataMachine\\Abilities\\Flow\\{$class}() in data-machine.php",
		false !== strpos( $plugin_main, "new \\DataMachine\\Abilities\\Flow\\{$class}();" )
	);
}
assert_proxy_removed(
	'no leftover `new \\DataMachine\\Abilities\\FlowAbilities()` line',
	false === strpos( $plugin_main, 'new \\DataMachine\\Abilities\\FlowAbilities()' )
);

// ---------------------------------------------------------------
// SECTION 4: CLI files migrated to wp_get_ability().
// ---------------------------------------------------------------

echo "\n[cli:1] CLI files no longer instantiate the proxy\n";
$cli_files = array(
	'inc/Cli/Commands/Flows/FlowsCommand.php',
	'inc/Cli/Commands/Flows/QueueCommand.php',
);
foreach ( $cli_files as $file ) {
	$src = (string) file_get_contents( $root_dir . '/' . $file );
	assert_proxy_removed(
		"{$file} has no `new FlowAbilities()`",
		false === strpos( $src, 'new \\DataMachine\\Abilities\\FlowAbilities()' )
	);
}

echo "\n[cli:2] CLI files use wp_get_ability() for every Flow ability slug they exercise\n";
$ability_slugs = array(
	'datamachine/get-flows',
	'datamachine/create-flow',
	'datamachine/update-flow',
	'datamachine/delete-flow',
	'datamachine/pause-flow',
	'datamachine/resume-flow',
	'datamachine/queue-add',
	'datamachine/queue-list',
	'datamachine/queue-clear',
	'datamachine/queue-remove',
	'datamachine/queue-update',
	'datamachine/queue-move',
	'datamachine/queue-mode',
	'datamachine/config-patch-add',
	'datamachine/config-patch-list',
	'datamachine/config-patch-clear',
	'datamachine/config-patch-remove',
	'datamachine/config-patch-update',
	'datamachine/config-patch-move',
);

$cli_combined = '';
foreach ( $cli_files as $file ) {
	$cli_combined .= (string) file_get_contents( $root_dir . '/' . $file );
}

foreach ( $ability_slugs as $slug ) {
	// Two valid call shapes:
	//   wp_get_ability( 'datamachine/foo' )->execute(...)   ← inline literal
	//   $ability_id = ('fetch' === $step_type) ? 'datamachine/foo' : 'datamachine/bar';
	//     wp_get_ability( $ability_id )->execute(...)       ← dynamic ternary
	// Both shapes embed the literal slug in the file. Confirm presence
	// of the literal AND at least one wp_get_ability() call in the file.
	$slug_present = false !== strpos( $cli_combined, "'{$slug}'" );
	$wga_present  = false !== strpos( $cli_combined, 'wp_get_ability' );
	assert_proxy_removed(
		"CLI references '{$slug}' (literal present, wp_get_ability() in file)",
		$slug_present && $wga_present
	);
}

// ---------------------------------------------------------------
// SECTION 5: test files migrated.
// ---------------------------------------------------------------

echo "\n[tests:1] No test file references the FlowAbilities class\n";
$test_hits     = grep_php_files( $root_dir . '/tests', 'FlowAbilities' );
$bad_test_hits = array();
foreach ( $test_hits as $hit ) {
	// Allowed test files: this smoke (which references the dead class
	// in its docblock + grep target literal) and FlowAbilitiesTest.php
	// (whose class name is `FlowAbilitiesTest` — that's the test class
	// name, not the dead production class). Both are intentional.
	if (
		'tests/flow-abilities-proxy-removal-smoke.php' === $hit
		|| 'tests/Unit/Abilities/FlowAbilitiesTest.php' === $hit
	) {
		continue;
	}
	$contents = (string) file_get_contents( $root_dir . '/' . $hit );
	// Allowed in any file: `FlowStepAbilities` (a separate class).
	$contents_normalized = preg_replace(
		'/(?<![A-Za-z])FlowStepAbilities/',
		'___',
		$contents
	);
	if ( preg_match( '/(?<![A-Za-z])FlowAbilities/', $contents_normalized ) ) {
		$bad_test_hits[] = $hit;
	}
}
assert_proxy_removed(
	'tests contain no live `FlowAbilities` references (excluding this smoke + FlowAbilitiesTest class name)',
	array() === $bad_test_hits
);

echo "\n[tests:2] FlowAbilitiesTest.php still tests the same five abilities via wp_get_ability()\n";
$fab_test = (string) file_get_contents(
	$root_dir . '/tests/Unit/Abilities/FlowAbilitiesTest.php'
);
$tested_via_wga = array(
	'datamachine/get-flows',
	'datamachine/create-flow',
	'datamachine/update-flow',
	'datamachine/delete-flow',
	'datamachine/duplicate-flow',
);
foreach ( $tested_via_wga as $slug ) {
	assert_proxy_removed(
		"FlowAbilitiesTest exercises '{$slug}' via wp_get_ability()",
		false !== strpos( $fab_test, "wp_get_ability('{$slug}')->execute" )
			|| false !== strpos( $fab_test, "wp_get_ability( '{$slug}' )->execute" )
	);
}

// ---------------------------------------------------------------
// SECTION 6: AllAbilitiesRegisteredTest still asserts the slugs.
// ---------------------------------------------------------------

echo "\n[bootstrap:2] AllAbilitiesRegisteredTest still expects every Flow ability slug\n";
$all_test = (string) file_get_contents(
	$root_dir . '/tests/Unit/Abilities/AllAbilitiesRegisteredTest.php'
);
foreach ( array(
	'datamachine/get-flows',
	'datamachine/create-flow',
	'datamachine/delete-flow',
	'datamachine/update-flow',
	'datamachine/duplicate-flow',
) as $slug ) {
	assert_proxy_removed(
		"AllAbilitiesRegisteredTest expects '{$slug}'",
		false !== strpos( $all_test, "'{$slug}'" )
	);
}

echo "\n";
if ( 0 === $failed ) {
	echo "=== flow-abilities-proxy-removal-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}
echo "=== flow-abilities-proxy-removal-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
