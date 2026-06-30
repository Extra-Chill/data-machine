<?php
/**
 * Pure-PHP smoke test for ConversationCompactionPolicyResolver and
 * ConversationCompactionSummarizer model resolution.
 *
 * Run with: php tests/conversation-compaction-policy-resolver-smoke.php
 *
 * Verifies the per-agent compaction opt-in contract without booting WordPress:
 *  - Compaction is DISABLED by default (no opt-in) — strict backward-compatible
 *    no-op.
 *  - An explicit conversation_compaction_policy with enabled=true opts in.
 *  - The supports_conversation_compaction capability flag opts in with defaults.
 *  - An explicit enabled=false (even with the capability flag) stays disabled.
 *  - The summarizer honors an explicit policy provider/model pin and the
 *    summary-model filter, falling back to the resolved system model otherwise.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Agents {
	class Agents {
		public function get_agent( int $agent_id ): ?array {
			return $GLOBALS['__compaction_agents'][ $agent_id ] ?? null;
		}
	}
}

namespace DataMachine\Core {
	class PluginSettings {
		public static function resolveModelForAgentMode( ?int $agent_id, string $mode ): array {
			return $GLOBALS['__compaction_system_model'] ?? array(
				'provider' => '',
				'model'    => '',
			);
		}
	}
}

namespace DataMachine\Engine\AI {
	// Stub the conversation runner referenced by the summarizer so requiring the
	// summarizer file does not pull the full loop; the resolver smoke does not
	// invoke the summarizer body.
	if ( ! function_exists( 'DataMachine\\Engine\\AI\\datamachine_run_conversation' ) ) {
		function datamachine_run_conversation( ...$args ): array {
			return array( 'final_content' => '' );
		}
	}
}

namespace {
	require_once __DIR__ . '/agents-api-loader.php';

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			return $value;
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $options = 0, $depth = 512 ) {
			return json_encode( $data, $options, $depth );
		}
	}

	datamachine_tests_require_agents_api();

	require_once __DIR__ . '/../inc/Engine/AI/Compaction/ConversationCompactionPolicyResolver.php';
	require_once __DIR__ . '/../inc/Engine/AI/Compaction/ConversationCompactionSummarizer.php';

	use DataMachine\Engine\AI\Compaction\ConversationCompactionPolicyResolver;
	use DataMachine\Engine\AI\Compaction\ConversationCompactionSummarizer;

	$assertions = 0;

	function compaction_assert_same( $expected, $actual, string $message ): void {
		global $assertions;
		++$assertions;
		if ( $expected !== $actual ) {
			fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
			exit( 1 );
		}
	}

	$resolver = new ConversationCompactionPolicyResolver();

	// 1. No agent / no opt-in => disabled no-op.
	$policy = $resolver->resolve( array( 'agent_id' => 0, 'modes' => array( 'chat' ) ) );
	compaction_assert_same( false, $policy['enabled'], 'no agent resolves to disabled compaction' );
	compaction_assert_same( true, $policy['preserve_tool_boundaries'], 'disabled default still carries safe substrate defaults' );

	// 2. Agent exists but has no compaction config => disabled no-op.
	$GLOBALS['__compaction_agents'][1] = array( 'agent_config' => array() );
	$policy = $resolver->resolve( array( 'agent_id' => 1, 'modes' => array( 'chat' ) ) );
	compaction_assert_same( false, $policy['enabled'], 'agent without compaction config resolves to disabled' );

	// 3. Explicit policy with enabled=true opts in.
	$GLOBALS['__compaction_agents'][2] = array(
		'agent_config' => array(
			'conversation_compaction_policy' => array(
				'enabled'         => true,
				'max_messages'    => 30,
				'recent_messages' => 8,
			),
		),
	);
	$policy = $resolver->resolve( array( 'agent_id' => 2, 'modes' => array( 'chat' ) ) );
	compaction_assert_same( true, $policy['enabled'], 'explicit enabled policy opts in' );
	compaction_assert_same( 30, $policy['max_messages'], 'explicit policy max_messages is honored' );
	compaction_assert_same( 8, $policy['recent_messages'], 'explicit policy recent_messages is honored' );

	// 4. Capability flag alone opts in with substrate defaults.
	$GLOBALS['__compaction_agents'][3] = array(
		'agent_config' => array(
			'supports_conversation_compaction' => true,
		),
	);
	$policy = $resolver->resolve( array( 'agent_id' => 3, 'modes' => array( 'chat' ) ) );
	compaction_assert_same( true, $policy['enabled'], 'capability flag alone opts in' );
	compaction_assert_same( 40, $policy['max_messages'], 'capability-flag opt-in uses default max_messages' );

	// 5. Explicit enabled=false wins even with the capability flag set.
	$GLOBALS['__compaction_agents'][4] = array(
		'agent_config' => array(
			'supports_conversation_compaction' => true,
			'conversation_compaction_policy'   => array( 'enabled' => false ),
		),
	);
	$policy = $resolver->resolve( array( 'agent_id' => 4, 'modes' => array( 'chat' ) ) );
	compaction_assert_same( false, $policy['enabled'], 'explicit enabled=false overrides capability flag' );

	// 6. Summarizer honors an explicit provider/model pin in the policy.
	$pinned = ConversationCompactionSummarizer::resolveSummaryModel(
		array( 'agent_id' => 7 ),
		array( 'summary_provider' => 'openai', 'summary_model' => 'gpt-4o-mini' )
	);
	compaction_assert_same( 'openai', $pinned['provider'], 'summarizer honors pinned provider' );
	compaction_assert_same( 'gpt-4o-mini', $pinned['model'], 'summarizer honors pinned model' );

	// 7. Summarizer falls back to the resolved system model when not pinned.
	$GLOBALS['__compaction_system_model'] = array( 'provider' => 'anthropic', 'model' => 'claude-haiku' );
	$fallback = ConversationCompactionSummarizer::resolveSummaryModel( array( 'agent_id' => 7 ), array() );
	compaction_assert_same( 'anthropic', $fallback['provider'], 'summarizer falls back to system provider' );
	compaction_assert_same( 'claude-haiku', $fallback['model'], 'summarizer falls back to system model' );

	// 8. build() returns a callable matching the contract arity.
	$summarizer = ConversationCompactionSummarizer::build( array( 'agent_id' => 7 ), array( 'enabled' => true ) );
	compaction_assert_same( true, is_callable( $summarizer ), 'summarizer factory returns a callable' );

	fwrite( fopen( 'php://stdout', 'w' ), "ConversationCompactionPolicyResolver smoke passed ({$assertions} assertions).\n" );
}
