<?php
/**
 * Regression smoke for REST-compatible ability schemas.
 *
 * Run standalone for the source audit, or through `wp eval-file` with the
 * candidate plugin loaded to exercise WordPress registration and validation.
 *
 * @package DataMachine\Tests
 */

use DataMachine\Abilities\Content\UpsertPostAbility;

$root = dirname( __DIR__ );

if ( ! function_exists( 'wp_get_abilities' ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/inc/Abilities' )
	);
	$invalid  = array();
	$pattern  = '/([\'\"])type\1\s*=>\s*array\(\s*(?:(?:[\'\"])(?:array|object|string|number|integer|boolean|null)(?:[\'\"])\s*,?\s*)+\)/';

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
			continue;
		}

		if ( preg_match( $pattern, file_get_contents( $file->getPathname() ) ) ) {
			$invalid[] = str_replace( $root . '/', '', $file->getPathname() );
		}
	}

	if ( $invalid ) {
		fwrite( STDERR, 'FAIL: array-valued ability schema types remain: ' . implode( ', ', $invalid ) . "\n" );
		exit( 1 );
	}

	echo "PASS: ability source contains no array-valued schema types\n";
	return;
}

if ( ! defined( 'DATAMACHINE_VERSION' ) ) {
	require $root . '/data-machine.php';
}

$notices = array();
$watch   = static function ( string $function_name, string $message ) use ( &$notices ): void {
	if ( 'rest_validate_value_from_schema' === $function_name ) {
		$notices[] = $message;
	}
};

add_action( 'doing_it_wrong_run', $watch, 10, 2 );

$cases = array(
	array( UpsertPostAbility::ABILITY_NAME, 'content', array( 'Post content.' ) ),
	array( 'datamachine/get-flows', 'agent_id', array( 7, null ) ),
	array( 'datamachine/query-posts', 'filter_value', array( 'featured', 7 ) ),
);

foreach ( $cases as [ $ability_name, $property, $values ] ) {
	$ability = wp_get_ability( $ability_name );
	if ( ! $ability ) {
		throw new RuntimeException( "Missing registered ability: {$ability_name}" );
	}

	$schema = $ability->get_input_schema()['properties'][ $property ];
	foreach ( $values as $value ) {
		$result = rest_validate_value_from_schema( $value, $schema, $property );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
	}
}

$array_types = array();
$walk_schema = static function ( array $schema, string $path ) use ( &$walk_schema, &$array_types ): void {
	if ( isset( $schema['type'] ) && is_array( $schema['type'] ) ) {
		$array_types[] = $path;
	}

	foreach ( array( 'properties', 'patternProperties' ) as $keyword ) {
		foreach ( $schema[ $keyword ] ?? array() as $name => $child_schema ) {
			if ( is_array( $child_schema ) ) {
				$walk_schema( $child_schema, $path . '.' . $name );
			}
		}
	}

	if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
		$walk_schema( $schema['items'], $path . '.items' );
	}

	foreach ( array( 'anyOf', 'oneOf' ) as $keyword ) {
		foreach ( $schema[ $keyword ] ?? array() as $index => $child_schema ) {
			if ( is_array( $child_schema ) ) {
				$walk_schema( $child_schema, $path . '.' . $keyword . '.' . $index );
			}
		}
	}
};

foreach ( wp_get_abilities() as $name => $ability ) {
	if ( ! str_starts_with( $name, 'datamachine/' ) ) {
		continue;
	}

	$walk_schema( $ability->get_input_schema(), $name . '.input' );
	$walk_schema( $ability->get_output_schema(), $name . '.output' );
}

$request         = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . UpsertPostAbility::ABILITY_NAME );
$request['name'] = UpsertPostAbility::ABILITY_NAME;
$controller      = new WP_REST_Abilities_V1_List_Controller();
$response        = $controller->get_item( $request );
$response_data   = $response->get_data();

remove_action( 'doing_it_wrong_run', $watch, 10 );

if ( 200 !== $response->get_status() || 'string' !== $response_data['input_schema']['properties']['content']['type'] ) {
	throw new RuntimeException( 'Abilities REST response did not expose the expected content schema.' );
}

if ( $notices || $array_types ) {
	throw new RuntimeException( wp_json_encode( compact( 'notices', 'array_types' ) ) );
}

echo "PASS: registered ability schemas validate and expose through REST without notices\n";
