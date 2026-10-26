<?php
/**
 * Generic fetch handler compatibility for event import steps.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachineEvents\Steps\EventImport\EventImportStep;
use WP_UnitTestCase;

class EventImportFetchHandlersTest extends WP_UnitTestCase {

	private bool $collision = false;

	public function set_up(): void {
		parent::set_up();
		new EventImportStep();
		HandlerAbilities::clearCache();
		add_filter( 'datamachine_handlers', array( $this, 'registerHandlers' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_handlers', array( $this, 'registerHandlers' ), 10 );
		HandlerAbilities::clearCache();
		parent::tear_down();
	}

	public function registerHandlers( array $handlers, ?string $type = null ): array {
		if ( null === $type || 'fetch' === $type ) {
			$handlers['test_generic_source'] = array( 'class' => GenericEventSource::class, 'type' => 'fetch' );
		}
		if ( $this->collision && ( null === $type || 'event_import' === $type ) ) {
			$handlers['test_generic_source'] = array( 'class' => NativeEventSource::class, 'type' => 'event_import' );
		}
		return $handlers;
	}

	public function test_fetch_handler_executes_through_packet_path(): void {
		$packets = ( new ExecutableEventImportStep() )->runHandler();

		$this->assertCount( 1, $packets );
		$this->assertSame( 'Generic source', $packets[0]['data']['title'] );
		$this->assertSame( 'test_generic_source', $packets[0]['metadata']['handler'] );
		$this->assertSame( 17, $packets[0]['metadata']['pipeline_id'] );
		$this->assertSame( 23, $packets[0]['metadata']['flow_id'] );
	}

	public function test_processed_fetch_item_is_deduplicated_in_event_import(): void {
		$context = ExecutionContext::fromConfig(
			array( 'flow_step_id' => 'test-event-import', 'flow_id' => 23, 'pipeline_id' => 17 ),
			'1',
			'test_generic_source'
		);
		$context->markItemProcessed( 'already-imported' );
		$this->assertTrue( $context->isItemProcessed( 'already-imported' ) );
		$this->assertSame( array(), ( new ExecutableEventImportStep() )->runHandler( array( 'item_identifier' => 'already-imported' ) ) );
	}

	public function test_native_handler_wins_slug_collision_during_execution(): void {
		$this->collision = true;
		$packets         = ( new ExecutableEventImportStep() )->runHandler();

		$this->assertCount( 1, $packets );
		$this->assertSame( 'Native source', $packets[0]['data']['title'] );
	}

	public function test_core_validation_and_step_listing_accept_fetch_handlers(): void {
		$abilities = new HandlerAbilities();

		$this->assertTrue( $abilities->validateHandler( 'test_generic_source', 'event_import' )['valid'] );
		$this->assertSame( GenericEventSource::class, $abilities->getAllHandlers( 'event_import' )['test_generic_source']['class'] );
		$this->assertArrayNotHasKey( 'test_generic_source', $abilities->getAllHandlers( 'publish' ) );
	}

	public function test_core_listing_preserves_native_definition_and_fetch_registry(): void {
		$this->collision = true;
		$abilities      = new HandlerAbilities();

		$this->assertSame( NativeEventSource::class, $abilities->getAllHandlers( 'event_import' )['test_generic_source']['class'] );
		$this->assertSame( GenericEventSource::class, $abilities->getAllHandlers( 'fetch' )['test_generic_source']['class'] );
		$this->assertSame( NativeEventSource::class, $abilities->getAllHandlers()['test_generic_source']['class'] );
	}
}

/** Expose the protected step entry point without requiring a persisted job. */
class ExecutableEventImportStep extends EventImportStep {
	public function runHandler( array $config = array() ): array {
		$this->job_id           = 1;
		$this->flow_step_id     = 'test-event-import';
		$this->dataPackets      = array();
		$this->engine           = new EngineData( array(), 1 );
		$this->flow_step_config = array(
			'pipeline_id'     => 17,
			'flow_id'         => 23,
			'handler_slugs'   => array( 'test_generic_source' ),
			'handler_configs' => array( 'test_generic_source' => $config ),
		);
		return $this->executeStep();
	}
}

class GenericEventSource extends FetchHandler {
	public function __construct() {
		parent::__construct( 'test_generic_source' );
	}

	protected function executeFetch( array $config, ExecutionContext $context ): array {
		return array(
			'title'    => 'Generic source',
			'content'  => 'Fetched content',
			'metadata' => isset( $config['item_identifier'] ) ? array( 'item_identifier' => $config['item_identifier'] ) : array(),
		);
	}
}

class NativeEventSource extends GenericEventSource {
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		return array( 'title' => 'Native source', 'content' => 'Native content' );
	}
}
