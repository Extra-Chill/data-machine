<?php
/**
 * Standalone output-resolution coverage for image rendering.
 *
 * @package DataMachine\Tests
 */
namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/' );
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value, ...$args ) {
			return $value;
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( ...$args ) {}
	}
	if ( ! function_exists( 'wp_delete_file' ) ) {
		function wp_delete_file( $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code, private string $message = '', private array $data = array() ) {}
			public function get_error_code(): string {
				return $this->code;
			}
		}
	}
}

namespace DataMachine\Tests\Unit\Abilities\Media {
	use DataMachine\Abilities\Media\GDRenderer;
	use DataMachine\Abilities\Media\ImageTemplateAbilities;
	use DataMachine\Abilities\Media\PlatformPresets;
	use DataMachine\Abilities\Media\TemplateInterface;
	use DataMachine\Abilities\Media\TemplateRegistry;
	use PHPUnit\Framework\TestCase;

	class ImageRenderDpiTest extends TestCase {
		private array $files = array();

		protected function setUp(): void {
			if ( ! extension_loaded( 'gd' ) ) {
				$this->markTestSkipped( 'GD required.' );
			}
		}

		protected function tearDown(): void {
			foreach ( $this->files as $path ) {
				wp_delete_file( $path );
			}
			TemplateRegistry::clear_cache();
		}

		private function reload_resolution( GDRenderer $renderer, string $format ): array {
			$path = $renderer->save_temp( $format );
			$this->assertNotNull( $path );
			$this->files[] = $path;
			$image = 'jpeg' === $format ? imagecreatefromjpeg( $path ) : imagecreatefrompng( $path );
			$this->assertSame( $renderer->get_width(), imagesx( $image ) );
			$this->assertSame( $renderer->get_height(), imagesy( $image ) );
			return imageresolution( $image );
		}

		public function test_print_presets_embed_resolution_in_png_and_jpeg(): void {
			foreach ( array( 'print_tabloid' => 150, 'print_tabloid_300' => 300, 'print_pocket_flyer' => 300 ) as $preset => $dpi ) {
				$this->assertSame( $dpi, PlatformPresets::get( $preset )['dpi'] );
				$renderer = ( new GDRenderer() )->create_canvas( $preset );
				foreach ( array( 'png', 'jpeg' ) as $format ) {
					$this->assertSame( array( $dpi, $dpi ), $this->reload_resolution( $renderer, $format ) );
				}
			}
		}

		public function test_non_print_and_explicit_dimensions_keep_gd_default_after_canvas_reuse(): void {
			$renderer = ( new GDRenderer() )->create_canvas( 'print_tabloid' );
			$this->reload_resolution( $renderer, 'png' );
			$renderer->create_canvas( 'instagram_feed_portrait' );
			$this->assertArrayNotHasKey( 'dpi', PlatformPresets::get( 'instagram_feed_portrait' ) );
			$default = imageresolution( $renderer->get_image() );
			foreach ( array( 'png', 'jpeg' ) as $format ) {
				$this->assertSame( $default, $this->reload_resolution( $renderer, $format ) );
			}
			$renderer->create_canvas( 20, 30 );
			$this->assertSame( $default, $this->reload_resolution( $renderer, 'png' ) );
		}

		public function test_override_wins_and_survives_batch_canvas_creation(): void {
			$renderer = ( new GDRenderer() )->set_dpi( 240 );
			foreach ( array( 'print_tabloid', 'print_pocket_flyer', 'instagram_feed_portrait' ) as $preset ) {
				$renderer->create_canvas( $preset );
				foreach ( array( 'png', 'jpeg' ) as $format ) {
					$this->assertSame( array( 240, 240 ), $this->reload_resolution( $renderer, $format ) );
				}
			}
		}

		public function test_ability_rejects_invalid_dpi_before_rendering(): void {
			foreach ( array( 0, -1, 1.5, '150', 'bad', true, null, array() ) as $dpi ) {
				$result = ImageTemplateAbilities::renderTemplate( array( 'dpi' => $dpi ) );
				$this->assertInstanceOf( \WP_Error::class, $result );
				$this->assertSame( 'invalid_dpi', $result->get_error_code() );
			}
		}

		public function test_ability_applies_override_before_template_creates_canvas(): void {
			$template = $this->createMock( TemplateInterface::class );
			$template->method( 'get_fields' )->willReturn( array() );
			$template->expects( $this->once() )->method( 'render' )->willReturnCallback(
				function ( $data, GDRenderer $renderer, $options ) {
					$renderer->create_canvas( 'print_tabloid' );
					$this->assertSame( array( 200, 200 ), $this->reload_resolution( $renderer, 'png' ) );
					// Avoid storage: the callback's render is sufficient to check propagation.
					return array();
				}
			);
			$property = new \ReflectionProperty( TemplateRegistry::class, 'templates' );
			$property->setValue( null, array( 'dpi_test' => $template ) );
			$result = ImageTemplateAbilities::renderTemplate( array( 'template_id' => 'dpi_test', 'data' => array(), 'dpi' => 200 ) );
			$this->assertSame( 'template_render_empty', $result->get_error_code() );
		}
	}
}
