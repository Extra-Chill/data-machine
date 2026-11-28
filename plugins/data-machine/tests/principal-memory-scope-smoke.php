<?php
/**
 * Pure-PHP contract test for principal-scoped memory paths.
 *
 * Run with: php tests/principal-memory-scope-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

function absint( $value ): int {
	return abs( (int) $value );
}

function sanitize_title( string $value ): string {
	$value = strtolower( $value );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
	return trim( (string) $value, '-' );
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/' ) . '/';
}

function wp_upload_dir(): array {
	return array( 'basedir' => sys_get_temp_dir() . '/datamachine-principal-memory' );
}

function is_multisite(): bool {
	return false;
}

eval( 'namespace DataMachine\\Core\\Database\\Agents; class Agents { public function get_agent( int $id ): ?array { return [ "agent_id" => $id, "agent_slug" => 42 === $id ? "north" : "writer", "owner_id" => 1, "instance_key" => "default" ]; } public function get_by_slug( string $slug ): ?array { return [ "agent_id" => "north" === $slug ? 42 : 84 ]; } }' );

require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();
require_once __DIR__ . '/../inc/Core/FilesRepository/DirectoryManager.php';

$directory = new DataMachine\Core\FilesRepository\DirectoryManager();
$north_7   = $directory->get_principal_directory( 7, 42 );
$north_8   = $directory->get_principal_directory( 8, 42 );
$writer_7  = $directory->get_principal_directory( 7, 84 );
$north     = $directory->resolve_agent_directory( array( 'agent_id' => 42 ) );

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( $condition ) {
		fwrite( STDOUT, "PASS {$label}\n" );
		return;
	}
	$failures[] = $label;
	fwrite( STDERR, "FAIL {$label}\n" );
};

$assert( $north . '/users/7' === $north_7, 'principal path nests the authenticated user beneath the effective agent' );
$assert( $north_7 !== $north_8, 'two users of one agent have isolated memory paths' );
$assert( $north_7 !== $writer_7, 'one user across two agents has isolated memory paths' );
$assert( $north === $directory->resolve_agent_directory( array( 'agent_id' => 42, 'user_id' => 8 ) ), 'agent-global path is independent of the calling user' );
$assert( 'principal' === WP_Agent_Memory_Layer::normalize( 'principal' ), 'Data Machine consumes the canonical Agents API principal layer' );

$invalid_scope_rejected = false;
try {
	$directory->get_principal_directory( 0, 42 );
} catch ( InvalidArgumentException ) {
	$invalid_scope_rejected = true;
}
$assert( $invalid_scope_rejected, 'principal path fails closed without an authenticated user' );

if ( $failures ) {
	exit( 1 );
}

fwrite( STDOUT, "Principal memory scope contract passed.\n" );
