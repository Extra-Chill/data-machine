<?php
/**
 * Contract smoke: abilities must not gate token use on is_authenticated().
 *
 * BaseOAuth2Provider::is_authenticated() reports false once an access token
 * hard-expires. A path that checks it before get_valid_access_token() rejects
 * the request before the stored refresh token can be used (see #3981, #3985).
 * Token-consuming paths must call get_valid_access_token() and handle a null
 * or empty result instead.
 *
 * Run with: php tests/oauth-refresh-gate-contract-smoke.php
 *
 * @package DataMachineSocials\Tests
 */

$root     = dirname( __DIR__ ) . '/inc/Abilities';
$window   = 12;
$failures = array();
$files    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}

	$lines = file( $file->getPathname() );
	foreach ( $lines as $i => $line ) {
		if ( false === strpos( $line, '->is_authenticated()' ) ) {
			continue;
		}

		$ahead = implode( '', array_slice( $lines, $i, $window ) );
		if ( false !== strpos( $ahead, 'get_valid_access_token()' ) ) {
			$failures[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ':' . ( $i + 1 );
		}
	}
}

if ( $failures ) {
	fwrite( STDERR, "FAIL: is_authenticated() gates get_valid_access_token() (blocks refresh of expired tokens):\n  " . implode( "\n  ", $failures ) . "\n" );
	exit( 1 );
}

echo "OAuth refresh gate contract smoke passed.\n";
