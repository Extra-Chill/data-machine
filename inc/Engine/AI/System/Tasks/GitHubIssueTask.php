<?php
/**
 * GitHub Issue Creation Task for System Agent.
 *
 * Creates GitHub issues via the GitHub REST API using a Personal Access Token.
 *
 * @package DataMachine\Engine\AI\System\Tasks
 * @since 0.24.0
 */

namespace DataMachine\Engine\AI\System\Tasks;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\PluginSettings;

class GitHubIssueTask extends SystemTask {

	/**
	 * Execute GitHub issue creation.
	 *
	 * @since 0.24.0
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function execute( int $jobId, array $params ): void {
		$title  = trim( $params['title'] ?? '' );
		$body   = $params['body'] ?? '';
		$labels = $params['labels'] ?? array();
		$repo   = trim( $params['repo'] ?? '' );

		if ( empty( $repo ) ) {
			$repo = trim( PluginSettings::get( 'github_default_repo', '' ) );
		}

		if ( empty( $title ) ) {
			$this->failJob( $jobId, 'Missing required parameter: title' );
			return;
		}

		if ( empty( $repo ) ) {
			$this->failJob( $jobId, 'Missing required parameter: repo (and no default configured)' );
			return;
		}

		$pat = PluginSettings::get( 'github_pat', '' );

		if ( empty( $pat ) ) {
			$this->failJob( $jobId, 'GitHub Personal Access Token not configured in settings' );
			return;
		}

		$url = sprintf( 'https://api.github.com/repos/%s/issues', $repo );

		$request_body = array(
			'title' => $title,
		);

		if ( ! empty( $body ) ) {
			$request_body['body'] = $body;
		}

		if ( ! empty( $labels ) && is_array( $labels ) ) {
			$request_body['labels'] = $labels;
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'token ' . $pat,
					'Accept'        => 'application/vnd.github.v3+json',
					'User-Agent'    => 'DataMachine',
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->failJob( $jobId, 'GitHub API request failed: ' . $response->get_error_message() );
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$resp_body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 !== $status_code ) {
			$error_message = $resp_body['message'] ?? 'Unknown error';
			$this->failJob( $jobId, sprintf( 'GitHub API error (%d): %s', $status_code, $error_message ) );
			return;
		}

		$this->completeJob( $jobId, array(
			'issue_url'    => $resp_body['url'] ?? '',
			'issue_number' => $resp_body['number'] ?? 0,
			'html_url'     => $resp_body['html_url'] ?? '',
			'repo'         => $repo,
			'title'        => $title,
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
}
