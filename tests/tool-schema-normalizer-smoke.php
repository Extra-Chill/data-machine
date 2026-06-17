<?php
/**
 * Smoke test for canonical AI tool schema normalization.
 *
 * Run with: php tests/tool-schema-normalizer-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use DataMachine\Engine\AI\ProviderRequestAssembler;
use DataMachine\Engine\AI\ToolSchemaNormalizer;

$failures = array();
$passes   = 0;

function datamachine_tool_schema_assert_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

echo "tool-schema-normalizer-smoke\n";

$empty_schema = ToolSchemaNormalizer::normalize( array() );
datamachine_tool_schema_assert_equals( 'object', $empty_schema['type'] ?? null, 'empty schema becomes an object schema', $failures, $passes );
datamachine_tool_schema_assert_equals( true, is_object( $empty_schema['properties'] ?? null ), 'empty schema properties are encoded as an object', $failures, $passes );

$canonical = array(
	'type'       => 'object',
	'required'   => array( 'message' ),
	'properties' => array(
		'message' => array(
			'type'        => 'string',
			'description' => 'Message to summarize.',
		),
	),
);
datamachine_tool_schema_assert_equals( $canonical, ToolSchemaNormalizer::normalize( $canonical ), 'canonical object schema is preserved', $failures, $passes );

$flat = ToolSchemaNormalizer::normalize(
	array(
		'message' => array(
			'type'        => 'string',
			'description' => 'Message to summarize.',
		),
		'count'   => 'integer',
	)
);
datamachine_tool_schema_assert_equals( 'object', $flat['type'] ?? null, 'flat legacy map becomes root object schema', $failures, $passes );
datamachine_tool_schema_assert_equals(
	array(
		'message' => array(
			'type'        => 'string',
			'description' => 'Message to summarize.',
		),
		'count'   => array( 'type' => 'string' ),
	),
	$flat['properties'] ?? null,
	'flat legacy map becomes properties map',
	$failures,
	$passes
);

$required = ToolSchemaNormalizer::normalize(
	array(
		'type'       => 'object',
		'required'   => array( 'message' ),
		'properties' => array(
			'message' => array(
				'type'     => 'string',
				'required' => true,
			),
			'title'   => array(
				'type'     => 'string',
				'required' => true,
			),
		),
	)
);
datamachine_tool_schema_assert_equals( array( 'message', 'title' ), $required['required'] ?? null, 'property-level required flags are hoisted and deduped', $failures, $passes );
datamachine_tool_schema_assert_equals( false, isset( $required['properties']['title']['required'] ), 'property-level required flag is removed from property schema', $failures, $passes );

$structured = ProviderRequestAssembler::restructureTools(
	array(
		'summarize' => array(
			'description' => 'Summarize text.',
			'parameters'  => array(
				'message' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		),
	)
);
datamachine_tool_schema_assert_equals( array( 'message' ), $structured['summarize']['parameters']['required'] ?? null, 'ProviderRequestAssembler uses canonical normalizer', $failures, $passes );
datamachine_tool_schema_assert_equals( false, isset( $structured['summarize']['parameters']['properties']['message']['required'] ), 'ProviderRequestAssembler strips legacy property required flag', $failures, $passes );

echo "\nAssertions: {$passes} passed, " . count( $failures ) . ' failed, ' . ( $passes + count( $failures ) ) . " total\n";
exit( count( $failures ) );
