<?php
/**
 * WP-CLI base class stub.
 *
 * Defines an empty `\WP_CLI_Command` in the global namespace so that command
 * classes which extend it can be loaded for REFLECTION ONLY in non-CLI
 * (web/cron) AGENTS.md compose contexts, where the WP-CLI runtime — and thus
 * the real base class — is not loaded.
 *
 * This file is only ever required by AgentsMdSection::ensure_wp_cli_base_class()
 * after confirming the real `\WP_CLI_Command` does not exist, so it can never
 * shadow or interfere with the genuine WP-CLI runtime during command execution.
 *
 * @package DataMachineEvents\Cli
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Minimal stand-in for the WP-CLI base command class.
	 */
	class WP_CLI_Command {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, PEAR.NamingConventions.ValidClassName.Invalid
}
