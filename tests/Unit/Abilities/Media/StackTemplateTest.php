<?php
/**
 * Pixel renders and measured layout coverage for the generic stack template.
 *
 * @package DataMachine\Tests
 */
namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/' ); }
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value, ...$args ) {
			if ( 'datamachine/image_generation/qr_image_path' === $tag && isset( $GLOBALS['dm_stack_qr'] ) ) {
				return ( $GLOBALS['dm_stack_qr'] )( $value, ...$args );
			}
			return $value;
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( ...$args ) {}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			return $default; }
	}
	if ( ! function_exists( 'get_template_directory' ) ) {
		function get_template_directory() {
			return '/tmp/no-theme'; }
	}
	if ( ! function_exists( 'wp_delete_file' ) ) {
		function wp_delete_file( $path ) {
			if ( is_file( $path ) ) {
				unlink( $path ); } }
	}
}

namespace DataMachine\Tests\Unit\Abilities\Media {
	use DataMachine\Abilities\Media\GDRenderer;
	use DataMachine\Abilities\Media\PlatformPresets;
	use DataMachine\Abilities\Media\Templates\StackTemplate;
	use PHPUnit\Framework\TestCase;

	class StackTemplateTest extends TestCase {
		private array $files = array();
		private $provider;

		protected function setUp(): void {
			if ( ! extension_loaded( 'gd' ) ) {
				$this->markTestSkipped( 'GD required.' ); }
			$this->provider = static function ( $path, $url, $size ) {
				return isset( $GLOBALS['dm_stack_qr'] ) ? ( $GLOBALS['dm_stack_qr'] )( $path, $url, $size ) : $path;
			};
			if ( function_exists( 'add_filter' ) ) {
				add_filter( 'datamachine/image_generation/qr_image_path', $this->provider, 10, 3 );
			}
		}

		protected function tearDown(): void {
			unset( $GLOBALS['dm_stack_qr'] );
			if ( function_exists( 'remove_filter' ) ) {
				remove_filter( 'datamachine/image_generation/qr_image_path', $this->provider, 10 );
			}
			foreach ( $this->files as $path ) {
				if ( is_file( $path ) ) {
					unlink( $path ); }
			}
		}

		private function image(): string {
			$path  = tempnam( sys_get_temp_dir(), 'stack-test-' );
			$image = imagecreatetruecolor( 120, 40 );
			imagefill( $image, 0, 0, imagecolorallocate( $image, 180, 20, 30 ) );
			imagepng( $image, $path );
			imagedestroy( $image );
			$this->files[] = $path;
			return $path;
		}

		public function test_core_registration_and_field_definitions(): void {
			$templates = \DataMachine\Core\Bootstrap\HostIntegrationServiceProvider::register_image_templates( array() );
			$this->assertSame( StackTemplate::class, $templates['stack'] );
			$override = \DataMachine\Core\Bootstrap\HostIntegrationServiceProvider::register_image_templates( array( 'stack' => 'CustomTemplate' ) );
			$this->assertSame( 'CustomTemplate', $override['stack'] );
			$this->assertTrue( ( new StackTemplate() )->get_fields()['blocks']['required'] );
		}

		public function test_order_and_fit_width(): void {
			$r     = ( new GDRenderer() )->create_canvas( 1080, 1350 );
			$t     = new StackTemplate();
			$boxes = $t->plan_layout(
				$r,
				array(
					'blocks' => array(
						array(
							'type' => 'text',
							'text' => 'A headline far too wide at its original size',
							'size' => 250,
							'fit'  => true,
							'wrap' => false,
						),
						array(
							'type'       => 'divider',
							'gap_before' => 20,
						),
						array(
							'type' => 'text',
							'text' => 'Footer',
						),
					),
				)
			);
			$this->assertLessThan( $boxes[1]['y'], $boxes[0]['y'] );
			$this->assertLessThan( $boxes[2]['y'], $boxes[1]['y'] );
			$this->assertLessThanOrEqual( $boxes[0]['width'], $r->measure_text_width( $boxes[0]['text'], $boxes[0]['size'], $boxes[0]['font'] ) );
			$this->assertSame( array(), $t->get_warnings() );
		}

		public function test_overflow_shrinks_text_and_lists_before_warning(): void {
			$r     = ( new GDRenderer() )->create_canvas( 1080, 600 );
			$t     = new StackTemplate();
			$data  = array(
				'blocks' => array(
					array(
						'type' => 'text',
						'text' => 'Headline',
						'size' => 180,
						'fit'  => true,
					),
					array(
						'type'  => 'list',
						'items' => array_fill( 0, 8, 'Read this step' ),
						'size'  => 90,
					),
				),
			);
			$boxes = $t->plan_layout( $r, $data );
			$this->assertLessThan( 180, $boxes[0]['size'] );
			$this->assertLessThan( 90, $boxes[1]['size'] );
			$this->assertSame( array(), $t->get_warnings() );
			$this->assertLessThanOrEqual( 536, $boxes[1]['y'] + $boxes[1]['height'] );
			$data['blocks'][] = array(
				'type'   => 'spacer',
				'height' => 1000,
			);
			$boxes            = $t->plan_layout( $r, $data );
			$this->assertCount( 3, $boxes );
			$this->assertSame( 1, $boxes[0]['size'] );
			$this->assertSame( 1, $boxes[1]['size'] );
			$this->assertArrayHasKey( 'overflow', $t->get_warnings() );
			$this->assertSame( array(), $t->get_dropped_fields() );
		}

