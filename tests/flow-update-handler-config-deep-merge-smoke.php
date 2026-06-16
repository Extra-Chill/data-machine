<?php
/**
 * Pure-PHP smoke test for atomic deep-merge of handler/settings config
 * (#2673).
 *
 * Run with: php tests/flow-update-handler-config-deep-merge-smoke.php
 *
 * Pins the data-integrity fix for the CLI write path that nearly corrupted
 * a live flow: a partial `--handler-config '{"params":{"message":"..."}}'`
 * update used to be applied with a shallow array_merge(), which replaced the
 * entire `params` array and silently dropped the sibling keys (channel,
 * recipient) that weren't restated. The stored message was truncated rather
 * than left untouched.
 *
 * FlowStepHelpers::deepMergeConfig() recursively merges nested associative
 * arrays so a partial nested patch keeps the existing sibling keys. This
 * smoke pins:
 *
 *   1. A partial `params` patch preserves unmentioned sibling keys.
 *   2. A mentioned nested key is overwritten with the new value.
 *   3. Top-level scalar keys still behave like array_merge() (overwrite).
 *   4. Numeric-keyed (list) arrays are replaced wholesale, not index-merged.
 *   5. An empty incoming patch object does not clobber an existing nested map.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/inc/Abilities/FlowStep/FlowStepHelpers.php';

/**
 * Minimal host that exposes the trait's protected static deepMergeConfig()
 * for direct assertion without a full WordPress bootstrap.
 */
class DeepMergeConfigSmokeHost {
	use \DataMachine\Abilities\FlowStep\FlowStepHelpers;

	public static function merge( array $existing, array $patch ): array {
		return self::deepMergeConfig( $existing, $patch );
	}
}

// ─── Test harness ─────────────────────────────────────────────────────

$failures = array();
$passes   = 0;

function smoke_assert_equals( $expected, $actual, string $name ): void {
	global $failures, $passes;
	if ( $expected === $actual ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

echo "flow update --handler-config deep-merge atomicity smoke (#2673)\n";
echo "----------------------------------------------------------------\n";

echo "\n[1] partial params patch preserves unmentioned sibling keys:\n";

$existing = array(
	'task_type' => 'dispatch_message',
	'params'    => array(
		'channel'   => 'example-channel',
		'recipient' => 'example-recipient',
		'message'   => 'original long message body',
	),
);

$patch  = array( 'params' => array( 'message' => 'NEW message body' ) );
$merged = DeepMergeConfigSmokeHost::merge( $existing, $patch );

smoke_assert_equals(
	array(
		'task_type' => 'dispatch_message',
		'params'    => array(
			'channel'   => 'example-channel',
			'recipient' => 'example-recipient',
			'message'   => 'NEW message body',
		),
	),
	$merged,
	'partial params message update keeps channel/recipient and task_type'
);

echo "\n[2] a mentioned nested key is overwritten:\n";

smoke_assert_equals(
	'NEW message body',
	$merged['params']['message'],
	'message reflects the patch value, not the original'
);

echo "\n[3] top-level scalar keys overwrite (array_merge parity):\n";

$merged_scalar = DeepMergeConfigSmokeHost::merge(
	array( 'task_type' => 'dispatch_message', 'params' => array( 'message' => 'x' ) ),
	array( 'task_type' => 'send_email' )
);

smoke_assert_equals( 'send_email', $merged_scalar['task_type'], 'top-level task_type overwrites' );
smoke_assert_equals( array( 'message' => 'x' ), $merged_scalar['params'], 'untouched params survives a top-level overwrite' );

echo "\n[4] numeric-keyed list arrays are replaced wholesale:\n";

$merged_list = DeepMergeConfigSmokeHost::merge(
	array( 'params' => array( 'attachments' => array( 'a', 'b', 'c' ) ) ),
	array( 'params' => array( 'attachments' => array( 'z' ) ) )
);

smoke_assert_equals(
	array( 'z' ),
	$merged_list['params']['attachments'],
	'list value is replaced, not index-merged into [z,b,c]'
);

echo "\n[5] empty incoming patch object does not clobber an existing nested map:\n";

$merged_empty = DeepMergeConfigSmokeHost::merge(
	array( 'params' => array( 'message' => 'keep me', 'channel' => 'c1' ) ),
	array( 'params' => array() )
);

smoke_assert_equals(
	array( 'message' => 'keep me', 'channel' => 'c1' ),
	$merged_empty['params'],
	'empty params patch leaves the existing params map intact'
);

echo "\n----------------------------------------------------------------\n";
$total = $passes + count( $failures );
echo "{$passes} / {$total} passed\n";

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "\nAll assertions passed.\n";
