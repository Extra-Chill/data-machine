<?php
/**
 * GitHub Issue Creation Task for System Agent.
 *
 * Creates GitHub issues via GitHubAbilities for centralized API handling.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.24.0
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\Fetch\GitHubAbilities;

class GitHubIssueTask extends SystemTask {

	/**
	 * Execute GitHub issue creation.
	 *
	 * Delegates to GitHubAbilities::createIssue() for centralized API handling.
	 *
	 * @since 0.24.0
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function execute( int $jobId, array $params ): void {
		$result = GitHubAbilities::createIssue( $params );

		if ( ! $result['success'] ) {
			$this->failJob( $jobId, $result['error'] ?? 'Unknown error creating GitHub issue' );
			return;
		}

		$this->completeJob( $jobId, array(
			'issue_url'    => $result['issue_url'] ?? '',
			'issue_number' => $result['issue_number'] ?? 0,
			'html_url'     => $result['html_url'] ?? '',
			'repo'         => $result['issue']['repo'] ?? '',
			'title'        => $result['issue']['title'] ?? '',
			'message'      => $result['message'] ?? '',
		) );
	}

	/**
	 * Get the task type identifier.
	 *
	 * @since 0.24.0
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'github_create_issue';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'GitHub Issue Creation',
			'description'     => 'Create GitHub issues via the GitHub REST API.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'AI tool call',
			'trigger_type'    => 'tool',
			'supports_run'    => false,
		);
	}
}
