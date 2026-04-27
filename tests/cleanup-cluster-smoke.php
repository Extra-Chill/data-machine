<?php
/**
 * Pure-PHP smoke test for the LOC-reducing cleanup cluster (#1332, #1334, #1335).
 *
 * Run with: php tests/cleanup-cluster-smoke.php
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
function assert_cleanup_cluster( string $name, bool $condition ): void {
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
 * Recursively scan repository files for a literal.
 *
 * @param string $directory Absolute path to walk.
 * @param string $needle    Literal text to find.
 * @param array  $extensions Extensions to include.
 * @return array<int, string> Relative matching file paths.
 */
function cleanup_cluster_grep_files( string $directory, string $needle, array $extensions ): array {
	$root = dirname( __DIR__ );
	$hits = array();
	$rii  = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $rii as $file ) {
		if ( $file->isDir() ) {
			continue;
		}

		$path = $file->getPathname();
		if (
			false !== strpos( $path, '/vendor/' )
			|| false !== strpos( $path, '/node_modules/' )
			|| false !== strpos( $path, '/build/' )
		) {
			continue;
		}

		$extension = strtolower( $file->getExtension() );
		if ( ! in_array( $extension, $extensions, true ) ) {
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

$root_dir    = dirname( __DIR__ );
$plugin_main = (string) file_get_contents( $root_dir . '/data-machine.php' );

echo "=== Cleanup Cluster Smoke (#1332, #1334, #1335) ===\n";

echo "\n[file:1] Facade proxy files are deleted\n";
foreach ( array(
	'inc/Abilities/PipelineAbilities.php',
	'inc/Abilities/JobAbilities.php',
	'inc/Abilities/FlowStepAbilities.php',
	'inc/Abilities/TaxonomyAbilities.php',
) as $file ) {
	assert_cleanup_cluster( "{$file} deleted", ! file_exists( $root_dir . '/' . $file ) );
}

echo "\n[code:1] Facade proxy class names are gone from PHP source\n";
$facade_names = array( 'PipelineAbilities', 'JobAbilities', 'FlowStepAbilities', 'TaxonomyAbilities' );
foreach ( $facade_names as $name ) {
	$hits = cleanup_cluster_grep_files( $root_dir, $name, array( 'php' ) );
	$hits = array_values(
		array_filter(
			$hits,
			function ( string $hit ): bool {
				return 'tests/cleanup-cluster-smoke.php' !== $hit
					&& 'tests/flow-abilities-proxy-removal-smoke.php' !== $hit;
			}
		)
	);
	assert_cleanup_cluster( "no PHP source references {$name}", array() === $hits );
}

echo "\n[bootstrap:1] data-machine.php directly instantiates concrete ability classes\n";
foreach ( array(
	'FlowStep\\GetFlowStepsAbility',
	'FlowStep\\UpdateFlowStepAbility',
	'FlowStep\\ConfigureFlowStepsAbility',
	'FlowStep\\ValidateFlowStepsConfigAbility',
	'Job\\GetJobsAbility',
	'Job\\DeleteJobsAbility',
	'Job\\ExecuteWorkflowAbility',
	'Job\\FlowHealthAbility',
	'Job\\ProblemFlowsAbility',
	'Job\\RecoverStuckJobsAbility',
	'Job\\JobsSummaryAbility',
	'Job\\FailJobAbility',
	'Job\\RetryJobAbility',
	'Pipeline\\GetPipelinesAbility',
	'Pipeline\\CreatePipelineAbility',
	'Pipeline\\UpdatePipelineAbility',
	'Pipeline\\DeletePipelineAbility',
	'Pipeline\\DuplicatePipelineAbility',
	'Pipeline\\ImportExportAbility',
	'Taxonomy\\ResolveTermAbility',
	'Taxonomy\\MergeTermMetaAbility',
	'Taxonomy\\GetTaxonomyTermsAbility',
	'Taxonomy\\CreateTaxonomyTermAbility',
	'Taxonomy\\UpdateTaxonomyTermAbility',
	'Taxonomy\\DeleteTaxonomyTermAbility',
) as $class ) {
	assert_cleanup_cluster(
		"new \\DataMachine\\Abilities\\{$class}() in data-machine.php",
		false !== strpos( $plugin_main, "new \\DataMachine\\Abilities\\{$class}();" )
	);
}

echo "\n[oauth:1] unused OAuth callback helper is deleted\n";
$oauth_hits = cleanup_cluster_grep_files( $root_dir, 'datamachine_get_oauth_callback_url', array( 'php', 'md' ) );
$oauth_hits = array_values( array_diff( $oauth_hits, array( 'tests/cleanup-cluster-smoke.php' ) ) );
assert_cleanup_cluster( 'no repository references to datamachine_get_oauth_callback_url', array() === $oauth_hits );

echo "\n[tools:1] ToolManager get_global_tools alias is fully collapsed\n";
$tool_hits = cleanup_cluster_grep_files( $root_dir, 'get_global_tools', array( 'php', 'md' ) );
$tool_hits = array_values( array_diff( $tool_hits, array( 'tests/cleanup-cluster-smoke.php' ) ) );
assert_cleanup_cluster( 'no repository references to get_global_tools', array() === $tool_hits );

echo "\n";
if ( 0 === $failed ) {
	echo "=== cleanup-cluster-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}

echo "=== cleanup-cluster-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
