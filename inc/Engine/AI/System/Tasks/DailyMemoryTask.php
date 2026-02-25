<?php
/**
 * Daily Memory Generation Task
 *
 * System agent task that gathers the day's activity (jobs, chat sessions)
 * and uses AI to synthesize a concise daily memory entry. Runs once daily
 * via Action Scheduler when daily_memory_enabled is active.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.32.0
 * @see https://github.com/Extra-Chill/data-machine/issues/357
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\PluginSettings;
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
			$this->completeJob( $jobId, array(
				'skipped' => true,
				'reason'  => 'Daily memory is disabled.',
			) );
			return;
		}

		$system_defaults = PluginSettings::getAgentModel( 'system' );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			$this->failJob( $jobId, 'No system agent AI provider/model configured.' );
			return;
		}

		// Gather today's activity context.
		$context = $this->gatherContext( $params );

		if ( empty( $context ) ) {
			$this->completeJob( $jobId, array(
				'skipped' => true,
				'reason'  => 'No activity found for today.',
			) );
			return;
		}

		// Build prompt and call AI.
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

		if ( empty( $response['success'] ) ) {
			$this->failJob( $jobId, 'AI request failed: ' . ( $response['error'] ?? 'Unknown error' ) );
			return;
		}

		$content = trim( $response['data']['content'] ?? '' );
		if ( empty( $content ) ) {
			$this->failJob( $jobId, 'AI returned empty response.' );
			return;
		}

		// Normalize literal \n sequences to actual newlines. LLMs sometimes output
		// the two-character string \n instead of a real newline when generating
		// structured text — this is model behavior, not a serialization bug.
		$content = str_replace( '\n', "\n", $content );

		// Write to the target date's daily memory file.
		$date  = $params['date'] ?? gmdate( 'Y-m-d' );
		$daily = new DailyMemory();
		$parts = explode( '-', $date );

		$result = $daily->append( $parts[0], $parts[1], $parts[2], $content . "\n" );

		if ( empty( $result['success'] ) ) {
			$this->failJob( $jobId, 'Failed to write daily memory: ' . ( $result['message'] ?? 'Unknown error' ) );
			return;
		}

		$this->completeJob( $jobId, array(
			'date'           => $date,
			'content_length' => strlen( $content ),
		) );
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
			'description'     => 'AI-generated daily summary of activity — jobs, chat sessions, and key events.',
			'setting_key'     => 'daily_memory_enabled',
			'default_enabled' => false,
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

		if ( empty( $jobs ) ) {
			return '';
		}

		$lines = array();
		foreach ( $jobs as $job ) {
			$label  = $job['label'] ?: "Job #{$job['job_id']}";
			$status = $job['status'];
			$source = $job['source'];
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

		if ( empty( $sessions ) ) {
			return '';
		}

		$lines = array();
		foreach ( $sessions as $session ) {
			$title = $session['title'] ?: 'Untitled session';
			$type  = $session['agent_type'] ?? 'chat';
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
