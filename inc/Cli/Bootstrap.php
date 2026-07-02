<?php
/**
 * WP-CLI Bootstrap
 *
 * Registers WP-CLI commands for Data Machine from the single-source-of-truth
 * CommandRegistry map. The same map feeds the gated AGENTS.md `datamachine`
 * section generator, so the documented command surface can never drift from
 * what is actually registered here.
 *
 * @package DataMachine\Cli
 * @since 0.11.0
 */

namespace DataMachine\Cli;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/ActionSchedulerWPCLICompat.php';
require_once __DIR__ . '/CommandRegistry.php';

foreach ( CommandRegistry::map() as $command => $class ) {
	WP_CLI::add_command( $command, $class );
}
