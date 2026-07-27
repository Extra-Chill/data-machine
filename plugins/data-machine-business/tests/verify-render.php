<?php
/**
 * Standalone verification of concise AGENTS.md Business routing.
 */

namespace DataMachine\Engine\AI {
	class SectionRegistry {
		public static array $section = array();

		public static function register( $file, $name, $priority, $callback, $meta ): void {
			self::$section = compact( 'file', 'name', 'priority', 'callback', 'meta' );
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/var/www/extrachill.com/' );
	}

	function apply_filters( $tag, $value ) {
		return 'datamachine_wp_cli_cmd' === $tag ? 'wp-test --url=example.test' : $value;
	}

	require_once dirname( __DIR__ ) . '/inc/Runtime/AgentsMdSections.php';

	\DataMachineBusiness\Runtime\AgentsMdSections::register();
	$section = \DataMachine\Engine\AI\SectionRegistry::$section;
	$output  = (string) call_user_func( $section['callback'] );
	$errors  = array();

	$expected = array(
		'## Data Machine Business',
		'**Default routing**',
		'wp-test --url=example.test datamachine analytics ga <action>',
		'wp-test --url=example.test datamachine analytics gsc <action>',
		'wp-test --url=example.test datamachine analytics bing <action>',
		'wp-test --url=example.test datamachine analytics mediavine <action>',
		'wp-test --url=example.test datamachine analytics pagespeed <action>',
		'wp-test --url=example.test datamachine media <action>',
		'wp-test --url=example.test datamachine --help',
		'wp-test --url=example.test datamachine analytics --help',
		'wp-test --url=example.test help <command>',
		'Live help is authoritative.',
	);

	foreach ( $expected as $needle ) {
		if ( false === strpos( $output, $needle ) ) {
			$errors[] = "Missing rendered guidance: {$needle}";
		}
	}

	foreach ( array( 'page_stats', 'inspect_url', 'ad_units', 'delete-unused' ) as $action ) {
		if ( false !== strpos( $output, $action ) ) {
			$errors[] = "Exhaustive action leaked into guidance: {$action}";
		}
	}

	if ( 'AGENTS.md' !== ( $section['file'] ?? '' ) || 'data-machine-business' !== ( $section['name'] ?? '' ) || 25 !== ( $section['priority'] ?? 0 ) ) {
		$errors[] = 'Section registration contract changed.';
	}

	echo $output . "\n";

	if ( ! empty( $errors ) ) {
		foreach ( $errors as $error ) {
			echo "FAIL: {$error}\n";
		}
		exit( 1 );
	}

	echo 'PASS: concise routing, discovery, prefix filtering, and registration verified.' . "\n";
}
