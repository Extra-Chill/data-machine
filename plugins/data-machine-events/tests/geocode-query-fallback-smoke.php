<?php
/**
 * Geocode query-strategy fallback smoke test.
 *
 * Self-contained (no PHPUnit / WP test framework). Verifies that
 * Venue_Taxonomy::build_geocode_queries() does NOT emit a context-free
 * bare-street `raw_address` fallback when separate city meta is available.
 *
 * Regression guard for data-machine-events#379: a bare street like
 * "500 College Drive" was being geocoded alone after the contextual
 * strategies returned no Nominatim match, resolving to an unrelated city
 * (Reno, NV) and mis-placing a Lake Jackson, TX venue 1,500+ miles away.
 *
 * Run directly:
 *   php tests/geocode-query-fallback-smoke.php
 *
 * @package DataMachineEvents\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Minimal WP shims used by build_geocode_queries (pure string logic).
if ( ! function_exists( 'html_entity_decode' ) ) {
	// Always available in PHP core; guard for paranoia only.
	function html_entity_decode( $s ) {
		return $s;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/Venue_Taxonomy.php';

use DataMachineEvents\Core\Venue_Taxonomy;

$method = new ReflectionMethod( Venue_Taxonomy::class, 'build_geocode_queries' );
$method->setAccessible( true );

/**
 * Invoke the private query builder.
 *
 * @param array $venue_data Venue data.
 * @return array Strategy => query string.
 */
function build_queries( array $venue_data ): array {
	global $method;
	return $method->invoke( null, $venue_data );
}

$pass = 0;
$fail = 0;

/**
 * Assert helper.
 *
 * @param string $label     Case label.
 * @param bool   $condition Result.
 */
function check( string $label, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
	} else {
		++$fail;
		echo "FAIL: {$label}\n";
	}
}

// Case 1: The Brazosport regression — bare street + separate city meta.
// raw_address (bare "500 College Drive") must NOT be emitted.
$q = build_queries(
	array(
		'address' => '500 College Drive',
		'city'    => 'Lake Jackson',
		'state'   => 'TX',
		'zip'     => '77566',
		'country' => 'US',
		'name'    => 'The Clarion at Brazosport College',
	)
);
check( 'bare street + city: cleaned_address present', isset( $q['cleaned_address'] ) );
check( 'bare street + city: no bare raw_address fallback', ! isset( $q['raw_address'] ) );
check(
	'bare street + city: cleaned_address is fully scoped',
	'500 College Drive, Lake Jackson, TX, 77566' === ( $q['cleaned_address'] ?? '' )
);

// Case 2: Raw address that already carries context (comma) — raw_address allowed.
$q = build_queries(
	array(
		'address' => '123 Main St, Austin, TX 78701',
		'city'    => '',
		'state'   => '',
		'zip'     => '',
		'country' => 'US',
		'name'    => 'Some Venue',
	)
);
check( 'context-rich raw address: raw_address allowed', isset( $q['raw_address'] ) );

// Case 3: Bare street with NO city meta — raw_address is the only signal, allow it.
$q = build_queries(
	array(
		'address' => '500 College Drive',
		'city'    => '',
		'state'   => '',
		'zip'     => '',
		'country' => '',
		'name'    => '',
	)
);
check( 'bare street, no city: raw_address allowed as last resort', isset( $q['raw_address'] ) );

// Case 4: Name + city lookup still produced when name present.
$q = build_queries(
	array(
		'address' => '500 College Drive',
		'city'    => 'Lake Jackson',
		'state'   => 'TX',
		'zip'     => '77566',
		'country' => 'US',
		'name'    => 'The Clarion at Brazosport College',
	)
);
check( 'name_lookup still produced', isset( $q['name_lookup'] ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
