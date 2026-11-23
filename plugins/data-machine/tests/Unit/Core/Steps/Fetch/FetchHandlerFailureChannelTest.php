<?php
/**
 * FetchHandler failure channel tests.
 *
 * A handler that returns WP_Error from executeFetch() must surface that error
 * through get_fetch_data(), so FetchStep fails the job instead of reporting
 * `completed_no_items`. An empty array keeps meaning "nothing new".
 *
 * @package DataMachine\Tests\Unit\Core\Steps\Fetch
 * @see https://github.com/Extra-Chill/data-machine/issues/4028
 */

namespace DataMachine\Tests\Unit\Core\Steps\Fetch;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use WP_UnitTestCase;

class FetchHandlerFailureChannelTest extends WP_UnitTestCase {

	private function handlerReturning( array|\WP_Error $result ): FetchHandler {
		return new class( $result ) extends FetchHandler {
			public function __construct( private array|\WP_Error $result ) {
				parent::__construct( 'failure_channel_test' );
			}

			protected function executeFetch( array $config, ExecutionContext $context ): array|\WP_Error {
				return $this->result;
			}
		};
	}

	public function test_wp_error_from_handler_is_returned_unchanged(): void {
		$error   = new \WP_Error( 'missing_auth', 'Example not authenticated' );
		$handler = $this->handlerReturning( $error );

		$result = $handler->get_fetch_data( 'direct', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_auth', $result->get_error_code() );
		$this->assertSame( 'Example not authenticated', $result->get_error_message() );
	}

	public function test_empty_array_still_means_no_new_items(): void {
		$handler = $this->handlerReturning( array() );

		$this->assertSame( array(), $handler->get_fetch_data( 'direct', array() ) );
	}

	public function test_handlers_declaring_array_return_type_remain_compatible(): void {
		$handler = new class() extends FetchHandler {
			public function __construct() {
				parent::__construct( 'legacy_array_handler' );
			}

			protected function executeFetch( array $config, ExecutionContext $context ): array {
				return array( 'items' => array( array( 'title' => 'Legacy item' ) ) );
			}
		};

		$packets = $handler->get_fetch_data( 'direct', array() );

		$this->assertIsArray( $packets );
		$this->assertCount( 1, $packets );
	}
}
