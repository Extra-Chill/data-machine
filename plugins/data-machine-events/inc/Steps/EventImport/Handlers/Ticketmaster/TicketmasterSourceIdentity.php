<?php
/**
 * Cross-flow Ticketmaster source identity lifecycle.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster;

use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\Database\TrackedItems\TrackedItems;
use DataMachine\Core\ExecutionContext;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates stable source revisions and atomic claims across city flows.
 */
class TicketmasterSourceIdentity {

	public const CLAIM_SCOPE     = 'event_import:ticketmaster';
	public const TRACK_NAMESPACE = 'event_import:ticketmaster';
	public const ENGINE_KEY      = 'ticketmaster_source_identity';
	public const CLAIM_TTL       = 3 * DAY_IN_SECONDS;

	/**
	 * Static lifecycle service.
	 */
	private function __construct() {
	}

	/**
	 * Register terminal lifecycle handlers for source claims and revisions.
	 */
	public static function register(): void {
		add_action( 'datamachine_step_lifecycle_completed', array( static::class, 'handleCompleted' ), 20, 2 );
		add_action( 'datamachine_step_lifecycle_failed', array( static::class, 'handleFailed' ), 20, 2 );
	}

	/**
	 * Build a revision from the mapped fields that EventUpsert can persist.
	 *
	 * @param array $event Standardized event data.
	 * @return string Source revision hash.
	 */
	public static function revision( array $event ): string {
		return hash( 'sha256', (string) wp_json_encode( $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Check whether the current revision should remain suppressed.
	 *
	 * The generic reprocess filter remains authoritative, using one stable scope
	 * shared by every Ticketmaster city flow.
	 *
	 * @return bool Whether the revision should be skipped.
	 */
	public static function shouldSkip( string $item_id, string $revision, ExecutionContext $context ): bool {
		if ( $context->isDirect() || $context->isStandalone() ) {
			return false;
		}

		$tracked = ( new TrackedItems() )->get( self::TRACK_NAMESPACE, $item_id );
		$skip    = is_array( $tracked )
			&& TrackedItems::STATE_GENERATED === ( $tracked['state'] ?? '' )
			&& hash_equals( (string) ( $tracked['source_revision'] ?? '' ), $revision );

		return (bool) apply_filters(
			'datamachine_should_reprocess_item',
			$skip,
			array(
				'flow_step_id'    => self::CLAIM_SCOPE,
				'source_type'     => 'ticketmaster',
				'item_identifier' => $item_id,
				'job_id'          => (int) $context->getJobId(),
				'source_revision' => $revision,
			)
		);
	}

	/**
	 * Atomically select an item after the handler's fan-out cap is applied.
	 *
	 * @return string claimed, direct, unchanged, or contended.
	 */
	public static function claim( string $item_id, string $revision, ExecutionContext $context ): string {
		if ( $context->isDirect() || $context->isStandalone() ) {
			return 'direct';
		}

		$job_id = (int) $context->getJobId();
		if ( $job_id < 1 ) {
			return 'contended';
		}

		$processed_items = new ProcessedItems();
		if ( ! $processed_items->claim_item( self::CLAIM_SCOPE, 'ticketmaster', $item_id, $job_id, self::CLAIM_TTL ) ) {
			return 'contended';
		}

		// Recheck inside the lock because another city may have completed after
		// the pre-cap revision check but before this claim was acquired.
		if ( self::shouldSkip( $item_id, $revision, $context ) ) {
			$processed_items->release_claim( self::CLAIM_SCOPE, 'ticketmaster', $item_id );
			return 'unchanged';
		}

		return 'claimed';
	}

	/**
	 * Persist the source revision only after the downstream pipeline succeeds.
	 *
	 * @param int        $job_id      Completed child job ID.
	 * @param array|null $engine_data Child engine data.
	 */
	public static function handleCompleted( int $job_id, ?array $engine_data = null ): void {
		$identity = self::identityFromEngine( $engine_data );
		if ( null === $identity ) {
			return;
		}

		( new TrackedItems() )->upsert(
			array(
				'namespace'       => self::TRACK_NAMESPACE,
				'item_id'         => $identity['item_id'],
				'item_type'       => 'event',
				'state'           => TrackedItems::STATE_GENERATED,
				'source_ref'      => $identity['source_ref'],
				'source_revision' => $identity['revision'],
				'last_job_id'     => $job_id,
			)
		);

		self::release( $identity['item_id'] );
	}

	/**
	 * Release the cross-flow claim after terminal failure without tracking the revision.
	 *
	 * @param int        $job_id      Failed child job ID.
	 * @param array|null $engine_data Child engine data.
	 */
	public static function handleFailed( int $job_id, ?array $engine_data = null ): void {
		$job_id;
		$identity = self::identityFromEngine( $engine_data );
		if ( null !== $identity ) {
			self::release( $identity['item_id'] );
		}
	}

	/**
	 * Release a source claim explicitly.
	 */
	public static function release( string $item_id ): void {
		( new ProcessedItems() )->release_claim( self::CLAIM_SCOPE, 'ticketmaster', $item_id );
	}

	/**
	 * Read validated Ticketmaster identity data from a child engine snapshot.
	 *
	 * @param array|null $engine_data Engine data.
	 * @return array{item_id:string,revision:string,source_ref:string}|null
	 */
	private static function identityFromEngine( ?array $engine_data ): ?array {
		$identity = is_array( $engine_data[ self::ENGINE_KEY ] ?? null )
			? $engine_data[ self::ENGINE_KEY ]
			: array();

		$item_id  = trim( (string) ( $identity['item_id'] ?? '' ) );
		$revision = trim( (string) ( $identity['revision'] ?? '' ) );
		if ( '' === $item_id || '' === $revision ) {
			return null;
		}

		return array(
			'item_id'    => $item_id,
			'revision'   => $revision,
			'source_ref' => (string) ( $identity['source_ref'] ?? '' ),
		);
	}
}
