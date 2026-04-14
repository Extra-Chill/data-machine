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
 * @see https://github.com/Extra-Chill/data-machine/issues/357
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\PluginSettings;
use DataMachine\Core\FilesRepository\AgentMemory;
use DataMachine\Core\FilesRepository\DailyMemory;
use DataMachine\Engine\AI\RequestBuilder;

class DailyMemoryTask extends SystemTask {

	/**
	 * Execute daily memory maintenance.
	 *
	 * Single-phase operation: read MEMORY.md, gather activity context,
	 * send one AI call to clean/compress/archive, write results.
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function execute( int $jobId, array $params ): void {
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

		$date  = $params['date'] ?? gmdate( 'Y-m-d' );
		$daily = new DailyMemory();

		// Read current MEMORY.md.
		$memory = new AgentMemory();
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

		$response = RequestBuilder::build(
			$messages,
			$provider,
			$model,
			array(),
			'system',
			array()
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
		$new_content = $parsed['persistent'];
		$new_size    = strlen( $new_content );

		// Safety check: don't write if the new content is suspiciously small.
		$target_size     = AgentMemory::MAX_FILE_SIZE;
		$oversize_factor = $original_size / max( $target_size, 1 );

		if ( $oversize_factor > 2 ) {
			// File is more than 2x over budget -- allow reduction down to half the target.
			$min_size = intval( $target_size * 0.5 );
		} else {
			// File is near budget -- don't allow reduction below 10% of original.
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

		// Write cleaned MEMORY.md.
		$fs = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
		$fs->put_contents( $memory->get_file_path(), $new_content );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $memory->get_file_path() );

		// Archive extracted content to the daily file.
		$archived_size = 0;
		$parts         = explode( '-', $date );

		if ( ! empty( $parsed['archived'] ) ) {
			$archived_size = strlen( $parsed['archived'] );

			$archive_context = array(
				'persistent'    => $parsed['persistent'],
				'original_size' => $original_size,
				'new_size'      => $new_size,
				'archived_size' => $archived_size,
				'job_id'        => $jobId,
			);

			/**
			 * Filters whether the default daily file archive write should be skipped.
			 *
			 * Developers can return `true` to handle archived content storage
			 * themselves (e.g., creating a WordPress post or page, sending to
			 * an external service, etc.). When `true` is returned, the flat-file
			 * write to `daily/YYYY/MM/DD.md` is skipped entirely.
			 *
			 * For a complete storage backend override (read, list, search, delete),
			 * see the `datamachine_daily_memory_storage` filter in DailyMemoryAbilities.
			 *
			 * @since 0.46.0
			 *
			 * @param bool   $handled Whether a handler has already stored the content.
			 *                        Default false (flat-file write proceeds).
			 * @param string $content The archived content extracted from MEMORY.md.
			 * @param string $date    The archive date (YYYY-MM-DD).
			 * @param array  $context {
			 *     Additional context about the daily memory operation.
			 *
			 *     @type string $persistent    The persistent content remaining in MEMORY.md.
			 *     @type int    $original_size Original MEMORY.md size in bytes.
			 *     @type int    $new_size      New MEMORY.md size in bytes after cleanup.
			 *     @type int    $archived_size Archived content size in bytes.
			 *     @type int    $job_id        The job ID for this task execution.
			 * }
			 */
			$handled = apply_filters(
				'datamachine_daily_memory_pre_archive',
				false,
				$parsed['archived'],
				$date,
				$archive_context
			);

