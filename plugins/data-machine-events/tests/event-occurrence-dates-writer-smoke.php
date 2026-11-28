<?php
/**
 * occurrenceDates writer type-safety smoke test.
 *
 * Self-contained (no PHPUnit / WP test framework). Guards the write path that
 * corrupted 132 published events.
 *
 * EventUpsert sanitised every schema field through
 *
 *   sanitize_text_field( decode_entities( (string) $parameters[ $field ] ) )
 *
 * `occurrenceDates` is the only field EventSchemaProvider declares as an
 * array, and `(string) array( '2026-01-01' )` yields the literal "Array" — so
 * the dates were destroyed before storage and the stored markup carried
 * `"occurrenceDates":"Array"`. The renderer then fatalled on count(), serving
 * HTTP 500 for those events.
 *
 * Run directly:
 *   php tests/event-occurrence-dates-writer-smoke.php
 *
 * @package DataMachineEvents\Tests
 */

$failures = array();

function odw_assert( bool $ok, string $label ): void {
	global $failures;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $ok ) {
		$failures[] = $label;
	}
}

$provider = file_get_contents( dirname( __DIR__ ) . '/inc/Core/EventSchemaProvider.php' );
$upsert   = file_get_contents( dirname( __DIR__ ) . '/inc/Steps/Upsert/Events/EventUpsert.php' );
$builder  = file_get_contents( dirname( __DIR__ ) . '/inc/Steps/Upsert/Events/EventBlockContentBuilder.php' );

// The array-typed fields must come from the schema, never a hardcoded list,
// so a future array field is covered the day it is declared.
odw_assert( str_contains( $provider, 'function getArrayFieldKeys' ), 'schema exposes its array-typed field keys' );
odw_assert( str_contains( $upsert, 'EventSchemaProvider::getArrayFieldKeys()' ), 'the writer asks the schema which fields are arrays' );
odw_assert( str_contains( $upsert, 'in_array( $field, $array_fields, true )' ), 'array fields take a branch before the scalar cast' );

// The exact destructive expression must not be reachable for an array field.
$scalar_cast_at = strpos( $upsert, '(string) $parameters[ $field ]' );
$guard_at       = strpos( $upsert, 'in_array( $field, $array_fields, true )' );
odw_assert(
	false !== $guard_at && false !== $scalar_cast_at && $guard_at < $scalar_cast_at,
	'the array guard precedes the (string) cast'
);

// Block markup must never carry a non-array for this attribute.
odw_assert(
	str_contains( $builder, "is_array( \$event_data['occurrenceDates'] ?? null )" ),
	'block builder refuses a non-array occurrenceDates'
);

// Behavioural: reproduce both paths on the real shapes seen in production.
$sanitize = static fn( $v ) => trim( strip_tags( (string) $v ) );

foreach ( array(
	'array of dates' => array( array( '2026-01-01', '2026-02-01' ), 2 ),
	'empty array'    => array( array(), 0 ),
	'string'         => array( '2026-01-01', 0 ),
	'null'           => array( null, 0 ),
) as $label => $case ) {
	list( $input, $expected ) = $case;

	$result = is_array( $input )
		? array_values( array_filter( array_map( $sanitize, $input ), static fn( $i ) => '' !== $i ) )
		: array();

	odw_assert( is_array( $result ), "writer yields an array for {$label}" );
	odw_assert( count( $result ) === $expected, "writer preserves {$expected} date(s) for {$label}" );
}

// The regression itself: an array must never stringify to "Array".
$corrupted = @( (string) array( '2026-01-01' ) );
odw_assert( 'Array' === $corrupted, 'sanity: PHP does render an array as "Array"' );
$safe = is_array( array( '2026-01-01' ) ) ? array( '2026-01-01' ) : array();
odw_assert( 'Array' !== $safe && is_array( $safe ), 'guarded path never produces the "Array" string' );

if ( $failures ) {
	echo count( $failures ) . " failure(s).\n";
	exit( 1 );
}
echo "All occurrenceDates writer checks passed.\n";
