<?php
/**
 * Runtime smoke test for image optimization scheduler context propagation.
 *
 * Run with: php tests/image-optimization-scheduling-context-smoke.php
 *
 * @package DataMachineBusiness\Tests
 */

namespace DataMachine\Engine\Tasks {
	class TaskScheduler {
		/** @var array<string, mixed>|null */
		public static ?array $context = null;

		public static function scheduleBatch( string $task_type, array $item_params, array $context ): array {
			self::$context = $context;
			return array(
				'batch_id' => 'image-context-smoke',
			);
		}
	}
}

namespace DataMachineBusiness\Abilities\Media {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

namespace {
	function datamachine_resolve_system_agent_context(): array {
		return array(
			'user_id'            => '17',
			'agent_id'           => '29',
			'triggering_user_id' => '43',
		);
	}

	define( 'ABSPATH', __DIR__ . '/' );

	require_once dirname( __DIR__ ) . '/inc/Abilities/Media/ImageOptimizationAbilities.php';

	$result = \DataMachineBusiness\Abilities\Media\ImageOptimizationAbilities::optimizeImages(
		array(
			'attachment_id' => 101,
		)
	);

	$expected_context = array(
		'user_id'            => 17,
		'agent_id'           => 29,
		'triggering_user_id' => 43,
	);
	if ( true !== $result['success'] || 'image-context-smoke' !== $result['batch_id'] || $expected_context !== \DataMachine\Engine\Tasks\TaskScheduler::$context ) {
		fwrite( STDERR, "Image optimization scheduling context smoke failed.\n" );
		exit( 1 );
	}

	echo "Image optimization scheduling context smoke passed.\n";
}
