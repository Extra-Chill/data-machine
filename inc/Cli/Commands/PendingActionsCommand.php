<?php
/**
 * WP-CLI pending-actions command.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use DataMachine\Engine\AI\Actions\PendingActionInspectionAbility;
use DataMachine\Engine\AI\Actions\PendingActionScope;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect durable pending-action approval queues.
 */
class PendingActionsCommand extends BaseCommand {

	/** Resolve a pending action through the canonical resolver. */
	public function accept( array $args, array $assoc_args ): void {
		$this->resolve( $args, $assoc_args, 'accepted' );
	}

	/** Resolve a pending action through the canonical resolver. */
	public function reject( array $args, array $assoc_args ): void {
		$this->resolve( $args, $assoc_args, 'rejected' );
	}

	private function resolve( array $args, array $assoc_args, string $decision ): void {
		$action_id = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $action_id ) {
			WP_CLI::error( 'action_id is required.' );
		}
		$result = \DataMachine\Engine\AI\Actions\ResolvePendingActionAbility::execute( array( 'action_id' => $action_id, 'decision' => $decision, 'resolver' => 'cli:' . get_current_user_id(), 'context' => array( 'resolution_transport' => 'cli' ) ) );
		if ( empty( $result['success'] ) ) {
			WP_CLI::error( (string) ( $result['error'] ?? 'Pending action could not be resolved.' ) );
		}
		WP_CLI::success( sprintf( 'Pending action %s.', $decision ) );
		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::line( wp_json_encode( $result ) );
		}
	}

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
	 * [--operator-wide]
	 * : Explicitly inspect actions across all owners/agents/workspaces.
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
		if ( ! empty( $assoc_args['operator-wide'] ) ) {
			$filters['operator_wide'] = true;
		}

		$filters = PendingActionScope::filters( $filters );
		if ( is_wp_error( $filters ) ) {
			WP_CLI::error( $filters->get_error_message() );
		}

		$result = \AgentsAPI\AI\Approvals\agents_list_pending_actions( array( 'filters' => $filters ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$rows = PendingActionInspectionAbility::normalize_action_rows( is_array( $result['actions'] ?? null ) ? $result['actions'] : array() );

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
	 * [--operator-wide]
	 * : Explicitly inspect an action outside the current caller scope.
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

		$scope_input = ! empty( $assoc_args['operator-wide'] ) ? array( 'operator_wide' => true ) : array();
		$result      = \AgentsAPI\AI\Approvals\agents_get_pending_action(
			array(
				'action_id'        => $action_id,
				'include_resolved' => true,
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$action = is_array( $result['action'] ?? null ) ? $result['action'] : null;
		if ( null === $action || ! PendingActionScope::can_access_payload( PendingActionScope::action_array_to_payload( $action ), $scope_input ) ) {
			WP_CLI::error( 'Pending action not found.' );
		}

		$action = PendingActionInspectionAbility::normalize_action_row( $action );
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
	 * [--context-limit=<limit>]
	 * : Maximum context buckets to include. Default 25, max 200. Use 0 for all buckets.
	 *
	 * [--include-context-details]
	 * : Include all context buckets in the summary.
	 *
	 * [--operator-wide]
	 * : Explicitly summarize actions across all owners/agents/workspaces.
	 *
	 * [--format=<format>]
	 * : Output format. Default json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine pending-actions summary
	 */
	public function summary( array $args, array $assoc_args ): void {
		$filters = PendingActionInspectionAbility::normalize_filters( $assoc_args );
		if ( ! empty( $assoc_args['operator-wide'] ) ) {
			$filters['operator_wide'] = true;
		}

		$filters = PendingActionScope::filters( $filters );
		if ( is_wp_error( $filters ) ) {
			WP_CLI::error( $filters->get_error_message() );
		}

		$summary = \AgentsAPI\AI\Approvals\agents_summary_pending_actions( array( 'filters' => $filters ) );
		if ( is_wp_error( $summary ) ) {
			WP_CLI::error( $summary->get_error_message() );
		}
		$format = $assoc_args['format'] ?? 'json';

		WP_CLI\Utils\format_items( $format, array( $summary ), array_keys( $summary ) );
	}
}
