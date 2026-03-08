<?php
/**
 * Directory path management for hierarchical file storage.
 *
 * Provides pipeline → flow → job directory structure with WordPress-native
 * path operations. All paths use wp_upload_dir() as base with organized
 * subdirectory hierarchy.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.2.1
 */

namespace DataMachine\Core\FilesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DirectoryManager {

	/**
	 * Repository directory name
	 */
	private const REPOSITORY_DIR = 'datamachine-files';

	/**
	 * Whether agent file scaffolding has been verified this request.
	 *
	 * @var bool
	 */
	private static bool $agent_files_ensured = false;

	/**
	 * Ensure default agent files exist (SOUL.md, USER.md, MEMORY.md).
	 *
	 * Lazy self-healing: runs at most once per PHP request on the first
	 * call. If any registered memory files are missing, recreates them
	 * from scaffold defaults without overwriting existing files.
	 *
	 * Call this from any read path that depends on agent files existing
	 * (directives, REST API, CLI, etc.). The static flag makes repeated
	 * calls free.
	 *
	 * @since 0.32.0
	 * @return void
	 */
	public static function ensure_agent_files(): void {
		if ( self::$agent_files_ensured ) {
			return;
		}

		self::$agent_files_ensured = true;

		if ( function_exists( 'datamachine_ensure_default_memory_files' ) ) {
			datamachine_ensure_default_memory_files();
		}
	}

	/**
	 * Reset the ensure-agent-files flag. For testing only.
	 *
	 * @since 0.32.0
	 * @return void
	 */
	public static function reset_ensure_flag(): void {
		self::$agent_files_ensured = false;
	}

	/**
	 * Get pipeline directory path
	 *
	 * @param int|string $pipeline_id Pipeline ID or 'direct' for direct execution
	 * @return string Full path to pipeline directory
	 */
	public function get_pipeline_directory( int|string $pipeline_id ): string {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . self::REPOSITORY_DIR;
		return "{$base}/pipeline-{$pipeline_id}";
	}

	/**
	 * Get flow directory path
	 *
	 * @param int|string $pipeline_id Pipeline ID or 'direct' for direct execution
	 * @param int|string $flow_id Flow ID or 'direct' for direct execution
	 * @return string Full path to flow directory
	 */
	public function get_flow_directory( int|string $pipeline_id, int|string $flow_id ): string {
		$pipeline_dir = $this->get_pipeline_directory( $pipeline_id );
		return "{$pipeline_dir}/flow-{$flow_id}";
	}

	/**
	 * Get job directory path
	 *
	 * @param int|string $pipeline_id Pipeline ID or 'direct' for direct execution
	 * @param int|string $flow_id Flow ID or 'direct' for direct execution
	 * @param int|string $job_id Job ID
	 * @return string Full path to job directory
	 */
	public function get_job_directory( int|string $pipeline_id, int|string $flow_id, int|string $job_id ): string {
		$flow_dir = $this->get_flow_directory( $pipeline_id, $flow_id );
		return "{$flow_dir}/jobs/job-{$job_id}";
	}

	/**
	 * Get flow files directory path
	 *
	 * @param int|string $pipeline_id Pipeline ID or 'direct' for direct execution
	 * @param int|string $flow_id Flow ID or 'direct' for direct execution
	 * @return string Full path to flow files directory
	 */
	public function get_flow_files_directory( int|string $pipeline_id, int|string $flow_id ): string {
		$flow_dir = $this->get_flow_directory( $pipeline_id, $flow_id );
		return "{$flow_dir}/flow-{$flow_id}-files";
	}

	/**
	 * Get pipeline context directory path
	 *
	 * @param int|string $pipeline_id Pipeline ID or 'direct' for direct execution
	 * @param string     $pipeline_name Pipeline name (unused, for signature compatibility)
	 * @return string Full path to pipeline context directory
	 */
	public function get_pipeline_context_directory( int|string $pipeline_id): string {
		$pipeline_dir = $this->get_pipeline_directory( $pipeline_id );
		return "{$pipeline_dir}/context";
	}

	/**
	 * Get agent directory path.
	 *
	 * When a user_id is provided, returns the per-agent subdirectory
	 * ({base}/agent/{user_id}). When user_id is 0, returns the legacy
	 * shared directory ({base}/agent) for backward compatibility.
	 *
	 * @since 0.37.0 Added $user_id parameter for multi-agent partitioning.
	 *
	 * @param int $user_id WordPress user ID. 0 = legacy shared directory.
	 * @return string Full path to agent directory.
	 */
	public function get_agent_directory( int $user_id = 0 ): string {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . self::REPOSITORY_DIR;

		if ( 0 < $user_id ) {
			return "{$base}/agent/{$user_id}";
		}

		return "{$base}/agent";
	}

