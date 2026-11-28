<?php
/** Regression coverage for read-only Action Scheduler insert evidence. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public $prefix = '';
		public $last_error = '';
	}
}

require_once __DIR__ . '/../inc/Core/ActionScheduler/ActionInsertReadiness.php';

use DataMachine\Core\ActionScheduler\ActionInsertReadiness;

final class ActionInsertReadinessSmokeWpdb extends wpdb {
	public function __construct() {
		$this->prefix = 'wp_7_';
	}

	public function prepare( $query, ...$args ): string {
		foreach ( $args as $argument ) {
			$query = preg_replace( '/%[is]/', '%i' === substr( $query, strpos( $query, '%' ), 2 ) ? '`' . $argument . '`' : "'{$argument}'", $query, 1 );
		}
		return $query;
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		unset( $x, $y );
		if ( 'SELECT DATABASE()' === $query ) {
			return 'wordpress';
		}
		if ( str_contains( (string) $query, 'MAX(action_id)' ) ) {
			return 82650691;
		}
		return null;
	}

	public function get_row( $query = null, $output = null, $y = 0 ) {
		unset( $query, $output, $y );
		return array(
			'ENGINE'         => 'InnoDB',
			'TABLE_ROWS'     => 45000,
			'AUTO_INCREMENT' => 82650692,
			'CREATE_TIME'    => '2026-08-01 00:00:00',
			'UPDATE_TIME'    => null,
			'CHECK_TIME'     => null,
		);
	}
}

$failed = 0;
$assert = static function ( bool $condition, string $label ) use ( &$failed ): void {
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}
	++$failed;
	echo "FAIL: {$label}\n";
};

$result = ( new ActionInsertReadiness( new ActionInsertReadinessSmokeWpdb() ) )->inspect();
$assert( true === $result['success'] && 'metadata_coherent' === $result['status'], 'coherent metadata is reported without claiming a write' );
$assert( false === $result['write_test_performed'], 'diagnostic never inserts a probe action' );
$assert( 82650692 === $result['auto_increment'] && 82650691 === $result['max_action_id'], 'diagnostic preserves sequence evidence' );
$assert( str_contains( $result['limitations'][0], 'cannot prove' ), 'diagnostic states its evidentiary limit' );

$command = file_get_contents( __DIR__ . '/../inc/Cli/Commands/SystemCommand.php' ) ?: '';
$assert( str_contains( $command, '@subcommand action-scheduler-insert-readiness' ), 'operator command exposes the diagnostic' );
$assert( ! str_contains( file_get_contents( __DIR__ . '/../inc/Core/ActionScheduler/ActionInsertReadiness.php' ) ?: '', 'INSERT INTO' ), 'diagnostic contains no repair or insert statement' );

exit( $failed > 0 ? 1 : 0 );
