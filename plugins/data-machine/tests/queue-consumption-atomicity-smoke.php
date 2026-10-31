<?php
/**
 * Pure-PHP smoke test for queue consumption atomicity (#1344).
 *
 * Run with: php tests/queue-consumption-atomicity-smoke.php
 *
 * The production queue slots live inside the flow_config JSON blob. This
 * smoke uses an in-memory DB_Flows double that exposes the same raw-JSON
 * compare-and-swap contract and can inject a stale-read conflict between
 * QueueAbility's read and write phases.
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
		// no-op for tests.
	}
}

require_once __DIR__ . '/../inc/Core/Database/BaseRepository.php';
require_once __DIR__ . '/../inc/Core/Database/Flows/Flows.php';
require_once __DIR__ . '/../inc/Abilities/Flow/FlowHelpers.php';
require_once __DIR__ . '/../inc/Abilities/Flow/QueueAbility.php';

use DataMachine\Abilities\Flow\QueueAbility;
use DataMachine\Core\Database\Flows\Flows as DB_Flows;

$failed = 0;
$total  = 0;

function assert_atomicity( string $name, bool $condition ): void {
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
 * In-memory flow repository with a raw JSON CAS surface.
 */
class QueueAtomicityFlowsDouble extends DB_Flows {
	/** @var string */
	private $raw_config_json;

	/** @var callable|null */
	private $before_next_cas = null;

	/** @var int */
	public $raw_reads = 0;

	/** @var int */
	public $cas_calls = 0;

	public function __construct( array $flow_config ) {
		$this->raw_config_json = wp_json_encode( $flow_config );
	}

	public function get_flow( int $flow_id ): ?array {
		return array(
			'flow_id'     => $flow_id,
			'flow_config' => json_decode( $this->raw_config_json, true ) ?: array(),
		);
	}

	public function get_flow_config_json( int $flow_id ): ?string {
		++$this->raw_reads;
		return $this->raw_config_json;
	}

	public function compare_and_swap_flow_config( int $flow_id, string $expected_config_json, array $new_flow_config ): bool {
		++$this->cas_calls;

		if ( null !== $this->before_next_cas ) {
			$callback              = $this->before_next_cas;
			$this->before_next_cas = null;
			$callback( $this );
		}

		if ( $this->raw_config_json !== $expected_config_json ) {
			return false;
		}

		$this->raw_config_json = wp_json_encode( $new_flow_config );
		return true;
	}

	public function injectBeforeNextCas( callable $callback ): void {
		$this->before_next_cas = $callback;
	}

	public function consumeExternally( string $flow_step_id, string $slot, string $mode ): ?array {
		$flow_config = json_decode( $this->raw_config_json, true ) ?: array();
		$queue       = $flow_config[ $flow_step_id ][ $slot ] ?? array();
		if ( empty( $queue ) ) {
			return null;
		}

		$entry = array_shift( $queue );
		if ( 'loop' === $mode ) {
			$queue[] = $entry;
		}

		$flow_config[ $flow_step_id ][ $slot ] = $queue;
		$flow_config[ $flow_step_id ]['_queue_consume_revision'] = (int) ( $flow_config[ $flow_step_id ]['_queue_consume_revision'] ?? 0 ) + 1;
		$this->raw_config_json = wp_json_encode( $flow_config );

		return $entry;
	}

	public function queuePrompts(): array {
		$flow_config = json_decode( $this->raw_config_json, true ) ?: array();
		return array_map(
			function ( array $entry ): string {
				return (string) $entry['prompt'];
			},
			$flow_config['step1'][ QueueAbility::SLOT_PROMPT_QUEUE ] ?? array()
		);
	}

	public function revision(): int {
		$flow_config = json_decode( $this->raw_config_json, true ) ?: array();
		return (int) ( $flow_config['step1']['_queue_consume_revision'] ?? 0 );
	}
}

