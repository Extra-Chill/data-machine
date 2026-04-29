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

	/**
	 * @param AgentBundleManifest       $manifest Bundle manifest.
	 * @param array<string,string>      $memory_files Relative memory path => contents.
	 * @param AgentBundlePipelineFile[] $pipelines Pipeline files.
	 * @param AgentBundleFlowFile[]     $flows Flow files.
	 * @param array<string,array<string,array|string>> $artifact_files Artifact directory => relative path => decoded JSON payload or text contents.
	 * @param array<int,array<string,mixed>> $extension_artifacts Plugin-owned artifact envelopes.
	 */
	public function __construct( AgentBundleManifest $manifest, array $memory_files, array $pipelines, array $flows, array $artifact_files = array(), array $extension_artifacts = array() ) {
		$this->manifest            = $manifest;
		$this->memory_files        = self::normalize_memory_files( $memory_files );
		$this->pipelines           = self::sort_documents_by_slug( $pipelines, AgentBundlePipelineFile::class );
		$this->flows               = self::sort_documents_by_slug( $flows, AgentBundleFlowFile::class );
		$this->artifact_files      = self::normalize_artifact_files( $artifact_files );
		$this->extension_artifacts = AgentBundleArtifactExtensions::normalize_artifacts( $extension_artifacts );
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
			self::read_documents( $directory . '/' . BundleSchema::PIPELINES_DIR, AgentBundlePipelineFile::class ),
			self::read_documents( $directory . '/' . BundleSchema::FLOWS_DIR, AgentBundleFlowFile::class ),
			self::read_artifact_directories( $directory ),
			self::read_extension_artifacts( $directory . '/' . BundleSchema::EXTENSIONS_DIR )
		);
	}

	public function write( string $directory ): void {
		$directory = rtrim( $directory, '/\\' );
		self::ensure_directory( $directory );
		self::ensure_directory( $directory . '/' . BundleSchema::MEMORY_DIR );
		self::ensure_directory( $directory . '/' . BundleSchema::PIPELINES_DIR );
		self::ensure_directory( $directory . '/' . BundleSchema::FLOWS_DIR );
		foreach ( self::artifact_directories() as $artifact_directory ) {
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
		$normalized = array_fill_keys( self::artifact_directories(), array() );
		foreach ( $artifact_files as $artifact_directory => $files ) {
			$artifact_directory = (string) $artifact_directory;
			if ( ! in_array( $artifact_directory, self::artifact_directories(), true ) ) {
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
		foreach ( self::artifact_directories() as $artifact_directory ) {
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
	private static function read_documents( string $directory, string $document_class ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$documents = array();
		$paths     = glob( $directory . '/*.json' );
		$paths     = is_array( $paths ) ? $paths : array();
		sort( $paths, SORT_STRING );
		foreach ( $paths as $path ) {
			$documents[] = $document_class::from_array( BundleSchema::decode_json( self::read_file( $path ), basename( $path ) ) );
		}

		return self::sort_documents_by_slug( $documents, $document_class );
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

	/** @return string[] */
	private static function artifact_directories(): array {
		return array(
			BundleSchema::PROMPTS_DIR,
			BundleSchema::RUBRICS_DIR,
			BundleSchema::TOOL_POLICIES_DIR,
			BundleSchema::AUTH_REFS_DIR,
			BundleSchema::SEED_QUEUES_DIR,
		);
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
