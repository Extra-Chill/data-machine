<?php
/**
 * Tests for FetchHandler DataPacket wrapping via toDataPackets().
 *
 * Verifies that FetchHandler::get_fetch_data() correctly normalizes handler
 * output and wraps it into DataPacket[] through a single toDataPackets() method.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\Fetch
 */

namespace DataMachine\Tests\Unit\Core\Steps\Fetch;

use PHPUnit\Framework\TestCase;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use ReflectionMethod;

/**
 * Concrete test double for FetchHandler.
 *
 * Allows tests to control what executeFetch() returns without needing
 * real HTTP, ExecutionContext, or WordPress functions.
 */
class StubFetchHandler extends FetchHandler {

	/** @var array The return value for executeFetch(). */
	private array $stub_result;

	public function __construct( string $handler_type, array $stub_result ) {
		$this->handler_type = $handler_type;
		$this->stub_result  = $stub_result;
		// Skip parent __construct to avoid side effects.
	}

	protected function executeFetch( array $config, \DataMachine\Core\ExecutionContext $context ): array {
		return $this->stub_result;
	}
}

class FetchHandlerDataPacketTest extends TestCase {

	// ---------------------------------------------------------------
	// toDataPackets — the single wrapping method
	// ---------------------------------------------------------------

	public function test_single_item_creates_one_packet(): void {
		$handler = $this->createStubHandler( 'rss', array() );
		$method  = $this->getToDataPackets();

		$items  = array(
			array( 'title' => 'Article', 'content' => 'Body text', 'metadata' => array( 'original_id' => '42' ) ),
		);
		$result = $method->invoke( $handler, $items, 10, 5 );

		$this->assertCount( 1, $result );
		$this->assertInstanceOf( DataPacket::class, $result[0] );

		$packets = $result[0]->addTo( array() );
		$this->assertSame( 'fetch', $packets[0]['type'] );
		$this->assertSame( 'Article', $packets[0]['data']['title'] );
		$this->assertSame( 'Body text', $packets[0]['data']['body'] );
		$this->assertSame( 'rss', $packets[0]['metadata']['handler'] );
		$this->assertSame( 10, $packets[0]['metadata']['pipeline_id'] );
		$this->assertSame( 5, $packets[0]['metadata']['flow_id'] );
		$this->assertSame( '42', $packets[0]['metadata']['original_id'] );
	}

	public function test_multiple_items_creates_multiple_packets(): void {
		$handler = $this->createStubHandler( 'ticketmaster', array() );
		$method  = $this->getToDataPackets();

		$items = array(
			array( 'title' => 'Event 1', 'content' => 'Band A', 'metadata' => array() ),
			array( 'title' => 'Event 2', 'content' => 'Band B', 'metadata' => array() ),
			array( 'title' => 'Event 3', 'content' => 'Band C', 'metadata' => array() ),
		);

		$result = $method->invoke( $handler, $items, 10, 5 );

		$this->assertCount( 3, $result );
		$this->assertContainsOnlyInstancesOf( DataPacket::class, $result );

		// Verify each has correct title.
		$titles = array_map( function ( $packet ) {
			$arr = $packet->addTo( array() );
			return $arr[0]['data']['title'];
		}, $result );
		$this->assertSame( array( 'Event 1', 'Event 2', 'Event 3' ), $titles );
	}

	public function test_empty_items_are_filtered_out(): void {
		$handler = $this->createStubHandler( 'rss', array() );
		$method  = $this->getToDataPackets();

		$items = array(
			array( 'title' => 'Good', 'content' => 'Content' ),
			array( 'title' => '', 'content' => '' ),                // empty → dropped
			array( 'title' => 'Also Good', 'content' => 'More' ),
		);

		$result = $method->invoke( $handler, $items, 1, 1 );
		$this->assertCount( 2, $result );
	}

	public function test_all_empty_items_returns_empty(): void {
		$handler = $this->createStubHandler( 'rss', array() );
		$method  = $this->getToDataPackets();

		$items = array(
			array( 'title' => '', 'content' => '' ),
			array( 'title' => '', 'content' => '' ),
		);

		$result = $method->invoke( $handler, $items, 1, 1 );
		$this->assertEmpty( $result );
	}

	public function test_non_array_items_are_skipped(): void {
		$handler = $this->createStubHandler( 'rss', array() );
		$method  = $this->getToDataPackets();

		$items = array(
			array( 'title' => 'Valid', 'content' => 'Content' ),
			'not an array',
			null,
			42,
			array( 'title' => 'Also Valid', 'content' => 'More' ),
		);

		$result = $method->invoke( $handler, $items, 1, 1 );
		$this->assertCount( 2, $result );
	}