		public function test_missing_image_is_reported(): void {
			$t     = new StackTemplate();
			$boxes = $t->plan_layout(
				( new GDRenderer() )->create_canvas( 1080, 1350 ),
				array(
					'blocks' => array(
						array(
							'type' => 'image',
							'path' => '/missing/asset.png',
						),
					),
				)
			);
			$this->assertSame( 0, $boxes[0]['height'] );
			$this->assertSame( array( 'blocks.0' ), $t->get_dropped_fields() );
			$this->assertStringContainsString( 'unreadable', $t->get_warnings()['blocks.0'] );
		}

		public function test_flex_spacer_and_row_centering(): void {
			$t     = new StackTemplate();
			$r     = ( new GDRenderer() )->create_canvas( 1080, 1350 );
			$boxes = $t->plan_layout(
				$r,
				array(
					'align'  => 'left',
					'blocks' => array(
						array(
							'type'  => 'row',
							'items' => array(
								array(
									'type' => 'text',
									'text' => 'Presented by',
								),
								array(
									'type'  => 'image',
									'path'  => $this->image(),
									'width' => 120,
								),
							),
						),
						array(
							'type' => 'spacer',
							'flex' => true,
						),
						array( 'type' => 'divider' ),
					),
				)
			);
			$this->assertGreaterThan( 1000, $boxes[1]['height'] );
			$this->assertSame( 64, $boxes[0]['x'] );
			$this->assertSame( 1286, $boxes[2]['y'] + $boxes[2]['height'] );
			$this->assertCount( 2, $boxes[0]['children'] );
		}

		public function test_all_presets_render_and_missing_qr_warns(): void {
			foreach ( PlatformPresets::names() as $preset ) {
				$t = new StackTemplate();
				$r = new GDRenderer();
				$paths = $t->render( array( 'background' => '#123', 'blocks' => array( array( 'type' => 'text', 'text' => 'Hello', 'fit' => true ) ) ), $r, array( 'preset' => $preset ) );
				$this->files = array_merge( $this->files, $paths );
				$this->assertCount( 1, $paths, $preset );
				$pixel = imagecolorat( $r->get_image(), 0, 0 );
				$this->assertSame( 0x112233, $pixel & 0xffffff );
			}
			$t = new StackTemplate();
			$GLOBALS['dm_stack_qr'] = static fn() => null;
			$paths = $t->render( array( 'blocks' => array( array( 'type' => 'qr', 'url' => 'https://example.org', 'size' => 1 ) ) ), new GDRenderer() );
			$this->files = array_merge( $this->files, $paths );
			$this->assertSame( array( 'blocks.0' ), $t->get_dropped_fields() );
			$this->assertStringContainsString( 'QR provider unavailable', $t->get_warnings()['blocks.0'] );
		}

		public function test_qr_card_stays_inside_content_width(): void {
			$t = new StackTemplate();
			$boxes = $t->plan_layout( ( new GDRenderer() )->create_canvas( 1080, 1350 ), array( 'blocks' => array( array( 'type' => 'qr', 'size' => 1, 'url' => 'https://example.org' ) ) ) );
			$this->assertLessThanOrEqual( 952, $boxes[0]['width'] );
			$this->assertGreaterThanOrEqual( 64, $boxes[0]['x'] );
		}

		public function test_representative_eight_block_render_on_print_and_feed(): void {
			$requested              = array();
			$GLOBALS['dm_stack_qr'] = function ( $path, $url, $size ) use ( &$requested ) {
				$requested[] = $size;
				return $this->image();
			};
			$data                   = array(
				'blocks' => array(
					array(
						'type' => 'text',
						'text' => 'Join the community',
						'font' => 'heading',
						'size' => 64,
						'fit'  => true,
					),
					array(
						'type' => 'text',
						'text' => 'Scan to get started',
						'size' => 30,
					),
					array(
						'type' => 'qr',
						'url'  => 'https://example.org/join',
						'size' => .4,
					),
					array(
						'type'       => 'image',
						'path'       => $this->image(),
						'width'      => .6,
						'max_height' => 120,
					),
					array(
						'type' => 'text',
						'text' => 'A shared project',
						'size' => 48,
						'fit'  => true,
					),
					array(
						'type'  => 'row',
						'items' => array(
							array(
								'type' => 'text',
								'text' => 'Presented by',
							),
							array(
								'type'  => 'image',
								'path'  => $this->image(),
								'width' => 150,
							),
						),
					),
					array(
						'type'  => 'list',
						'items' => array( 'Scan the code', 'Choose a project', 'Share with friends' ),
						'size'  => 34,
					),
					array(
						'type' => 'text',
						'text' => 'Every Saturday at noon',
						'size' => 28,
					),
				),
			);
			foreach ( array( 'print_pocket_flyer', 'instagram_feed_portrait' ) as $preset ) {
				$t           = new StackTemplate();
				$r           = ( new GDRenderer() )->create_canvas( $preset );
				$boxes       = $t->plan_layout( $r, $data );
				$paths       = $t->render( $data, $r, array( 'preset' => $preset ) );
				$this->files = array_merge( $this->files, $paths );
				$this->assertCount( 1, $paths );
				$this->assertSame( $boxes[2]['size'], end( $requested ) );
				$this->assertSame( array(), $t->get_warnings() );
				$info = getimagesize( $paths[0] );
				$this->assertSame( $r->get_width(), $info[0] );
				$this->assertSame( $r->get_height(), $info[1] );
				foreach ( $boxes as $i => $box ) {
					if ( $i ) {
						$this->assertGreaterThanOrEqual( $boxes[ $i - 1 ]['y'] + $boxes[ $i - 1 ]['height'], $box['y'] ); }
				}
			}
		}
	}
}
