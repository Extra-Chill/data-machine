<?php
/**
 * Data Machine — Migrations, scaffolding, and activation helpers.
 *
 * Extracted from data-machine.php to keep the main plugin file clean.
 * All functions are prefixed with datamachine_ and called from the
 * plugin bootstrap and activation hooks.
 *
 * @package DataMachine
 * @since 0.38.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'datamachine_maybe_run_migrations', 5 );

add_action( 'plugins_loaded', 'datamachine_register_scaffold_generators', 5 );
