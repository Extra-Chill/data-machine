<?php
/**
 * Daily Memory Generation Task
 *
 * System agent task that gathers the day's activity (jobs, chat sessions)
 * and uses AI to synthesize a concise daily memory entry. Runs once daily
 * via Action Scheduler when daily_memory_enabled is active.
 *
 * After generating the daily summary, runs a memory cleanup phase that
 * reads MEMORY.md, identifies session-specific content that should be
 * archived to daily files, and rewrites MEMORY.md with only persistent
 * knowledge. This keeps the memory file lean for context window budget.
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
	 * Execute daily memory generation.
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

		$system_defaults = PluginSettings::getAgentModel( 'system' );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			$this->failJob( $jobId, 'No system agent AI provider/model configured.' );
			return;
		}

		$date  = $params['date'] ?? gmdate( 'Y-m-d' );
		$daily = new DailyMemory();
		$parts = explode( '-', $date );

		// Phase 1: Generate daily summary from DM activity (jobs, chat sessions).
		$summary_length = 0;
		$context        = $this->gatherContext( $params );

		if ( ! empty( $context ) ) {
			$prompt   = $this->buildPrompt( $context );
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

			if ( ! empty( $response['success'] ) ) {
				$content = trim( $response['data']['content'] ?? '' );
				// Normalize literal \n sequences to actual newlines.
				$content = str_replace( '\n', "\n", $content );

				if ( ! empty( $content ) ) {
					$result = $daily->append( $parts[0], $parts[1], $parts[2], $content . "\n" );
					if ( ! empty( $result['success'] ) ) {
						$summary_length = strlen( $content );
					}
				}
			}
		}

		// Phase 2: Clean up MEMORY.md — move session-specific content to daily file.
		// This runs regardless of whether Phase 1 found DM activity, because most
		// agent work happens via Kimaki sessions which don't create DM jobs.
		$cleanup_result = $this->cleanupMemory( $date, $provider, $model, $daily );

		$this->completeJob(
			$jobId,
			array(
				'date'           => $date,
				'summary_length' => $summary_length,
				'cleanup'        => $cleanup_result,
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
			'description'     => 'AI-generated daily summary of activity and automatic MEMORY.md cleanup — archives session-specific content to daily files.',
			'setting_key'     => 'daily_memory_enabled',
			'default_enabled' => false,
		);
	}

	/**
	 * Clean up MEMORY.md by archiving session-specific content to the daily file.
	 *
	 * Reads the full MEMORY.md, sends it to AI with instructions to separate
	 * persistent knowledge from session-specific detail, rewrites MEMORY.md
	 * with only persistent content, and appends the extracted session content
	 * to the day's daily memory file.
	 *
	 * Skips cleanup if MEMORY.md is already within the recommended size threshold
	 * (AgentMemory::MAX_FILE_SIZE). Cleanup failures are logged but do not fail
	 * the overall daily memory job — the daily summary is already written.
	 *
	 * @since 0.38.0
	 * @param string      $date     Target date (YYYY-MM-DD).
	 * @param string      $provider AI provider slug.
	 * @param string      $model    AI model identifier.
	 * @param DailyMemory $daily    DailyMemory service instance (reused from summary phase).
	 * @return array{skipped?: bool, reason?: string, original_size?: int, new_size?: int, archived_size?: int}
	 */
	private function cleanupMemory( string $date, string $provider, string $model, DailyMemory $daily ): array {
		$memory = new AgentMemory();
		$result = $memory->get_all();

		if ( empty( $result['success'] ) || empty( $result['content'] ) ) {
			return array(
				'skipped' => true,
				'reason'  => 'MEMORY.md not found or empty.',
			);
		}

		$memory_content = $result['content'];
		$original_size  = strlen( $memory_content );

		// Only clean up if MEMORY.md exceeds the recommended threshold.
		if ( $original_size <= AgentMemory::MAX_FILE_SIZE ) {
			return array(
				'skipped'       => true,
				'reason'        => 'MEMORY.md within recommended size threshold.',
				'original_size' => $original_size,
			);
		}

		$prompt   = $this->buildCleanupPrompt( $memory_content, $date );
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
				'Memory cleanup AI request failed: ' . ( $response['error'] ?? 'Unknown error' ),
				array( 'date' => $date )
			);
			return array(
				'skipped' => true,
				'reason'  => 'AI request failed: ' . ( $response['error'] ?? 'Unknown error' ),
			);
		}

		$ai_output = trim( $response['data']['content'] ?? '' );
		$ai_output = str_replace( '\n', "\n", $ai_output );

		if ( empty( $ai_output ) ) {
			return array(
				'skipped' => true,
				'reason'  => 'AI returned empty cleanup response.',
			);
		}

		// Parse the AI output into the two sections.
		$parsed = $this->parseCleanupResponse( $ai_output );

		if ( empty( $parsed['persistent'] ) ) {
			// Safety: never wipe MEMORY.md if parsing failed.
			do_action(
				'datamachine_log',
				'warning',
				'Memory cleanup parse failed — no persistent section found. MEMORY.md unchanged.',
				array(
					'date'             => $date,
					'ai_output_length' => strlen( $ai_output ),
				)
			);
			return array(
				'skipped' => true,
				'reason'  => 'Could not parse AI cleanup response — persistent section missing.',
			);
		}

		// Write the cleaned MEMORY.md.
		$new_content = $parsed['persistent'];
		$new_size    = strlen( $new_content );

		// Safety check: don't write if the new content is suspiciously small.
		// When the file is massively oversized, the TARGET size is a small
		// percentage of the original, so the minimum ratio must scale accordingly.
		// For a file that's 12x over budget, the target IS ~8% of original.
		$target_size     = AgentMemory::MAX_FILE_SIZE;
		$oversize_factor = $original_size / max( $target_size, 1 );

		if ( $oversize_factor > 2 ) {
			// File is more than 2x over budget — allow reduction down to half the target.
			$min_size = intval( $target_size * 0.5 );
		} else {
			// File is near budget — don't allow reduction below 10% of original.
			$min_size = intval( $original_size * 0.10 );
		}

		if ( $new_size < $min_size ) {
			do_action(
				'datamachine_log',
				'warning',
				sprintf(
					'Memory cleanup aborted — new content (%s) is below minimum (%s). AI may have been too aggressive.',
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
			return array(
				'skipped'       => true,
				'reason'        => 'Cleanup produced suspiciously small output — safety check prevented write.',
				'original_size' => $original_size,
				'new_size'      => $new_size,
			);
		}

		// Write cleaned MEMORY.md via the AgentMemory service.
		// AgentMemory doesn't have a "replace all" method, so we write directly
		// via the filesystem helper using the same path.
		$fs = \DataMachine\Core\FilesRepository\FilesystemHelper::get();
		$fs->put_contents( $memory->get_file_path(), $new_content );
		\DataMachine\Core\FilesRepository\FilesystemHelper::make_group_writable( $memory->get_file_path() );

		// Archive extracted content to the daily file.
		$archived_size = 0;
		if ( ! empty( $parsed['archived'] ) ) {
			$archive_header = "\n### Archived from MEMORY.md\n\n";
			$archive_text   = $archive_header . $parsed['archived'] . "\n";
			$archived_size  = strlen( $parsed['archived'] );
			$parts          = explode( '-', $date );

			$daily->append( $parts[0], $parts[1], $parts[2], $archive_text );
		}

		do_action(
			'datamachine_log',
			'info',
			sprintf(
				'Memory cleanup complete: %s → %s (%s archived to daily/%s)',
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

		return array(
			'original_size' => $original_size,
			'new_size'      => $new_size,
			'archived_size' => $archived_size,
		);
	}

	/**
	 * Parse the AI cleanup response into persistent and archived sections.
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
			// Both sections present — extract content between delimiters.
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
			// Only PERSISTENT section — no content to archive (memory was all persistent).
			$persistent = trim( substr( $ai_output, $persistent_pos + strlen( '===PERSISTENT===' ) ) );
		}

		return array(
			'persistent' => $persistent,
			'archived'   => $archived,
		);
	}

	/**
	 * Build the AI prompt for MEMORY.md cleanup.
	 *
	 * @since 0.38.0
	 * @param string $memory_content Current MEMORY.md content.
	 * @param string $date           Target date for archival context.
	 * @return string
	 */
	private function buildCleanupPrompt( string $memory_content, string $date ): string {
		$max_size = size_format( AgentMemory::MAX_FILE_SIZE );
		return <<<PROMPT
You are a system agent responsible for keeping an AI agent's MEMORY.md file lean and useful.

MEMORY.md is injected into every AI context window, so it must contain ONLY persistent knowledge that is useful across ALL sessions. Session-specific detail belongs in daily memory files, not in MEMORY.md.

## Your Task

Read the MEMORY.md content below and split it into two parts:

### 1. PERSISTENT (stays in MEMORY.md)
Content that should remain because it is useful in every future session:
- Architecture decisions and patterns (how systems are built)
- Configuration facts (paths, URLs, credentials locations, tool names)
- Active project state (what's deployed, what's in progress — NOT session logs)
- Key relationships (repos, people, services and how they connect)
- Lessons learned that prevent repeating mistakes (the RULE, not the story)
- Current status of long-running efforts (just the state, not the history)

Condense aggressively:
- Collapse detailed PR/commit histories into one-line status summaries
- Remove "Session N did X" narratives — extract only the lasting fact
- Merge duplicate/overlapping sections
- Remove anything already captured in daily memory files
- Drop version numbers (they go stale — check live instead)
- Drop "Lessons Learned (continued)" sprawl — keep only unique, actionable rules

### 2. ARCHIVED (moves to daily memory file for {$date})
Content that is session-specific or historical detail:
- Detailed PR descriptions and commit-by-commit narratives
- "What we did in this session" logs
- Debugging stories and investigation traces
- Temporary state that will change soon
- Redundant detail already covered by a condensed persistent entry

## Output Format

You MUST output your response in EXACTLY this format with these delimiters:

===PERSISTENT===
(cleaned MEMORY.md content here — start with the # header, include all sections)

===ARCHIVED===
(session-specific content extracted from MEMORY.md, organized by topic)

## Rules
- NEVER drop information entirely — if it's not persistent, it goes to ARCHIVED
- The persistent section should ideally be under {$max_size} (currently it is much larger)
- Preserve the ## section header structure in MEMORY.md
- Keep the # Agent Memory top-level header
- If a section is entirely session-specific, archive the whole section
- If a section has a mix, keep the persistent facts and archive the detail

---

## Current MEMORY.md Content

{$memory_content}
PROMPT;
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
				"SELECT session_id, title, agent_type, created_at
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
			$type    = $session['agent_type'] ?? 'chat';
			$lines[] = "- [{$type}] {$title}";
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build the AI prompt for daily memory synthesis.
	 *
	 * @param string $context Gathered activity context.
	 * @return string
	 */
	private function buildPrompt( string $context ): string {
		return <<<PROMPT
You are a system agent generating a daily memory entry for an AI agent's memory library.

Below is a summary of today's activity on this WordPress site — jobs that ran, chat sessions that occurred, and their outcomes.

Your task: Synthesize this into a concise, useful daily memory entry in markdown format. Focus on:
- What happened (key events, not every job)
- What was accomplished
- Notable outcomes, failures, or patterns worth remembering
- Any context that would help a future agent session understand what this day was about

Keep it concise — a few paragraphs at most. Use ### headings for sections if needed. Do NOT include raw job IDs or technical metadata. Write as if you're creating notes that a colleague would find useful tomorrow.

---

{$context}
PROMPT;
	}
}
