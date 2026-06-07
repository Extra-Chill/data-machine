<?php
/**
 * Agent bundle directory reader/writer.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Bundle directories intentionally use local filesystem I/O.

/**
 * Pure filesystem adapter for schema_version 1 agent bundle directories.
 */
final class AgentBundleDirectory {

	private AgentBundleManifest $manifest;
	/** @var array<string,string> */
	private array $memory_files;
	/** @var AgentBundlePipelineFile[] */
	private array $pipelines;
	/** @var AgentBundleFlowFile[] */
	private array $flows;
	/** @var array<string,array<string,array|string>> */
	private array $artifact_files;
	/** @var array<int,array<string,mixed>> */
	private array $extension_artifacts;
	/** @var array<string,array<string,string>> */
	private array $extras;

	/**
	 * @param AgentBundleManifest       $manifest Bundle manifest.
	 * @param array<string,string>      $memory_files Relative memory path => contents.
	 * @param AgentBundlePipelineFile[] $pipelines Pipeline files.
	 * @param AgentBundleFlowFile[]     $flows Flow files.
	 * @param array<string,array<string,array|string>> $artifact_files Artifact directory => relative path => decoded JSON payload or text contents.
	 * @param array<int,array<string,mixed>> $extension_artifacts Plugin-owned artifact envelopes.
	 * @param array<string,array<string,string>> $extras Plugin-owned opaque file maps keyed by top-level directory.
	 */
	public function __construct( AgentBundleManifest $manifest, array $memory_files, array $pipelines, array $flows, array $artifact_files = array(), array $extension_artifacts = array(), array $extras = array() ) {
		$this->manifest            = $manifest;
		$this->memory_files        = self::normalize_memory_files( $memory_files );
		$this->pipelines           = self::sort_documents_by_slug( $pipelines, AgentBundlePipelineFile::class );
		$this->flows               = self::sort_documents_by_slug( $flows, AgentBundleFlowFile::class );
		$this->artifact_files      = self::normalize_artifact_files( $artifact_files );
		$this->extension_artifacts = AgentBundleArtifactExtensions::normalize_artifacts( $extension_artifacts );
		$this->extras              = BundleSchema::validate_extras( $extras );
	}

	public static function read( string $directory ): self {
		$directory = rtrim( $directory, '/\\' );
		if ( ! is_dir( $directory ) ) {
			throw new BundleValidationException( sprintf( 'Bundle directory does not exist: %s', esc_html( $directory ) ) );
		}

		$manifest_path = $directory . '/' . BundleSchema::MANIFEST_FILE;
		if ( ! is_file( $manifest_path ) ) {
			throw new BundleValidationException( 'Bundle directory is missing manifest.json.' );
		}

		$manifest = AgentBundleManifest::from_array(
			BundleSchema::decode_json( self::read_file( $manifest_path ), 'manifest.json' )
		);

		return new self(
			$manifest,
			self::read_memory_files( $directory . '/' . BundleSchema::MEMORY_DIR ),
			self::read_documents( $directory . '/' . BundleSchema::PIPELINES_DIR, AgentBundlePipelineFile::class, $directory ),
			self::read_documents( $directory . '/' . BundleSchema::FLOWS_DIR, AgentBundleFlowFile::class, $directory ),
			self::read_artifact_directories( $directory ),
			self::read_extension_artifacts( $directory . '/' . BundleSchema::EXTENSIONS_DIR ),
			self::read_extras( $directory )
		);
	}

	public function write( string $directory ): void {
		$directory = rtrim( $directory, '/\\' );
		self::ensure_directory( $directory );
		self::ensure_directory( $directory . '/' . BundleSchema::MEMORY_DIR );
		self::ensure_directory( $directory . '/' . BundleSchema::PIPELINES_DIR );
		self::ensure_directory( $directory . '/' . BundleSchema::FLOWS_DIR );
		foreach ( AgentBundleArtifactDefinitions::file_artifact_directories() as $artifact_directory ) {
			self::ensure_directory( $directory . '/' . $artifact_directory );
		}
		self::ensure_directory( $directory . '/' . BundleSchema::EXTENSIONS_DIR );

		self::write_file( $directory . '/' . BundleSchema::MANIFEST_FILE, BundleSchema::encode_json( $this->manifest->to_array() ) );

		foreach ( $this->memory_files as $relative_path => $contents ) {
			$path = $directory . '/' . BundleSchema::MEMORY_DIR . '/' . $relative_path;
			self::ensure_directory( dirname( $path ) );
			self::write_file( $path, $contents );
		}

		foreach ( $this->pipelines as $pipeline ) {
			self::write_file( $directory . '/' . BundleSchema::PIPELINES_DIR . '/' . $pipeline->slug() . '.json', BundleSchema::encode_json( $pipeline->to_array() ) );
		}

		foreach ( $this->flows as $flow ) {
			self::write_file( $directory . '/' . BundleSchema::FLOWS_DIR . '/' . $flow->slug() . '.json', BundleSchema::encode_json( $flow->to_array() ) );
		}

		foreach ( $this->artifact_files as $artifact_directory => $files ) {
			foreach ( $files as $relative_path => $payload ) {
				$path = $directory . '/' . $artifact_directory . '/' . $relative_path;
				self::ensure_directory( dirname( $path ) );
				self::write_file( $path, is_array( $payload ) ? BundleSchema::encode_json( $payload ) : $payload );
			}
		}

		foreach ( $this->extension_artifacts as $artifact ) {
			$path = $directory . '/' . $artifact['source_path'];
			self::ensure_directory( dirname( $path ) );
			self::write_file( $path, BundleSchema::encode_json( $artifact ) );
		}

		foreach ( $this->extras as $extras_key => $files ) {
			self::ensure_directory( $directory . '/' . $extras_key );
			foreach ( $files as $relative_path => $contents ) {
				$path = $directory . '/' . $relative_path;
				self::ensure_directory( dirname( $path ) );
				self::write_file( $path, $contents );
			}
		}
	}

