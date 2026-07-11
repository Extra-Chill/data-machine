<?php
/**
 * Standalone verification of the AGENTS.md renderer action enumeration.
 *
 * Stubs the minimal dependencies (BaseCommand, ABSPATH, apply_filters) so the
 * real AgentsMdSections::render() can run outside WordPress against the real
 * CommandRegistry + command classes (which declare static actions()).
 */

namespace DataMachine\Cli {
	// Stub the BaseCommand parent the command classes extend. The static
	// actions() accessor never reaches instance methods, so an empty stub is
	// enough to let the class files load.
	if ( ! class_exists( 'DataMachine\Cli\BaseCommand' ) ) {
		class BaseCommand {}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/var/www/extrachill.com/' );
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			return $value;
		}
	}

	$root = dirname( __DIR__ );

	require_once $root . '/inc/Cli/GoogleSearchConsoleCommand.php';
	require_once $root . '/inc/Cli/GoogleAnalyticsCommand.php';
	require_once $root . '/inc/Cli/PageSpeedCommand.php';
	require_once $root . '/inc/Cli/MediaHygieneCommand.php';
	require_once $root . '/inc/Cli/Commands/BingWebmasterCommand.php';
	require_once $root . '/inc/Cli/CommandRegistry.php';
	require_once $root . '/inc/Runtime/AgentsMdSections.php';

	$output = \DataMachineBusiness\Runtime\AgentsMdSections::render();

	echo $output;
	echo "\n\n--- ASSERTIONS ---\n";

	$gsc_actions = array(
		'query_stats', 'page_stats', 'query_page_stats', 'date_stats',
		'inspect_url', 'list_sitemaps', 'get_sitemap', 'submit_sitemap',
	);

	$failures = array();
	foreach ( $gsc_actions as $action ) {
		if ( false === strpos( $output, '`' . $action . '`' ) ) {
			$failures[] = "GSC action '{$action}' missing from rendered output";
		}
	}

	// Confirm a non-GSC action also appears.
	if ( false === strpos( $output, '`realtime`' ) ) {
		$failures[] = "GA action 'realtime' missing from rendered output";
	}
	if ( false === strpos( $output, '`diagnose`' ) ) {
		$failures[] = "Media action 'diagnose' missing from rendered output";
	}

	if ( empty( $failures ) ) {
		echo "PASS: all expected actions are enumerated in the rendered section.\n";
		exit( 0 );
	}

	foreach ( $failures as $f ) {
		echo "FAIL: {$f}\n";
	}
	exit( 1 );
}
