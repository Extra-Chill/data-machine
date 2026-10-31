<?php
/**
 * RedditCommand Tests
 *
 * @package DataMachineSocials\Tests\Unit\Cli\Commands
 */

namespace DataMachineSocials\Tests\Unit\Cli\Commands;

use DataMachineSocials\Cli\Commands\RedditCommand;
use WP_UnitTestCase;

class RedditCommandTest extends WP_UnitTestCase {

	public function test_fetch_limit_maps_to_ability_max_items(): void {
		$input = $this->applyFetchLimit( array( 'limit' => '25' ) );

		$this->assertSame( 25, $input['max_items'] );
	}

	public function test_omitted_fetch_limit_preserves_unbounded_ability_input(): void {
		$input = $this->applyFetchLimit();

		$this->assertArrayNotHasKey( 'max_items', $input );
	}

	/**
	 * @dataProvider invalidFetchLimitProvider
	 */
	public function test_fetch_limit_rejects_invalid_values( mixed $limit ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '--limit must be a whole number between 1 and 500.' );

		$this->applyFetchLimit( array( 'limit' => $limit ) );
	}

	public function invalidFetchLimitProvider(): array {
		return array(
			'zero'         => array( '0' ),
			'negative'     => array( '-1' ),
			'decimal'      => array( '1.5' ),
			'malformed'    => array( 'ten' ),
			'unreasonable' => array( '501' ),
		);
	}

	private function applyFetchLimit( array $assoc_args = array() ): array {
		$method = new \ReflectionMethod( RedditCommand::class, 'applyFetchLimit' );

		return $method->invoke( new RedditCommand(), array(), $assoc_args );
	}
}
