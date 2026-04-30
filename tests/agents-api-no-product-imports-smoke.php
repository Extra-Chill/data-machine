<?php
/**
 * Static smoke test proving agents-api does not import Data Machine product code (#1639).
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
	'DataMachine\\Engine\\Handlers',
	'DataMachine\\Core\\ActionScheduler',
	'DataMachine\\Engine\\AI\\System\\Tasks\\Retention',
	'DataMachine\\Engine\\Pipelines',
	'DataMachine\\Engine\\Flows',
	'DataMachine\\Engine\\Queue',
	'DataMachine\\Core\\Content',
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
}

agents_api_smoke_assert_equals( array(), $matches, 'agents-api has no Data Machine product imports', $failures, $passes );
agents_api_smoke_assert_equals( $forbidden_namespaces, array_values( array_unique( $forbidden_namespaces ) ), 'forbidden namespace list has no duplicates', $failures, $passes );

agents_api_smoke_finish( 'Agents API no-product-imports', $failures, $passes );
