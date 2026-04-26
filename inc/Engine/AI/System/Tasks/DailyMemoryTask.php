<?php
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
use DataMachine\Engine\AI\RequestBuilder;

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

		$system_defaults = $this->resolveSystemModel( $params );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			$this->failJob( $jobId, 'No system agent AI provider/model configured.' );
			return;
		}

		$date     = $params['date'] ?? gmdate( 'Y-m-d' );
		$user_id  = (int) ( $params['user_id'] ?? 0 );
		$agent_id = (int) ( $params['agent_id'] ?? 0 );
		$daily    = new DailyMemory( $user_id, $agent_id );

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
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		$ai_payload = array();
		if ( $agent_id > 0 ) {
			$ai_payload['agent_id'] = $agent_id;
		}
		if ( $user_id > 0 ) {
			$ai_payload['user_id'] = $user_id;
		}

		$response = RequestBuilder::build(
			$messages,
			$provider,
			$model,
			array(),
			'system',
			$ai_payload
		);

		if ( empty( $response['success'] ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Daily memory AI request failed: ' . ( $response['error'] ?? 'Unknown error' ),
				array( 'date' => $date )
			);
			$this->failJob( $jobId, 'AI request failed: ' . ( $response['error'] ?? 'Unknown error' ) );
			return;
		}

		$ai_output = trim( $response['data']['content'] ?? '' );
		$ai_output = str_replace( '\n', "\n", $ai_output );

		if ( empty( $ai_output ) ) {
			$this->failJob( $jobId, 'AI returned empty response.' );
			return;
		}

		// Parse the AI output into persistent and archived sections.
		$parsed = $this->parseCleanupResponse( $ai_output );

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

		// Conservation check: persistent + archived must approximately
		// account for the original. The prompt explicitly says "NEVER
		// discard information -- everything goes to either PERSISTENT or
		// ARCHIVED", but a model that ignores that instruction can emit
		// a short ARCHIVED section and silently lose content. Without
		// this gate, the truncated MEMORY.md gets committed and the
		// missing content is gone (the daily file ends up with the AI's
		// _description_ of what it archived, not the content itself).
		//
		// Threshold defaults to 0.85 (combined size must be at least 85%
		// of original) and is filterable for consumers that legitimately
		// expect heavier compression. A value of 0 disables the check
		// entirely (not recommended).
		$combined_size = $new_size + $archived_size;

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
		 * @param float $threshold      Default 0.85.
		 * @param array $context        date, original_size, new_size, archived_size, job_id.
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

		if ( $conservation_threshold > 0 ) {
			$min_combined = (int) ( $original_size * $conservation_threshold );
			if ( $combined_size < $min_combined ) {
				$discarded = $original_size - $combined_size;
				do_action(
					'datamachine_log',
					'warning',
					sprintf(
						'Daily memory aborted -- conservation check failed: persistent (%s) + archived (%s) = %s, expected at least %s of %s original (~%s discarded). AI ignored the "NEVER discard information" rule.',
						size_format( $new_size ),
						size_format( $archived_size ),
						size_format( $combined_size ),
						size_format( $min_combined ),
						size_format( $original_size ),
						size_format( $discarded )
					),
					array(
						'date'           => $date,
						'original_size'  => $original_size,
						'new_size'       => $new_size,
						'archived_size'  => $archived_size,
						'combined_size'  => $combined_size,
						'min_combined'   => $min_combined,
						'discarded_size' => $discarded,
						'threshold'      => $conservation_threshold,
					)
				);
				$this->failJob( $jobId, 'Conservation check failed -- AI emitted a lossy split. MEMORY.md unchanged.' );
				return;
			}
		}

		$write_result = $memory->replace_all( $new_content );
		if ( empty( $write_result['success'] ) ) {
			$this->failJob( $jobId, $write_result['message'] ?? 'Failed to persist cleaned memory.' );
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
				'date'          => $date,
				'original_size' => $original_size,
				'new_size'      => $new_size,
				'archived_size' => $archived_size,
			)
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
				'default'     => "You are maintaining an AI agent's MEMORY.md file. This file is injected into every AI context window, so it must stay lean -- only persistent knowledge that helps across all future sessions.\n\n"
					. "## Principle\n\n"
					. "For each piece of content ask: \"Would a fresh session need this to do its job?\" If yes, keep it. If it only makes sense in the context of a specific session or time period, archive it.\n\n"
					. "## Your Task\n\n"
					. "Split the MEMORY.md content below into two parts:\n\n"
					. "### PERSISTENT -- stays in MEMORY.md\n"
					. "Knowledge useful regardless of when or why the next session runs:\n"
					. "- How things work (architecture, patterns, conventions)\n"
					. "- Where things are (paths, URLs, config locations, tool names)\n"
					. "- Current state of ongoing work (just the status, not the journey)\n"
					. "- Rules and constraints learned from experience\n"
					. "- Relationships between systems, people, and services\n\n"
					. "When condensing, prefer the **lasting fact** over the **story of how we learned it**. Merge overlapping entries. Remove detail that duplicates what is already in daily files or source code.\n\n"
					. "### ARCHIVED -- moves to the daily file for {{date}}\n"
					. "Content tied to a specific session, investigation, or moment in time:\n"
					. "- Play-by-play narratives of what happened in a session\n"
					. "- Debugging traces and investigation logs\n"
					. "- Temporary state that will be outdated soon\n"
					. "- Detail already captured by a condensed persistent entry\n\n"
					. "## Output Format\n\n"
					. "Respond in EXACTLY this format:\n\n"
					. "===PERSISTENT===\n"
					. "(cleaned MEMORY.md content -- preserve existing section structure)\n\n"
					. "===ARCHIVED===\n"
					. "(extracted session-specific content, organized by topic)\n\n"
					. "## Rules\n"
					. "- NEVER discard information -- everything goes to either PERSISTENT or ARCHIVED\n"
					. "- Target size for persistent section: under {{max_size}}\n"
					. "- Preserve the document's existing heading structure\n"
					. "- If a section is entirely temporal, archive the whole section\n"
					. "- If a section mixes persistent facts with session detail, keep the facts and archive the detail\n\n"
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