	public function manifest(): AgentBundleManifest {
		return $this->manifest;
	}

	/** @return array<string,string> */
	public function memory_files(): array {
		return $this->memory_files;
	}

	/** @return AgentBundlePipelineFile[] */
	public function pipelines(): array {
		return $this->pipelines;
	}

	/** @return AgentBundleFlowFile[] */
	public function flows(): array {
		return $this->flows;
	}

	/** @return array<string,array|string> */
	public function prompts(): array {
		return $this->artifact_files[ BundleSchema::PROMPTS_DIR ];
	}

	/** @return array<string,array|string> */
	public function rubrics(): array {
		return $this->artifact_files[ BundleSchema::RUBRICS_DIR ];
	}

	/** @return array<string,array|string> */
	public function tool_policies(): array {
		return $this->artifact_files[ BundleSchema::TOOL_POLICIES_DIR ];
	}

	/** @return array<string,array|string> */
	public function auth_refs(): array {
		return $this->artifact_files[ BundleSchema::AUTH_REFS_DIR ];
	}

	/** @return array<string,array|string> */
	public function seed_queues(): array {
		return $this->artifact_files[ BundleSchema::SEED_QUEUES_DIR ];
	}

	/** @return array<int,array<string,mixed>> */
	public function extension_artifacts(): array {
		return $this->extension_artifacts;
	}

	/**
	 * Plugin-owned opaque file maps keyed by top-level directory.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function extras(): array {
		return $this->extras;
	}

	private static function ensure_directory( string $directory ): void {
		if ( is_dir( $directory ) ) {
			return;
		}
		if ( ! mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
			throw new BundleValidationException( sprintf( 'Unable to create bundle directory: %s', esc_html( $directory ) ) );
		}
	}

	/** @return array<string,string> */
	private static function normalize_memory_files( array $memory_files ): array {
		$normalized = array();
		foreach ( $memory_files as $relative_path => $contents ) {
			$relative_path = str_replace( '\\', '/', (string) $relative_path );
			$relative_path = ltrim( $relative_path, '/' );
			if ( str_contains( $relative_path, '..' ) || '' === $relative_path ) {
				throw new BundleValidationException( sprintf( 'Invalid memory file path: %s', esc_html( $relative_path ) ) );
			}
			$normalized[ $relative_path ] = (string) $contents;
		}
		ksort( $normalized, SORT_STRING );
		return $normalized;
	}

	/** @return array<string,array<string,array|string>> */
	private static function normalize_artifact_files( array $artifact_files ): array {
		$normalized = array_fill_keys( AgentBundleArtifactDefinitions::file_artifact_directories(), array() );
		foreach ( $artifact_files as $artifact_directory => $files ) {
			$artifact_directory = (string) $artifact_directory;
			if ( ! in_array( $artifact_directory, AgentBundleArtifactDefinitions::file_artifact_directories(), true ) ) {
				throw new BundleValidationException( sprintf( 'Invalid artifact directory: %s', esc_html( $artifact_directory ) ) );
			}
			if ( ! is_array( $files ) ) {
				throw new BundleValidationException( sprintf( 'Artifact directory %s must be an object.', esc_html( $artifact_directory ) ) );
			}
			foreach ( $files as $relative_path => $payload ) {
				$relative_path = self::normalize_relative_path( (string) $relative_path, $artifact_directory );
				if ( ! is_array( $payload ) ) {
					$payload = (string) $payload;
				}
				$normalized[ $artifact_directory ][ $relative_path ] = $payload;
			}
			ksort( $normalized[ $artifact_directory ], SORT_STRING );
		}

		return $normalized;
	}

