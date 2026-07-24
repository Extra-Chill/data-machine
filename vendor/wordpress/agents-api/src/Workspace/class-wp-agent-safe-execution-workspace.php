<?php
/**
 * Safe execution workspace primitive.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Core\Workspace;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves named, host-approved workspace roots for agent code execution.
 */
final class WP_Agent_Safe_Execution_Workspace {

	public const TARGET_ID = 'agents-api/safe-execution-workspace';

	/**
	 * Whether the optional module is enabled.
	 */
	public static function enabled(): bool {
		$enabled = defined( 'AGENTS_API_ENABLE_SAFE_WORKSPACE' ) && (bool) AGENTS_API_ENABLE_SAFE_WORKSPACE;
		return (bool) apply_filters( 'agents_api_enable_safe_workspace', $enabled );
	}

	/**
	 * Configured root path for all safe execution workspaces.
	 */
	public static function root(): string {
		$constant = defined( 'AGENTS_API_SAFE_WORKSPACE_ROOT' ) ? constant( 'AGENTS_API_SAFE_WORKSPACE_ROOT' ) : '';
		$root     = is_scalar( $constant ) ? (string) $constant : '';
		$root     = apply_filters( 'agents_api_safe_workspace_root', $root );
		return is_string( $root ) ? rtrim( $root, '/\\' ) : '';
	}

	/**
	 * Whether the module can operate with the current configuration.
	 */
	public static function available(): bool {
		if ( ! self::enabled() ) {
			return false;
		}

		return is_string( self::root_realpath() );
	}

	/**
	 * Target metadata for Agents API task placement.
	 *
	 * @return array<string,mixed>
	 */
	public static function target_metadata(): array {
		return array(
			'id'               => self::TARGET_ID,
			'label'            => 'Safe execution workspace',
			'kind'             => 'workspace',
			'description'      => 'Host-approved filesystem workspace for isolated agent code execution.',
			'capabilities'     => array(
				'workspace.files.read',
				'workspace.files.write',
				'code.execution.safe-root',
			),
			'resource_classes' => array( 'workspace' ),
			'metadata'         => array(
				'schema'             => 'agents-api/safe-execution-workspace-target/v1',
				'experimental'       => true,
				'isolated_from_site' => true,
				'mutation_boundary'  => 'workspace-root',
			),
		);
	}

