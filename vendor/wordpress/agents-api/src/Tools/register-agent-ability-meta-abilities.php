<?php
/**
 * Canonical ability discovery and dispatch meta-abilities.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

use AgentsAPI\AI\Abilities\WP_Agent_Ability_Dispatcher;

defined( 'ABSPATH' ) || exit;

const AGENTS_ABILITY_SEARCH_ABILITY = 'agents/ability-search';
const AGENTS_ABILITY_CALL_ABILITY   = 'agents/ability-call';

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( wp_has_ability_category( 'agents-api' ) ) {
			return;
		}

		wp_register_ability_category(
			'agents-api',
			array(
				'label'       => 'Agents API',
				'description' => 'Cross-cutting abilities provided by the Agents API substrate.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! wp_has_ability( AGENTS_ABILITY_SEARCH_ABILITY ) ) {
			wp_register_ability(
				AGENTS_ABILITY_SEARCH_ABILITY,
				array(
					'label'               => 'Search Abilities',
					'description'         => 'Search registered abilities by name, category, and keywords. Returns compact entries for tool discovery.',
					'category'            => 'agents-api',
					'input_schema'        => agents_ability_search_input_schema(),
					'output_schema'       => agents_ability_search_output_schema(),
					'execute_callback'    => __NAMESPACE__ . '\\agents_ability_search',
					'permission_callback' => __NAMESPACE__ . '\\agents_ability_search_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);
		}

		if ( ! wp_has_ability( AGENTS_ABILITY_CALL_ABILITY ) ) {
			wp_register_ability(
				AGENTS_ABILITY_CALL_ABILITY,
				array(
					'label'               => 'Call Ability',
					'description'         => 'Invoke a registered ability by name with JSON parameters. Used for Tier-2 tools that are discovered at runtime.',
					'category'            => 'agents-api',
					'input_schema'        => agents_ability_call_input_schema(),
					'output_schema'       => agents_ability_call_output_schema(),
					'execute_callback'    => __NAMESPACE__ . '\\agents_ability_call',
					'permission_callback' => __NAMESPACE__ . '\\agents_ability_call_permission',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'idempotent' => false,
						),
					),
				)
			);
		}
	}
);

/**
 * Search registered abilities and return compact model-facing entries.
 *
 * @param array<string, mixed> $input Search input.
 * @return array<string, mixed>
 */
function agents_ability_search( array $input ): array {
	$query    = is_string( $input['query'] ?? null ) ? trim( $input['query'] ) : '';
	$category = is_string( $input['category'] ?? null ) ? trim( $input['category'] ) : '';
	$limit_value = $input['limit'] ?? 20;
	$limit       = max( 1, min( 100, is_numeric( $limit_value ) ? (int) $limit_value : 20 ) );
	$parsed   = agents_parse_ability_search_query( $query );
	$entries  = array();

	foreach ( wp_get_abilities() as $ability ) {
		if ( '' !== $category && $ability->get_category() !== $category ) {
			continue;
		}

		if ( ! agents_ability_matches_query( $ability, $parsed ) ) {
			continue;
		}

		$entries[] = agents_compact_ability_entry( $ability );
		if ( count( $entries ) >= $limit ) {
			break;
		}
	}

	return array(
		'query'     => $query,
		'count'     => count( $entries ),
		'abilities' => $entries,
	);
}

/**
 * Invoke an ability by name.
 *
 * @param array<string, mixed> $input Call input.
 * @return array<string, mixed>|\WP_Error
 */
function agents_ability_call( array $input ) {
	$name       = is_string( $input['name'] ?? null ) ? trim( $input['name'] ) : '';
	$parameters = isset( $input['parameters'] ) && is_array( $input['parameters'] ) ? $input['parameters'] : array();

	if ( '' === $name ) {
		return new \WP_Error( 'agents_ability_call_missing_name', 'Ability name is required.' );
	}

	if ( AGENTS_ABILITY_CALL_ABILITY === $name ) {
		return new \WP_Error( 'agents_ability_call_recursion', 'agents/ability-call cannot call itself.' );
	}

	$result = WP_Agent_Ability_Dispatcher::dispatch( $name, $parameters );
	if ( is_wp_error( $result ) && 'ability_not_found' === $result->get_error_code() ) {
		return new \WP_Error( 'agents_ability_call_not_found', 'Ability is not registered.' );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'name'                => $name,
		'parameters'          => WP_Agent_Ability_Dispatcher::redacted_parameters( $name, $parameters ),
		'parameters_redacted' => true,
		'result'              => $result,
	);
}

/**
 * Permission gate for ability search.
 *
 * @param array<string, mixed> $input Search input.
 * @return bool
 */
function agents_ability_search_permission( array $input ): bool {
	return (bool) apply_filters( 'agents_ability_search_permission', current_user_can( 'manage_options' ), $input );
}

/**
 * Permission gate for ability call.
 *
 * @param array<string, mixed> $input Call input.
 * @return bool
 */
function agents_ability_call_permission( array $input ): bool {
	return (bool) apply_filters( 'agents_ability_call_permission', current_user_can( 'manage_options' ), $input );
}

/**
 * Parse the compact ability-search query language.
 *
 * @param string $query Raw query.
 * @return array{terms: array<int, string>, required: array<int, string>, selected: array<int, string>}
 */