function queue_atomicity_config( array $prompts ): array {
	return array(
		'step1' => array(
			QueueAbility::SLOT_PROMPT_QUEUE => array_map(
				function ( string $prompt ): array {
					return array(
						'prompt'   => $prompt,
						'added_at' => 't-' . $prompt,
					);
				},
				$prompts
			),
		),
	);
}

echo "=== Queue Consumption Atomicity Smoke (#1344) ===\n";

echo "\n[drain:1] stale drain read retries and consumes the new head\n";
$db       = new QueueAtomicityFlowsDouble( queue_atomicity_config( array( 'first', 'second' ) ) );
$external = null;
$db->injectBeforeNextCas(
	function ( QueueAtomicityFlowsDouble $db ) use ( &$external ): void {
		$external = $db->consumeExternally( 'step1', QueueAbility::SLOT_PROMPT_QUEUE, 'drain' );
	}
);
$entry = QueueAbility::consumeFromQueueSlot( 123, 'step1', QueueAbility::SLOT_PROMPT_QUEUE, 'drain', $db );
assert_atomicity( 'simulated first worker consumed the original head', 'first' === ( $external['prompt'] ?? null ) );
assert_atomicity( 'production consumer retried and returned the next head', 'second' === ( $entry['prompt'] ?? null ) );
assert_atomicity( 'queue is empty after two distinct drain consumptions', array() === $db->queuePrompts() );
assert_atomicity( 'stale drain path performed two CAS attempts', 2 === $db->cas_calls );

echo "\n[loop:1] stale loop read retries against the rotated queue\n";
$db       = new QueueAtomicityFlowsDouble( queue_atomicity_config( array( 'first', 'second', 'third' ) ) );
$external = null;
$db->injectBeforeNextCas(
	function ( QueueAtomicityFlowsDouble $db ) use ( &$external ): void {
		$external = $db->consumeExternally( 'step1', QueueAbility::SLOT_PROMPT_QUEUE, 'loop' );
	}
);
$entry = QueueAbility::consumeFromQueueSlot( 123, 'step1', QueueAbility::SLOT_PROMPT_QUEUE, 'loop', $db );
assert_atomicity( 'simulated first worker rotated the original head', 'first' === ( $external['prompt'] ?? null ) );
assert_atomicity( 'production consumer retried and returned the rotated head', 'second' === ( $entry['prompt'] ?? null ) );
assert_atomicity( 'loop queue rotated deterministically after contention', array( 'third', 'first', 'second' ) === $db->queuePrompts() );
assert_atomicity( 'loop contention bumped the consume revision twice', 2 === $db->revision() );

echo "\n[static:1] static mode peeks without raw-write/CAS path\n";
$db    = new QueueAtomicityFlowsDouble( queue_atomicity_config( array( 'first', 'second' ) ) );
$entry = QueueAbility::consumeFromQueueSlot( 123, 'step1', QueueAbility::SLOT_PROMPT_QUEUE, 'static', $db );
assert_atomicity( 'static returns the head entry', 'first' === ( $entry['prompt'] ?? null ) );
assert_atomicity( 'static leaves the queue unchanged', array( 'first', 'second' ) === $db->queuePrompts() );
assert_atomicity( 'static does not call raw JSON read or CAS helpers', 0 === $db->raw_reads && 0 === $db->cas_calls );
assert_atomicity( 'static does not bump the consume revision', 0 === $db->revision() );

echo "\n[source:1] production source uses CAS for mutating consumption\n";
$qa_src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Flow/QueueAbility.php' );
assert_atomicity( 'consumeFromQueueSlot reads raw JSON for mutating modes', false !== strpos( $qa_src, 'get_flow_config_json' ) );
assert_atomicity( 'consumeFromQueueSlot writes through compare_and_swap_flow_config', false !== strpos( $qa_src, 'compare_and_swap_flow_config' ) );
assert_atomicity( 'consume revision makes loop writes observable even for one-item queues', false !== strpos( $qa_src, "'_queue_consume_revision'" ) );

echo "\n";
if ( 0 === $failed ) {
	echo "=== queue-consumption-atomicity-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}

echo "=== queue-consumption-atomicity-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
