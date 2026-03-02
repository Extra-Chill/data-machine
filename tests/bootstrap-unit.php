<?php
/**
 * Bootstrap for unit tests that don't require WordPress.
 *
 * Defines ABSPATH to prevent the early-exit guards in plugin files,
 * then loads the Composer autoloader.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

require_once __DIR__ . '/../vendor/autoload.php';
