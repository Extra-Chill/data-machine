<?php
/**
 * Regression coverage for #4043: WpAiClientCache must never redeclare its
 * cache classes.
 *
 * During an atomic symlink-swap deploy the same plugin file can be reachable
 * through two real paths in a single request (old plugin dir vs. new release
 * dir). `require_once` dedupes by real path, so hand-including autoloaded
 * cache classes re-included them and fataled with "Cannot redeclare class
 * DataMachine\Engine\AI\ScopedWpAiClientTransientCache". The fix relies on the
 * Composer PSR-4 autoloader instead.
 *
 * All cases run in-process: the WordPress test runtime does not reliably
 * expose the plugin filesystem to child PHP processes.
 *
 * @package DataMachine\Tests\Unit\Engine\AI
 */

namespace DataMachine\Tests\Unit\Engine\AI;

use DataMachine\Engine\AI\WpAiClientCache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DataMachine\Engine\AI\WpAiClientCache
 */
class WpAiClientCacheRedeclareTest extends TestCase {

	private const SCOPED_INTERFACE = '\WordPress\AiClientDependencies\Psr\SimpleCache\CacheInterface';
	private const PSR_INTERFACE    = '\Psr\SimpleCache\CacheInterface';
	private const SCOPED_CLASS     = 'DataMachine\\Engine\\AI\\ScopedWpAiClientTransientCache';
	private const PSR_CLASS        = 'DataMachine\\Engine\\AI\\PsrWpAiClientTransientCache';

	/**
	 * Temporary directories created by the test.
	 *
	 * @var string[]
	 */
	private array $temp_dirs = array();

	protected function tearDown(): void {
		foreach ( $this->temp_dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		$this->temp_dirs = array();
		parent::tearDown();
	}

	public function test_source_does_not_hand_include_autoloaded_cache_classes(): void {
		$source = file_get_contents( $this->ai_dir() . '/WpAiClientCache.php' );

		$this->assertIsString( $source );
		$this->assertDoesNotMatchRegularExpression( '/^\s*(require|include)(_once)?[\s(]/m', $source );
	}

	public function test_cache_classes_are_covered_by_composer_psr4_autoload(): void {
		$composer = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/composer.json' ), true );

		$this->assertIsArray( $composer );
		$this->assertSame( 'inc/Engine/', $composer['autoload']['psr-4']['DataMachine\\Engine\\'] ?? null );

		foreach ( array( 'WpAiClientTransientCacheBase', 'ScopedWpAiClientTransientCache', 'PsrWpAiClientTransientCache' ) as $class ) {
			$this->assertFileExists( $this->ai_dir() . '/' . $class . '.php' );
		}
	}

	public function test_cache_class_matches_available_psr_interface_and_is_idempotent(): void {
		$first  = $this->invoke_cache_class( WpAiClientCache::class );
		$second = $this->invoke_cache_class( WpAiClientCache::class );

		$this->assertSame( $first, $second );
		$this->assertSame( $this->expected_cache_class(), $first );

		if ( null !== $first ) {
			$this->assertTrue( class_exists( $first, false ), 'Cache class should be declared via the autoloader.' );
		}
	}

	public function test_repeated_install_is_safe(): void {
		WpAiClientCache::install();
		WpAiClientCache::install();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Simulate the deploy race: cache classes are already declared from the
	 * "old" plugin directory, then the installer is loaded from a "new"
	 * release directory that contains its own copies of the cache class files.
	 * A hand-included `require_once __DIR__ . '/...'` would re-include those
	 * copies from the new real path and fatal with "Cannot redeclare class".
	 */
	public function test_cache_class_is_safe_when_cache_classes_already_declared_from_other_path(): void {
		// Declare the cache classes from the original path first (old dir).
		$expected = $this->invoke_cache_class( WpAiClientCache::class );
		if ( null !== $expected ) {
			$this->assertTrue( class_exists( $expected, false ) );
		}

		$release_dir = $this->make_temp_dir();
		foreach ( array( 'WpAiClientTransientCacheBase', 'ScopedWpAiClientTransientCache', 'PsrWpAiClientTransientCache' ) as $class ) {
			copy( $this->ai_dir() . '/' . $class . '.php', $release_dir . '/' . $class . '.php' );
		}

		// Load a copy of the installer from the new release path. Rename the
		// installer class itself so only the cache-class resolution is tested.
		$copy_class = 'WpAiClientCacheReleaseCopy' . bin2hex( random_bytes( 4 ) );
		$source     = (string) file_get_contents( $this->ai_dir() . '/WpAiClientCache.php' );
		$source     = preg_replace( '/\bclass\s+WpAiClientCache\b/', 'class ' . $copy_class, $source, 1 );
		$this->assertIsString( $source );
		file_put_contents( $release_dir . '/WpAiClientCache.php', $source );

		require $release_dir . '/WpAiClientCache.php';

		$fqcn = 'DataMachine\\Engine\\AI\\' . $copy_class;
		$this->assertTrue( class_exists( $fqcn, false ) );

		$this->assertSame( $expected, $this->invoke_cache_class( $fqcn ) );
		$this->assertSame( $expected, $this->invoke_cache_class( $fqcn ) );

		$fqcn::install();
		$fqcn::install();

		// No cache class file was included from the release directory.
		foreach ( get_included_files() as $file ) {
			if ( 0 === strpos( $file, $release_dir ) ) {
				$this->assertStringEndsWith( '/WpAiClientCache.php', $file );
			}
		}
	}

	private function expected_cache_class(): ?string {
		if ( interface_exists( self::SCOPED_INTERFACE ) && class_exists( self::SCOPED_CLASS ) ) {
			return self::SCOPED_CLASS;
		}
		if ( interface_exists( self::PSR_INTERFACE ) && class_exists( self::PSR_CLASS ) ) {
			return self::PSR_CLASS;
		}
		return null;
	}

	private function invoke_cache_class( string $class_name ): ?string {
		$method = new \ReflectionMethod( $class_name, 'cache_class' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	private function ai_dir(): string {
		return dirname( __DIR__, 4 ) . '/inc/Engine/AI';
	}

	private function make_temp_dir(): string {
		$dir = sys_get_temp_dir() . '/dm-wpai-cache-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0777, true );
		$this->temp_dirs[] = $dir;
		return $dir;
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->remove_dir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