	/**
	 * Get shared site directory path.
	 *
	 * @since 0.36.1
	 * @return string Full path to shared directory.
	 */
	public function get_shared_directory(): string {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . self::REPOSITORY_DIR;
		return "{$base}/shared";
	}

	/**
	 * Get first-class agent directory path by slug.
	 *
	 * @since 0.36.1
	 * @param string $agent_slug Agent slug.
	 * @return string Full path to agent identity directory.
	 */
	public function get_agent_identity_directory( string $agent_slug ): string {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . self::REPOSITORY_DIR;
		$safe_slug  = sanitize_title( $agent_slug );

		return "{$base}/agents/{$safe_slug}";
	}

	/**
	 * Get user layer directory path.
	 *
	 * @since 0.36.1
	 * @param int $user_id WordPress user ID.
	 * @return string Full path to user layer directory.
	 */
	public function get_user_directory( int $user_id ): string {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . self::REPOSITORY_DIR;
		$user_id    = absint( $user_id );

		return "{$base}/users/{$user_id}";
	}

	/**
	 * Resolve effective user ID for layered memory context.
	 *
	 * @since 0.37.0
	 * @param int $user_id Optional user ID from request/payload.
	 * @return int Effective user ID.
	 */
	public function get_effective_user_id( int $user_id = 0 ): int {
		$user_id = absint( $user_id );

		if ( $user_id > 0 ) {
			return $user_id;
		}

		return self::get_default_agent_user_id();
	}

	/**
	 * Resolve the first-class agent slug for a user.
	 *
	 * Resolution order:
	 * 1) datamachine_agents.owner_id mapping
	 * 2) WordPress user_login
	 * 3) deterministic user-{id} fallback
	 *
	 * For multi-agent resolution, use resolve_agent_slug() instead — it
	 * accepts agent_id or agent_slug directly and only falls back to
	 * user-based lookup when neither is provided.
	 *
	 * @since 0.37.0
	 * @param int $user_id WordPress user ID.
	 * @return string Agent slug.
	 */
	public function get_agent_slug_for_user( int $user_id ): string {
		$user_id = $this->get_effective_user_id( $user_id );

		if ( class_exists( '\\DataMachine\\Core\\Database\\Agents\\Agents' ) ) {
			$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
			$agent       = $agents_repo->get_by_owner_id( $user_id );

			if ( ! empty( $agent['agent_slug'] ) ) {
				return sanitize_title( (string) $agent['agent_slug'] );
			}
		}

		$user = get_user_by( 'id', $user_id );
		if ( $user && ! empty( $user->user_login ) ) {
			return sanitize_title( (string) $user->user_login );
		}

		return 'user-' . $user_id;
	}

	/**
	 * Resolve agent slug from the best available identifier.
	 *
	 * Multi-agent safe. Resolution order:
	 * 1) Explicit agent_slug (already resolved — just sanitize)
	 * 2) agent_id → lookup slug from agents table
	 * 3) user_id → fallback to get_agent_slug_for_user() (single-agent compat)
	 *
	 * @since 0.41.0
	 * @param array $context {
	 *     @type string $agent_slug Explicit agent slug.
	 *     @type int    $agent_id   Agent ID for DB lookup.
	 *     @type int    $user_id    WordPress user ID (fallback).
	 * }
	 * @return string Agent slug.
	 */
	public function resolve_agent_slug( array $context ): string {
		// 1) Explicit slug — fastest path.
		if ( ! empty( $context['agent_slug'] ) ) {
			return sanitize_title( (string) $context['agent_slug'] );
		}

		// 2) Agent ID → lookup.
		$agent_id = (int) ( $context['agent_id'] ?? 0 );
		if ( $agent_id > 0 && class_exists( '\\DataMachine\\Core\\Database\\Agents\\Agents' ) ) {
			$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
			$agent       = $agents_repo->get_agent( $agent_id );

			if ( ! empty( $agent['agent_slug'] ) ) {
				return sanitize_title( (string) $agent['agent_slug'] );
			}
		}

		// 3) User ID fallback (single-agent compat).
		$user_id = (int) ( $context['user_id'] ?? 0 );
		return $this->get_agent_slug_for_user( $user_id );
	}

	/**
	 * Get first-class agent identity directory from the best available identifier.
	 *
	 * Multi-agent safe. Accepts the same context array as resolve_agent_slug().
	 *
	 * @since 0.41.0
	 * @param array $context See resolve_agent_slug() for keys.
	 * @return string Full path to agent identity directory.
	 */
	public function resolve_agent_directory( array $context ): string {
		return $this->get_agent_identity_directory( $this->resolve_agent_slug( $context ) );
	}

