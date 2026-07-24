<?php
/**
 * Base extractor contract tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\BaseExtractor;
use ReflectionClass;
use WP_UnitTestCase;

class BaseExtractorTest extends WP_UnitTestCase {

	public function test_declares_extractor_interface_methods_as_abstract(): void {
		$reflection = new ReflectionClass( BaseExtractor::class );

		$this->assertTrue( $reflection->isAbstract() );

		foreach ( array( 'canExtract', 'extract', 'getMethod' ) as $method_name ) {
			$method = $reflection->getMethod( $method_name );

			$this->assertSame( BaseExtractor::class, $method->getDeclaringClass()->getName() );
			$this->assertTrue( $method->isPublic() );
			$this->assertTrue( $method->isAbstract() );
		}
	}
}