			if ( ! $handled ) {
				$archive_header = "\n### Archived from MEMORY.md\n\n";
				$archive_text   = $archive_header . $parsed['archived'] . "\n";

				$daily->append( $parts[0], $parts[1], $parts[2], $archive_text );
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
			'trigger'         => 'Daily at midnight UTC',
			'trigger_type'    => 'cron',
			'supports_run'    => true,
		);
	}

	/**
	 * Get the single editable prompt definition for this task.
	 *
	 * One prompt handles the full memory maintenance cycle: read MEMORY.md,
	 * incorporate activity context, clean/compress, split into persistent
	 * and archived sections.
	 *
	 * @return array Prompt definitions keyed by prompt key.
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
					. "{{activity_section}}"
					. "---\n\n"
					. "## Current MEMORY.md Content\n\n"
					. "{{memory_content}}",
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
	 * Parse the AI response into persistent and archived sections.
	 *
	 * Expects the AI output to contain two clearly delimited sections:
	 * - `===PERSISTENT===` followed by the cleaned MEMORY.md content
	 * - `===ARCHIVED===` followed by the session-specific content to archive
	 *
	 * @since 0.38.0
	 * @param string $ai_output Raw AI response text.
	 * @return array{persistent: string|null, archived: string|null}
	 */
	private function parseCleanupResponse( string $ai_output ): array {
		$persistent = null;
		$archived   = null;

		// Find the delimiters.
		$persistent_pos = strpos( $ai_output, '===PERSISTENT===' );
		$archived_pos   = strpos( $ai_output, '===ARCHIVED===' );

		if ( false !== $persistent_pos && false !== $archived_pos ) {
			// Both sections present -- extract content between delimiters.
			$persistent_start = $persistent_pos + strlen( '===PERSISTENT===' );

			if ( $archived_pos > $persistent_pos ) {
				// Normal order: PERSISTENT then ARCHIVED.
				$persistent = trim( substr( $ai_output, $persistent_start, $archived_pos - $persistent_start ) );
				$archived   = trim( substr( $ai_output, $archived_pos + strlen( '===ARCHIVED===' ) ) );
			} else {
				// Reversed order: ARCHIVED then PERSISTENT.
				$archived_start = $archived_pos + strlen( '===ARCHIVED===' );
				$archived       = trim( substr( $ai_output, $archived_start, $persistent_pos - $archived_start ) );
				$persistent     = trim( substr( $ai_output, $persistent_start ) );
			}
		} elseif ( false !== $persistent_pos ) {
			// Only PERSISTENT section -- no content to archive.
			$persistent = trim( substr( $ai_output, $persistent_pos + strlen( '===PERSISTENT===' ) ) );
		}

		return array(
			'persistent' => $persistent,
			'archived'   => $archived,
		);
	}

	/**
	 * Gather today's activity context from jobs and chat sessions.
	 *
	 * @param array $params Task params (may contain target date override).
	 * @return string Combined context text, empty if nothing happened.
	 */
	private function gatherContext( array $params ): string {
		$date  = $params['date'] ?? gmdate( 'Y-m-d' );
		$parts = array();

		// Gather pipeline and system jobs from today.
		$jobs_context = $this->getJobsContext( $date );
		if ( ! empty( $jobs_context ) ) {
			$parts[] = "## Jobs completed on {$date}\n\n{$jobs_context}";
		}

		// Gather chat sessions from today.
		$chat_context = $this->getChatContext( $date );
		if ( ! empty( $chat_context ) ) {
			$parts[] = "## Chat sessions on {$date}\n\n{$chat_context}";
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Get a summary of today's jobs.
	 *
	 * @param string $date Date string (Y-m-d).
	 * @return string
	 */
	private function getJobsContext( string $date ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Get a summary of today's chat sessions.
	 *
	 * @param string $date Date string (Y-m-d).
	 * @return string
	 */
	private function getChatContext( string $date ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_chat_sessions';

		// Check if the table exists first.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( ! $table_exists ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT session_id, title, context, created_at
				 FROM {$table}
				 WHERE DATE(created_at) = %s
				 ORDER BY created_at ASC",
				$date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( empty( $sessions ) ) {
			return '';
		}

		$lines = array();
		foreach ( $sessions as $session ) {
			$title   = $session['title'] ? $session['title'] : 'Untitled session';
			$context = $session['context'] ?? 'chat';
			$lines[] = "- [{$context}] {$title}";
		}

		return implode( "\n", $lines );
	}
}