	/**
	 * Get first-class agent identity directory for a user context.
	 *
	 * For multi-agent resolution, use resolve_agent_directory() instead.
	 *
	 * @since 0.37.0
	 * @param int $user_id WordPress user ID (0 resolves to default user).
	 * @return string Full path to agent identity directory.
	 */
	public function get_agent_identity_directory_for_user( int $user_id = 0 ): string {
		$agent_slug = $this->get_agent_slug_for_user( $user_id );
		return $this->get_agent_identity_directory( $agent_slug );
	}

	/**
	 * Get the default agent user ID.
	 *
	 * For single-agent installs, returns the configured default or the first admin user.
	 *
	 * @since 0.37.0
	 * @return int Default agent user ID.
	 */
	public static function get_default_agent_user_id(): int {
		if ( defined( 'DATAMACHINE_DEFAULT_AGENT_USER' ) ) {
			return absint( DATAMACHINE_DEFAULT_AGENT_USER );
		}

		// Cache in a static to avoid repeated DB queries.
		static $default_id = null;
		if ( null !== $default_id ) {
			return $default_id;
		}

		// First admin user.
		$admins = get_users( array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'ID',
		) );

		$default_id = ! empty( $admins ) ? absint( $admins[0] ) : 1;
		return $default_id;
	}

	/**
	 * Get workspace directory path.
	 *
	 * Returns the managed workspace for agent file operations (cloning repos, etc.).
	 * Path resolution order:
	 * 1. DATAMACHINE_WORKSPACE_PATH constant (if defined)
	 * 2. /var/lib/datamachine/workspace (if writable or creatable)
	 *
	 * The workspace must be outside the web root. No uploads fallback is
	 * provided because the workspace supports write operations — placing
	 * writable agent files inside wp-content/uploads would create a
	 * remote code execution risk.
	 *
	 * If neither option resolves, an empty string is returned and
	 * Workspace::ensure_exists() will fail with a clear error.
	 *
	 * @since 0.31.0
	 * @return string Full path to workspace directory, or empty string if unavailable.
	 */
	public function get_workspace_directory(): string {
		// 1. Explicit constant override.
		if ( defined( 'DATAMACHINE_WORKSPACE_PATH' ) ) {
			return rtrim( DATAMACHINE_WORKSPACE_PATH, '/' );
		}

		// 2. System-level default (outside web root).
		$system_path = '/var/lib/datamachine/workspace';
		$system_base = dirname( $system_path );
		$fs          = FilesystemHelper::get();
		$base_writable = $fs
			? $fs->is_writable( $system_base )
			: is_writable( $system_base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		$parent_writable = ! $base_writable && ! file_exists( $system_base ) && (
			$fs
				? $fs->is_writable( dirname( $system_base ) )
				: is_writable( dirname( $system_base ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		);
		if ( $base_writable || $parent_writable ) {
			return $system_path;
		}

		// No fallback. Log the issue so admins know how to fix it.
		do_action(
			'datamachine_log',
			'error',
			'Workspace unavailable: /var/lib/datamachine/ is not writable. Define DATAMACHINE_WORKSPACE_PATH in wp-config.php to set a custom path outside the web root.',
			array()
		);

		return '';
	}

	/**
	 * Ensure directory exists
	 *
	 * @param string $directory Directory path
	 * @return bool True if exists or was created
	 */
	public function ensure_directory_exists( string $directory ): bool {
		if ( ! file_exists( $directory ) ) {
			$created = wp_mkdir_p( $directory );
			if ( ! $created ) {
				do_action(
					'datamachine_log',
					'error',
					'DirectoryManager: Failed to create directory.',
					array(
						'path' => $directory,
					)
				);
				return false;
			}
		}
		return true;
	}

	/**
	 * Ensure the agent directory and its parents are group-writable.
	 *
	 * Agent files need to be writable by both the web server user (www-data)
	 * and the coding agent user (e.g. opencode). This method creates the
	 * agent directory if needed and sets 0775 permissions on the agent
	 * directory and its parent (datamachine-files/).
	 *
	 * @since 0.32.0
	 * @since 0.37.0 Added $user_id parameter for multi-agent partitioning.
	 *
	 * @param int $user_id WordPress user ID. 0 = legacy shared directory.
	 * @return bool True if directory exists and permissions were set.
	 */
	public function ensure_agent_directory_writable( int $user_id = 0 ): bool {
		$agent_dir = $this->get_agent_directory( $user_id );

		if ( ! $this->ensure_directory_exists( $agent_dir ) ) {
			return false;
		}

		$perms = FilesystemHelper::AGENT_DIR_PERMISSIONS;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		chmod( $agent_dir, $perms );

		// Also set the parent datamachine-files/ directory.
		$parent = dirname( $agent_dir );
		if ( is_dir( $parent ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			chmod( $parent, $perms );
		}

		return true;
	}
}
