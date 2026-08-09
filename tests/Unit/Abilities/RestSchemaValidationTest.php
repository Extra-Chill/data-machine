<?php
/**
 * REST schema validation tests for registered Data Machine abilities.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Content\UpsertPostAbility;
use WP_Ability;
use WP_UnitTestCase;

class RestSchemaValidationTest extends WP_UnitTestCase {

	/**
	 * Data provider for representative schemas that previously looked suspect.
	 *
	 * @return array<string, array{string, string, mixed[]}>
	 */
	public function representative_schema_provider(): array {
		return array(
			'content value'        => array( UpsertPostAbility::ABILITY_NAME, 'content', array( 'Post content.' ) ),
			'nullable value'       => array( 'datamachine/get-flows', 'agent_id', array( 7, null ) ),
			'multi-scalar value'   => array( 'datamachine/query-posts', 'filter_value', array( 'featured', 7 ) ),
		);
	}

	/**
	 * Every registered Data Machine schema must validate without developer notices.
	 */
	public function test_registered_ability_schemas_use_rest_supported_types(): void {
		$notices = array();
		$listener = static function ( string $function_name, string $message ) use ( &$notices ): void {
			if ( 'rest_validate_value_from_schema' === $function_name ) {
				$notices[] = $message;
			}
		};

		add_action( 'doing_it_wrong_run', $listener, 10, 2 );

		foreach ( wp_get_abilities() as $name => $ability ) {
			if ( ! str_starts_with( $name, 'datamachine/' ) ) {
				continue;
			}

			$this->validate_schema_nodes( $ability->get_input_schema(), $name . '.input' );
			$this->validate_schema_nodes( $ability->get_output_schema(), $name . '.output' );
		}

		remove_action( 'doing_it_wrong_run', $listener, 10 );

		$this->assertSame( array(), $notices, implode( "\n", $notices ) );
	}

	/**
	 * REST exposes the corrected schema without changing its representation.
	 */
	public function test_rest_response_exposes_corrected_ability_schema(): void {
		$request         = new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . UpsertPostAbility::ABILITY_NAME );
		$request['name'] = UpsertPostAbility::ABILITY_NAME;
		$controller      = new \WP_REST_Abilities_V1_List_Controller();
		$response        = $controller->get_item( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame(
			'string',
			$response->get_data()['input_schema']['properties']['content']['type']
		);
	}

	/**
	 * The production content signal is WordPress core's template REST schema.
	 */
	public function test_core_template_content_schema_valid_values_do_not_emit_notices(): void {
		$controller = new \WP_REST_Templates_Controller( 'wp_template' );
		$schema     = $controller->get_item_schema()['properties']['content'];
		$notices    = array();
		$listener   = static function ( string $function_name, string $message ) use ( &$notices ): void {
			if ( 'rest_validate_value_from_schema' === $function_name ) {
				$notices[] = $message;
			}
		};

		add_action( 'doing_it_wrong_run', $listener, 10, 2 );
		$this->assertTrue( rest_validate_value_from_schema( 'Post content.', $schema, 'content' ) );
		$this->assertTrue( rest_validate_value_from_schema( array( 'raw' => 'Post content.' ), $schema, 'content' ) );
		remove_action( 'doing_it_wrong_run', $listener, 10 );

		$this->assertSame( array(), $notices, implode( "\n", $notices ) );
	}

	/**
	 * Representative content, nullable, and multi-type properties retain their behavior.
	 *
	 * @dataProvider representative_schema_provider
	 */
	public function test_representative_schemas_validate_without_notices( string $ability_name, string $property, array $values ): void {
		$ability = wp_get_ability( $ability_name );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		$schema = $ability->get_input_schema()['properties'][ $property ];
		$notices = array();
		$listener = static function ( string $function_name, string $message ) use ( &$notices ): void {
			if ( 'rest_validate_value_from_schema' === $function_name ) {
				$notices[] = $message;
			}
		};

		add_action( 'doing_it_wrong_run', $listener, 10, 2 );
		foreach ( $values as $value ) {
			$this->assertTrue( rest_validate_value_from_schema( $value, $schema, $property ) );
		}
		remove_action( 'doing_it_wrong_run', $listener, 10 );

		$this->assertSame( array(), $notices, implode( "\n", $notices ) );
	}

	/**
	 * Recursively exercise every schema node using a value for each declared type.
	 *
	 * @param array<string, mixed> $schema Schema node.
	 * @param string               $path   Diagnostic path.
	 */
	private function validate_schema_nodes( array $schema, string $path ): void {
		if ( isset( $schema['type'] ) ) {
			$this->assertIsString( $schema['type'], $path . ' uses an array-valued type declaration.' );
			rest_validate_value_from_schema( $this->value_for_type( $schema['type'] ), $schema, $path );
		}

		foreach ( array( 'properties', 'patternProperties' ) as $keyword ) {
			foreach ( $schema[ $keyword ] ?? array() as $name => $child_schema ) {
				if ( is_array( $child_schema ) ) {
					$this->validate_schema_nodes( $child_schema, $path . '.' . $name );
				}
			}
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$this->validate_schema_nodes( $schema['items'], $path . '.items' );
		}

		foreach ( array( 'anyOf', 'oneOf' ) as $keyword ) {
			foreach ( $schema[ $keyword ] ?? array() as $index => $child_schema ) {
				if ( is_array( $child_schema ) ) {
					$this->validate_schema_nodes( $child_schema, $path . '.' . $keyword . '.' . $index );
				}
			}
		}
	}

	/**
	 * Return a representative value for a WordPress REST built-in type.
	 *
	 * @param mixed $type Schema type.
	 * @return mixed
	 */
	private function value_for_type( $type ) {
		$values = array(
			'array'   => array(),
			'object'  => array(),
			'string'  => 'value',
			'number'  => 1.5,
			'integer' => 1,
			'boolean' => true,
			'null'    => null,
		);

		return $values[ $type ] ?? 'value';
	}
}
