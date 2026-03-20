<?php
/**
 * Scaffold Abilities
 *
 * Core primitive for memory file scaffolding. ALL business logic for
 * creating missing memory files lives here — directory resolution,
 * content generation, file writing, permission checks.
 *
 * Every caller uses the ability directly:
 *   wp_get_ability( 'datamachine/scaffold-memory-file' )->execute( $input )
 *
 * Content generators register on the `datamachine_scaffold_content`
 * filter, keyed by filename. The ability never overwrites existing files.
 *
 * @package DataMachine\Abilities\File
 * @since   0.50.0
 */

namespace DataMachine\Abilities\File;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FilesystemHelper;
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class ScaffoldAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerScaffoldMemoryFile();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerScaffoldMemoryFile(): void {
		wp_register_ability(
			'datamachine/scaffold-memory-file',
			array(
				'label'               => __( 'Scaffold Memory File', 'data-machine' ),
				'description'         => __( 'Create a missing memory file with default content generated from context. Never overwrites existing files. Supports registered files (USER.md, SOUL.md, etc.) and dynamic files (daily memory).', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'filename'   => array(
							'type'        => 'string',
							'description' => __( 'Filename to scaffold. For registered files: USER.md, SOUL.md, MEMORY.md, etc. For daily memory: daily/YYYY/MM/DD.md.', 'data-machine' ),
						),
						'user_id'    => array(
							'type'        => 'integer',
							'description' => __( 'WordPress user ID. Required for user-layer files (USER.md). Also used as fallback for agent-layer resolution.', 'data-machine' ),
							'default'     => 0,
						),
						'agent_slug' => array(
							'type'        => 'string',
							'description' => __( 'Agent slug. Used for agent-layer files (SOUL.md, MEMORY.md). Takes priority over user_id for agent directory resolution.', 'data-machine' ),
						),
						'agent_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Agent ID. Alternative to agent_slug for agent-layer files. Resolved to slug internally.', 'data-machine' ),
							'default'     => 0,
						),
						'filepath'   => array(
							'type'        => 'string',
							'description' => __( 'Explicit filesystem path. For dynamic files not in the registry (daily memory). When provided, filename is the logical name for content generation only.', 'data-machine' ),
						),
						'date'       => array(
							'type'        => 'string',
							'description' => __( 'Date in YYYY-MM-DD format. Used by the daily memory content generator for the date header.', 'data-machine' ),
						),
						'layer'      => array(
							'type'        => 'string',
							'description' => __( 'Target layer when scaffolding all files in a layer at once. One of: shared, agent, user, network. When set, filename is ignored and all registered files for this layer are scaffolded.', 'data-machine' ),
						),
					),
				),
				'permission_callback' => function () {
					return PermissionHelper::can( 'chat' );
				},
				'execute_callback'    => array( __CLASS__, 'execute' ),
			)
		);
	}

	// =========================================================================
	// Execution
	// =========================================================================

	/**
	 * Execute the scaffold ability.
	 *
	 * Handles three modes:
	 * 1. Single registered file: filename + context → resolve from registry
	 * 2. Single dynamic file: filename + filepath + context → explicit path
	 * 3. Layer batch: layer + context → all registered files for that layer
	 *
	 * @param array $input Ability input.
	 * @return array Result with success, message, filename(s), created count.
	 */
	public static function execute( array $input ): array {
		$layer = $input['layer'] ?? '';

		// Mode 3: scaffold all registered files in a layer.
		if ( ! empty( $layer ) ) {
			return self::scaffold_layer( $layer, $input );
		}

		$filename = $input['filename'] ?? '';
		if ( empty( $filename ) ) {
			return array(
				'success' => false,
				'error'   => 'Either filename or layer is required.',
			);
		}

		// Mode 2: explicit filepath for dynamic files.
		$explicit_path = $input['filepath'] ?? '';
		if ( ! empty( $explicit_path ) ) {
			return self::scaffold_at_path( $explicit_path, $filename, $input );
		}

		// Mode 1: registered file.
		return self::scaffold_registered( $filename, $input );
	}

	// =========================================================================
	// Mode 1: Registered file
	// =========================================================================

	/**
	 * Scaffold a single registered memory file.
	 *
	 * @param string $filename Registered filename (e.g. USER.md).
	 * @param array  $input    Full ability input as context.
	 * @return array Result.
	 */
	private static function scaffold_registered( string $filename, array $input ): array {
		$meta = MemoryFileRegistry::get( $filename );
		if ( ! $meta ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'File "%s" is not registered in the MemoryFileRegistry.', $filename ),
			);
		}

		$directory = self::resolve_directory( $meta['layer'], $input );
		if ( ! $directory ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Could not resolve directory for layer "%s". Check that required context (user_id, agent_slug, etc.) is provided.', $meta['layer'] ),
			);
		}

		$filepath = trailingslashit( $directory ) . $filename;

		if ( file_exists( $filepath ) ) {
			return array(
				'success'  => true,
				'message'  => sprintf( '%s already exists.', $filename ),
				'filename' => $filename,
				'filepath' => $filepath,
				'created'  => false,
			);
		}

		$content = self::generate_content( $filename, $input );
		if ( '' === $content ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'No content generator returned content for "%s".', $filename ),
			);
		}

		$written = self::write_file( $filepath, $directory, $content, $filename, $input );
		if ( ! $written ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Failed to write %s to disk.', $filename ),
			);
		}

		return array(
			'success'  => true,
			'message'  => sprintf( 'Scaffolded %s.', $filename ),
			'filename' => $filename,
			'filepath' => $filepath,
			'created'  => true,
		);
	}

	// =========================================================================
	// Mode 2: Dynamic file at explicit path
	// =========================================================================

	/**
	 * Scaffold a file at an explicit filesystem path.
	 *
	 * For dynamic files not in the MemoryFileRegistry (e.g. daily memory
	 * at agent/daily/YYYY/MM/DD.md). Content generation uses the logical
	 * filename via the same filter chain.
	 *
	 * @param string $filepath         Full filesystem path.
	 * @param string $logical_filename Logical name for the content filter.
	 * @param array  $input            Full ability input as context.
	 * @return array Result.
	 */
	private static function scaffold_at_path( string $filepath, string $logical_filename, array $input ): array {
		if ( file_exists( $filepath ) ) {
			return array(
				'success'  => true,
				'message'  => sprintf( '%s already exists.', $logical_filename ),
				'filename' => $logical_filename,
				'filepath' => $filepath,
				'created'  => false,
			);
		}

		$content = self::generate_content( $logical_filename, $input );
		if ( '' === $content ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'No content generator returned content for "%s".', $logical_filename ),
			);
		}

		$directory = dirname( $filepath );
		$written   = self::write_file( $filepath, $directory, $content, $logical_filename, $input );
		if ( ! $written ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Failed to write %s to disk.', $logical_filename ),
			);
		}

		return array(
			'success'  => true,
			'message'  => sprintf( 'Scaffolded %s.', $logical_filename ),
			'filename' => $logical_filename,
			'filepath' => $filepath,
			'created'  => true,
		);
	}

	// =========================================================================
	// Mode 3: Scaffold all files in a layer
	// =========================================================================

	/**
	 * Scaffold all registered files for a layer.
	 *
	 * @param string $layer Layer identifier.
	 * @param array  $input Full ability input as context.
	 * @return array Result with created count and file details.
	 */
	private static function scaffold_layer( string $layer, array $input ): array {
		$valid_layers = array(
			MemoryFileRegistry::LAYER_SHARED,
			MemoryFileRegistry::LAYER_AGENT,
			MemoryFileRegistry::LAYER_USER,
			MemoryFileRegistry::LAYER_NETWORK,
		);

		if ( ! in_array( $layer, $valid_layers, true ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Invalid layer "%s". Must be one of: %s', $layer, implode( ', ', $valid_layers ) ),
			);
		}

		$files   = MemoryFileRegistry::get_by_layer( $layer );
		$results = array();
		$created = 0;

		foreach ( $files as $filename => $meta ) {
			$input['filename'] = $filename;
			$result            = self::scaffold_registered( $filename, $input );
			$results[]         = $result;

			if ( ! empty( $result['created'] ) ) {
				++$created;
			}
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Scaffolded %d of %d %s-layer file(s).', $created, count( $files ), $layer ),
			'layer'   => $layer,
			'created' => $created,
			'total'   => count( $files ),
			'files'   => $results,
		);
	}

	// =========================================================================
	// Business Logic
	// =========================================================================

	/**
	 * Generate default content for a file via the filter chain.
	 *
	 * @param string $filename Filename or logical path.
	 * @param array  $context  Input context.
	 * @return string Generated content, or empty string if no generator produced content.
	 */
	public static function generate_content( string $filename, array $context = array() ): string {
		/**
		 * Filter the scaffold content for a memory file.
		 *
		 * Content generators register on this filter and return content
		 * when the filename matches their responsibility. Return empty
		 * string to skip file creation.
		 *
		 * @since 0.50.0
		 *
		 * @param string $content  Default content (empty string).
		 * @param string $filename The filename being scaffolded (e.g. 'USER.md',
		 *                         'SOUL.md', or 'daily/2026/03/20.md').
		 * @param array  $context  Full ability input (user_id, agent_slug, date, etc.).
		 */
		$content = apply_filters( 'datamachine_scaffold_content', '', $filename, $context );

		return is_string( $content ) ? trim( $content ) : '';
	}

	/**
	 * Resolve the target directory for a layer + context combination.
	 *
	 * @param string $layer   Layer identifier.
	 * @param array  $context Input context.
	 * @return string|null Directory path, or null if unresolvable.
	 */
	public static function resolve_directory( string $layer, array $context ): ?string {
		$dm = new DirectoryManager();

		switch ( $layer ) {
			case MemoryFileRegistry::LAYER_SHARED:
				return $dm->get_shared_directory();

			case MemoryFileRegistry::LAYER_NETWORK:
				return $dm->get_network_directory();

			case MemoryFileRegistry::LAYER_USER:
				$user_id = (int) ( $context['user_id'] ?? 0 );
				if ( $user_id <= 0 ) {
					return null;
				}
				return $dm->get_user_directory( $user_id );

			case MemoryFileRegistry::LAYER_AGENT:
				$agent_slug = $context['agent_slug'] ?? null;
				if ( $agent_slug ) {
					return $dm->get_agent_identity_directory( $agent_slug );
				}
				$agent_id = (int) ( $context['agent_id'] ?? 0 );
				if ( $agent_id > 0 ) {
					$slug = $dm->resolve_agent_slug( array( 'agent_id' => $agent_id ) );
					return $dm->get_agent_identity_directory( $slug );
				}
				$user_id = (int) ( $context['user_id'] ?? 0 );
				return $dm->get_agent_identity_directory_for_user( $user_id );

			default:
				return null;
		}
	}

	/**
	 * Write a scaffolded file to disk.
	 *
	 * Creates directory structure, writes content with trailing newline,
	 * sets group-writable permissions, and protects directory from listing.
	 *
	 * @param string $filepath  Full file path.
	 * @param string $directory Parent directory.
	 * @param string $content   File content.
	 * @param string $filename  Filename for logging.
	 * @param array  $context   Input context for logging.
	 * @return bool True on success.
	 */
	private static function write_file( string $filepath, string $directory, string $content, string $filename, array $context ): bool {
		$dm = new DirectoryManager();
		if ( ! $dm->ensure_directory_exists( $directory ) ) {
			return false;
		}

		$fs = FilesystemHelper::get();
		if ( ! $fs ) {
			return false;
		}

		$fs->put_contents( $filepath, $content . "\n", FS_CHMOD_FILE );
		FilesystemHelper::make_group_writable( $filepath );

		// Protect directory from listing.
		$index_path = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index_path ) ) {
			$fs->put_contents( $index_path, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			FilesystemHelper::make_group_writable( $index_path );
		}

		// Log only meaningful context keys.
		$log_context = array_filter(
			array(
				'user_id'    => $context['user_id'] ?? null,
				'agent_slug' => $context['agent_slug'] ?? null,
				'agent_id'   => $context['agent_id'] ?? null,
				'date'       => $context['date'] ?? null,
			),
			function ( $v ) {
				return null !== $v && '' !== $v && 0 !== $v;
			}
		);

		do_action(
			'datamachine_log',
			'info',
			sprintf( 'Scaffolded %s.', $filename ),
			array(
				'filename' => $filename,
				'filepath' => $filepath,
				'context'  => $log_context,
			)
		);

		return true;
	}
}
