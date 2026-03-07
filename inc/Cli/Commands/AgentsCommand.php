<?php
/**
 * WP-CLI Agents Command
 *
 * CRUD operations for first-class Data Machine agents.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.37.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\Database\Agents\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Data Machine agents.
 *
 * Agents are first-class entities with their own identity, memory,
 * configuration, and file directories. Each agent can own pipelines,
 * flows, and chat sessions independently.
 *
 * @since 0.37.0
 */
class AgentsCommand extends BaseCommand {

	/**
	 * List all registered agents.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status (active, inactive).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all agents
	 *     wp datamachine agents list
	 *
	 *     # JSON output
	 *     wp datamachine agents list --format=json
	 *
	 *     # Only active agents
	 *     wp datamachine agents list --status=active
	 *
	 * @subcommand list
	 */
	public function list_agents( array $args, array $assoc_args ): void {
		global $wpdb;

		$agents_table = $wpdb->prefix . Agents::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $agents_table ) ) === $agents_table );

		if ( ! $table_exists ) {
			WP_CLI::warning( 'datamachine_agents table not found. Falling back to legacy user listing.' );
			$this->list_legacy_agents( $assoc_args );
			return;
		}

		$agent_repository = new Agents();
		$status           = $assoc_args['status'] ?? '';
		$agents           = $agent_repository->get_all( $status );

		$pipelines_table   = $wpdb->prefix . 'datamachine_pipelines';
		$flows_table       = $wpdb->prefix . 'datamachine_flows';
		$directory_manager = new DirectoryManager();
		$items             = array();

		foreach ( $agents as $agent ) {
			$owner_id = (int) ( $agent['owner_id'] ?? 0 );
			$user     = $owner_id > 0 ? get_user_by( 'id', $owner_id ) : false;
			$slug     = (string) ( $agent['agent_slug'] ?? '' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$pipeline_count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $pipelines_table, $owner_id )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$flow_count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $flows_table, $owner_id )
			);

			$agent_dir = $directory_manager->get_agent_identity_directory( $slug );
			$items[]   = array(
				'agent_id'    => (int) ( $agent['agent_id'] ?? 0 ),
				'agent_slug'  => $slug,
				'agent_name'  => (string) ( $agent['agent_name'] ?? '' ),
				'owner_id'    => $owner_id,
				'owner_login' => $user ? $user->user_login : '(none)',
				'pipelines'   => $pipeline_count,
				'flows'       => $flow_count,
				'has_files'   => is_dir( $agent_dir ) ? 'Yes' : 'No',
				'status'      => (string) ( $agent['status'] ?? 'active' ),
			);
		}

		$fields = array( 'agent_id', 'agent_slug', 'agent_name', 'owner_id', 'owner_login', 'pipelines', 'flows', 'has_files', 'status' );
		$this->format_items( $items, $fields, $assoc_args, 'agent_id' );

		WP_CLI::log( sprintf( 'Total: %d agent(s).', count( $items ) ) );
	}

	/**
	 * Create a new agent.
	 *
	 * Creates a first-class agent with its own identity directory
	 * and scaffold files (SOUL.md, MEMORY.md).
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Agent slug (lowercase, hyphens). Must be unique.
	 *
	 * [--name=<name>]
	 * : Display name for the agent. Defaults to the slug.
	 *
	 * [--owner=<owner>]
	 * : WordPress user ID or login to assign as owner. Default: 0 (no owner).
	 *
	 * [--config=<json>]
	 * : Agent configuration as JSON string.
	 *
	 * [--porcelain]
	 * : Output just the agent ID for scripting.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a basic agent
	 *     wp datamachine agents create crypto-bot --name="Crypto Trading Bot"
	 *
	 *     # Create an agent owned by a user
	 *     wp datamachine agents create sarai --name="Sarai Chinwag" --owner=chubes
	 *
	 *     # Create with config
	 *     wp datamachine agents create studio --name="Studio Agent" --config='{"model":{"default":"gpt-5-mini"}}'
	 *
	 * @subcommand create
	 */
	public function create_agent( array $args, array $assoc_args ): void {
		$slug = sanitize_title( $args[0] );

		if ( empty( $slug ) ) {
			WP_CLI::error( 'Agent slug cannot be empty.' );
		}

		$agent_repository = new Agents();

		// Check for duplicate.
		$existing = $agent_repository->get_by_slug( $slug );
		if ( $existing ) {
			WP_CLI::error( sprintf( 'Agent "%s" already exists (agent_id: %d).', $slug, $existing['agent_id'] ) );
		}

		// Resolve owner.
		$owner_id = 0;
		if ( ! empty( $assoc_args['owner'] ) ) {
			$owner_id = $this->resolve_user( $assoc_args['owner'] );
		}

		// Resolve name.
		$name = $assoc_args['name'] ?? $slug;

		// Parse config.
		$config = array();
		if ( ! empty( $assoc_args['config'] ) ) {
			$config = json_decode( $assoc_args['config'], true );
			if ( ! is_array( $config ) ) {
				WP_CLI::error( 'Invalid JSON for --config.' );
			}
		}

		// Create the agent row.
		$agent_id = $agent_repository->create_if_missing( $slug, $name, $owner_id, $config );

		if ( ! $agent_id ) {
			WP_CLI::error( 'Failed to create agent.' );
		}

		// Create the agent identity directory and scaffold files.
		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_identity_directory( $slug );

		if ( ! $directory_manager->ensure_directory_exists( $agent_dir ) ) {
			WP_CLI::warning( sprintf( 'Could not create agent directory: %s (check permissions on parent).', $agent_dir ) );
		} else {
			$this->scaffold_agent_files( $slug, $name, $agent_dir );
		}

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $agent_id );
			return;
		}

		WP_CLI::success( sprintf(
			'Agent "%s" created (agent_id: %d, directory: %s).',
			$slug,
			$agent_id,
			$agent_dir
		) );
	}

	/**
	 * Show detailed information about an agent.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Agent slug to inspect.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show agent details
	 *     wp datamachine agents show extra-chill
	 *
	 *     # JSON output
	 *     wp datamachine agents show crypto-bot --format=json
	 *
	 * @subcommand show
	 */
	public function show_agent( array $args, array $assoc_args ): void {
		global $wpdb;

		$slug  = sanitize_title( $args[0] );
		$agent = $this->resolve_agent( $slug );

		$owner_id = (int) $agent['owner_id'];
		$user     = $owner_id > 0 ? get_user_by( 'id', $owner_id ) : false;

		$directory_manager = new DirectoryManager();
		$agent_dir         = $directory_manager->get_agent_identity_directory( $slug );

		// Count resources.
		$pipelines_table = $wpdb->prefix . 'datamachine_pipelines';
		$flows_table     = $wpdb->prefix . 'datamachine_flows';
		$jobs_table      = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$pipeline_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $pipelines_table, $owner_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$flow_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $flows_table, $owner_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$job_count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $jobs_table, $owner_id )
		);

		// List files in agent directory.
		$files = array();
		if ( is_dir( $agent_dir ) ) {
			$entries = scandir( $agent_dir );
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$files[] = $entry;
			}
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format || 'yaml' === $format ) {
			$output = array(
				'agent_id'    => (int) $agent['agent_id'],
				'agent_slug'  => $agent['agent_slug'],
				'agent_name'  => $agent['agent_name'],
				'owner_id'    => $owner_id,
				'owner_login' => $user ? $user->user_login : '(none)',
				'status'      => $agent['status'],
				'config'      => $agent['agent_config'],
				'directory'   => $agent_dir,
				'files'       => $files,
				'pipelines'   => $pipeline_count,
				'flows'       => $flow_count,
				'jobs'        => $job_count,
				'created_at'  => $agent['created_at'],
				'updated_at'  => $agent['updated_at'],
			);

			if ( 'json' === $format ) {
				WP_CLI::line( wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			} else {
				WP_CLI\Utils\format_items( 'yaml', array( $output ), array_keys( $output ) );
			}
			return;
		}

		// Table format — key/value pairs.
		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( "%BAgent: {$agent['agent_slug']}%n" ) );
		WP_CLI::line( str_repeat( '─', 50 ) );
		WP_CLI::line( sprintf( '  %-14s %s', 'ID:', $agent['agent_id'] ) );
		WP_CLI::line( sprintf( '  %-14s %s', 'Name:', $agent['agent_name'] ) );
		WP_CLI::line( sprintf( '  %-14s %s', 'Slug:', $agent['agent_slug'] ) );
		WP_CLI::line( sprintf( '  %-14s %s (%s)', 'Owner:', $owner_id, $user ? $user->user_login : 'none' ) );
		WP_CLI::line( sprintf( '  %-14s %s', 'Status:', $agent['status'] ) );
		WP_CLI::line( sprintf( '  %-14s %s', 'Created:', $agent['created_at'] ) );
		WP_CLI::line( sprintf( '  %-14s %s', 'Updated:', $agent['updated_at'] ) );

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%BResources%n' ) );
		WP_CLI::line( sprintf( '  %-14s %d', 'Pipelines:', $pipeline_count ) );
		WP_CLI::line( sprintf( '  %-14s %d', 'Flows:', $flow_count ) );
		WP_CLI::line( sprintf( '  %-14s %d', 'Jobs:', $job_count ) );

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%BFiles%n' ) );
		WP_CLI::line( sprintf( '  %-14s %s', 'Directory:', $agent_dir ) );
		if ( empty( $files ) ) {
			WP_CLI::line( '  (no files)' );
		} else {
			foreach ( $files as $file ) {
				$full_path = $agent_dir . '/' . $file;
				$is_dir    = is_dir( $full_path );
				WP_CLI::line( sprintf( '  - %s%s', $file, $is_dir ? '/' : '' ) );
			}
		}

		if ( ! empty( $agent['agent_config'] ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%BConfig%n' ) );
			WP_CLI::line( wp_json_encode( $agent['agent_config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}

		WP_CLI::line( '' );
	}

	/**
	 * Delete an agent.
	 *
	 * Removes the agent from the database. Does NOT delete the agent's
	 * file directory (use --delete-files to also remove files).
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Agent slug to delete.
	 *
	 * [--delete-files]
	 * : Also delete the agent's identity directory.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete an agent (keeps files)
	 *     wp datamachine agents delete crypto-bot --yes
	 *
	 *     # Delete agent and its files
	 *     wp datamachine agents delete crypto-bot --delete-files --yes
	 *
	 * @subcommand delete
	 */
	public function delete_agent( array $args, array $assoc_args ): void {
		$slug  = sanitize_title( $args[0] );
		$agent = $this->resolve_agent( $slug );

		$agent_id = (int) $agent['agent_id'];

		WP_CLI::confirm(
			sprintf( 'Delete agent "%s" (agent_id: %d)?', $slug, $agent_id ),
			$assoc_args
		);

		$agent_repository = new Agents();
		$deleted           = $agent_repository->delete( $agent_id );

		if ( ! $deleted ) {
			WP_CLI::error( 'Failed to delete agent from database.' );
		}

		WP_CLI::log( sprintf( 'Agent "%s" removed from database.', $slug ) );

		// Optionally delete files.
		if ( ! empty( $assoc_args['delete-files'] ) ) {
			$directory_manager = new DirectoryManager();
			$agent_dir         = $directory_manager->get_agent_identity_directory( $slug );

			if ( is_dir( $agent_dir ) ) {
				$this->recursive_rmdir( $agent_dir );
				WP_CLI::log( sprintf( 'Deleted directory: %s', $agent_dir ) );
			}
		}

		WP_CLI::success( sprintf( 'Agent "%s" deleted.', $slug ) );
	}

	/**
	 * Get or set agent configuration.
	 *
	 * Without a key, shows the full config. With a key, shows that value.
	 * With a key and value, sets it. Use dot notation for nested keys
	 * (e.g., "model.default").
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Agent slug.
	 *
	 * [<key>]
	 * : Configuration key (supports dot notation for nested access).
	 *
	 * [<value>]
	 * : Value to set. Omit to read. Use JSON for objects/arrays.
	 *
	 * [--delete]
	 * : Delete the key from config.
	 *
	 * [--format=<format>]
	 * : Output format for get operations.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - yaml
	 *   - raw
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show full config
	 *     wp datamachine agents config extra-chill
	 *
	 *     # Get a specific key
	 *     wp datamachine agents config extra-chill model.default
	 *
	 *     # Set a value
	 *     wp datamachine agents config extra-chill model.default gpt-5-mini
	 *
	 *     # Set a complex value
	 *     wp datamachine agents config extra-chill webhook '{"url":"https://discord.com/api/webhooks/..."}'
	 *
	 *     # Delete a key
	 *     wp datamachine agents config extra-chill webhook --delete
	 *
	 * @subcommand config
	 */
	public function config_agent( array $args, array $assoc_args ): void {
		$slug  = sanitize_title( $args[0] );
		$agent = $this->resolve_agent( $slug );

		$agent_id = (int) $agent['agent_id'];
		$config   = is_array( $agent['agent_config'] ) ? $agent['agent_config'] : array();
		$key      = $args[1] ?? null;
		$value    = $args[2] ?? null;
		$delete   = ! empty( $assoc_args['delete'] );
		$format   = $assoc_args['format'] ?? 'json';

		// No key — show full config.
		if ( null === $key ) {
			$this->output_value( $config, $format );
			return;
		}

		$key_parts = explode( '.', $key );

		// Delete mode.
		if ( $delete ) {
			$config = $this->unset_nested( $config, $key_parts );

			$agent_repository = new Agents();
			$agent_repository->update( $agent_id, array( 'agent_config' => $config ) );

			WP_CLI::success( sprintf( 'Deleted config key "%s" from agent "%s".', $key, $slug ) );
			return;
		}

		// No value — read mode.
		if ( null === $value ) {
			$result = $this->get_nested( $config, $key_parts );

			if ( null === $result ) {
				WP_CLI::error( sprintf( 'Config key "%s" not found.', $key ) );
			}

			$this->output_value( $result, $format );
			return;
		}

		// Set mode.
		// Try to parse value as JSON first (for objects/arrays).
		$parsed = json_decode( $value, true );
		if ( null !== $parsed && json_last_error() === JSON_ERROR_NONE ) {
			$value = $parsed;
		}

		$config = $this->set_nested( $config, $key_parts, $value );

		$agent_repository = new Agents();
		$agent_repository->update( $agent_id, array( 'agent_config' => $config ) );

		WP_CLI::success( sprintf( 'Set config "%s" on agent "%s".', $key, $slug ) );
	}

	/**
	 * Update an agent's name, owner, or status.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Agent slug to update.
	 *
	 * [--name=<name>]
	 * : New display name.
	 *
	 * [--owner=<owner>]
	 * : New owner (user ID or login). Use 0 for no owner.
	 *
	 * [--status=<status>]
	 * : New status (active, inactive).
	 *
	 * ## EXAMPLES
	 *
	 *     # Rename an agent
	 *     wp datamachine agents update crypto-bot --name="Crypto Alpha Bot"
	 *
	 *     # Change owner
	 *     wp datamachine agents update extra-chill --owner=chubes
	 *
	 *     # Deactivate an agent
	 *     wp datamachine agents update crypto-bot --status=inactive
	 *
	 * @subcommand update
	 */
	public function update_agent( array $args, array $assoc_args ): void {
		$slug  = sanitize_title( $args[0] );
		$agent = $this->resolve_agent( $slug );

		$agent_id = (int) $agent['agent_id'];
		$data     = array();
		$changes  = array();

		if ( isset( $assoc_args['name'] ) ) {
			$data['agent_name'] = $assoc_args['name'];
			$changes[]          = sprintf( 'name → "%s"', $assoc_args['name'] );
		}

		if ( isset( $assoc_args['owner'] ) ) {
			$owner_id       = ( '0' === $assoc_args['owner'] ) ? 0 : $this->resolve_user( $assoc_args['owner'] );
			$data['owner_id'] = $owner_id;
			$changes[]        = sprintf( 'owner → %d', $owner_id );
		}

		if ( isset( $assoc_args['status'] ) ) {
			$valid_statuses = array( 'active', 'inactive' );
			if ( ! in_array( $assoc_args['status'], $valid_statuses, true ) ) {
				WP_CLI::error( sprintf( 'Invalid status. Valid values: %s', implode( ', ', $valid_statuses ) ) );
			}
			$data['status'] = $assoc_args['status'];
			$changes[]      = sprintf( 'status → %s', $assoc_args['status'] );
		}

		if ( empty( $data ) ) {
			WP_CLI::error( 'Nothing to update. Provide --name, --owner, or --status.' );
		}

		$agent_repository = new Agents();
		$updated           = $agent_repository->update( $agent_id, $data );

		if ( ! $updated ) {
			WP_CLI::error( 'Failed to update agent.' );
		}

		WP_CLI::success( sprintf( 'Agent "%s" updated: %s.', $slug, implode( ', ', $changes ) ) );
	}

	// ─── Helper methods ──────────────────────────────────────────────

	/**
	 * Resolve an agent by slug, or error if not found.
	 *
	 * @param string $slug Agent slug.
	 * @return array Agent row.
	 */
	private function resolve_agent( string $slug ): array {
		$agent_repository = new Agents();
		$agent            = $agent_repository->get_by_slug( $slug );

		if ( ! $agent ) {
			WP_CLI::error( sprintf( 'Agent "%s" not found.', $slug ) );
		}

		return $agent;
	}

	/**
	 * Resolve a user by ID or login.
	 *
	 * @param string|int $identifier User ID or login.
	 * @return int User ID.
	 */
	private function resolve_user( $identifier ): int {
		if ( is_numeric( $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );
		} else {
			$user = get_user_by( 'login', $identifier );
		}

		if ( ! $user ) {
			WP_CLI::error( sprintf( 'User "%s" not found.', $identifier ) );
		}

		return $user->ID;
	}

	/**
	 * Create scaffold files for a new agent.
	 *
	 * @param string $slug      Agent slug.
	 * @param string $name      Agent display name.
	 * @param string $agent_dir Agent directory path.
	 */
	private function scaffold_agent_files( string $slug, string $name, string $agent_dir ): void {
		$site_name = get_bloginfo( 'name' ) ?: 'WordPress Site';
		$site_url  = home_url();

		$soul_content = "# Agent Soul — {$name}\n\n"
			. "## Identity\n\n"
			. "I am **{$name}**, an agent on {$site_name} ({$site_url}).\n\n"
			. "## Purpose\n\n"
			. "(Define this agent's purpose and responsibilities here.)\n\n"
			. "## Rules\n\n"
			. "- Follow existing codebase patterns\n"
			. "- Ask for clarification when instructions are ambiguous\n";

		$memory_content = "# Agent Memory\n\n"
			. "## State\n\n"
			. "- Agent created: " . current_time( 'Y-m-d' ) . "\n"
			. "- Status: active\n\n"
			. "## Lessons Learned\n\n"
			. "(Record operational knowledge here.)\n";

		$files = array(
			'SOUL.md'   => $soul_content,
			'MEMORY.md' => $memory_content,
		);

		foreach ( $files as $filename => $content ) {
			$filepath = $agent_dir . '/' . $filename;
			if ( ! file_exists( $filepath ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $filepath, $content );
			}
		}
	}

	/**
	 * Get a nested value from an array using key parts.
	 *
	 * @param array $array Array to traverse.
	 * @param array $keys  Key path.
	 * @return mixed|null
	 */
	private function get_nested( array $array, array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
				return null;
			}
			$array = $array[ $key ];
		}
		return $array;
	}

	/**
	 * Set a nested value in an array using key parts.
	 *
	 * @param array $array Array to modify.
	 * @param array $keys  Key path.
	 * @param mixed $value Value to set.
	 * @return array Modified array.
	 */
	private function set_nested( array $array, array $keys, $value ): array {
		$current = &$array;
		foreach ( $keys as $i => $key ) {
			if ( $i === count( $keys ) - 1 ) {
				$current[ $key ] = $value;
			} else {
				if ( ! isset( $current[ $key ] ) || ! is_array( $current[ $key ] ) ) {
					$current[ $key ] = array();
				}
				$current = &$current[ $key ];
			}
		}
		return $array;
	}

	/**
	 * Unset a nested value from an array using key parts.
	 *
	 * @param array $array Array to modify.
	 * @param array $keys  Key path.
	 * @return array Modified array.
	 */
	private function unset_nested( array $array, array $keys ): array {
		$current = &$array;
		foreach ( $keys as $i => $key ) {
			if ( $i === count( $keys ) - 1 ) {
				unset( $current[ $key ] );
			} else {
				if ( ! isset( $current[ $key ] ) || ! is_array( $current[ $key ] ) ) {
					return $array;
				}
				$current = &$current[ $key ];
			}
		}
		return $array;
	}

	/**
	 * Output a value in the requested format.
	 *
	 * @param mixed  $value  Value to output.
	 * @param string $format Output format (json, yaml, raw).
	 */
	private function output_value( $value, string $format ): void {
		if ( 'raw' === $format ) {
			WP_CLI::line( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
			return;
		}

		if ( 'yaml' === $format ) {
			WP_CLI::line( \Symfony\Component\Yaml\Yaml::dump( $value, 4, 2 ) );
			return;
		}

		// Default: json.
		WP_CLI::line( wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function recursive_rmdir( string $dir ): void {
		$entries = scandir( $dir );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}

	/**
	 * Legacy fallback listing for pre-migration installs.
	 *
	 * @param array $assoc_args Command args.
	 */
	private function list_legacy_agents( array $assoc_args ): void {
		global $wpdb;

		$items = array();

		$directory_manager = new DirectoryManager();
		$shared_dir        = $directory_manager->get_agent_directory( 0 );
		$shared_exists     = is_dir( $shared_dir );

		$pipelines_table = $wpdb->prefix . 'datamachine_pipelines';
		$flows_table     = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$shared_pipelines = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $pipelines_table, 0 )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$shared_flows = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $flows_table, 0 )
		);

		$items[] = array(
			'user_id'   => 0,
			'login'     => '(shared)',
			'email'     => '-',
			'pipelines' => $shared_pipelines,
			'flows'     => $shared_flows,
			'has_files' => $shared_exists ? 'Yes' : 'No',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids_from_pipelines = $wpdb->get_col(
			$wpdb->prepare( 'SELECT DISTINCT user_id FROM %i WHERE user_id > %d', $pipelines_table, 0 )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids_from_flows = $wpdb->get_col(
			$wpdb->prepare( 'SELECT DISTINCT user_id FROM %i WHERE user_id > %d', $flows_table, 0 )
		);

		$all_user_ids = array_unique(
			array_merge(
				array_map( 'intval', $user_ids_from_pipelines ),
				array_map( 'intval', $user_ids_from_flows )
			)
		);

		$upload_dir = wp_upload_dir();
		$agent_base = trailingslashit( $upload_dir['basedir'] ) . 'datamachine-files';
		$user_dirs  = glob( $agent_base . '/agent-*', GLOB_ONLYDIR );

		if ( $user_dirs ) {
			foreach ( $user_dirs as $dir ) {
				$dirname = basename( $dir );
				if ( preg_match( '/^agent-(\d+)$/', $dirname, $matches ) ) {
					$uid = (int) $matches[1];
					if ( $uid > 0 && ! in_array( $uid, $all_user_ids, true ) ) {
						$all_user_ids[] = $uid;
					}
				}
			}
		}

		sort( $all_user_ids );

		foreach ( $all_user_ids as $uid ) {
			$user = get_user_by( 'id', $uid );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$user_pipelines = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $pipelines_table, $uid )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$user_flows = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $flows_table, $uid )
			);

			$user_dir   = $directory_manager->get_agent_directory( $uid );
			$dir_exists = is_dir( $user_dir );

			$items[] = array(
				'user_id'   => $uid,
				'login'     => $user ? $user->user_login : '(deleted)',
				'email'     => $user ? $user->user_email : '-',
				'pipelines' => $user_pipelines,
				'flows'     => $user_flows,
				'has_files' => $dir_exists ? 'Yes' : 'No',
			);
		}

		$fields = array( 'user_id', 'login', 'email', 'pipelines', 'flows', 'has_files' );
		$this->format_items( $items, $fields, $assoc_args );

		WP_CLI::log( sprintf( 'Total: %d agent(s).', count( $items ) ) );
	}
}
