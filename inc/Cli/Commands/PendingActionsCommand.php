<?php
/**
 * WP-CLI pending-actions command.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use DataMachine\Engine\AI\Actions\PendingActionInspectionAbility;
use DataMachine\Engine\AI\Actions\PendingActionStore;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect durable pending-action approval queues.
 */
class PendingActionsCommand extends BaseCommand {

	/**
	 * List pending actions.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status: pending, accepted, rejected, expired, deleted.
	 *
	 * [--kind=<kind>]
	 * : Filter by action kind.
	 *
	 * [--agent_id=<id>]
	 * : Filter by acting agent ID.
	 *
	 * [--created_by=<id>]
	 * : Filter by creator user ID.
	 *
	 * [--limit=<limit>]
	 * : Number of rows to return. Default 50, max 200.
	 *
	 * [--offset=<offset>]
	 * : Offset for pagination.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine pending-actions list
	 *     wp datamachine pending-actions list --status=pending --format=json
	 */
	public function list( array $args, array $assoc_args ): void {
		$filters = PendingActionInspectionAbility::normalize_filters( $assoc_args );
		$rows    = PendingActionStore::list( $filters );

		$fields = array( 'action_id', 'kind', 'summary', 'status', 'agent_id', 'created_by', 'created_at_iso', 'expires_at_iso' );
		$this->format_items( $rows, $fields, $assoc_args, 'action_id' );
	}

	/**
	 * Get a pending action by ID.
	 *
	 * ## OPTIONS
	 *
	 * <action_id>
	 * : Pending action ID.
	 *
	 * [--format=<format>]
	 * : Output format. Default json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine pending-actions get act_123
	 */
	public function get( array $args, array $assoc_args ): void {
		$action_id = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $action_id ) {
			WP_CLI::error( 'action_id is required.' );
		}

		$action = PendingActionStore::inspect( $action_id );
		if ( null === $action ) {
			WP_CLI::error( 'Pending action not found.' );
		}

		$format = $assoc_args['format'] ?? 'json';
		WP_CLI\Utils\format_items( $format, array( $action ), array_keys( $action ) );
	}

	/**
	 * Summarize pending actions.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status before summarizing.
	 *
	 * [--kind=<kind>]
	 * : Filter by action kind before summarizing.
	 *
	 * [--format=<format>]
	 * : Output format. Default json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine pending-actions summary
	 */
	public function summary( array $args, array $assoc_args ): void {
		$summary = PendingActionStore::summary( PendingActionInspectionAbility::normalize_filters( $assoc_args ) );
		$format  = $assoc_args['format'] ?? 'json';

		WP_CLI\Utils\format_items( $format, array( $summary ), array_keys( $summary ) );
	}
}
