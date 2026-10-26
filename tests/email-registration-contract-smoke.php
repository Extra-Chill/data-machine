<?php
/**
 * Compare all existing email registrations against the immutable pre-feature contract.
 *
 * Fixture captured from inc/Abilities/Email/EmailAbilities.php at
 * 5a8c7c894547e610164846636e1c43c306466d2d using the registration stub below.
 * Callback class names are intentionally reduced to method names.
 */

namespace DataMachine\Abilities {
	class AbilityRegistration {
		public static function on_abilities_api_init( callable $callback ): void { $callback(); }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['email_contracts'] = array();
	function __( string $text, string $domain = '' ): string { return $text; }
	function wp_register_ability( string $name, array $definition ): void {
		foreach ( array( 'execute_callback', 'permission_callback' ) as $key ) {
			$definition[ $key ] = $definition[ $key ][1];
		}
		$GLOBALS['email_contracts'][ $name ] = $definition;
	}
	function normalize_contract( array $value ): array {
		ksort( $value );
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) { $value[ $key ] = normalize_contract( $item ); }
		}
		return $value;
	}
	$before = normalize_contract( json_decode(
		file_get_contents( __DIR__ . '/fixtures/email-registration-pre-feature.json' ),
		true,
		512,
		JSON_THROW_ON_ERROR
	) );
	$GLOBALS['email_contracts'] = array();
	require_once dirname( __DIR__ ) . '/inc/Abilities/Email/EmailAbilities.php';
	new \DataMachine\Abilities\Email\EmailAbilities();
	$after = normalize_contract( $GLOBALS['email_contracts'] );
	if ( 10 !== count( $after ) || $before !== $after ) {
		throw new RuntimeException( 'Existing email schemas, callbacks, permissions, or REST visibility changed.' );
	}
	echo "PASS: all 10 existing email ability contracts remain identical after registration refactoring.\n";
}
