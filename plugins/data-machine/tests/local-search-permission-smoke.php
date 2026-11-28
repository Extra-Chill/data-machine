<?php
/**
 * Smoke test: local-search is readable by logged-in readers, not just operators.
 *
 * Run with: php tests/local-search-permission-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['lsp_user'] = 0;
	$GLOBALS['lsp_caps'] = array();

	function is_user_logged_in(): bool {
		return $GLOBALS['lsp_user'] > 0;
	}
	function get_current_user_id(): int {
		return $GLOBALS['lsp_user'];
	}
	function user_can( $user_id, string $cap ): bool {
		return in_array( $cap, $GLOBALS['lsp_caps'][ $user_id ] ?? array(), true );
	}
	function doing_action( $hook = null ): bool {
		return false;
	}
	function apply_filters( $hook, $value ) {
		return $value;
	}

	require dirname( __DIR__ ) . '/inc/Abilities/PermissionHelper.php';

	$failures = 0;
	$check    = static function ( bool $cond, string $msg ) use ( &$failures ): void {
		if ( ! $cond ) {
			++$failures;
			fwrite( STDERR, "FAIL: {$msg}\n" );
		}
	};

	$GLOBALS['lsp_caps'] = array(
		38 => array( 'read', 'edit_posts', 'upload_files' ),
		2  => array( 'read' ),
		1  => array( 'read', 'manage_options' ),
		5  => array( 'read', 'datamachine_manage_flows' ),
	);

	$GLOBALS['lsp_user'] = 0;
	$check( ! \DataMachine\Abilities\PermissionHelper::can( 'search_content' ), 'logged-out request cannot search' );

	$GLOBALS['lsp_user'] = 38;
	$check( \DataMachine\Abilities\PermissionHelper::can( 'search_content' ), 'editor (non-operator) can search' );
	$check( ! \DataMachine\Abilities\PermissionHelper::can_manage(), 'editor is still not an operator' );

	$GLOBALS['lsp_user'] = 2;
	$check( \DataMachine\Abilities\PermissionHelper::can( 'search_content' ), 'logged-in reader can search' );

	$GLOBALS['lsp_user'] = 1;
	$check( \DataMachine\Abilities\PermissionHelper::can( 'search_content' ), 'administrator can search' );

	$GLOBALS['lsp_user'] = 5;
	$check( \DataMachine\Abilities\PermissionHelper::can( 'search_content' ), 'operator keeps access' );

	$source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/LocalSearchAbilities.php' );
	$check( false !== strpos( $source, "PermissionHelper::can( 'search_content' )" ), 'local-search uses the search_content permission' );
	$check( false === strpos( $source, 'PermissionHelper::can_manage()' ), 'local-search no longer requires can_manage' );
	$check( 3 === substr_count( $source, "'publish'" ), 'local-search results stay publish-only' );

	if ( $failures ) {
		exit( 1 );
	}
	echo "Local search permission smoke passed.\n";
}
