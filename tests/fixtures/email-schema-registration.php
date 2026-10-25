<?php
/** Isolated registration harness: capture the actual public email schemas. */
namespace DataMachine\Abilities {
	class AbilityRegistration {
		public static function on_abilities_api_init( callable $callback ): void {
			$callback();
		}
	}
}
namespace {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	function __( string $text, string $domain = '' ): string { return $text; }
	function wp_register_ability( string $name, array $definition ): void {
		$GLOBALS['email_schemas'][ $name ] = $definition['input_schema'];
	}
	require_once ABSPATH . 'inc/Abilities/Email/EmailAbilities.php';
	new \DataMachine\Abilities\Email\EmailAbilities();
}
