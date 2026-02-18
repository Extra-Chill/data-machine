<?php
/**
 * Agent Memory Migration
 *
 * Migrates agent soul from wp_options (PluginSettings) to SOUL.md
 * in the files repository agent directory. Runs on plugin activation
 * and version-based upgrade check.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.13.0
 */

namespace DataMachine\Core\FilesRepository;

use DataMachine\Core\PluginSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentMemoryMigration {

	/**
	 * Option key for tracking migration version.
	 */
	private const MIGRATION_VERSION_KEY = 'datamachine_agent_memory_version';

	/**
	 * Current migration version.
	 */
	private const CURRENT_VERSION = '1.0';

	/**
	 * Section definitions matching the old AgentSoulDirective structure.
	 */
	private const SECTIONS = array(
		'identity' => 'Identity',
		'voice'    => 'Voice & Tone',
		'rules'    => 'Rules',
		'context'  => 'Context',
	);

	/**
	 * Default SOUL.md template for fresh installs.
	 */
	private const DEFAULT_TEMPLATE = <<<'MD'
# Agent Soul

## Identity
You are an AI content assistant.

## Voice & Tone
Write in a clear, helpful tone.

## Rules
- Follow the site's content guidelines
- Ask for clarification when instructions are ambiguous

## Context
<!-- Add background about your site, audience, brand, or domain expertise here -->
MD;

	/**
	 * Run migration if needed.
	 *
	 * @return void
	 */
	public static function maybe_run(): void {
		$stored_version = get_option( self::MIGRATION_VERSION_KEY, '' );

		if ( self::CURRENT_VERSION === $stored_version ) {
			return;
		}

		self::migrate();
		update_option( self::MIGRATION_VERSION_KEY, self::CURRENT_VERSION );
	}

	/**
	 * Execute the migration.
	 *
	 * @return void
	 */
	private static function migrate(): void {
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_directory();
		$soul_path         = "{$agent_dir}/SOUL.md";

		// Already migrated — skip.
		if ( file_exists( $soul_path ) ) {
			return;
		}

		// Ensure agent directory exists with index.php protection.
		if ( ! $directory_manager->ensure_directory_exists( $agent_dir ) ) {
			do_action(
				'datamachine_log',
				'error',
				'AgentMemoryMigration: Failed to create agent directory.',
				array( 'path' => $agent_dir )
			);
			return;
		}

		self::write_index_protection( $agent_dir );

		$content = self::build_soul_content();

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			do_action(
				'datamachine_log',
				'error',
				'AgentMemoryMigration: Filesystem not available.'
			);
			return;
		}

		$written = $fs->put_contents( $soul_path, $content, FS_CHMOD_FILE );

		if ( ! $written ) {
			do_action(
				'datamachine_log',
				'error',
				'AgentMemoryMigration: Failed to write SOUL.md.',
				array( 'path' => $soul_path )
			);
			return;
		}

		// Clean up old settings keys.
		self::cleanup_old_settings();

		do_action(
			'datamachine_log',
			'info',
			'AgentMemoryMigration: Successfully migrated agent soul to SOUL.md.',
			array( 'path' => $soul_path )
		);
	}

	/**
	 * Build SOUL.md content from existing settings or defaults.
	 *
	 * @return string Markdown content.
	 */
	private static function build_soul_content(): string {
		$soul = PluginSettings::get( 'agent_soul', array() );

		// Try structured soul sections.
		if ( is_array( $soul ) && ! empty( $soul ) ) {
			$parts      = array();
			$has_content = false;

			foreach ( self::SECTIONS as $key => $header ) {
				$value = trim( $soul[ $key ] ?? '' );
				if ( '' !== $value ) {
					$has_content = true;
					$parts[]     = "## {$header}\n{$value}";
				}
			}

			if ( $has_content ) {
				return "# Agent Soul\n\n" . implode( "\n\n", $parts ) . "\n";
			}
		}

		// Fallback: legacy global_system_prompt.
		$legacy = PluginSettings::get( 'global_system_prompt', '' );
		if ( ! empty( trim( $legacy ) ) ) {
			return trim( $legacy ) . "\n";
		}

		// Fresh install: default template.
		return self::DEFAULT_TEMPLATE . "\n";
	}

	/**
	 * Remove old settings keys after successful migration.
	 *
	 * @return void
	 */
	private static function cleanup_old_settings(): void {
		$settings = PluginSettings::all();
		$changed  = false;

		if ( isset( $settings['agent_soul'] ) ) {
			unset( $settings['agent_soul'] );
			$changed = true;
		}

		if ( isset( $settings['global_system_prompt'] ) ) {
			unset( $settings['global_system_prompt'] );
			$changed = true;
		}

		if ( $changed ) {
			update_option( 'datamachine_settings', $settings );
			PluginSettings::clearCache();
		}
	}

	/**
	 * Write index.php protection file to a directory.
	 *
	 * @param string $directory Directory path.
	 * @return void
	 */
	private static function write_index_protection( string $directory ): void {
		$index_path = trailingslashit( $directory ) . 'index.php';

		if ( file_exists( $index_path ) ) {
			return;
		}

		$fs = FilesystemHelper::get();
		if ( $fs ) {
			$fs->put_contents( $index_path, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}
	}
}
