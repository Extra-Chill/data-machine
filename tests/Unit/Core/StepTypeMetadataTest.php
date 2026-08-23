<?php
/**
 * Step type metadata tests.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\Steps\StepTypeMetadata;
use WP_UnitTestCase;

class StepTypeMetadataTest extends WP_UnitTestCase {

	public function test_fetch_declares_source_step_contract(): void {
		$step_types = apply_filters( 'datamachine_step_types', array() );
		$fetch      = $step_types['fetch'];

		$this->assertTrue( $fetch['source_ingestion'] );
		$this->assertTrue( $fetch['allows_empty_output'] );
		$this->assertTrue( $fetch['supports_item_disposition'] );
		$this->assertSame( 'source', $fetch['handler_category'] );
	}

	public function test_queries_extension_registered_metadata_without_known_slugs(): void {
		$register_external_source =
			static function ( array $step_types ): array {
				$step_types['external_source'] = array(
					'source_ingestion'          => true,
					'allows_empty_output'       => true,
					'supports_item_disposition' => true,
					'handler_category'          => 'source',
				);
				return $step_types;
			};
		add_filter(
			'datamachine_step_types',
			$register_external_source
		);

		$this->assertTrue( StepTypeMetadata::isSourceIngestion( 'external_source' ) );
		$this->assertTrue( StepTypeMetadata::allowsEmptyOutput( 'external_source' ) );
		$this->assertTrue( StepTypeMetadata::supportsItemDisposition( 'external_source' ) );
		$this->assertTrue( StepTypeMetadata::hasHandlerCategory( 'external_source', 'source' ) );
		$this->assertFalse( StepTypeMetadata::isSourceIngestion( 'unknown' ) );

		remove_filter( 'datamachine_step_types', $register_external_source );
	}
}