	public function test_file_info_included_in_packet(): void {
		$handler = $this->createStubHandler( 'files', array() );
		$method  = $this->getToDataPackets();

		$items = array(
			array(
				'title'     => '',
				'content'   => '',
				'file_info' => array( 'file_path' => '/tmp/image.jpg', 'mime_type' => 'image/jpeg' ),
			),
		);

		$result = $method->invoke( $handler, $items, 1, 1 );
		$this->assertCount( 1, $result );

		$packets = $result[0]->addTo( array() );
		$this->assertArrayHasKey( 'file_info', $packets[0]['data'] );
		$this->assertSame( '/tmp/image.jpg', $packets[0]['data']['file_info']['file_path'] );
	}

	public function test_handler_metadata_merges_with_defaults(): void {
		$handler = $this->createStubHandler( 'github', array() );
		$method  = $this->getToDataPackets();

		$items = array(
			array(
				'title'    => 'Issue #1',
				'content'  => 'Bug report',
				'metadata' => array(
					'source_type'   => 'github_custom',
					'github_number' => 1,
				),
			),
		);

		$result  = $method->invoke( $handler, $items, 5, 3 );
		$packets = $result[0]->addTo( array() );

		// Handler metadata overwrites defaults via array_merge.
		$this->assertSame( 'github_custom', $packets[0]['metadata']['source_type'] );
		$this->assertSame( 1, $packets[0]['metadata']['github_number'] );
		// Defaults still present.
		$this->assertSame( 5, $packets[0]['metadata']['pipeline_id'] );
		$this->assertSame( 3, $packets[0]['metadata']['flow_id'] );
		$this->assertSame( 'github', $packets[0]['metadata']['handler'] );
	}

	public function test_zero_items_returns_empty(): void {
		$handler = $this->createStubHandler( 'rss', array() );
		$method  = $this->getToDataPackets();

		$result = $method->invoke( $handler, array(), 1, 1 );
		$this->assertEmpty( $result );
	}

	// ---------------------------------------------------------------
	// Dedup consolidation — structural tests
	// ---------------------------------------------------------------

	public function test_dedup_method_exists_and_is_private(): void {
		$method = new ReflectionMethod( FetchHandler::class, 'dedup' );
		$this->assertTrue( $method->isPrivate() );
	}

	public function test_on_item_processed_hook_exists_and_is_protected(): void {
		$method = new ReflectionMethod( FetchHandler::class, 'onItemProcessed' );
		$this->assertTrue( $method->isProtected() );
	}

	public function test_on_item_processed_is_overridable(): void {
		// Verify it's not final so subclasses can override.
		$method = new ReflectionMethod( FetchHandler::class, 'onItemProcessed' );
		$this->assertFalse( $method->isFinal() );
	}

	// ---------------------------------------------------------------
	// Contract / structural tests
	// ---------------------------------------------------------------

	public function test_get_fetch_data_is_final(): void {
		$method = new ReflectionMethod( FetchHandler::class, 'get_fetch_data' );
		$this->assertTrue( $method->isFinal() );
		$this->assertTrue( $method->isPublic() );
	}

	public function test_get_fetch_data_return_type_is_array(): void {
		$method      = new ReflectionMethod( FetchHandler::class, 'get_fetch_data' );
		$return_type = $method->getReturnType();
		$this->assertNotNull( $return_type );
		$this->assertSame( 'array', $return_type->getName() );
	}

	public function test_to_data_packets_is_private(): void {
		$method = new ReflectionMethod( FetchHandler::class, 'toDataPackets' );
		$this->assertTrue( $method->isPrivate() );
	}

	public function test_no_wrap_single_item_method_exists(): void {
		$this->assertFalse(
			method_exists( FetchHandler::class, 'wrapSingleItem' ),
			'wrapSingleItem should not exist — toDataPackets handles all cardinalities'
		);
	}

	public function test_no_wrap_items_method_exists(): void {
		$this->assertFalse(
			method_exists( FetchHandler::class, 'wrapItems' ),
			'wrapItems should not exist — toDataPackets handles all cardinalities'
		);
	}

	// ---------------------------------------------------------------
	// FetchStep contract tests
	// ---------------------------------------------------------------

	public function test_fetchstep_exists_and_extends_step(): void {
		$this->assertTrue( class_exists( \DataMachine\Core\Steps\Fetch\FetchStep::class ) );
		$reflection = new \ReflectionClass( \DataMachine\Core\Steps\Fetch\FetchStep::class );
		$this->assertTrue( $reflection->isSubclassOf( \DataMachine\Core\Steps\Step::class ) );
	}

	public function test_fetchstep_execute_handler_returns_array(): void {
		$method = new ReflectionMethod( \DataMachine\Core\Steps\Fetch\FetchStep::class, 'execute_handler' );
		$this->assertTrue( $method->isPrivate() );

		$return_type = $method->getReturnType();
		$this->assertNotNull( $return_type );
		$this->assertSame( 'array', $return_type->getName() );
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	private function createStubHandler( string $handler_type, array $stub_result ): StubFetchHandler {
		return new StubFetchHandler( $handler_type, $stub_result );
	}

	private function getToDataPackets(): ReflectionMethod {
		$method = new ReflectionMethod( FetchHandler::class, 'toDataPackets' );
		$method->setAccessible( true );
		return $method;
	}
}
