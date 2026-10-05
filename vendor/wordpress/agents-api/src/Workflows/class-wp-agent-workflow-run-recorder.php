<?php
/**
 * Recording contract for workflow run history.
 *
 * Run recorders are the persistence side of executions: every run that
 * starts gets a row, the runner updates it as steps complete, and the
 * UI / observability layer can query past runs without re-executing.
 *
 * Like {@see WP_Agent_Workflow_Store}, agents-api ships no default
 * recorder. Consumers wire one against whatever storage they already
 * use for jobs / runs — a custom post type, a custom table, an external
 * observability system. Persistence detail stays a consumer concern.
 * When results include evidence references, recorders should preserve the
 * first-class `evidence_refs` array as-is instead of burying artifact or log
 * pointers under implementation metadata. The referenced storage remains
 * host-owned and may point at CPTs, feature-flagged request logs, external
 * artifacts, or any other JSON-serializable reference envelope.
 *
 * Implementations should be best-effort safe — losing a run record
 * must not break a workflow's user-facing outcome. The runner tolerates
 * recorder failures and continues.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Workflow_Run_Recorder {

	/**
	 * Persist the start of a new run. Called by the runner before any step
	 * executes. Returns the run id the runner should use for subsequent
	 * updates — implementations may generate their own ids (post id, UUID,
	 * row id) or accept and return the runner-suggested one.
	 *
	 * @since 0.103.0
	 *
	 * @param WP_Agent_Workflow_Run_Result $result Initial pending result.
	 * @return string|WP_Error Persisted run id.
	 */
	public function start( WP_Agent_Workflow_Run_Result $result );

	/**
	 * Update an existing run's recorded state. Called by the runner as
	 * steps progress and at completion.
	 *
	 * @since 0.103.0
	 *
	 * @param WP_Agent_Workflow_Run_Result $result Latest result.
	 * @return true|WP_Error
	 */
	public function update( WP_Agent_Workflow_Run_Result $result );

	/**
	 * Look up a previously recorded run.
	 *
	 * @since 0.103.0
	 *
	 * @param string $run_id
	 * @return WP_Agent_Workflow_Run_Result|null
	 */
	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result;

	/**
	 * Recent runs across (or filtered to) a workflow. `$args` accepted keys:
	 * `workflow_id`, `limit`, `offset`. Implementations may accept more.
	 *
	 * @since 0.103.0
	 *
	 * @param array<mixed> $args
	 * @return WP_Agent_Workflow_Run_Result[]
	 */
	public function recent( array $args = array() ): array;
}
