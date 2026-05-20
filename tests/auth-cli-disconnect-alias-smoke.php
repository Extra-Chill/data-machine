<?php
/**
 * Pure-PHP smoke coverage for the deprecated auth disconnect CLI alias.
 *
 * Run with: php tests/auth-cli-disconnect-alias-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace DataMachine\Abilities {
	class AuthAbilities {
		public array $calls = array();

		public function providerExists( string $handler_slug ): bool {
			$this->calls[] = array( 'providerExists', $handler_slug );
			return true;
		}

		public function getAuthStatus( string $handler_slug ): array {
			$this->calls[] = array( 'getAuthStatus', $handler_slug );
			return array( 'authenticated' => true );
		}

		public function executeDisconnectAuth( array $input ): array {
			$this->calls[] = array( 'executeDisconnectAuth', $input );
			return array(
				'success' => true,
				'message' => 'site-wide revoked',
			);
		}

		public function executeRevokeAuthForUser( array $input ): array {
			$this->calls[] = array( 'executeRevokeAuthForUser', $input );
			return array( 'success' => false );
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) {
			return trim( (string) $value );
		}
	}

	if ( ! function_exists( 'absint' ) ) {
		function absint( $value ): int {
			return abs( (int) $value );
		}
	}

	class WP_CLI {
		public static array $warnings = array();
		public static array $successes = array();

		public static function warning( string $message ): void {
			self::$warnings[] = $message;
		}

		public static function success( string $message ): void {
			self::$successes[] = $message;
		}

		public static function error( string $message ): void {
			throw new RuntimeException( $message );
		}

		public static function confirm( string $message ): void {
			throw new RuntimeException( 'Unexpected confirmation prompt: ' . $message );
		}
	}

	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/AuthCommand.php';

	$command = new \DataMachine\Cli\Commands\AuthCommand();
	$command->disconnect( array( 'twitter' ), array( 'yes' => true, 'user' => 42 ) );

	$reflection = new ReflectionClass( $command );
	$property   = $reflection->getProperty( 'abilities' );
	$abilities = $property->getValue( $command );

	$failures = array();

	if ( array( '`disconnect` is deprecated; use `revoke` instead.' ) !== WP_CLI::$warnings ) {
		$failures[] = 'disconnect emits the expected deprecation warning';
	}

	if ( array( 'site-wide revoked' ) !== WP_CLI::$successes ) {
		$failures[] = 'disconnect delegates to revoke site-wide success handling';
	}

	$called_per_user = false;
	$called_site     = false;
	foreach ( $abilities->calls as $call ) {
		if ( 'executeRevokeAuthForUser' === $call[0] ) {
			$called_per_user = true;
		}
		if ( 'executeDisconnectAuth' === $call[0] && array( 'handler_slug' => 'twitter' ) === $call[1] ) {
			$called_site = true;
		}
	}

	if ( $called_per_user ) {
		$failures[] = 'disconnect must not forward command-scoped --user into revoke';
	}

	if ( ! $called_site ) {
		$failures[] = 'disconnect reaches the site-wide revoke implementation';
	}

	if ( $failures ) {
		echo "auth-cli-disconnect-alias-smoke failed\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit( 1 );
	}

	echo "auth-cli-disconnect-alias-smoke passed\n";
}
