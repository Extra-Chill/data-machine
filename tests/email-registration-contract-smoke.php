<?php
/** Compare all existing email registrations against the immutable pre-feature contract. */

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
	$baseline = shell_exec( 'git show 5a8c7c894547e610164846636e1c43c306466d2d:inc/Abilities/Email/EmailAbilities.php' );
	if ( ! is_string( $baseline ) || ! str_starts_with( $baseline, '<?php' ) ) {
		throw new RuntimeException( 'The immutable pre-feature email contract is unavailable.' );
	}
	$baseline = str_replace( 'class EmailAbilities {', 'class BaselineEmailAbilities {', $baseline );
	eval( substr( $baseline, 5 ) );
	new \DataMachine\Abilities\Email\BaselineEmailAbilities();
	$before = normalize_contract( $GLOBALS['email_contracts'] );
	$GLOBALS['email_contracts'] = array();
	require_once dirname( __DIR__ ) . '/inc/Abilities/Email/EmailAbilities.php';
	new \DataMachine\Abilities\Email\EmailAbilities();
	$after = normalize_contract( $GLOBALS['email_contracts'] );
	if ( 10 !== count( $after ) || $before !== $after ) {
		throw new RuntimeException( 'Existing email schemas, callbacks, permissions, or REST visibility changed.' );
	}
	echo "PASS: all 10 existing email ability contracts remain identical after registration refactoring.\n";
}
