<?php
/**
 * Ownership and task registration continuity for DMB image diagnostics.
 */

namespace {
	$root     = dirname( __DIR__ );
	$failures = array();
	$filters  = array();

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $root . '/' );
	}

	function add_filter( string $hook, callable $callback ): void {
		global $filters;
		$filters[ $hook ][] = $callback;
	}

	function image_diagnostics_assert( bool $condition, string $message ): void {
		global $failures;
		if ( ! $condition ) {
			$failures[] = $message;
		}
	}
}

namespace DataMachine\Engine\AI\System\Tasks {
	abstract class SystemTask {}
}

namespace {
	$ability_paths = array(
		$root . '/inc/Abilities/Media/ImageOptimizationAbilities.php',
		$root . '/inc/Abilities/Media/BrokenImageReferenceAbilities.php',
	);
	$task_path     = $root . '/inc/Engine/AI/System/Tasks/ImageOptimizationTask.php';
	$provider_path = $root . '/inc/Bootstrap/ProviderModules.php';

	foreach ( array_merge( $ability_paths, array( $task_path ) ) as $path ) {
		image_diagnostics_assert( file_exists( $path ), 'Missing DMB-owned file: ' . $path );
	}

	$abilities = file_get_contents( $ability_paths[0] ) . file_get_contents( $ability_paths[1] );
	$task      = file_get_contents( $task_path );
	$provider  = file_get_contents( $provider_path );

	foreach ( array( 'datamachine/diagnose-images', 'datamachine/optimize-images', 'datamachine/diagnose-broken-image-references' ) as $slug ) {
		image_diagnostics_assert( str_contains( $abilities, $slug ), 'Owned ability slug missing: ' . $slug );
		image_diagnostics_assert( str_contains( $provider, $slug ), 'Provider capability missing: ' . $slug );
	}

	$continuity = array(
		'namespace DataMachineBusiness\\Abilities\\Media'                         => $abilities,
		'use DataMachine\\Abilities\\AbilityRegistration'                       => $abilities,
		'use DataMachine\\Abilities\\PermissionHelper'                          => $abilities,
		'use DataMachine\\Engine\\Tasks\\TaskScheduler'                        => $abilities,
		'AbilityRegistration::on_abilities_api_init'                               => $abilities,
		'datamachine_resolve_system_agent_context'                                 => $abilities,
		"'show_in_rest'=>true"                                                    => str_replace( array( "\t", ' ' ), '', $abilities ),
		'namespace DataMachineBusiness\\Engine\\AI\\System\\Tasks'           => $task,
		'use DataMachine\\Engine\\AI\\System\\Tasks\\SystemTask'           => $task,
		"return 'image_optimization'"                                             => $task,
		"'attachment_file_modified'"                                             => $task,
		"'file_created'"                                                         => $task,
		"'task:image_optimization'"                                              => $provider,
		'DataMachineBusiness\\Engine\\AI\\System\\Tasks\\ImageOptimizationTask::register' => $provider,
	);

	foreach ( $continuity as $needle => $haystack ) {
		image_diagnostics_assert( str_contains( $haystack, $needle ), 'Continuity marker missing: ' . $needle );
	}

	foreach ( array( 'AltText', 'ImageGeneration', 'ImageTemplate', 'VideoMetadata', 'UploadValidation' ) as $unrelated ) {
		image_diagnostics_assert( ! str_contains( $abilities . $task . $provider, $unrelated ), 'Unrelated media ownership moved: ' . $unrelated );
	}

	require_once $task_path;
	\DataMachineBusiness\Engine\AI\System\Tasks\ImageOptimizationTask::register();
	\DataMachineBusiness\Engine\AI\System\Tasks\ImageOptimizationTask::register();
	image_diagnostics_assert( 1 === count( $filters['datamachine_tasks'] ?? array() ), 'Task filter registration is idempotent' );

	$registered_tasks = ( $filters['datamachine_tasks'][0] )( array( 'existing' => 'ExistingTask' ) );
	image_diagnostics_assert( 'ExistingTask' === $registered_tasks['existing'], 'Task registration preserves existing handlers' );
	image_diagnostics_assert(
		\DataMachineBusiness\Engine\AI\System\Tasks\ImageOptimizationTask::class === $registered_tasks['image_optimization'],
		'image_optimization resolves to the DMB task class'
	);

	if ( ! empty( $failures ) ) {
		fwrite( STDERR, "Image diagnostics ownership smoke failed:\n - " . implode( "\n - ", $failures ) . "\n" );
		exit( 1 );
	}

	echo "Image diagnostics ownership smoke checks passed.\n";
}