	/**
	 * Prepare a named workspace directory.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prepare( array $input ): array|\WP_Error {
		$handle = self::handle( $input['handle'] ?? '' );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$root = self::root_realpath();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$path = $root . DIRECTORY_SEPARATOR . $handle;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- This module's primitive is a local filesystem workspace root.
		if ( ! is_dir( $path ) && ! mkdir( $path, 0755, true ) ) {
			return new \WP_Error( 'agents_workspace_prepare_failed', 'Safe execution workspace directory could not be created.' );
		}

		$workspace = self::workspace_path( $handle );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		return array(
			'success' => true,
			'handle'  => $handle,
			'path'    => $workspace,
			'target'  => self::TARGET_ID,
		);
	}

	/**
	 * List prepared workspaces.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function list_workspaces(): array|\WP_Error {
		$root = self::root_realpath();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$workspaces = array();
		$entries    = scandir( $root );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $root . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$workspaces[] = array(
					'handle' => $entry,
					'path'   => $path,
				);
			}
		}

		return array(
			'success'    => true,
			'root'       => $root,
			'workspaces' => $workspaces,
		);
	}

	/**
	 * Read a file from a named workspace.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function read_file( array $input ): array|\WP_Error {
		$handle   = self::handle( $input['handle'] ?? '' );
		$relative = self::relative_path( $input['path'] ?? '' );
		$path     = self::contained_path( $input, true );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'agents_workspace_file_not_readable', 'Safe execution workspace file is not readable.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This reads a validated local workspace file, not a remote URL.
		$content = file_get_contents( $path );
		if ( false === $content ) {
			return new \WP_Error( 'agents_workspace_file_read_failed', 'Safe execution workspace file could not be read.' );
		}

		return array(
			'success' => true,
			'handle'  => $handle,
			'path'    => $relative,
			'content' => $content,
			'bytes'   => strlen( $content ),
		);
	}

	/**
	 * Write a file within a named workspace.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function write_file( array $input ): array|\WP_Error {
		$content  = is_scalar( $input['content'] ?? null ) ? (string) $input['content'] : '';
		$handle   = self::handle( $input['handle'] ?? '' );
		$relative = self::relative_path( $input['path'] ?? '' );
		$path     = self::contained_path( $input, false );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$parent = dirname( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- This module's primitive is a local filesystem workspace root.
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0755, true ) ) {
			return new \WP_Error( 'agents_workspace_directory_create_failed', 'Safe execution workspace directory could not be created.' );
		}

		$parent_real = realpath( $parent );
		$workspace   = self::workspace_path( $handle );
		if ( is_wp_error( $workspace ) || false === $parent_real || ! self::is_inside( $parent_real, $workspace ) ) {
			return new \WP_Error( 'agents_workspace_path_escape', 'Safe execution workspace write path escapes the workspace root.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- This writes a validated local workspace file.
		if ( false === file_put_contents( $path, $content ) ) {
			return new \WP_Error( 'agents_workspace_file_write_failed', 'Safe execution workspace file could not be written.' );
		}

		return array(
			'success' => true,
			'handle'  => $handle,
			'path'    => $relative,
			'bytes'   => strlen( $content ),
		);
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function root_realpath(): string|\WP_Error {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'agents_workspace_disabled', 'Safe execution workspace is disabled.' );
		}

		$root = self::root();
		if ( '' === $root || ! is_dir( $root ) ) {
			return new \WP_Error( 'agents_workspace_root_unavailable', 'Safe execution workspace root is not configured or does not exist.' );
		}

		$real = realpath( $root );
		if ( false === $real ) {
			return new \WP_Error( 'agents_workspace_root_unavailable', 'Safe execution workspace root could not be resolved.' );
		}

		$site_root = self::site_root_realpath();
		if ( is_string( $site_root ) && ( self::is_inside( $real, $site_root ) || self::is_inside( $site_root, $real ) ) ) {
			return new \WP_Error( 'agents_workspace_root_not_isolated', 'Safe execution workspace root must be isolated from the WordPress site root.' );
		}

		return $real;
	}

	/**
	 * @return string|false
	 */
	private static function site_root_realpath(): string|false {
		return defined( 'ABSPATH' ) ? realpath( (string) ABSPATH ) : false;
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function workspace_path( string|\WP_Error $handle ): string|\WP_Error {
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$root = self::root_realpath();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$path = realpath( $root . DIRECTORY_SEPARATOR . $handle );
		if ( false === $path || ! is_dir( $path ) || ! self::is_inside( $path, $root ) ) {
			return new \WP_Error( 'agents_workspace_not_found', 'Safe execution workspace was not prepared.' );
		}

		return $path;
	}

	/**
	 * @param array<string,mixed> $input Ability input.
	 * @return string|\WP_Error
	 */
	private static function contained_path( array $input, bool $must_exist ): string|\WP_Error {
		$handle = self::handle( $input['handle'] ?? '' );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$workspace = self::workspace_path( $handle );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		$relative = self::relative_path( $input['path'] ?? '' );
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}

		$candidate = $workspace . DIRECTORY_SEPARATOR . $relative;
		$real      = realpath( $candidate );
		if ( false !== $real ) {
			return self::is_inside( $real, $workspace ) ? $real : new \WP_Error( 'agents_workspace_path_escape', 'Safe execution workspace path escapes the workspace root.' );
		}

		if ( $must_exist ) {
			return new \WP_Error( 'agents_workspace_path_not_found', 'Safe execution workspace path does not exist.' );
		}

		return self::is_inside( dirname( $candidate ), $workspace ) ? $candidate : new \WP_Error( 'agents_workspace_path_escape', 'Safe execution workspace path escapes the workspace root.' );
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function handle( mixed $value ): string|\WP_Error {
		$handle = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $handle || 1 !== preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/', $handle ) ) {
			return new \WP_Error( 'agents_workspace_invalid_handle', 'Safe execution workspace handle must be a simple name.' );
		}

		return $handle;
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function relative_path( mixed $value ): string|\WP_Error {
		$path = is_scalar( $value ) ? str_replace( '\\', '/', trim( (string) $value ) ) : '';
		if ( '' === $path || str_starts_with( $path, '/' ) || str_contains( $path, "\0" ) ) {
			return new \WP_Error( 'agents_workspace_invalid_path', 'Safe execution workspace path must be relative.' );
		}

		$parts = array_filter( explode( '/', $path ), static fn( string $part ): bool => '' !== $part && '.' !== $part );
		foreach ( $parts as $part ) {
			if ( '..' === $part ) {
				return new \WP_Error( 'agents_workspace_invalid_path', 'Safe execution workspace path cannot contain parent traversal.' );
			}
		}

		return implode( '/', $parts );
	}

	private static function is_inside( string $path, string $root ): bool {
		$root = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		$path = rtrim( $path, DIRECTORY_SEPARATOR ) . ( is_dir( $path ) ? DIRECTORY_SEPARATOR : '' );
		return str_starts_with( $path, $root );
	}
}
