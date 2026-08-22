<?php
/**
 * PHPStan stub for the WordPress 7.1 Abilities API `WP_Ability` class.
 *
 * Not yet present in php-stubs/wordpress-stubs (which tracks stable WordPress).
 * Minimal signatures for type resolution only. See abilities-api-functions.php
 * for the function stubs.
 *
 * @see https://github.com/WordPress/abilities-api
 */

/**
 * A registered ability.
 */
class WP_Ability {
	private string $name;

	/** @var array<string,mixed> */
	private array $args;

	/**
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability arguments.
	 */
	public function __construct( string $name, array $args = array() ) {
		$this->name = $name;
		$this->args = $args;
	}

	public function get_name(): string { return $this->name; }

	public function get_label(): string { return ''; }

	public function get_description(): string { return ''; }

	public function get_category(): string { return ''; }

	/**
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array {
		$schema = $this->args['input_schema'] ?? array();
		return is_array( $schema ) ? self::string_keyed_array( $schema ) : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_output_schema(): array {
		$schema = $this->args['output_schema'] ?? array();
		return is_array( $schema ) ? self::string_keyed_array( $schema ) : array();
	}

	/**
	 * @param mixed $input Ability input.
	 * @return mixed|\WP_Error
	 */
	public function execute( $input = null ) { return $input; }

	/**
	 * @param array<mixed> $values
	 * @return array<string,mixed>
	 */
	private static function string_keyed_array( array $values ): array {
		$prepared = array();
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) ) {
				$prepared[ $key ] = $value;
			}
		}
		return $prepared;
	}
}
