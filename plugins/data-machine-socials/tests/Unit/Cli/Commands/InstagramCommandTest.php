<?php
/**
 * Publish command synopsis regression tests (#299).
 *
 * @package DataMachineSocials\Tests\Unit\Cli\Commands
 */

namespace DataMachineSocials\Tests\Unit\Cli\Commands;

use DataMachineSocials\Cli\Commands\InstagramCommand;
use WP_UnitTestCase;

class InstagramCommandTest extends WP_UnitTestCase {

	public function test_publish_image_synopsis_is_repeatable(): void {
		$method = new \ReflectionMethod( InstagramCommand::class, 'publish' );
		$this->assertStringContainsString( '[--image=<url>...]', $method->getDocComment() );
	}

	public function test_all_publish_media_flags_documented_as_repeatable_have_ellipsis(): void {
		$files = glob( dirname( __DIR__, 4 ) . '/inc/Cli/Commands/*Command.php' );
		$this->assertNotEmpty( $files );
		$audited = 0;
		// Tokenize rather than load every platform command and its dependencies.
		foreach ( $files as $file ) {
			$doc = '';
			$is_function = false;
			foreach ( token_get_all( file_get_contents( $file ) ) as $token ) {
				if ( ! is_array( $token ) ) {
					continue;
				}
				if ( T_DOC_COMMENT === $token[0] ) {
					$doc = $token[1];
				}
				if ( T_FUNCTION === $token[0] ) {
					$is_function = true;
				} elseif ( $is_function && T_STRING === $token[0] ) {
					$is_function = false;
					if ( ! str_starts_with( $token[1], 'publish' ) ) {
						continue;
					}
					preg_match_all( '/\[--(?:image|video|media)[\w-]*=<[^>]+>([^\]]*)\]\s*\*\s*:\s*([^\n]+)/', $doc, $flags, PREG_SET_ORDER );
					foreach ( $flags as $flag ) {
						if ( preg_match( '/repeat|multiple times/i', $flag[2] ) ) {
							++$audited;
							$this->assertSame( '...', $flag[1], basename( $file ) . '::' . $token[1] . ' repeatable media flag needs an ellipsis: ' . $flag[0] );
						}
					}
				}
			}
		}
		$this->assertGreaterThan( 0, $audited, 'The audit must discover repeatable publish media flags.' );
	}
}
