<?php
/**
 * Architecture contract for Data Machine integration boundaries.
 *
 * @package DataMachineEvents\Tests\Contracts
 */

namespace DataMachineEvents\Tests\Contracts;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class StableDataMachineContractsTest extends TestCase {

	public function test_runtime_does_not_import_data_machine_duplicate_implementation_classes(): void {
		$runtime = dirname( __DIR__, 2 ) . '/inc';
		$files   = new RegexIterator(
			new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $runtime ) ),
			'/\.php$/'
		);
		$forbidden = array(
			'DataMachine\\Core\\Database\\PostIdentityIndex',
			'DataMachine\\Core\\Similarity\\SimilarityEngine',
			'DataMachine\\Abilities\\Taxonomy\\ResolveTermAbility',
			'DataMachine\\Abilities\\Taxonomy\\MergeTermMetaAbility',
		);
		$violations = array();

		foreach ( $files as $file ) {
			$contents = file_get_contents( $file->getPathname() );
			foreach ( $forbidden as $namespace ) {
				if ( false !== strpos( $contents, $namespace ) ) {
					$violations[] = str_replace( dirname( __DIR__, 2 ) . '/', '', $file->getPathname() ) . ': ' . $namespace;
				}
			}
		}

		$this->assertSame( array(), $violations, implode( "\n", $violations ) );
	}
}
