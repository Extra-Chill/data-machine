<?php
/**
 * GitHub Tools — AI agent tools for GitHub read/write operations.
 *
 * Complements the existing GitHubIssueTool (create-only) with read and
 * management capabilities: list issues, list PRs, view issue, close issue,
 * comment on issue, list repos.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 * @since 0.33.0
 */

namespace DataMachine\Engine\AI\Tools\Global;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\Fetch\GitHubAbilities;

defined( 'ABSPATH' ) || exit;

class GitHubTools extends BaseTool {

	/**
	 * Constructor — register all GitHub tools as global tools.
	 */
	public function __construct() {
		$this->registerGlobalTool( 'list_github_issues', array( $this, 'getListIssuesDefinition' ) );
		$this->registerGlobalTool( 'get_github_issue', array( $this, 'getGetIssueDefinition' ) );
		$this->registerGlobalTool( 'manage_github_issue', array( $this, 'getManageIssueDefinition' ) );
		$this->registerGlobalTool( 'list_github_pulls', array( $this, 'getListPullsDefinition' ) );
		$this->registerGlobalTool( 'list_github_repos', array( $this, 'getListReposDefinition' ) );
	}

	// -------------------------------------------------------------------------
	// List Issues
	// -------------------------------------------------------------------------

	/**
	 * Handle list_github_issues tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handleListIssues( array $parameters, array $tool_def = array() ): array {
		$result = GitHubAbilities::listIssues( $parameters );

		if ( empty( $result['success'] ) ) {
			return $this->buildErrorResponse( $result['error'] ?? 'Failed to list issues.', 'list_github_issues' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'list_github_issues',
		);
	}

	/**
	 * Get tool definition for list_github_issues.
	 *
	 * @return array
	 */
	public function getListIssuesDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleListIssues',
			'description' => 'List issues from a GitHub repository. Returns issue numbers, titles, states, labels, and assignees. Use to review open issues, track progress, or find specific issues by label.',
			'parameters'  => array(
				'repo'     => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format (e.g., Extra-Chill/data-machine).',
				),
				'state'    => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Issue state: open, closed, or all. Default: open.',
				),
				'labels'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Comma-separated label names to filter by.',
				),
				'per_page' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Results per page (max: 100). Default: 30.',
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Get Issue
	// -------------------------------------------------------------------------

	/**
	 * Handle get_github_issue tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handleGetIssue( array $parameters, array $tool_def = array() ): array {
		$result = GitHubAbilities::getIssue( $parameters );

		if ( empty( $result['success'] ) ) {
			return $this->buildErrorResponse( $result['error'] ?? 'Failed to get issue.', 'get_github_issue' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'get_github_issue',
		);
	}

	/**
	 * Get tool definition for get_github_issue.
	 *
	 * @return array
	 */
	public function getGetIssueDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleGetIssue',
			'description' => 'Get a single GitHub issue with full details including body, labels, assignees, and comment count.',
			'parameters'  => array(
				'repo'         => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'issue_number' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Issue number.',
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Manage Issue (update, close, comment)
	// -------------------------------------------------------------------------

	/**
	 * Handle manage_github_issue tool call.
	 *
	 * Supports three actions: update, close, comment.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handleManageIssue( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? '';

		if ( 'comment' === $action ) {
			$result = GitHubAbilities::commentOnIssue( $parameters );
		} elseif ( 'close' === $action ) {
			$parameters['state'] = 'closed';
			$result              = GitHubAbilities::updateIssue( $parameters );
		} else {
			// Default: update.
			$result = GitHubAbilities::updateIssue( $parameters );
		}

		if ( empty( $result['success'] ) ) {
			return $this->buildErrorResponse( $result['error'] ?? 'Failed to manage issue.', 'manage_github_issue' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'manage_github_issue',
		);
	}

	/**
	 * Get tool definition for manage_github_issue.
	 *
	 * @return array
	 */
	public function getManageIssueDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleManageIssue',
			'description' => 'Update, close, or comment on a GitHub issue. Use action "update" to change title/body/labels, "close" to close the issue, or "comment" to add a comment.',
			'parameters'  => array(
				'repo'         => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'issue_number' => array(
					'type'        => 'integer',
					'required'    => true,
					'description' => 'Issue number.',
				),
				'action'       => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action: update, close, or comment.',
				),
				'title'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New issue title (update action).',
				),
				'body'         => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'New issue body (update action) or comment text (comment action).',
				),
				'labels'       => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Labels to set (update action). Replaces existing labels.',
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// List Pulls
	// -------------------------------------------------------------------------

	/**
	 * Handle list_github_pulls tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handleListPulls( array $parameters, array $tool_def = array() ): array {
		$result = GitHubAbilities::listPulls( $parameters );

		if ( empty( $result['success'] ) ) {
			return $this->buildErrorResponse( $result['error'] ?? 'Failed to list pull requests.', 'list_github_pulls' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'list_github_pulls',
		);
	}

	/**
	 * Get tool definition for list_github_pulls.
	 *
	 * @return array
	 */
	public function getListPullsDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleListPulls',
			'description' => 'List pull requests from a GitHub repository. Returns PR numbers, titles, states, branches, and merge status.',
			'parameters'  => array(
				'repo'  => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Repository in owner/repo format.',
				),
				'state' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'PR state: open, closed, or all. Default: open.',
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// List Repos
	// -------------------------------------------------------------------------

	/**
	 * Handle list_github_repos tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition.
	 * @return array
	 */
	public function handleListRepos( array $parameters, array $tool_def = array() ): array {
		$result = GitHubAbilities::listRepos( $parameters );

		if ( empty( $result['success'] ) ) {
			return $this->buildErrorResponse( $result['error'] ?? 'Failed to list repos.', 'list_github_repos' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'list_github_repos',
		);
	}

	/**
	 * Get tool definition for list_github_repos.
	 *
	 * @return array
	 */
	public function getListReposDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleListRepos',
			'description' => 'List GitHub repositories for a user or organization. Shows repo names, languages, stars, open issues, and last push date.',
			'parameters'  => array(
				'owner' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'GitHub user or organization name.',
				),
				'sort'  => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Sort by: created, updated, pushed, full_name. Default: updated.',
				),
			),
		);
	}
}

// Self-register the tools.
new GitHubTools();
