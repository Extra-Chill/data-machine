<?php
/**
 * Cross-flow Ticketmaster source identity lifecycle.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster;

use DataMachine\Core\Database\TrackedItems\TrackedItems;
use DataMachine\Core\ExecutionContext;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates stable source revisions and atomic claims across city flows.
 */
class TicketmasterSourceIdentity {

	public const CLAIM_SCOPE     = 'event_import:ticketmaster';
	public const TRACK_NAMESPACE = 'event_import:ticketmaster';
	public const CLAIM_TTL       = 3 * DAY_IN_SECONDS;

	/**
	 * Static lifecycle service.
	 */
	private function __construct() {
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
}
