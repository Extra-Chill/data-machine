<?php
/**
 * Pure path-integrity smoke for persisted graph projection.
 *
 * Run with: php tests/persisted-agent-graph-projector-paths-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) { return (string) $value; }
}

require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleValidationException.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Bundle/BundleRelativePath.php';
require_once dirname( __DIR__ ) . '/inc/Engine/Agents/PersistedAgentGraphProjector.php';

use DataMachine\Engine\Agents\PersistedAgentGraphProjector;
use DataMachine\Engine\Bundle\BundleValidationException;

$method = new ReflectionMethod( PersistedAgentGraphProjector::class, 'verified_source_path' );
$tmp = sys_get_temp_dir() . '/datamachine-graph-paths-' . getmypid();
@mkdir( $tmp, 0775, true );
file_put_contents( $tmp . '/valid.md', "valid\0bytes" );

$assert = static function ( bool $condition, string $label ): void {
	echo ( $condition ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $condition ) { exit( 1 ); }
};

$hash = hash_file( 'sha256', $tmp . '/valid.md' );
$assert( realpath( $tmp . '/valid.md' ) === $method->invoke( null, $tmp, 'valid.md', $hash, 'skill' ), 'valid regular file with matching hash resolves' );

foreach ( array(
	array( '../escape.md', $hash, 'traversal key' ),
	array( 'valid.md', str_repeat( '0', 64 ), 'modified hash' ),
) as $case ) {
	list( $path, $expected_hash, $label ) = $case;
	$rejected = false;
	try { $method->invoke( null, $tmp, $path, $expected_hash, 'skill' ); } catch ( Throwable $e ) { $rejected = true; }
	$assert( $rejected, $label . ' is rejected' );
}

if ( function_exists( 'symlink' ) && @symlink( $tmp . '/valid.md', $tmp . '/linked.md' ) ) {
	$rejected = false;
	try { $method->invoke( null, $tmp, 'linked.md', $hash, 'skill' ); } catch ( Throwable $e ) { $rejected = true; }
	$assert( $rejected, 'symlink source is rejected' );
	@unlink( $tmp . '/linked.md' );
}

@unlink( $tmp . '/valid.md' );
@rmdir( $tmp );
