<?php
/**
 * EventDetails occurrenceDates type-safety smoke test.
 *
 * Self-contained (no PHPUnit / WP test framework). Verifies that the
 * EventDetails block render normalises a non-array `occurrenceDates`
 * attribute before any count() reaches it.
 *
 * Regression guard: block.json declares `occurrenceDates` as an array of
 * strings, but 132 published posts held a bare string there. `??` only
 * substitutes its default for null, so the string flowed through to
 *
 *   $has_more_occurrences = count( $occurrence_dates ) > count( ... );
 *
 * and raised "count(): Argument #1 ($value) must be of type Countable|array,
 * string given", fatalling the request. Those event pages returned HTTP 500
 * to visitors and crawlers — e.g. /events/beirut-2 — while the calendar and
 * every other event rendered normally, so the failure was invisible in
 * uptime checks and only visible as recurring fatals in debug.log.
 *
 * Run directly:
 *   php tests/event-details-occurrence-dates-smoke.php
 *
 * @package DataMachineEvents\Tests
 */

$render = dirname( __DIR__ ) . '/inc/Blocks/EventDetails/render.php';
$source = file_get_contents( $render );

$failures = array();

function ods_assert( bool $ok, string $label ): void {
	global $failures;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $ok ) {
		$failures[] = $label;
	}
}

// The normalisation must exist and must run before the count() comparison,
// otherwise a string attribute still reaches count() and fatals.
$normalise_at = strpos( $source, 'if ( ! is_array( $occurrence_dates ) ) {' );
$count_at     = strpos( $source, 'count( $occurrence_dates )' );

ods_assert( false !== $normalise_at, 'render normalises a non-array occurrenceDates' );
ods_assert( false !== $count_at, 'render still compares occurrence counts' );
ods_assert(
	false !== $normalise_at && false !== $count_at && $normalise_at < $count_at,
	'normalisation happens before any count() on occurrenceDates'
);

// Behavioural check on the exact expression that fatalled, proving the
// normalised value is safe for every shape the attribute has been seen in.
foreach ( array(
	'string'       => '2026-01-01',
	'empty string' => '',
	'null'         => null,
	'int'          => 5,
	'array'        => array( '2026-01-01', '2026-02-01' ),
) as $label => $value ) {
	$attributes       = array( 'occurrenceDates' => $value );
	$occurrence_dates = $attributes['occurrenceDates'] ?? array();
	if ( ! is_array( $occurrence_dates ) ) {
		$occurrence_dates = array();
	}

	$threw = false;
	try {
		$ignored = count( $occurrence_dates ) > count( array() );
	} catch ( \TypeError $e ) {
		$threw = true;
	}
	ods_assert( ! $threw, "count() survives occurrenceDates given as {$label}" );
}

// An array value must still be preserved, not flattened to empty.
$attributes       = array( 'occurrenceDates' => array( '2026-01-01', '2026-02-01' ) );
$occurrence_dates = $attributes['occurrenceDates'] ?? array();
if ( ! is_array( $occurrence_dates ) ) {
	$occurrence_dates = array();
}
ods_assert( 2 === count( $occurrence_dates ), 'a valid array of occurrence dates is preserved' );

if ( $failures ) {
	echo count( $failures ) . " failure(s).\n";
	exit( 1 );
}
echo "All EventDetails occurrenceDates checks passed.\n";
