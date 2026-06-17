<?php
/**
 * Smoke test for generic output contract helper semantics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/wp/' );
}

require_once dirname( __DIR__ ) . '/inc/Core/DataPath.php';
require_once dirname( __DIR__ ) . '/inc/Core/OutputContract.php';

use DataMachine\Core\DataPath;
use DataMachine\Core\OutputContract;

$failures = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$source = array(
	'a' => array(
		'b'     => array(
			'zero_int'    => 0,
			'zero_string' => '0',
			'false_bool'  => false,
			'empty_text'  => '',
			'empty_list'  => array(),
		),
		'value' => 'present',
	),
);

$assert( 'present' === DataPath::value( $source, 'a.value' ), 'DataPath resolves nested scalar values.' );
$assert( null === DataPath::value( $source, 'a.missing' ), 'DataPath returns null for missing paths.' );
$assert( 0 === DataPath::value( $source, 'a.b.zero_int' ), 'DataPath preserves integer zero.' );
$assert( false === DataPath::value( $source, 'a.b.false_bool' ), 'DataPath preserves false.' );

$assert( DataPath::hasValue( 0 ), 'Integer zero is present.' );
$assert( DataPath::hasValue( '0' ), 'String zero is present.' );
$assert( DataPath::hasValue( false ), 'False is present.' );
$assert( ! DataPath::hasValue( null ), 'Null is absent.' );
$assert( ! DataPath::hasValue( '' ), 'Empty string is absent.' );
$assert( ! DataPath::hasValue( array() ), 'Empty array is absent.' );
$assert( DataPath::hasPresentPath( $source, 'a.b.zero_int' ), 'Present path accepts integer zero.' );
$assert( DataPath::hasPresentPath( $source, 'a.b.false_bool' ), 'Present path accepts false.' );
$assert( ! DataPath::hasPresentPath( $source, 'a.b.empty_text' ), 'Present path rejects empty string.' );
$assert( DataPath::hasPathValue( $source, 'a.b.false_bool', false ), 'Path value match uses strict comparison.' );

$required_outputs = OutputContract::normalizeRequiredArtifactOutputs(
	array(
		'hero_image',
		'report' => array(
			'schema'   => 'datamachine/artifact/v1',
			'artifact' => 'report',
		),
		array(
			'output_key' => 'hero_image',
			'schema'     => 'datamachine/image/v1',
		),
	)
);

$assert(
	array(
		array(
			'output_key' => 'hero_image',
			'schema'     => 'datamachine/image/v1',
			'artifact'   => '',
		),
		array(
			'output_key' => 'report',
			'schema'     => 'datamachine/artifact/v1',
			'artifact'   => 'report',
		),
	) === $required_outputs,
	'OutputContract normalizes and de-duplicates required artifact outputs by output key.'
);

$typed_artifacts = array(
	'hero_image' => array(
		'schema'  => 'datamachine/image/v1',
		'payload' => false,
	),
	'report'     => array(
		'schema'   => 'datamachine/artifact/v1',
		'artifact' => 'report',
		'payload'  => 0,
	),
);

$assert( OutputContract::artifactOutputSatisfied( $required_outputs[0], $typed_artifacts ), 'Typed artifact output accepts false payload as present.' );
$assert( OutputContract::artifactOutputSatisfied( $required_outputs[1], $typed_artifacts ), 'Typed artifact output accepts zero payload as present.' );

if ( ! empty( $failures ) ) {
	echo "FAILED:\n- " . implode( "\n- ", $failures ) . "\n";
	exit( 1 );
}

echo "All output contract helper assertions passed.\n";
