//! agent_files_multi — extracted from MemoryCommand.php.


	/**
	 * Agent files operations.
	 *
	 * Manage all agent memory files (SOUL.md, USER.md, MEMORY.md, etc.).
	 * Supports listing, reading, writing, and staleness detection.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: list, read, write, check.
	 *
	 * [<filename>]
	 * : Filename for read/write actions (e.g., SOUL.md, USER.md).
	 *
	 * [--agent=<slug>]
	 * : Agent slug or numeric ID. When provided, operates on that agent's
	 *   files instead of the current user's agent. Required for managing
	 *   shared agents in multi-agent setups.
	 *
	 * [--days=<days>]
	 * : Staleness threshold in days for the check action.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format for list/check actions.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all agent files with timestamps and sizes
	 *     wp datamachine agent files list
	 *
	 *     # List files for a specific agent
	 *     wp datamachine agent files list --agent=studio
	 *
	 *     # Read an agent file
	 *     wp datamachine agent files read SOUL.md
	 *
	 *     # Read a specific agent's SOUL.md
	 *     wp datamachine agent files read SOUL.md --agent=studio
	 *
	 *     # Write to an agent file via stdin
	 *     cat new-soul.md | wp datamachine agent files write SOUL.md
	 *
	 *     # Write to a specific agent's file via stdin
	 *     cat soul.md | wp datamachine agent files write SOUL.md --agent=studio
	 *
	 *     # Check for stale files (not updated in 7 days)
	 *     wp datamachine agent files check
	 *
	 *     # Check with custom threshold
	 *     wp datamachine agent files check --days=14
	 *
	 *     # Check a specific agent's files
	 *     wp datamachine agent files check --agent=studio
	 *
	 * @subcommand files
	 */
	public function files( array $args, array $assoc_args ): void {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Usage: wp datamachine agent files <list|read|write|check> [filename]' );
			return;
		}

		$action   = $args[0];
		$agent_id = AgentResolver::resolve( $assoc_args );
		$user_id  = ( null === $agent_id ) ? UserResolver::resolve( $assoc_args ) : 0;

		switch ( $action ) {
			case 'list':
				$this->files_list( $assoc_args, $user_id, $agent_id );
				break;
			case 'read':
				$filename = $args[1] ?? null;
				if ( ! $filename ) {
					WP_CLI::error( 'Filename is required. Usage: wp datamachine agent files read <filename>' );
					return;
				}
				$this->files_read( $filename, $user_id, $agent_id );
				break;
			case 'write':
				$filename = $args[1] ?? null;
				if ( ! $filename ) {
					WP_CLI::error( 'Filename is required. Usage: wp datamachine agent files write <filename>' );
					return;
				}
				$this->files_write( $filename, $user_id, $agent_id );
				break;
			case 'check':
				$this->files_check( $assoc_args, $user_id, $agent_id );
				break;
			default:
				WP_CLI::error( "Unknown files action: {$action}. Use: list, read, write, check" );
		}
	}

	/**
	 * List all agent files with metadata.
	 *
	 * @param array $assoc_args Command arguments.
	 */
	private function files_list( array $assoc_args, int $user_id = 0, ?int $agent_id = null ): void {
		$agent_dir = $this->get_agent_dir( $user_id, $agent_id );

		if ( ! is_dir( $agent_dir ) ) {
			WP_CLI::error( 'Agent directory does not exist.' );
			return;
		}

		$files = glob( $agent_dir . '/*.md' );

		if ( empty( $files ) ) {
			WP_CLI::log( 'No agent files found.' );
			return;
		}

		$items = array();
		$now   = time();

		foreach ( $files as $file ) {
			$mtime    = filemtime( $file );
			$age_days = floor( ( $now - $mtime ) / 86400 );

			$items[] = array(
				'file'     => basename( $file ),
				'size'     => size_format( filesize( $file ) ),
				'modified' => wp_date( 'Y-m-d H:i:s', $mtime ),
				'age'      => $age_days . 'd',
			);
		}

		$this->format_items( $items, array( 'file', 'size', 'modified', 'age' ), $assoc_args );
	}

	/**
	 * Read an agent file by name.
	 *
	 * @param string $filename File name (e.g., SOUL.md).
	 */
	private function files_read( string $filename, int $user_id = 0, ?int $agent_id = null ): void {
		$agent_dir = $this->get_agent_dir( $user_id, $agent_id );
		$filepath  = $agent_dir . '/' . $this->sanitize_agent_filename( $filename );

		if ( ! file_exists( $filepath ) ) {
			$available = $this->list_agent_filenames( $user_id, $agent_id );
			WP_CLI::error( sprintf( 'File "%s" not found. Available files: %s', $filename, implode( ', ', $available ) ) );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		WP_CLI::log( file_get_contents( $filepath ) );
	}

	/**
	 * Write to an agent file from stdin.
	 *
	 * @param string $filename File name (e.g., SOUL.md).
	 */
	private function files_write( string $filename, int $user_id = 0, ?int $agent_id = null ): void {
		$fs        = FilesystemHelper::get();
		$safe_name = $this->sanitize_agent_filename( $filename );

		// Only allow .md files.
		if ( '.md' !== substr( $safe_name, -3 ) ) {
			WP_CLI::error( 'Only .md files can be written to the agent directory.' );
			return;
		}

		// Editability check — warn for read-only files.
		$edit_cap = \DataMachine\Engine\AI\MemoryFileRegistry::get_edit_capability( $safe_name );
		if ( false === $edit_cap ) {
			WP_CLI::error( sprintf( 'File %s is read-only. It is auto-generated and can only be extended via PHP filters.', $safe_name ) );
			return;
		}

		$agent_dir = $this->get_agent_dir( $user_id, $agent_id );
		$filepath  = $agent_dir . '/' . $safe_name;

		// Read from stdin.
		$content = $fs->get_contents( 'php://stdin' );

		if ( false === $content || '' === trim( $content ) ) {
			WP_CLI::error( 'No content received from stdin. Pipe content in: echo "content" | wp datamachine agent files write SOUL.md' );
			return;
		}

		$directory_manager = new DirectoryManager();
		$directory_manager->ensure_directory_exists( $agent_dir );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $filepath, $content );

		if ( false === $written ) {
			WP_CLI::error( sprintf( 'Failed to write file: %s', $safe_name ) );
			return;
		}

		FilesystemHelper::make_group_writable( $filepath );

		WP_CLI::success( sprintf( 'Wrote %s (%s).', $safe_name, size_format( $written ) ) );
	}

	/**
	 * Check agent files for staleness.
	 *
	 * @param array $assoc_args Command arguments.
	 */
	private function files_check( array $assoc_args, int $user_id = 0, ?int $agent_id = null ): void {
		$agent_dir      = $this->get_agent_dir( $user_id, $agent_id );
		$threshold_days = (int) ( $assoc_args['days'] ?? 7 );

		if ( ! is_dir( $agent_dir ) ) {
			WP_CLI::error( 'Agent directory does not exist.' );
			return;
		}

		$files = glob( $agent_dir . '/*.md' );

		if ( empty( $files ) ) {
			WP_CLI::log( 'No agent files found.' );
			return;
		}

		$items     = array();
		$now       = time();
		$threshold = $now - ( $threshold_days * 86400 );
		$stale     = 0;

		foreach ( $files as $file ) {
			$mtime    = filemtime( $file );
			$age_days = floor( ( $now - $mtime ) / 86400 );
			$is_stale = $mtime < $threshold;

			if ( $is_stale ) {
				++$stale;
			}

			$items[] = array(
				'file'     => basename( $file ),
				'modified' => wp_date( 'Y-m-d H:i:s', $mtime ),
				'age'      => $age_days . 'd',
				'status'   => $is_stale ? 'STALE' : 'OK',
			);
		}

		$this->format_items( $items, array( 'file', 'modified', 'age', 'status' ), $assoc_args );

		if ( $stale > 0 ) {
			WP_CLI::warning( sprintf( '%d file(s) not updated in %d+ days. Review for accuracy.', $stale, $threshold_days ) );
		} else {
			WP_CLI::success( sprintf( 'All %d file(s) updated within the last %d days.', count( $files ), $threshold_days ) );
		}
	}

	/**
	 * Resolve memory scoping from CLI flags.
	 *
	 * Returns an input array with agent_id (preferred) or user_id for
	 * memory ability calls. Agent scoping takes precedence over user scoping.
	 *
	 * @param array $assoc_args Command arguments.
	 * @return array Input parameters with user_id and/or agent_id.
	 */
	private function resolveMemoryScoping( array $assoc_args ): array {
		$agent_id = AgentResolver::resolve( $assoc_args );

		if ( null !== $agent_id ) {
			return array( 'agent_id' => $agent_id );
		}

		return array( 'user_id' => UserResolver::resolve( $assoc_args ) );
	}

	private function get_agent_dir( int $user_id = 0, ?int $agent_id = null ): string {
		$directory_manager = new DirectoryManager();

		if ( null !== $agent_id && $agent_id > 0 ) {
			$slug = $directory_manager->resolve_agent_slug( array( 'agent_id' => $agent_id ) );
			return $directory_manager->get_agent_identity_directory( $slug );
		}

		return $directory_manager->get_agent_identity_directory_for_user( $user_id );
	}

	/**
	 * Sanitize an agent filename (allow only alphanumeric, hyphens, underscores, dots).
	 *
	 * @param string $filename Raw filename.
	 * @return string Sanitized filename.
	 */
	private function sanitize_agent_filename( string $filename ): string {
		return preg_replace( '/[^a-zA-Z0-9._-]/', '', basename( $filename ) );
	}

	/**
	 * List available agent filenames.
	 *
	 * @return string[]
	 */
	private function list_agent_filenames( int $user_id = 0, ?int $agent_id = null ): array {
		$agent_dir = $this->get_agent_dir( $user_id, $agent_id );
		$files     = glob( $agent_dir . '/*.md' );
		return array_map( 'basename', $files ? $files : array() );
	}

	/**
	 * Show resolved file paths for all agent memory layers.
	 *
	 * External consumers (Kimaki, Claude Code, setup scripts) use this to
	 * discover the correct file paths instead of hardcoding them.
	 * Outputs absolute paths, relative paths (from site root), and layer directories.
	 *
	 * ## OPTIONS
	 *
	 * [--agent=<slug>]
	 * : Agent slug to resolve paths for. When provided, bypasses
	 *   user-to-agent lookup and resolves directly by slug.
	 *   Required for multi-agent setups where a user owns multiple agents.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 * ---
	 *
	 * [--relative]
	 * : Output paths relative to the WordPress root (for config file injection).
	 *
	 * ## EXAMPLES
	 *
	 *     # Get all resolved paths as JSON (for setup scripts)
	 *     wp datamachine agent paths --format=json
	 *
	 *     # Get paths for a specific agent (multi-agent)
	 *     wp datamachine agent paths --agent=chubes-bot
	 *
	 *     # Get relative paths for config file injection
	 *     wp datamachine agent paths --relative
	 *
	 *     # Table view for debugging
	 *     wp datamachine agent paths --format=table
	 *
	 * @subcommand paths
	 */
	public function paths( array $args, array $assoc_args ): void {
		$directory_manager = new DirectoryManager();
		$explicit_slug     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'agent', null );

		if ( null !== $explicit_slug ) {
			// Direct slug resolution — multi-agent safe.
			$agent_slug = $directory_manager->resolve_agent_slug( array( 'agent_slug' => $explicit_slug ) );
			$agent_dir  = $directory_manager->get_agent_identity_directory( $agent_slug );

			// Look up the agent's owner for the user layer.
			$effective_user_id = 0;
			if ( class_exists( '\\DataMachine\\Core\\Database\\Agents\\Agents' ) ) {
				$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
				$agent_row   = $agents_repo->get_by_slug( $agent_slug );
				if ( $agent_row ) {
					$effective_user_id = (int) $agent_row['owner_id'];
				}
			}

			if ( 0 === $effective_user_id ) {
				$effective_user_id = DirectoryManager::get_default_agent_user_id();
			}
		} else {
			// Legacy user-based resolution (single-agent compat).
			$user_id           = UserResolver::resolve( $assoc_args );
			$effective_user_id = $directory_manager->get_effective_user_id( $user_id );
			$agent_slug        = $directory_manager->get_agent_slug_for_user( $effective_user_id );
			$agent_dir         = $directory_manager->get_agent_identity_directory_for_user( $effective_user_id );
		}

		$shared_dir  = $directory_manager->get_shared_directory();
		$user_dir    = $directory_manager->get_user_directory( $effective_user_id );
		$network_dir = $directory_manager->get_network_directory();

		$site_root = untrailingslashit( ABSPATH );
		$relative  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'relative', false );

		// Core files in injection order (matches CoreMemoryFilesDirective).
		$core_files = array(
			array(
				'file'      => 'SITE.md',
				'layer'     => 'shared',
				'directory' => $shared_dir,
			),
			array(
				'file'      => 'SOUL.md',
				'layer'     => 'agent',
				'directory' => $agent_dir,
			),
			array(
				'file'      => 'MEMORY.md',
				'layer'     => 'agent',
				'directory' => $agent_dir,
			),
			array(
				'file'      => 'USER.md',
				'layer'     => 'user',
				'directory' => $user_dir,
			),
			array(
				'file'      => 'NETWORK.md',
				'layer'     => 'network',
				'directory' => $network_dir,
			),
		);

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'json' );

		if ( 'json' === $format ) {
			$layers = array(
				'shared'  => $shared_dir,
				'agent'   => $agent_dir,
				'user'    => $user_dir,
				'network' => $network_dir,
			);

			$files          = array();
			$relative_files = array();

			foreach ( $core_files as $entry ) {
				$abs_path = trailingslashit( $entry['directory'] ) . $entry['file'];
				$rel_path = str_replace( $site_root . '/', '', $abs_path );
				$exists   = file_exists( $abs_path );

				$files[ $entry['file'] ] = array(
					'layer'    => $entry['layer'],
					'path'     => $abs_path,
					'relative' => $rel_path,
					'exists'   => $exists,
				);

				if ( $exists ) {
					$relative_files[] = $rel_path;
				}
			}

			$output = array(
				'agent_slug'     => $agent_slug,
				'user_id'        => $effective_user_id,
				'layers'         => $layers,
				'files'          => $files,
				'relative_files' => $relative_files,
			);

			WP_CLI::line( wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			$items = array();
			foreach ( $core_files as $entry ) {
				$abs_path = trailingslashit( $entry['directory'] ) . $entry['file'];
				$rel_path = str_replace( $site_root . '/', '', $abs_path );

				$items[] = array(
					'file'   => $entry['file'],
					'layer'  => $entry['layer'],
					'path'   => $relative ? $rel_path : $abs_path,
					'exists' => file_exists( $abs_path ) ? 'yes' : 'no',
				);
			}

			$this->format_items( $items, array( 'file', 'layer', 'path', 'exists' ), $assoc_args );
		}
	}
