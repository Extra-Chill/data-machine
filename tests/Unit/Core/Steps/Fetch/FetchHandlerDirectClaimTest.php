<?php
/**
 * Direct/standalone fetches must not read a disposition id the claim lacks (#3650).
 *
 * ExecutionContext::claimItemOwnership() returns array( 'persisted' => false )
 * in direct and standalone mode. FetchHandler::claimItems() read
 * $claim['disposition_id'] unconditionally, emitting an "Undefined array key"
 * warning that broke redirects in admin-post callers.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\Fetch
 */

namespace DataMachine\Tests\Unit\Core\Steps\Fetch;

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DirectClaimStubFetchHandler extends FetchHandler {
	public function __construct() {
		$this->handler_type = 'direct_claim_stub';
	}

	protected function executeFetch( array $config, ExecutionContext $context ): array {
		return array();
	}
}

class FetchHandlerDirectClaimTest extends TestCase {

	private function claim( ExecutionContext $context, array $items ): array {
		$method = new ReflectionMethod( FetchHandler::class, 'claimItems' );
		$method->setAccessible( true );

		$warnings = array();
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
				$warnings[] = $errstr;
				return true;
			}
		);
		try {
			$result = $method->invoke( new DirectClaimStubFetchHandler(), $items, $context );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings, 'claimItems() must not emit warnings for non-persisted claims.' );
		return $result;
	}

	private function items(): array {
		return array(
			array(
				'title'    => 'Show',
				'content'  => 'Body',
				'metadata' => array( 'item_identifier' => 'https://artist.example/tour' ),
			),
		);
	}

	public function test_direct_context_claims_without_disposition_warning(): void {
		$result = $this->claim( ExecutionContext::direct( 'direct_claim_stub' ), $this->items() );

		$this->assertCount( 1, $result );
		$this->assertSame( array( 'persisted' => false ), $result[0]['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ] );
		$this->assertArrayNotHasKey( ProcessedItems::DISPOSITION_ID_METADATA_KEY, $result[0]['metadata'] );
	}

	public function test_standalone_context_claims_without_disposition_warning(): void {
		$result = $this->claim( ExecutionContext::standalone( null, 'direct_claim_stub' ), $this->items() );

		$this->assertCount( 1, $result );
		$this->assertArrayNotHasKey( ProcessedItems::DISPOSITION_ID_METADATA_KEY, $result[0]['metadata'] );
	}

	public function test_non_persisted_claim_metadata_is_still_valid(): void {
		$result = $this->claim( ExecutionContext::direct( 'direct_claim_stub' ), $this->items() );

		$this->assertTrue( ProcessedItems::has_valid_claim_metadata( $result[0]['metadata'] ) );
	}
}