	/** @return array<string,string> */
	private static function read_memory_files( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$files    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$path               = $file->getPathname();
			$relative           = str_replace( '\\', '/', substr( $path, strlen( $directory ) + 1 ) );
			$files[ $relative ] = self::read_file( $path );
		}
		ksort( $files, SORT_STRING );
		return $files;
	}

	/** @return array<string,array<string,array|string>> */
	private static function read_artifact_directories( string $directory ): array {
		$artifact_files = array();
		foreach ( AgentBundleArtifactDefinitions::file_artifact_directories() as $artifact_directory ) {
			$artifact_files[ $artifact_directory ] = self::read_artifact_files( $directory . '/' . $artifact_directory );
		}

		return $artifact_files;
	}

	/** @return array<string,array|string> */
	private static function read_artifact_files( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$files    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$path               = $file->getPathname();
			$relative           = str_replace( '\\', '/', substr( $path, strlen( $directory ) + 1 ) );
			$contents           = self::read_file( $path );
			$files[ $relative ] = str_ends_with( $relative, '.json' ) ? BundleSchema::decode_json( $contents, $relative ) : $contents;
		}
		ksort( $files, SORT_STRING );

		return $files;
	}

	/** @return array */
	private static function read_documents( string $directory, string $document_class, string $bundle_root ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$documents = array();
		$paths     = glob( $directory . '/*.json' );
		$paths     = is_array( $paths ) ? $paths : array();
		sort( $paths, SORT_STRING );
		foreach ( $paths as $path ) {
			$document    = BundleSchema::decode_json( self::read_file( $path ), basename( $path ) );
			$document    = self::resolve_prompt_file_fields( $document, $document_class, $bundle_root, basename( $path ) );
			$documents[] = $document_class::from_array( $document );
		}

		return self::sort_documents_by_slug( $documents, $document_class );
	}

	private static function resolve_prompt_file_fields( array $document, string $document_class, string $bundle_root, string $label ): array {
		if ( empty( $document['steps'] ) || ! is_array( $document['steps'] ) ) {
			return $document;
		}

		foreach ( $document['steps'] as $step_index => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			if ( AgentBundlePipelineFile::class === $document_class ) {
				$step_config = is_array( $step['step_config'] ?? null ) ? $step['step_config'] : array();
				if ( array_key_exists( 'system_prompt_file', $step_config ) ) {
					$step_config['system_prompt'] = self::read_bundle_prompt_file( (string) $step_config['system_prompt_file'], $bundle_root, $label );
					unset( $step_config['system_prompt_file'] );
					$document['steps'][ $step_index ]['step_config'] = $step_config;
				}
				continue;
			}

			if ( AgentBundleFlowFile::class !== $document_class || empty( $step['prompt_queue'] ) || ! is_array( $step['prompt_queue'] ) ) {
				continue;
			}

			foreach ( $step['prompt_queue'] as $queue_index => $entry ) {
				if ( ! is_array( $entry ) || ! array_key_exists( 'prompt_file', $entry ) ) {
					continue;
				}
				$entry['prompt'] = self::read_bundle_prompt_file( (string) $entry['prompt_file'], $bundle_root, $label );
				unset( $entry['prompt_file'] );
				$document['steps'][ $step_index ]['prompt_queue'][ $queue_index ] = $entry;
			}
		}

		return $document;
	}

	private static function read_bundle_prompt_file( string $relative_path, string $bundle_root, string $label ): string {
		$relative_path = self::normalize_relative_path( $relative_path, $label . ' prompt' );
		$bundle_real   = realpath( $bundle_root );
		$path          = $bundle_root . '/' . $relative_path;
		$file_real     = realpath( $path );

		if ( false === $bundle_real || false === $file_real || ! str_starts_with( $file_real, $bundle_real . DIRECTORY_SEPARATOR ) || ! is_file( $file_real ) ) {
			throw new BundleValidationException( sprintf( '%s references missing prompt file: %s', esc_html( $label ), esc_html( $relative_path ) ) );
		}

		return self::read_file( $file_real );
	}

	/**
	 * Read opaque extras directories at the bundle root.
	 *
	 * Skips files at the root, reserved trees, hidden entries (names starting
	 * with `.`), symlinks that escape the bundle root, binary files, and
	 * unreadable files. Empty extras directories are dropped.
	 *
	 * @param string $bundle_root Bundle root directory (no trailing slash).
	 * @return array<string,array<string,string>> Map of extras key => path => contents.
	 */
	private static function read_extras( string $bundle_root ): array {
		if ( ! is_dir( $bundle_root ) ) {
			return array();
		}

		$bundle_real = realpath( $bundle_root );
		if ( false === $bundle_real ) {
			return array();
		}

		$extras = array();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- scandir() warns on unreadable directory; we treat that as "no extras" rather than fatal.
		$entries = @scandir( $bundle_root );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		sort( $entries, SORT_STRING );

		foreach ( $entries as $entry ) {
			if ( '' === $entry || '.' === $entry[0] ) {
				continue;
			}
			if ( in_array( $entry, BundleSchema::RESERVED_ROOT_ENTRIES, true ) ) {
				continue;
			}
			$path = $bundle_root . '/' . $entry;
			if ( ! is_dir( $path ) ) {
				// Files at the root are out of scope; only directories become extras.
				continue;
			}

			$files = self::collect_extras_files( $path, $bundle_real, $entry );
			if ( array() === $files ) {
				continue;
			}

			ksort( $files, SORT_STRING );
			$extras[ $entry ] = $files;
		}

		ksort( $extras, SORT_STRING );
		return $extras;
	}

	/**
	 * Recursively collect text files from an extras directory.
	 *
	 * @param string $directory   Extras directory path.
	 * @param string $bundle_real Realpath of the bundle root for symlink containment checks.
	 * @param string $extras_key  Top-level extras directory name (used as path prefix).
	 * @return array<string,string> Relative path (`<extras_key>/...`) => file contents.
	 */
	private static function collect_extras_files( string $directory, string $bundle_real, string $extras_key ): array {
		$collected = array();
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS )
			);
		} catch ( \UnexpectedValueException $e ) {
			return array();
		}

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$basename = $file->getFilename();
			if ( '' === $basename || '.' === $basename[0] ) {
				continue;
			}

			$path = $file->getPathname();
			if ( $file->isLink() ) {
				$target = realpath( $path );
				if ( false === $target || ! str_starts_with( $target, $bundle_real . DIRECTORY_SEPARATOR ) ) {
					self::log_extras_warning( 'symlink target escapes bundle root', $path );
					continue;
				}
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unreadable files are skipped with a logged warning; bundle extras are loaded outside the WordPress filesystem abstraction.
			$contents = @file_get_contents( $path );
			if ( false === $contents ) {
				self::log_extras_warning( 'extras file unreadable', $path );
				continue;
			}
			if ( ! self::is_text_payload( $contents ) ) {
				self::log_extras_warning( 'extras binary file skipped', $path );
				continue;
			}

			$relative = str_replace( '\\', '/', substr( $path, strlen( $directory ) + 1 ) );
			if ( '' === $relative ) {
				continue;
			}

			$collected[ $extras_key . '/' . $relative ] = $contents;
		}

		return $collected;
	}

	private static function is_text_payload( string $contents ): bool {
		if ( '' === $contents ) {
			return true;
		}
		// NUL byte is the canonical "this is binary" signal.
		if ( false !== strpos( $contents, "\0" ) ) {
			return false;
		}
		return true;
	}

	private static function log_extras_warning( string $reason, string $path ): void {
		if ( \function_exists( 'do_action' ) ) {
			\do_action(
				'datamachine_log',
				'warning',
				'AgentBundleDirectory: skipping extras file',
				array(
					'reason' => $reason,
					'path'   => $path,
				)
			);
		}
	}

	/** @return array<int,array<string,mixed>> */
	private static function read_extension_artifacts( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$artifacts = array();
		$iterator  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'json' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$artifact = BundleSchema::decode_json( self::read_file( $file->getPathname() ), $file->getFilename() );
			if ( ! isset( $artifact['source_path'] ) ) {
				$artifact['source_path'] = BundleSchema::EXTENSIONS_DIR . '/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $directory ) + 1 ) );
			}
			$artifacts[] = $artifact;
		}

		return AgentBundleArtifactExtensions::normalize_artifacts( $artifacts );
	}

	/** @return array */
	private static function sort_documents_by_slug( array $documents, string $document_class ): array {
		foreach ( $documents as $document ) {
			if ( ! $document instanceof $document_class ) {
				throw new BundleValidationException( sprintf( 'Expected %s document.', esc_html( $document_class ) ) );
			}
		}
		usort( $documents, fn( $a, $b ) => strcmp( $a->slug(), $b->slug() ) );
		return $documents;
	}

	private static function normalize_relative_path( string $relative_path, string $label ): string {
		$relative_path = str_replace( '\\', '/', $relative_path );
		$relative_path = ltrim( $relative_path, '/' );
		if ( str_contains( $relative_path, '..' ) || '' === $relative_path ) {
			throw new BundleValidationException( sprintf( 'Invalid %s file path: %s', esc_html( $label ), esc_html( $relative_path ) ) );
		}

		return $relative_path;
	}

	private static function read_file( string $path ): string {
		$contents = file_get_contents( $path );
		return is_string( $contents ) ? $contents : '';
	}

	private static function write_file( string $path, string $contents ): void {
		file_put_contents( $path, $contents );
	}
}
