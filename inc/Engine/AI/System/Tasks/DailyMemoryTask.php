<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
/**
 * Daily Memory Task
 *
 * System agent task that maintains MEMORY.md — the persistent memory file
 * injected into every AI session. Reads the current MEMORY.md, gathers
 * available activity context, and uses a single AI call to:
 *
 * - Move day-specific/session-specific content to a daily archive file
 * - Compress and clean: remove redundancies, outdated info, verbose language
 * - Preserve all persistent knowledge without losing anything important
 *
 * Runs once daily via Action Scheduler when daily_memory_enabled is active.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.32.0
 * @since 0.72.0 Migrated to getWorkflow() + executeTask() contract.
 * @see https://github.com/Extra-Chill/data-machine/issues/357
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\PluginSettings;
use DataMachine\Core\FilesRepository\AgentMemory;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Engine\AI\NaturalCompletionPolicyInterface;
use AgentsAPI\AI\WP_Agent_Compaction_Conservation;
use AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision;
use AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy;
use AgentsAPI\AI\WP_Agent_Markdown_Section_Compaction_Adapter;

class DailyMemoryTask extends SystemTask {

	/**
	 * Execute daily memory maintenance.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function executeTask( int $jobId, array $params ): void {
		if ( ! PluginSettings::get( 'daily_memory_enabled', false ) ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => 'Daily memory is disabled.',
				)
			);
			return;
		}

		$date     = $params['date'] ?? gmdate( 'Y-m-d' );
		$user_id  = (int) ( $params['user_id'] ?? 0 );
		$agent_id = (int) ( $params['agent_id'] ?? 0 );

		$system_defaults = $this->resolveSystemModel( $params );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		// Treat an unresolvable model as a no-op skip rather than a hard
		// failure. The resolution chain checks five locations
		// (agent.mode_models[system], agent.default_*, site.mode_models,
		// site.default_*, network.default_*); if all five are empty the
		// install simply hasn't picked a system model yet. That's a
		// configuration state, not a runtime fault, so it should not
		// generate failed-job noise or cascade through the engine as
		// "empty_data_packet_returned". Once a model is configured
		// anywhere in the chain, the next tick proceeds normally with
		// no migration or manual reset needed. Mirrors the
		// daily_memory_enabled = false branch above.
		if ( empty( $provider ) || empty( $model ) ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => sprintf(
						'No AI model resolvable for agent_id=%d in system mode. Configure mode_models.system or default_model at agent, site, or network level.',
						$agent_id
					),
				)
			);
			return;
		}

		$daily = new DailyMemory( $user_id, $agent_id );

		// Read current MEMORY.md.
		$memory = new AgentMemory( $user_id, $agent_id );
		$result = $memory->get_all();

		if ( empty( $result['success'] ) || empty( $result['content'] ) ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped' => true,
					'reason'  => 'MEMORY.md not found or empty.',
				)
			);
			return;
		}

		$memory_content = $result['content'];
		$original_size  = strlen( $memory_content );

		$overflow_result = $this->maybeHandleDeterministicOverflow( $jobId, $memory, $daily, $memory_content, $original_size, $date );
		if ( null !== $overflow_result ) {
			if ( empty( $overflow_result['success'] ) ) {
				$this->failJob( $jobId, $overflow_result['message'] ?? 'Daily memory overflow split failed.' );
				return;
			}

			$this->completeJob( $jobId, $overflow_result );
			return;
		}

		// Skip if MEMORY.md is within the recommended threshold and no activity context.
		$context = $this->gatherContext( $params );
		if ( $original_size <= AgentMemory::MAX_FILE_SIZE && empty( $context ) ) {
			$this->completeJob(
				$jobId,
				array(
					'skipped'       => true,
					'reason'        => 'MEMORY.md within size threshold and no activity to process.',
					'original_size' => $original_size,
				)
			);
			return;
		}

		// Build prompt with all available context.
		$max_size         = size_format( AgentMemory::MAX_FILE_SIZE );
		$activity_section = '';
		if ( ! empty( $context ) ) {
			$activity_section = "## Today's Activity\n\n" . $context . "\n\n";
		}

		$prompt = $this->buildPromptFromTemplate(
			'daily_memory',
			array(
				'memory_content'   => $memory_content,
				'date'             => $date,
				'max_size'         => $max_size,
				'activity_section' => $activity_section,
			)
		);

		$messages = array(
			\DataMachine\Engine\AI\ConversationManager::buildConversationMessage( 'user', $prompt ),
		);

		$ai_payload = array(
			// System task — no human caller. See MetaDescriptionTask for rationale.
			'calling_user_id' => 0,
		);
		if ( $agent_id > 0 ) {
			$ai_payload['agent_id'] = $agent_id;
		}
		if ( $user_id > 0 ) {
			$ai_payload['user_id'] = $user_id;
		}

		$response = $this->runAiConversation(
			$messages,
			$provider,
			$model,
			$ai_payload,
			$this->buildCleanupCompletionPolicy( $memory_content, $date, $jobId, $provider, $model ),
			(int) apply_filters(
				'datamachine_daily_memory_max_turns',
				3,
				array(
					'job_id'        => $jobId,
					'date'          => $date,
					'original_size' => $original_size,
				)
			)
		);

		$datamachine_metadata = is_array( $response['metadata']['datamachine'] ?? null ) ? $response['metadata']['datamachine'] : array();
		if ( empty( $datamachine_metadata['completed'] ) ) {
			$response_error = is_string( $response['error'] ?? null ) ? trim( $response['error'] ) : '';

			// Distinguish a genuine execution failure from a legitimate
			// "nothing worth changing today" no-op.
			//
			// The conversation loop sets completed=false both when the
			// request actually failed (provider error, runtime exception,
			// malformed loop result, interruption) AND when the model simply
			// ran out of turns without producing a split the completion
			// policy would accept. The latter is the common path for small,
			// already-healthy MEMORY.md files: the agent reviewed the day's
			// activity and never emitted an acceptable PERSISTENT/ARCHIVED
			// partition because there was nothing memory-worthy to fold in.
			//
			// At this point nothing has been written to MEMORY.md yet (the
			// replace_all() call happens later in this method), so the file
			// is genuinely untouched. A no-op should therefore complete the
			// job successfully and log at info, not fail loudly and pollute
			// error-rate metrics / the wake briefing (issue #2783).
			//
			// A genuine fault is identified by an explicit error signal:
			// a non-empty error string, an error_code, or a hard failure /
			// interrupted status from the loop. Those still fail the job.
			$genuine_failure = '' !== $response_error
				|| ! empty( $response['error_code'] )
				|| in_array( (string) ( $response['status'] ?? '' ), array( 'error', 'failed', 'interrupted' ), true );

			if ( ! $genuine_failure ) {
				do_action(
					'datamachine_log',
					'info',
					'Daily memory no-op: completion policy not satisfied, MEMORY.md left unchanged.',
					array(
						'date'        => $date,
						'job_id'      => $jobId,
						'status'      => $response['status'] ?? '',
						'turn_count'  => $response['turn_count'] ?? 0,
						'datamachine' => $datamachine_metadata,
					)
				);

				$this->completeJob(
					$jobId,
					array(
						'skipped'       => true,
						'no_change'     => true,
						'reason'        => 'Completion policy not satisfied within turn budget; MEMORY.md left unchanged (no memory-worthy change this run).',
						'original_size' => $original_size,
						'turn_count'    => $response['turn_count'] ?? 0,
					)
				);
				return;
			}

			do_action(
				'datamachine_log',
				'warning',
				'Daily memory AI conversation did not satisfy completion policy.',
				array(
					'date'        => $date,
					'job_id'      => $jobId,
					'status'      => $response['status'] ?? '',
					'turn_count'  => $response['turn_count'] ?? 0,
					'datamachine' => $datamachine_metadata,
					'error'       => $response['error'] ?? null,
				)
			);
			$this->failJob( $jobId, '' !== $response_error ? $response_error : 'Daily memory completion policy was not satisfied. MEMORY.md unchanged.' );
			return;
		}

		$ai_output = trim( (string) ( $response['final_content'] ?? '' ) );
		$ai_output = str_replace( '\n', "\n", $ai_output );

		if ( empty( $ai_output ) ) {
			$this->failJob( $jobId, 'AI returned empty response.' );
			return;
		}

		// Parse and validate the AI output through the Agents API memory
		// compaction contract. Data Machine still owns the prompt, model call,
		// and file writes; Agents API owns the generic markdown item projection,
		// conservation metadata, and fail-closed compaction decision.
		$plan = $this->planMemoryCompaction( $memory_content, $ai_output, $date, $jobId, $provider, $model );
		if ( empty( $plan['success'] ) ) {
			$this->failJob( $jobId, $plan['message'] ?? 'Daily memory compaction failed.' );
			return;
		}

		$parsed = $plan['parsed'];

		if ( empty( $parsed['persistent'] ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Daily memory parse failed -- no persistent section found. MEMORY.md unchanged.',
				array(
					'date'             => $date,
					'ai_output_length' => strlen( $ai_output ),
				)
			);
			$this->failJob( $jobId, 'Could not parse AI response -- persistent section missing.' );
			return;
		}

		// Write the cleaned MEMORY.md.
		$new_content   = $parsed['persistent'];
		$new_size      = strlen( $new_content );
		$archived_text = $parsed['archived'] ?? '';
		$archived_size = strlen( $archived_text );

		// Safety check: don't write if the new content is suspiciously small.
		$target_size     = AgentMemory::MAX_FILE_SIZE;
		$oversize_factor = $original_size / max( $target_size, 1 );

		if ( $oversize_factor > 2 ) {
			$min_size = intval( $target_size * 0.5 );
		} else {
			$min_size = intval( $original_size * 0.10 );
		}

		if ( $new_size < $min_size ) {
			do_action(
				'datamachine_log',
				'warning',
				sprintf(
					'Daily memory aborted -- new content (%s) is below minimum (%s). AI may have been too aggressive.',
					size_format( $new_size ),
					size_format( $min_size )
				),
				array(
					'date'          => $date,
					'original_size' => $original_size,
					'new_size'      => $new_size,
					'min_size'      => $min_size,
				)
			);
			$this->failJob( $jobId, 'Output too small -- safety check prevented write.' );
			return;
		}

		$write_result = $memory->replace_all( $new_content );
		if ( empty( $write_result['success'] ) ) {
			$this->failJob( $jobId, $write_result['message'] );
			return;
		}

		// Archive extracted content to the daily file. $archived_size
		// is already computed above (was needed for the conservation
		// check).
		$parts = explode( '-', $date );

		if ( $archived_size > 0 ) {
			$archive_context = array(
				'persistent'    => $parsed['persistent'],
				'original_size' => $original_size,
				'new_size'      => $new_size,
				'archived_size' => $archived_size,
				'job_id'        => $jobId,
			);

			/** This filter is documented in DailyMemoryTask.php */
			$handled = apply_filters(
				'datamachine_daily_memory_pre_archive',
				false,
				$archived_text,
				$date,
				$archive_context
			);

			if ( ! $handled ) {
				$archive_header = "\n### Archived from MEMORY.md\n\n";
				$archive_body   = $archive_header . $archived_text . "\n";

				$daily->append( $parts[0], $parts[1], $parts[2], $archive_body );
			}
		}

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Daily memory complete: %s -> %s (%s archived to daily/%s)',
				size_format( $original_size ),
				size_format( $new_size ),
				size_format( $archived_size ),
				$date
			),
			array(
				'date'          => $date,
				'original_size' => $original_size,
				'new_size'      => $new_size,
				'archived_size' => $archived_size,
			)
		);

		$this->completeJob(
			$jobId,
			array(
				'date'              => $date,
				'original_size'     => $original_size,
				'new_size'          => $new_size,
				'archived_size'     => $archived_size,
				'compaction'        => $plan['metadata'],
				'compaction_events' => $plan['events'],
			)
		);
	}

	/**
	 * Plan AI-produced MEMORY.md compaction through Agents API primitives.
	 *
	 * Data Machine supplies the model output and owns persistence. Agents API
	 * supplies the markdown item projection and conservation contract so the
	 * fail-closed decision is shared with the broader agent runtime substrate.
	 *
	 * @param string $original_content Current MEMORY.md content.
	 * @param string $ai_output        Raw AI output.
	 * @param string $date             Archive date.
	 * @param int    $jobId            Job ID.
	 * @param string $provider         Summary provider.
	 * @param string $model            Summary model.
	 * @return array{success: bool, parsed: array{persistent: string|null, archived: string|null}, metadata: array<string, mixed>, events: array<int, array<string, mixed>>, message?: string}
	 */
	private function planMemoryCompaction( string $original_content, string $ai_output, string $date, int $jobId, string $provider, string $model ): array {
		$parsed = $this->parseCleanupResponse( $ai_output );
		if ( empty( $parsed['persistent'] ) ) {
			return array(
				'success'  => true,
				'parsed'   => $parsed,
				'metadata' => array(),
				'events'   => array(),
			);
		}

		$persistent_text = $parsed['persistent'];
		$archived_text   = $parsed['archived'] ?? '';

		$original_items  = WP_Agent_Markdown_Section_Compaction_Adapter::parse( $original_content );
		$compacted_items = WP_Agent_Markdown_Section_Compaction_Adapter::parse( $persistent_text );
		$archived_items  = '' === trim( $archived_text ) ? array() : WP_Agent_Markdown_Section_Compaction_Adapter::parse( $archived_text );
		$original_size   = strlen( $original_content );
		$new_size        = strlen( $persistent_text );
		$archived_size   = strlen( $archived_text );
		$combined_size   = $new_size + $archived_size;

		/**
		 * Filter the conservation threshold for daily memory compaction.
		 *
		 * The persistent section plus the archived section must together
		 * account for at least this fraction of the original MEMORY.md
		 * size. Below the threshold the task fails rather than commit a
		 * lossy split. Set to 0 to disable the check.
		 *
		 * @since 0.80.3
		 *
		 * @param float $threshold Default 0.85.
		 * @param array $context   date, original_size, new_size, archived_size, job_id.
		 */
		$conservation_threshold = (float) apply_filters(
			'datamachine_daily_memory_conservation_threshold',
			0.85,
			array(
				'date'          => $date,
				'original_size' => $original_size,
				'new_size'      => $new_size,
				'archived_size' => $archived_size,
				'job_id'        => $jobId,
			)
		);

		/**
		 * Filter the maximum combined size ratio for daily memory compaction.
		 *
		 * The persistent section plus archived section should account for the
		 * original MEMORY.md without substantially exceeding it. A large expansion
		 * indicates the model duplicated content into both sections, which makes the
		 * archive log misleading and leaves MEMORY.md bloated.
		 *
		 * @since 0.148.4
		 *
		 * @param float $threshold Default 1.15. Set to 0 to disable.
		 * @param array $context   date, original_size, new_size, archived_size, job_id.
		 */
		$max_combined_ratio = (float) apply_filters(
			'datamachine_daily_memory_max_combined_ratio',
			1.15,
			array(
				'date'          => $date,
				'original_size' => $original_size,
				'new_size'      => $new_size,
				'archived_size' => $archived_size,
				'job_id'        => $jobId,
			)
		);

		$policy = array(
			'conservation_enabled'         => $conservation_threshold > 0,
			'minimum_conserved_byte_ratio' => $conservation_threshold,
			'fail_on_conservation_failure' => true,
			'summary_provider'             => $provider,
			'summary_model'                => $model,
		);

		$metadata = WP_Agent_Compaction_Conservation::metadata(
			$policy,
			$original_items,
			$compacted_items,
			array(),
			$archived_items,
			array(
				'status'        => 'compacted',
				'strategy'      => 'ai_markdown_memory_compaction',
				'date'          => $date,
				'job_id'        => $jobId,
				'combined_size' => $combined_size,
			),
			array(
				'item_count' => count( $original_items ),
				'byte_count' => $original_size,
			),
			array(
				'item_count' => count( $compacted_items ),
				'byte_count' => $new_size,
			),
			null,
			array(
				'item_count' => count( $archived_items ),
				'byte_count' => $archived_size,
			)
		);

		if ( WP_Agent_Compaction_Conservation::failed_closed( $metadata ) ) {
			$discarded = max( 0, $original_size - $combined_size );
			do_action(
				'datamachine_log',
				'warning',
				sprintf(
					'Daily memory aborted -- conservation check failed: persistent (%s) + archived (%s) = %s, expected at least %s of %s original (~%s discarded). AI output did not satisfy the persistent/archive partition contract.',
					size_format( $new_size ),
					size_format( $archived_size ),
					size_format( $combined_size ),
					size_format( (int) ( $metadata['conservation']['required_byte_count'] ?? 0 ) ),
					size_format( $original_size ),
					size_format( $discarded )
				),
				array(
					'date'           => $date,
					'original_size'  => $original_size,
					'new_size'       => $new_size,
					'archived_size'  => $archived_size,
					'combined_size'  => $combined_size,
					'min_combined'   => (int) ( $metadata['conservation']['required_byte_count'] ?? 0 ),
					'discarded_size' => $discarded,
					'threshold'      => $conservation_threshold,
					'compaction'     => $metadata,
				)
			);

			$metadata['status'] = 'failed';
			return array(
				'success'  => false,
				'parsed'   => $parsed,
				'metadata' => $metadata,
				'events'   => array(
					array(
						'type'     => 'compaction_failed',
						'metadata' => $metadata,
					),
				),
				'message'  => 'Conservation check failed -- AI emitted a lossy split. MEMORY.md unchanged.',
			);
		}

		if ( $conservation_threshold > 0 && $max_combined_ratio > 0 ) {
			$max_combined_size = (int) ceil( $original_size * $max_combined_ratio );
			if ( $combined_size > $max_combined_size ) {
				do_action(
					'datamachine_log',
					'warning',
					sprintf(
						'Daily memory aborted -- compaction expanded content: persistent (%s) + archived (%s) = %s, allowed at most %s of %s original. AI likely duplicated archived content instead of moving it.',
						size_format( $new_size ),
						size_format( $archived_size ),
						size_format( $combined_size ),
						size_format( $max_combined_size ),
						size_format( $original_size )
					),
					array(
						'date'              => $date,
						'original_size'     => $original_size,
						'new_size'          => $new_size,
						'archived_size'     => $archived_size,
						'combined_size'     => $combined_size,
						'max_combined_size' => $max_combined_size,
						'max_ratio'         => $max_combined_ratio,
						'compaction'        => $metadata,
					)
				);

				$metadata['status'] = 'failed';
				return array(
					'success'  => false,
					'parsed'   => $parsed,
					'metadata' => $metadata,
					'events'   => array(
						array(
							'type'     => 'compaction_failed',
							'metadata' => $metadata,
						),
					),
					'message'  => 'Compaction expanded content -- AI likely duplicated archived content. MEMORY.md unchanged.',
				);
			}
		}

		return array(
			'success'  => true,
			'parsed'   => $parsed,
			'metadata' => $metadata,
			'events'   => array(
				array(
					'type'     => 'compaction_completed',
					'metadata' => $metadata,
				),
			),
		);
	}

	/**
	 * Build a completion policy for daily memory cleanup responses.
	 *
	 * The model may need more than one pass to satisfy a size target without
	 * losing information. This policy keeps iteration inside the shared Agents API
	 * conversation loop instead of hand-rolling retries in the task.
	 *
	 * @param string $original_content Current MEMORY.md content.
	 * @param string $date             Archive date.
	 * @param int    $jobId            Job ID.
	 * @param string $provider         Summary provider.
	 * @param string $model            Summary model.
	 * @return WP_Agent_Conversation_Completion_Policy
	 */
	private function buildCleanupCompletionPolicy( string $original_content, string $date, int $jobId, string $provider, string $model ): WP_Agent_Conversation_Completion_Policy {
		$validator = function ( string $assistant_text, int $turn_count ) use ( $original_content, $date, $jobId, $provider, $model ): WP_Agent_Conversation_Completion_Decision {
			$parsed = $this->parseCleanupResponse( $assistant_text );
			if ( empty( $parsed['persistent'] ) ) {
				return WP_Agent_Conversation_Completion_Decision::incomplete(
					'Daily memory completion policy: missing persistent section.',
					array(
						'turn_count'           => $turn_count,
						'continuation_message' => 'Your response must use the exact required format with both `===PERSISTENT===` and `===ARCHIVED===`. Return the full corrected split now.',
					)
				);
			}

			$plan = $this->planMemoryCompaction( $original_content, $assistant_text, $date, $jobId, $provider, $model );
			if ( empty( $plan['success'] ) ) {
				$persistent_size    = strlen( (string) $parsed['persistent'] );
				$archived_size      = strlen( (string) $parsed['archived'] );
				$combined_size      = $persistent_size + $archived_size;
				$max_combined_ratio = (float) apply_filters( 'datamachine_daily_memory_max_combined_ratio', 1.15 );
				$max_combined_size  = $max_combined_ratio > 0 ? (int) ceil( strlen( $original_content ) * $max_combined_ratio ) : 0;
				$continuation       = 'The split failed the conservation checks. Return a corrected full split that preserves every fact exactly once: persistent facts in `===PERSISTENT===`, archived/session-specific detail in `===ARCHIVED===`, with no duplicated archived content.';

				if ( $max_combined_size > 0 && $combined_size > $max_combined_size ) {
					$continuation = sprintf(
						'The split failed because total output expanded too much: `===PERSISTENT===` is %s, `===ARCHIVED===` is %s, combined is %s, and the allowed combined maximum is %s. Return a corrected full split with `===PERSISTENT===` at or below %s and combined PERSISTENT + ARCHIVED at or below %s. Condense ARCHIVED into compact retrievable notes instead of preserving verbose prose or duplicated context.',
						size_format( $persistent_size ),
						size_format( $archived_size ),
						size_format( $combined_size ),
						size_format( $max_combined_size ),
						size_format( AgentMemory::MAX_FILE_SIZE ),
						size_format( $max_combined_size )
					);
				}

				return WP_Agent_Conversation_Completion_Decision::incomplete(
					'Daily memory completion policy: conservation check failed.',
					array(
						'turn_count'           => $turn_count,
						'plan_message'         => $plan['message'] ?? 'Compaction failed conservation checks.',
						'persistent_size'      => $persistent_size,
						'archived_size'        => $archived_size,
						'combined_size'        => $combined_size,
						'max_combined_size'    => $max_combined_size,
						'continuation_message' => $continuation,
					)
				);
			}

			$persistent_size = strlen( (string) $parsed['persistent'] );
			$original_size   = strlen( $original_content );

			// Overflow-aware acceptance target for the persistent section.
			//
			// When MEMORY.md is already far over MAX_FILE_SIZE, demanding the
			// persistent section drop to MAX_FILE_SIZE in a single bounded
			// conversation (default 3 turns) is frequently unsatisfiable: the
			// model can rarely thread a clean, non-duplicating, conservation-
			// passing partition that also lands under budget in one pass, so
			// the loop exhausts its turns and the job fails every day, freezing
			// the agent's memory (issue #2775).
			//
			// Instead of failing closed forever, accept a split that makes
			// meaningful FORWARD PROGRESS toward budget. The acceptance ceiling
			// scales with how far over budget the file is, mirroring the
			// deterministic-overflow `oversize_factor` precedent below: a file
			// that is N* over budget need only shrink to a fraction of its
			// original size this pass, and subsequent daily runs compound the
			// progress until it converges on MAX_FILE_SIZE. Near-budget files
			// keep the strict MAX_FILE_SIZE target, so their fail-closed
			// protection is unchanged.
			$max_size            = AgentMemory::MAX_FILE_SIZE;
			$oversize_factor     = $original_size / max( $max_size, 1 );
			$acceptable_max_size = $max_size;
			if ( $oversize_factor > 1 ) {
				// Require the persistent section to drop to at most this
				// fraction of the original size this pass. The fraction
				// tightens as the file approaches budget (it can never exceed
				// the strict target once within ~1x), guaranteeing strict
				// per-run progress while remaining reachable for a far-over
				// file. Floored at MAX_FILE_SIZE so the target never relaxes
				// below budget.
				$progress_ratio      = (float) apply_filters(
					'datamachine_daily_memory_overflow_progress_ratio',
					0.75,
					array(
						'date'            => $date,
						'job_id'          => $jobId,
						'original_size'   => $original_size,
						'persistent_size' => $persistent_size,
						'oversize_factor' => $oversize_factor,
						'max_size'        => $max_size,
					)
				);
				$progress_ratio      = min( 1.0, max( 0.0, $progress_ratio ) );
				$acceptable_max_size = max( $max_size, (int) floor( $original_size * $progress_ratio ) );
			}

			if ( $persistent_size > $acceptable_max_size ) {
				return WP_Agent_Conversation_Completion_Decision::incomplete(
					'Daily memory completion policy: persistent memory remains oversized.',
					array(
						'turn_count'           => $turn_count,
						'original_size'        => $original_size,
						'persistent_size'      => $persistent_size,
						'max_size'             => $max_size,
						'acceptable_max_size'  => $acceptable_max_size,
						'continuation_message' => sprintf(
							'The `===PERSISTENT===` section is still %s. For this pass it must be at or below %s (the file is far over the %s budget, so it should shrink toward budget now and converge over subsequent runs). Return a corrected full split. Keep durable facts, archive session-specific detail, condense overlapping entries, and shrink `===PERSISTENT===` to at or below %s without discarding information.',
							size_format( $persistent_size ),
							size_format( $acceptable_max_size ),
							size_format( $max_size ),
							size_format( $acceptable_max_size )
						),
					)
				);
			}

			return WP_Agent_Conversation_Completion_Decision::complete(
				'Daily memory completion policy satisfied.',
				array(
					'turn_count'      => $turn_count,
					'original_size'   => $original_size,
					'persistent_size' => $persistent_size,
					'max_size'        => AgentMemory::MAX_FILE_SIZE,
				)
			);
		};

		return new class( $validator ) implements WP_Agent_Conversation_Completion_Policy, NaturalCompletionPolicyInterface {
			/** @var callable */
			private $validator;

			/** @param callable $validator Completion validator. */
			public function __construct( callable $validator ) {
				$this->validator = $validator;
			}

			/** @inheritDoc */
			public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): WP_Agent_Conversation_Completion_Decision {
				unset( $tool_name, $tool_def, $tool_result, $runtime_context, $turn_count );
				return WP_Agent_Conversation_Completion_Decision::incomplete();
			}

			/** @inheritDoc */
			public function recordNaturalCompletion( array $messages, string $assistant_text, array $runtime_context, int $turn_count ): WP_Agent_Conversation_Completion_Decision {
				unset( $messages, $runtime_context );
				return call_user_func( $this->validator, $assistant_text, $turn_count );
			}
		};
	}

	/**
	 * Deterministically split very large MEMORY.md files before invoking AI.
	 *
	 * Extremely large memory files can exceed the practical request envelope for
	 * non-streaming provider calls. This path archives whole tail sections verbatim
	 * and leaves a small persistent file with an archive pointer, preserving every
	 * byte without asking the model to process the entire oversized file.
	 *
	 * @param int         $jobId          Job ID.
	 * @param AgentMemory $memory         Agent memory facade.
	 * @param DailyMemory $daily          Daily memory facade.
	 * @param string      $memory_content Current MEMORY.md content.
	 * @param int         $original_size  Original byte size.
	 * @param string      $date           Archive date.
	 * @return array|null Result array when handled, null when normal AI compaction should proceed.
	 */
	private function maybeHandleDeterministicOverflow( int $jobId, AgentMemory $memory, DailyMemory $daily, string $memory_content, int $original_size, string $date ): ?array {
		$threshold = (int) apply_filters(
			'datamachine_daily_memory_overflow_threshold',
			AgentMemory::MAX_FILE_SIZE * 4,
			array(
				'job_id'        => $jobId,
				'date'          => $date,
				'original_size' => $original_size,
			)
		);

		if ( $threshold <= 0 || $original_size <= $threshold ) {
			return null;
		}

		$target_size = (int) apply_filters(
			'datamachine_daily_memory_overflow_target_size',
			AgentMemory::MAX_FILE_SIZE,
			array(
				'job_id'        => $jobId,
				'date'          => $date,
				'original_size' => $original_size,
			)
		);
		$target_size = max( 1024, $target_size );

		$split = self::planMemoryOverflowArchive( $memory_content, $target_size, $date );
		if ( empty( $split['archived'] ) ) {
			return null;
		}

		$write_result = $memory->replace_all( $split['persistent'] );
		if ( empty( $write_result['success'] ) ) {
			return array(
				'success' => false,
				'message' => $write_result['message'],
			);
		}

		$parts        = explode( '-', $date );
		$archive_body = "\n### Archived from oversized MEMORY.md\n\n" . $split['archived'] . "\n";
		$append       = $daily->append( $parts[0], $parts[1], $parts[2], $archive_body );
		if ( empty( $append['success'] ) ) {
			return array(
				'success' => false,
				'message' => $append['message'],
			);
		}

		$archived_size = strlen( $split['archived'] );
		$new_size      = strlen( $split['persistent'] );

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Daily memory overflow split complete: %s -> %s (%s archived verbatim to daily/%s)',
				size_format( $original_size ),
				size_format( $new_size ),
				size_format( $archived_size ),
				$date
			),
			array(
				'date'              => $date,
				'original_size'     => $original_size,
				'new_size'          => $new_size,
				'archived_size'     => $archived_size,
				'archived_blocks'   => $split['archived_blocks'],
				'persistent_blocks' => $split['persistent_blocks'],
			)
		);

		return array(
			'success'           => true,
			'date'              => $date,
			'original_size'     => $original_size,
			'new_size'          => $new_size,
			'archived_size'     => $archived_size,
			'overflow_split'    => true,
			'archived_blocks'   => $split['archived_blocks'],
			'persistent_blocks' => $split['persistent_blocks'],
		);
	}

	/**
	 * Plan a deterministic overflow archive through Agents API compaction primitives.
	 *
	 * @param string $content     Full MEMORY.md content.
	 * @param int    $target_size Target persistent size in bytes.
	 * @param string $date        Archive date.
	 * @return array{persistent: string, archived: string, persistent_blocks: int, archived_blocks: int}
	 */
	private static function planMemoryOverflowArchive( string $content, int $target_size, string $date ): array {
		$items = WP_Agent_Markdown_Section_Compaction_Adapter::parse( $content );
		$split = WP_Agent_Markdown_Section_Compaction_Adapter::split_for_overflow(
			$items,
			array(
				'target_bytes'         => $target_size,
				'pointer_destination'  => 'daily/' . str_replace( '-', '/', $date ) . '.md',
				'pointer_heading'      => 'Archived Memory Overflow',
				'pointer_content'      => self::buildOverflowArchivePointerContent( $date ),
				'conservation_enabled' => true,
			)
		);

		if ( WP_Agent_Markdown_Section_Compaction_Adapter::STATUS_ARCHIVED !== ( $split['status'] ?? '' ) || empty( $split['archive_items'] ) ) {
			return array(
				'persistent'        => $content,
				'archived'          => '',
				'persistent_blocks' => count( $items ),
				'archived_blocks'   => 0,
			);
		}
		return array(
			'persistent'        => WP_Agent_Markdown_Section_Compaction_Adapter::reconstruct( $split['retained_items'] ),
			'archived'          => WP_Agent_Markdown_Section_Compaction_Adapter::reconstruct( $split['archive_items'] ),
			'persistent_blocks' => count( $split['retained_items'] ),
			'archived_blocks'   => count( $split['archive_items'] ),
		);
	}

	/**
	 * Build the Data Machine-owned overflow archive pointer body.
	 *
	 * @param string $date Archive date.
	 * @return string Pointer content.
	 */
	private static function buildOverflowArchivePointerContent( string $date ): string {
		return sprintf(
			"\nOn %s, Daily Memory archived older MEMORY.md sections verbatim to `daily/%s`. Use daily memory search/read when those details are needed.\n",
			$date,
			str_replace( '-', '/', $date ) . '.md'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getTaskType(): string {
		return 'daily_memory_generation';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'Daily Memory',
			'description'     => 'Automated MEMORY.md maintenance -- archives session-specific content to daily files, compresses and cleans persistent knowledge.',
			'setting_key'     => 'daily_memory_enabled',
			'default_enabled' => false,
			'supports_run'    => true,
		);
	}

	/**
	 * @return array
	 * @since 0.41.0
	 */
	public function getPromptDefinitions(): array {
		return array(
			'daily_memory' => array(
				'label'       => __( 'Daily Memory Prompt', 'data-machine' ),
				'description' => __( 'Prompt for automated MEMORY.md maintenance. Cleans, compresses, and archives session-specific content.', 'data-machine' ),
				'default'     => "You are maintaining an AI agent's MEMORY.md file. This file is injected into every AI context window, so the PERSISTENT output must stay lean and fit under {{max_size}}.\n\n"
					. "## Goal\n\n"
					. "Produce a true partition of the current MEMORY.md:\n"
					. "- PERSISTENT becomes the new MEMORY.md. It contains compact durable facts needed in future sessions.\n"
					. "- ARCHIVED is appended to the daily file for {{date}}. It contains the original detail that was moved out of MEMORY.md.\n"
					. "- Each fact or detail from the source appears in exactly one output section. If a detail is archived, leave only a short durable summary or archive pointer in PERSISTENT.\n\n"
					. "## What Belongs In PERSISTENT\n\n"
					. "Keep concise facts that remain useful regardless of when the next session runs:\n"
					. "- Current architecture, APIs, command surfaces, paths, URLs, credentials locations without secrets, and ownership facts.\n"
					. "- Stable preferences, rules, naming conventions, and operational constraints.\n"
					. "- Current project state summarized as outcome and next action, not the investigation story.\n"
					. "- Cross-system relationships that prevent future rediscovery.\n\n"
					. "Use compact bullets. Merge overlapping entries. Replace long historical sections with short durable summaries plus archive pointers.\n\n"
					. "## What Belongs In ARCHIVED\n\n"
					. "Move details that future sessions can retrieve from daily memory instead of loading every time:\n"
					. "- Session play-by-play, troubleshooting traces, command transcripts, old release state, and temporary blockers.\n"
					. "- Background stories that led to a durable fact.\n"
					. "- Long lists where PERSISTENT only needs the current canonical pointer or summary.\n"
					. "- Repeated facts already captured by a shorter persistent entry.\n\n"
					. "## Acceptance Criteria\n\n"
					. "- PERSISTENT is under {{max_size}}.\n"
					. "- PERSISTENT and ARCHIVED are a partition: content moved to ARCHIVED is absent from PERSISTENT except for a concise summary or pointer.\n"
					. "- PERSISTENT remains a valid MEMORY.md document with useful headings, but headings may be merged, renamed, or removed to meet the size target.\n"
					. "- ARCHIVED preserves moved detail well enough that daily memory search/read can recover it later.\n\n"
					. "## Output Format\n\n"
					. "Respond in EXACTLY this format:\n\n"
					. "===PERSISTENT===\n"
					. "(new MEMORY.md content, compact and under {{max_size}})\n\n"
					. "===ARCHIVED===\n"
					. "(details moved out of MEMORY.md, organized by topic)\n\n"
					. '{{activity_section}}'
					. "---\n\n"
					. "## Current MEMORY.md Content\n\n"
					. '{{memory_content}}',
				'variables'   => array(
					'memory_content'   => 'Current full content of MEMORY.md',
					'date'             => 'Target date for archival context (YYYY-MM-DD)',
					'max_size'         => 'Recommended maximum file size (human-readable, e.g. "8 KB")',
					'activity_section' => 'Activity context section (jobs and chat sessions from the day, if any)',
				),
			),
		);
	}

	/**
	 * @param string $ai_output Raw AI response text.
	 * @return array{persistent: string|null, archived: string|null}
	 */
	private function parseCleanupResponse( string $ai_output ): array {
		$persistent = null;
		$archived   = null;

		$persistent_pos = strpos( $ai_output, '===PERSISTENT===' );
		$archived_pos   = strpos( $ai_output, '===ARCHIVED===' );

		if ( false !== $persistent_pos && false !== $archived_pos ) {
			$persistent_start = $persistent_pos + strlen( '===PERSISTENT===' );

			if ( $archived_pos > $persistent_pos ) {
				$persistent = trim( substr( $ai_output, $persistent_start, $archived_pos - $persistent_start ) );
				$archived   = trim( substr( $ai_output, $archived_pos + strlen( '===ARCHIVED===' ) ) );
			} else {
				$archived_start = $archived_pos + strlen( '===ARCHIVED===' );
				$archived       = trim( substr( $ai_output, $archived_start, $persistent_pos - $archived_start ) );
				$persistent     = trim( substr( $ai_output, $persistent_start ) );
			}
		} elseif ( false !== $persistent_pos ) {
			$persistent = trim( substr( $ai_output, $persistent_pos + strlen( '===PERSISTENT===' ) ) );
		}

		return array(
			'persistent' => $persistent,
			'archived'   => $archived,
		);
	}

	/**
	 * @param array $params Task params.
	 * @return string Combined context text.
	 */
	private function gatherContext( array $params ): string {
		$date  = $params['date'] ?? gmdate( 'Y-m-d' );
		$parts = array();

		$jobs_context = $this->getJobsContext( $date );
		if ( ! empty( $jobs_context ) ) {
			$parts[] = "## Jobs completed on {$date}\n\n{$jobs_context}";
		}

		$chat_context = $this->getChatContext( $date );
		if ( ! empty( $chat_context ) ) {
			$parts[] = "## Chat sessions on {$date}\n\n{$chat_context}";
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * @param string $date Date string (Y-m-d).
	 * @return string
	 */
	private function getJobsContext( string $date ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id, pipeline_id, flow_id, source, label, status,
						created_at, completed_at
				 FROM {$table}
				 WHERE DATE(created_at) = %s
				 ORDER BY job_id ASC",
				$date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $jobs ) ) {
			return '';
		}

		$lines = array();
		foreach ( $jobs as $job ) {
			$label   = $job['label'] ? $job['label'] : "Job #{$job['job_id']}";
			$status  = $job['status'];
			$source  = $job['source'];
			$lines[] = "- [{$source}] {$label}: {$status}";
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param string $date Date string (Y-m-d).
	 * @return string
	 */
	private function getChatContext( string $date ): string {
		$sessions = ConversationStoreFactory::get()->list_sessions_for_day( $date );

		if ( empty( $sessions ) ) {
			return '';
		}

		$lines = array();
		foreach ( $sessions as $session ) {
			$title   = ! empty( $session['title'] ) ? $session['title'] : 'Untitled session';
			$mode    = $session['mode'] ?? 'chat';
			$lines[] = "- [{$mode}] {$title}";
		}

		return implode( "\n", $lines );
	}
}
