<?php
/**
 * Focused contract test for typed artifact completion/output plumbing.
 *
 * Run with: php tests/typed-artifact-output-contract-smoke.php
 *
 * @package DataMachine\Tests
 */

$root     = dirname( __DIR__ );
$failures = array();
$passes   = 0;

defined( 'ABSPATH' ) || define( 'ABSPATH', $root . '/' );

require_once $root . '/inc/Core/DataPath.php';
require_once $root . '/inc/Core/OutputContract.php';
require_once $root . '/inc/Engine/AI/DataMachineCompletionAssertions.php';
require_once $root . '/inc/Engine/AI/conversation-loop.php';

function datamachine_typed_artifact_contract_assert( bool $condition, string $label, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  PASS {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "  FAIL {$label}\n";
}

echo "typed-artifact-output-contract-smoke\n";

$normalized = \DataMachine\Engine\AI\datamachine_normalize_typed_artifact_outputs(
	array(
		'typed_artifacts' => array(
			array(
				'output_key' => 'concept_packet',
				'schema'     => 'example-agent/ConceptPacket/v1',
				'artifact'   => 'ConceptPacket',
				'payload'    => array( 'title' => 'Normalized concept' ),
			),
		),
	)
);

datamachine_typed_artifact_contract_assert(
	array( 'title' => 'Normalized concept' ) === ( $normalized['concept_packet']['payload'] ?? null ),
	'loop result typed_artifacts normalize to outputs.typed_artifacts.<key>.payload shape',
	$failures,
	$passes
);

$tool_result_normalized = \DataMachine\Engine\AI\datamachine_normalize_typed_artifact_outputs(
	array(
		'tool_execution_results' => array(
			array(
				'success' => true,
				'data'    => array(
					'typed_artifacts' => array(
						'concept_packet' => array(
							'schema'   => 'example-agent/ConceptPacket/v1',
							'artifact' => 'ConceptPacket',
							'payload'  => array( 'title' => 'Tool Result Concept' ),
						),
					),
				),
			),
		),
	)
);

datamachine_typed_artifact_contract_assert(
	array( 'title' => 'Tool Result Concept' ) === ( $tool_result_normalized['concept_packet']['payload'] ?? null ),
	'typed artifact output normalizes from tool result data',
	$failures,
	$passes
);

$assertions = new \DataMachine\Engine\AI\DataMachineCompletionAssertions(
	array(
		'required_artifact_outputs' => array(
			array(
				'output_key' => 'concept_packet',
				'schema'     => 'example-agent/ConceptPacket/v1',
				'artifact'   => 'ConceptPacket',
			),
		),
	)
);

$satisfied = $assertions->evaluate(
	array(
		'engine_data' => array(
			'outputs' => array(
				'typed_artifacts' => $normalized,
			),
		),
	),
	''
);

datamachine_typed_artifact_contract_assert( true === $satisfied['complete'], 'required_artifact_outputs is recognized as a completion assertion target', $failures, $passes );
datamachine_typed_artifact_contract_assert( array( 'concept_packet' ) === ( $satisfied['satisfied']['artifact_outputs'] ?? null ), 'typed artifact completion reports the satisfied output key', $failures, $passes );

$top_level_satisfied = $assertions->evaluate(
	array(
		'outputs' => array(
			'typed_artifacts' => $normalized,
		),
	),
	''
);
datamachine_typed_artifact_contract_assert( true === $top_level_satisfied['complete'], 'required_artifact_outputs is recognized from top-level outputs.typed_artifacts', $failures, $passes );

$tool_result_assertions = new \DataMachine\Engine\AI\DataMachineCompletionAssertions(
	array(
		'required_artifact_outputs' => array(
			array(
				'output_key' => 'concept_packet',
				'schema'     => 'example-agent/ConceptPacket/v1',
				'artifact'   => 'ConceptPacket',
			),
		),
	)
);
$tool_result_assertions->recordToolResult(
	'emit_typed_artifact',
	array( 'handler' => 'typed_artifact' ),
	array(
		'success' => true,
		'outputs' => array(
			'typed_artifacts' => array(
				'concept_packet' => array(
					'schema'   => 'example-agent/ConceptPacket/v1',
					'artifact' => 'ConceptPacket',
					'payload'  => array( 'title' => 'Tool Result Concept' ),
				),
			),
		),
	)
);
$tool_result_satisfied = $tool_result_assertions->evaluate( array(), '' );
datamachine_typed_artifact_contract_assert( true === $tool_result_satisfied['complete'], 'required_artifact_outputs can be satisfied by a handler tool result', $failures, $passes );

$missing = $assertions->evaluate(
	array(
		'engine_data' => array(
			'outputs' => array(
				'typed_artifacts' => array(
					'concept_packet' => array(
						'schema'   => 'example-agent/ConceptPacket/v1',
						'artifact' => 'ConceptPacket',
						'payload'  => array(),
					),
				),
			),
		),
	),
	''
);

datamachine_typed_artifact_contract_assert( false === $missing['complete'], 'empty typed artifact payload does not satisfy required_artifact_outputs', $failures, $passes );
datamachine_typed_artifact_contract_assert( array( 'concept_packet' ) === ( $missing['missing']['artifact_outputs'] ?? null ), 'missing typed artifact output reports the required output key', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nTyped artifact output contract smoke failed (" . count( $failures ) . " failure(s)).\n";
	exit( 1 );
}

echo "\nTyped artifact output contract smoke passed ({$passes} assertions).\n";
