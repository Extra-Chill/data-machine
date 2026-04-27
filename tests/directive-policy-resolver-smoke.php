<?php
/**
 * Pure-PHP smoke test for DirectivePolicyResolver.
 *
 * Run with: php tests/directive-policy-resolver-smoke.php
 *
 * Covers the per-agent directive policy contract without booting WordPress.
 * Policy entries match either short class names or fully-qualified class names.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Agents {
	class Agents {
		public function get_agent( int $agent_id ): ?array {
			return $GLOBALS['__directive_policy_agents'][ $agent_id ] ?? null;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value ) {
			return $value;
		}
	}

	require_once __DIR__ . '/../inc/Engine/AI/Directives/DirectivePolicyResolver.php';

	use DataMachine\Engine\AI\Directives\DirectivePolicyResolver;

	$assertions = 0;

	function directive_policy_assert_same( $expected, $actual, string $message ): void {
		global $assertions;
		++$assertions;
		if ( $expected !== $actual ) {
			fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
			exit( 1 );
		}
	}

	function directive_policy_directives(): array {
		return array(
			array(
				'class'    => 'DataMachine\\Engine\\AI\\Directives\\CoreMemoryFilesDirective',
				'priority' => 10,
				'modes'    => array( 'all' ),
			),
			array(
				'class'    => 'DataMachine\\Engine\\AI\\Directives\\AgentDailyMemoryDirective',
				'priority' => 20,
				'modes'    => array( 'all' ),
			),
			array(
				'class'    => 'DataMachine\\Engine\\AI\\Directives\\AgentModeDirective',
				'priority' => 30,
				'modes'    => array( 'all' ),
			),
		);
	}

	function directive_policy_names( array $directives ): array {
		return array_map(
			function ( array $directive ): string {
				$class = $directive['class'];
				return substr( $class, strrpos( $class, '\\' ) + 1 );
			},
			$directives
		);
	}

	$resolver   = new DirectivePolicyResolver();
	$directives = directive_policy_directives();

	// Default policy applies all directives as before.
	$result = $resolver->resolve(
		$directives,
		array(
			'mode'     => 'pipeline',
			'agent_id' => 0,
		)
	);
	directive_policy_assert_same( directive_policy_names( $directives ), directive_policy_names( $result['directives'] ), 'default policy keeps all directives' );
	directive_policy_assert_same( array(), $result['suppressed'], 'default policy suppresses nothing' );

	// Deny policy suppresses a short class name.
	$GLOBALS['__directive_policy_agents'][1] = array(
		'agent_config' => array(
			'directive_policy' => array(
				'mode' => 'deny',
				'deny' => array( 'CoreMemoryFilesDirective' ),
			),
		),
	);
	$result = $resolver->resolve(
		$directives,
		array(
			'mode'     => 'pipeline',
			'agent_id' => 1,
		)
	);
	directive_policy_assert_same( array( 'AgentDailyMemoryDirective', 'AgentModeDirective' ), directive_policy_names( $result['directives'] ), 'deny policy removes short class match' );
	directive_policy_assert_same( array( 'CoreMemoryFilesDirective' ), $result['suppressed'], 'deny policy reports suppressed class' );

	// allow_only policy accepts fully-qualified class names.
	$GLOBALS['__directive_policy_agents'][2] = array(
		'agent_config' => array(
			'directive_policy' => array(
				'mode'       => 'allow_only',
				'allow_only' => array( 'DataMachine\\Engine\\AI\\Directives\\AgentModeDirective' ),
			),
		),
	);
	$result = $resolver->resolve(
		$directives,
		array(
			'mode'     => 'pipeline',
			'agent_id' => 2,
		)
	);
	directive_policy_assert_same( array( 'AgentModeDirective' ), directive_policy_names( $result['directives'] ), 'allow_only policy keeps only FQCN match' );
	directive_policy_assert_same( array( 'CoreMemoryFilesDirective', 'AgentDailyMemoryDirective' ), $result['suppressed'], 'allow_only policy reports suppressed classes' );

	// Invalid policy is ignored safely.
	$GLOBALS['__directive_policy_agents'][3] = array(
		'agent_config' => array(
			'directive_policy' => array(
				'mode' => 'nonsense',
				'deny' => array( 'CoreMemoryFilesDirective' ),
			),
		),
	);
	$result = $resolver->resolve(
		$directives,
		array(
			'mode'     => 'pipeline',
			'agent_id' => 3,
		)
	);
	directive_policy_assert_same( directive_policy_names( $directives ), directive_policy_names( $result['directives'] ), 'invalid policy keeps all directives' );
	directive_policy_assert_same( array(), $result['suppressed'], 'invalid policy suppresses nothing' );

	// Optional modes scope makes policy mode-aware.
	$GLOBALS['__directive_policy_agents'][4] = array(
		'agent_config' => array(
			'directive_policy' => array(
				'mode'  => 'deny',
				'deny'  => array( 'AgentDailyMemoryDirective' ),
				'modes' => array( 'pipeline' ),
			),
		),
	);
	$result = $resolver->resolve(
		$directives,
		array(
			'mode'     => 'chat',
			'agent_id' => 4,
		)
	);
	directive_policy_assert_same( directive_policy_names( $directives ), directive_policy_names( $result['directives'] ), 'mode-scoped policy does not affect other modes' );

	$result = $resolver->resolve(
		$directives,
		array(
			'mode'     => 'pipeline',
			'agent_id' => 4,
		)
	);
	directive_policy_assert_same( array( 'CoreMemoryFilesDirective', 'AgentModeDirective' ), directive_policy_names( $result['directives'] ), 'mode-scoped policy affects matching mode' );

	fwrite( STDOUT, "DirectivePolicyResolver smoke passed ({$assertions} assertions).\n" );
}
