<?php
/**
 * Pure-PHP smoke test for static prompt queues in direct workflows.
 *
 * Run with: php tests/queueable-direct-static-prompt-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities\Flow {
	class QueueAbility {
		public const SLOT_PROMPT_QUEUE       = 'prompt_queue';
		public const SLOT_CONFIG_PATCH_QUEUE = 'config_patch_queue';

		public static function consumeFromQueueSlot( int $flow_id, string $flow_step_id, string $slot, string $queue_mode ): ?array {
			throw new \RuntimeException( 'Persistent queue storage should not be read for direct static workflows.' );
		}
	}
}

namespace DataMachine\Core\Steps {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	require_once dirname( __DIR__ ) . '/inc/Core/Steps/QueueableTrait.php';

	class Queueable_Direct_Static_Prompt_Test_Engine {
		public function getJobContext(): array {
			return array( 'flow_id' => 'direct' );
		}
	}

	class Queueable_Direct_Static_Prompt_Test_Step {
		use QueueableTrait;

		public object $engine;
		public string $flow_step_id = 'ephemeral_step_1';
		public array $flow_step_config = array(
			'prompt_queue' => array(
				array(
					'prompt'   => 'Refresh this exact source URL.',
					'added_at' => '2026-05-16T00:00:00Z',
				),
			),
		);

		public function __construct() {
			$this->engine = new Queueable_Direct_Static_Prompt_Test_Engine();
		}

		public function consumeForTest( string $queue_mode ): array {
			return $this->consumeFromPromptQueue( $queue_mode );
		}
	}
}

namespace {
	$failures = 0;
	$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		++$failures;
		echo "  [FAIL] {$message}\n";
	};

	echo "Queueable direct static prompt smoke\n";

	$step   = new \DataMachine\Core\Steps\Queueable_Direct_Static_Prompt_Test_Step();
	$result = $step->consumeForTest( 'static' );

	$assert( 'Refresh this exact source URL.' === ( $result['value'] ?? '' ), 'direct static workflows read prompt_queue from step config' );
	$assert( true === ( $result['from_queue'] ?? false ), 'direct static prompt is marked as queue-sourced' );
	$assert( false === ( $result['mutated'] ?? true ), 'direct static prompt consumption is non-mutating' );

	$drain_result = $step->consumeForTest( 'drain' );
	$assert( '' === ( $drain_result['value'] ?? null ), 'direct non-static workflows do not read embedded prompt_queue' );

	if ( $failures > 0 ) {
		exit( 1 );
	}

	echo "Queueable direct static prompt smoke passed.\n";
}