function agents_parse_ability_search_query( string $query ): array {
	$terms    = array();
	$required = array();
	$selected = array();

	foreach ( preg_split( '/\s+/', $query ) ?: array() as $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			continue;
		}

		if ( str_starts_with( $token, 'select:' ) ) {
			$selected = array_merge( $selected, agents_string_list( explode( ',', substr( $token, 7 ) ) ) );
			continue;
		}

		if ( str_starts_with( $token, '+' ) ) {
			$required[] = strtolower( substr( $token, 1 ) );
			continue;
		}

		$terms[] = strtolower( $token );
	}

	return array(
		'terms'    => agents_string_list( $terms ),
		'required' => agents_string_list( $required ),
		'selected' => agents_string_list( $selected ),
	);
}

/**
 * Check whether an ability matches a parsed query.
 *
 * @param \WP_Ability $ability Ability object.
 * @param array<mixed>       $query   Parsed query.
 * @return bool
 */
function agents_ability_matches_query( \WP_Ability $ability, array $query ): bool {
	$name = $ability->get_name();
	$selected = agents_string_list( is_array( $query['selected'] ?? null ) ? array_values( $query['selected'] ) : array() );
	if ( ! empty( $selected ) ) {
		return in_array( $name, $selected, true );
	}

	$haystack = strtolower( implode( ' ', array( $name, $ability->get_label(), $ability->get_description(), $ability->get_category() ) ) );
	$terms    = agents_string_list( is_array( $query['terms'] ?? null ) ? array_values( $query['terms'] ) : array() );
	$required = agents_string_list( is_array( $query['required'] ?? null ) ? array_values( $query['required'] ) : array() );
	foreach ( array_merge( $terms, $required ) as $term ) {
		if ( '' !== $term && ! str_contains( $haystack, strtolower( $term ) ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Build a compact ability entry.
 *
 * @param \WP_Ability $ability Ability object.
 * @return array<string, mixed>
 */
function agents_compact_ability_entry( \WP_Ability $ability ): array {
	return array(
		'name'            => $ability->get_name(),
		'summary'         => agents_ability_summary( $ability ),
		'required_fields' => agents_ability_required_fields( $ability ),
	);
}

/**
 * Build a one-line summary for a compact ability entry.
 *
 * @param \WP_Ability $ability Ability object.
 * @return string
 */
function agents_ability_summary( \WP_Ability $ability ): string {
	$summary = trim( $ability->get_description() );
	if ( '' === $summary ) {
		$summary = trim( $ability->get_label() );
	}

	return preg_replace( '/\s+/', ' ', $summary ) ?: '';
}

/**
 * Extract required input fields from an ability schema.
 *
 * @param \WP_Ability $ability Ability object.
 * @return array<int, string>
 */
function agents_ability_required_fields( \WP_Ability $ability ): array {
	$schema = $ability->get_input_schema();
	return isset( $schema['required'] ) && is_array( $schema['required'] ) ? agents_string_list( array_values( $schema['required'] ) ) : array();
}

/**
 * Normalize a list of strings.
 *
 * @param array<int, mixed> $items Raw values.
 * @return array<int, string>
 */
function agents_string_list( array $items ): array {
	$strings = array();
	foreach ( $items as $item ) {
		if ( is_string( $item ) && '' !== trim( $item ) ) {
			$strings[] = trim( $item );
		}
	}

	return array_values( array_unique( $strings ) );
}

/**
 * Input schema for ability search.
 *
 * @return array<string, mixed>
 * @return array<string, mixed>
 */
function agents_ability_search_input_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'query'    => array(
				'type'        => 'string',
				'description' => 'Search query. Supports name/category substrings, +keyword requirements, and select:foo/bar,baz/qux.',
				'default'     => '',
			),
			'category' => array(
				'type'        => 'string',
				'description' => 'Optional exact ability category filter.',
				'default'     => '',
			),
			'limit'    => array(
				'type'        => 'integer',
				'description' => 'Maximum number of compact ability entries to return.',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
			),
		),
	);
}

/**
 * Output schema for ability search.
 *
 * @return array<string, mixed>
 * @return array<string, mixed>
 */
function agents_ability_search_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'query', 'count', 'abilities' ),
		'properties' => array(
			'query'     => array( 'type' => 'string' ),
			'count'     => array( 'type' => 'integer' ),
			'abilities' => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'required'   => array( 'name', 'summary', 'required_fields' ),
					'properties' => array(
						'name'            => array( 'type' => 'string' ),
						'summary'         => array( 'type' => 'string' ),
						'required_fields' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
	);
}

/**
 * Input schema for ability call.
 *
 * @return array<string, mixed>
 * @return array<string, mixed>
 */
function agents_ability_call_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'name' ),
		'properties' => array(
			'name'       => array(
				'type'        => 'string',
				'description' => 'Registered ability name to invoke.',
			),
			'parameters' => array(
				'type'        => 'object',
				'description' => 'JSON parameters passed to the target ability.',
				'default'     => array(),
			),
		),
	);
}

/**
 * Output schema for ability call.
 *
 * @return array<string, mixed>
 * @return array<string, mixed>
 */
function agents_ability_call_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'name', 'result' ),
		'properties' => array(
			'name'                => array( 'type' => 'string' ),
			'parameters'          => array(
				'type'        => 'object',
				'description' => 'Target ability parameters with sensitive values redacted.',
			),
			'parameters_redacted' => array( 'type' => 'boolean' ),
			'result'              => array( 'type' => array( 'object', 'array', 'string', 'number', 'boolean', 'null' ) ),
		),
	);
}
