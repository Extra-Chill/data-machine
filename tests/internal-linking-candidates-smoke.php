<?php
/**
 * Smoke tests for internal-linking candidate normalization and the
 * datamachine_internal_linking_candidates filter contract.
 *
 * Runs via:
 *
 *   php tests/internal-linking-candidates-smoke.php
 *
 * Verifies that filter-injected (including off-site, id=0) candidates are
 * accepted, malformed candidates are dropped, the list is re-sorted by score
 * descending, and the limit is enforced — the behavior that lets a platform
 * layer route internal links toward forward surfaces without core knowing
 * about any specific site.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

// Minimal WP shims used by normalizeCandidates().
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		$url = trim( (string) $url );
		// Accept http(s) absolute URLs; reject obviously invalid ones.
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/SystemTask.php';
require_once __DIR__ . '/../inc/Engine/AI/System/Tasks/InternalLinkingTask.php';

$failures = 0;
$assert   = static function ( string $label, bool $cond ) use ( &$failures ): void {
	if ( $cond ) {
		echo "  PASS  {$label}\n";
		return;
	}
	echo "  FAIL  {$label}\n";
	++$failures;
};

// Use reflection to reach the private normalizeCandidates() without booting WP.
$task   = ( new ReflectionClass( \DataMachine\Engine\AI\System\Tasks\InternalLinkingTask::class ) )
	->newInstanceWithoutConstructor();
$method = new ReflectionMethod( $task, 'normalizeCandidates' );
$method->setAccessible( true );

$call = static fn( $candidates, int $limit ) => $method->invoke( $task, $candidates, $limit );

// 1. Off-site candidate (id=0) with a valid url+title is kept.
$result = $call(
	array(
		array( 'id' => 0, 'url' => 'https://events.example.com/show', 'title' => 'A Relevant Show', 'score' => 9.0 ),
	),
	3
);
$assert( 'off-site id=0 candidate is accepted', count( $result ) === 1 && 0 === $result[0]['id'] );
$assert( 'off-site candidate keeps url', 'https://events.example.com/show' === $result[0]['url'] );

// 2. Re-sorts by score descending regardless of input order.
$result = $call(
	array(
		array( 'id' => 5, 'url' => 'https://a.example.com/1', 'title' => 'Low', 'score' => 1.0 ),
		array( 'id' => 0, 'url' => 'https://b.example.com/2', 'title' => 'High', 'score' => 99.0 ),
		array( 'id' => 7, 'url' => 'https://c.example.com/3', 'title' => 'Mid', 'score' => 50.0 ),
	),
	10
);
$assert(
	'sorted by score desc',
	array( 'High', 'Mid', 'Low' ) === array_column( $result, 'title' )
);

// 3. Limit is enforced after re-sorting.
$result = $call(
	array(
		array( 'url' => 'https://a/1', 'title' => 'One', 'score' => 5 ),
		array( 'url' => 'https://a/2', 'title' => 'Two', 'score' => 4 ),
		array( 'url' => 'https://a/3', 'title' => 'Three', 'score' => 3 ),
	),
	2
);
$assert( 'limit caps result count', count( $result ) === 2 );
$assert( 'limit keeps highest scores', array( 'One', 'Two' ) === array_column( $result, 'title' ) );

// 4. Malformed candidates are dropped (missing url, missing title, bad url, non-array).
$result = $call(
	array(
		array( 'title' => 'No URL', 'score' => 9 ),
		array( 'url' => 'https://ok/1', 'score' => 8 ),            // missing title
		array( 'url' => 'not-a-url', 'title' => 'Bad URL' ),       // rejected by esc_url_raw
		'garbage',                                                  // non-array
		array( 'url' => 'https://ok/2', 'title' => 'Good', 'score' => 7 ),
	),
	10
);
$assert( 'malformed candidates dropped, valid kept', count( $result ) === 1 && 'Good' === $result[0]['title'] );

// 5. Non-array input is handled safely.
$assert( 'non-array input yields empty list', $call( null, 3 ) === array() );

// 6. Optional keys are backfilled so downstream never hits undefined indices.
$result = $call( array( array( 'url' => 'https://x/1', 'title' => 'Bare' ) ), 3 );
$assert(
	'optional keys backfilled',
	0 === $result[0]['id'] && '' === $result[0]['excerpt'] && 0.0 === $result[0]['score']
);

echo "\n";
if ( $failures > 0 ) {
	echo "FAILED: {$failures} assertion(s) failed\n";
	exit( 1 );
}
echo "OK: all internal-linking candidate assertions passed\n";
