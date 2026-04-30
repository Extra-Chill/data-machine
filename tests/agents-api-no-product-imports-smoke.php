<?php
/**
 * Static smoke test proving agents-api stays product-free and UI-free (#1639, #1651).
 *
 * Run with: php tests/agents-api-no-product-imports-smoke.php
 *
 * @package DataMachine\Tests
 */

$failures = array();
$passes   = 0;

echo "agents-api-no-product-imports-smoke\n";

require_once __DIR__ . '/agents-api-smoke-helpers.php';

$agents_api_dir = realpath( __DIR__ . '/../agents-api' );
agents_api_smoke_assert_equals( true, is_string( $agents_api_dir ), 'agents-api directory exists', $failures, $passes );

$forbidden_namespaces = array(
	'DataMachine\\Core\\Steps',
	'DataMachine\\Core\\Database\\Jobs',
	'DataMachine\\Core\\Admin',
	'DataMachine\\Core\\Assets',
	'DataMachine\\Engine\\Handlers',
	'DataMachine\\Core\\ActionScheduler',
	'DataMachine\\Engine\\AI\\System\\Tasks\\Retention',
	'DataMachine\\Engine\\Pipelines',
	'DataMachine\\Engine\\Flows',
	'DataMachine\\Engine\\Queue',
	'DataMachine\\Core\\Content',
);

$forbidden_admin_apis = array(
	'add_menu_page',
	'add_submenu_page',
	'add_options_page',
	'add_management_page',
	'add_dashboard_page',
	'add_theme_page',
	'add_plugins_page',
	'register_setting',
);

$forbidden_admin_hooks = array(
	'admin_menu',
	'network_admin_menu',
	'user_admin_menu',
	'admin_init',
	'admin_enqueue_scripts',
	'admin_post_',
);

$matches  = array();
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( (string) $agents_api_dir ) );
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$source = (string) file_get_contents( $file->getPathname() );
	foreach ( $forbidden_namespaces as $namespace ) {
		$quoted = preg_quote( $namespace, '/' );
		if ( preg_match( '/(?:use\s+|new\s+|extends\s+|implements\s+|instanceof\s+|\\\\)' . $quoted . '(?:\\\\|;|\s|\(|::)/', $source ) ) {
			$matches[] = str_replace( (string) $agents_api_dir . '/', '', $file->getPathname() ) . ' imports ' . $namespace;
		}
	}

	if ( preg_match( '/(?:use\s+|new\s+|extends\s+|implements\s+|instanceof\s+)\\?DataMachine\\\\/', $source ) ) {
		$matches[] = str_replace( (string) $agents_api_dir . '/', '', $file->getPathname() ) . ' imports a DataMachine namespace';
	}

	foreach ( $forbidden_admin_apis as $function_name ) {
		if ( preg_match( '/\\b' . preg_quote( $function_name, '/' ) . '\\s*\(/', $source ) ) {
			$matches[] = str_replace( (string) $agents_api_dir . '/', '', $file->getPathname() ) . ' registers admin UI via ' . $function_name;
		}
	}

	foreach ( $forbidden_admin_hooks as $hook_name ) {
		if ( preg_match( '/add_action\s*\(\s*[\'\"]' . preg_quote( $hook_name, '/' ) . '/', $source ) ) {
			$matches[] = str_replace( (string) $agents_api_dir . '/', '', $file->getPathname() ) . ' registers admin hook ' . $hook_name;
		}
	}
}

agents_api_smoke_assert_equals( array(), $matches, 'agents-api has no Data Machine product imports or admin UI registrations', $failures, $passes );
agents_api_smoke_assert_equals( $forbidden_namespaces, array_values( array_unique( $forbidden_namespaces ) ), 'forbidden namespace list has no duplicates', $failures, $passes );
agents_api_smoke_assert_equals( $forbidden_admin_apis, array_values( array_unique( $forbidden_admin_apis ) ), 'forbidden admin API list has no duplicates', $failures, $passes );
agents_api_smoke_assert_equals( $forbidden_admin_hooks, array_values( array_unique( $forbidden_admin_hooks ) ), 'forbidden admin hook list has no duplicates', $failures, $passes );

agents_api_smoke_finish( 'Agents API no-product-imports', $failures, $passes );
