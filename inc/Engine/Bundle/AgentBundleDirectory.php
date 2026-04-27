<?php
/**
 * Agent bundle directory reader/writer.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

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

	/**
	 * @param AgentBundleManifest       $manifest Bundle manifest.
	 * @param array<string,string>      $memory_files Relative memory path => contents.
	 * @param AgentBundlePipelineFile[] $pipelines Pipeline files.
	 * @param AgentBundleFlowFile[]     $flows Flow files.
	 */
	public function __construct( AgentBundleManifest $manifest, array $memory_files, array $pipelines, array $flows ) {
		$this->manifest     = $manifest;
		$this->memory_files = self::normalize_memory_files( $memory_files );
		$this->pipelines    = self::sort_documents_by_slug( $pipelines, AgentBundlePipelineFile::class );
		$this->flows        = self::sort_documents_by_slug( $flows, AgentBundleFlowFile::class );
	}

	public static function read( string $directory ): self {
		$directory = rtrim( $directory, '/\\' );
		if ( ! is_dir( $directory ) ) {
			throw new BundleValidationException( "Bundle directory does not exist: {$directory}" );
		}

		$manifest_path = $directory . '/' . BundleSchema::MANIFEST_FILE;
		if ( ! is_file( $manifest_path ) ) {
			throw new BundleValidationException( 'Bundle directory is missing manifest.json.' );
		}

		$manifest = AgentBundleManifest::from_array(
			BundleSchema::decode_json( (string) file_get_contents( $manifest_path ), 'manifest.json' )
		);

		return new self(
			$manifest,
			self::read_memory_files( $directory . '/' . BundleSchema::MEMORY_DIR ),
			self::read_documents( $directory . '/' . BundleSchema::PIPELINES_DIR, AgentBundlePipelineFile::class ),
			self::read_documents( $directory . '/' . BundleSchema::FLOWS_DIR, AgentBundleFlowFile::class )
		);
	}

	public function write( string $directory ): void {
		$directory = rtrim( $directory, '/\\' );
		self::ensure_directory( $directory );
		self::ensure_directory( $directory . '/' . BundleSchema::MEMORY_DIR );
		self::ensure_directory( $directory . '/' . BundleSchema::PIPELINES_DIR );
		self::ensure_directory( $directory . '/' . BundleSchema::FLOWS_DIR );

		file_put_contents( $directory . '/' . BundleSchema::MANIFEST_FILE, BundleSchema::encode_json( $this->manifest->to_array() ) );

		foreach ( $this->memory_files as $relative_path => $contents ) {
			$path = $directory . '/' . BundleSchema::MEMORY_DIR . '/' . $relative_path;
			self::ensure_directory( dirname( $path ) );
			file_put_contents( $path, $contents );
		}

		foreach ( $this->pipelines as $pipeline ) {
			file_put_contents( $directory . '/' . BundleSchema::PIPELINES_DIR . '/' . $pipeline->slug() . '.json', BundleSchema::encode_json( $pipeline->to_array() ) );
		}

		foreach ( $this->flows as $flow ) {
			file_put_contents( $directory . '/' . BundleSchema::FLOWS_DIR . '/' . $flow->slug() . '.json', BundleSchema::encode_json( $flow->to_array() ) );
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

	private static function ensure_directory( string $directory ): void {
		if ( is_dir( $directory ) ) {
			return;
		}
		if ( ! mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
			throw new BundleValidationException( "Unable to create bundle directory: {$directory}" );
		}
	}

	/** @return array<string,string> */
	private static function normalize_memory_files( array $memory_files ): array {
		$normalized = array();
		foreach ( $memory_files as $relative_path => $contents ) {
			$relative_path = str_replace( '\\', '/', (string) $relative_path );
			$relative_path = ltrim( $relative_path, '/' );
			if ( str_contains( $relative_path, '..' ) || '' === $relative_path ) {
				throw new BundleValidationException( "Invalid memory file path: {$relative_path}" );
			}
			$normalized[ $relative_path ] = (string) $contents;
		}
		ksort( $normalized, SORT_STRING );
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
			$path             = $file->getPathname();
			$relative         = str_replace( '\\', '/', substr( $path, strlen( $directory ) + 1 ) );
			$files[ $relative ] = (string) file_get_contents( $path );
		}
		ksort( $files, SORT_STRING );
		return $files;
	}

	/** @return array */
	private static function read_documents( string $directory, string $class ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$documents = array();
		$paths     = glob( $directory . '/*.json' ) ?: array();
		sort( $paths, SORT_STRING );
		foreach ( $paths as $path ) {
			$documents[] = $class::from_array( BundleSchema::decode_json( (string) file_get_contents( $path ), basename( $path ) ) );
		}

		return self::sort_documents_by_slug( $documents, $class );
	}

	/** @return array */
	private static function sort_documents_by_slug( array $documents, string $class ): array {
		foreach ( $documents as $document ) {
			if ( ! $document instanceof $class ) {
				throw new BundleValidationException( "Expected {$class} document." );
			}
		}
		usort( $documents, fn( $a, $b ) => strcmp( $a->slug(), $b->slug() ) );
		return $documents;
	}
}
