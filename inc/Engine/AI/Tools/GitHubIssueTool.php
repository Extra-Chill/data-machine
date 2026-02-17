<?php
/**
 * GitHub Issue Tool — AI agent wrapper for the create-github-issue ability.
 *
 * Provides the AI-callable tool interface for creating GitHub issues.
 * Available only to the system agent.
 *
 * @package DataMachine\Engine\AI\Tools
 * @since 0.24.0
 */

namespace DataMachine\Engine\AI\Tools;

defined( 'ABSPATH' ) || exit;

class GitHubIssueTool extends BaseTool {

	/**
	 * This tool uses async execution via the System Agent.
	 *
	 * @var bool
	 */
	protected bool $async = true;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->registerTool( 'system', 'create_github_issue', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Execute GitHub issue creation by delegating to the ability.
	 *
	 * @since 0.24.0
	 *
	 * @param array $parameters Contains 'title', 'repo', 'body', 'labels'.
	 * @param array $tool_def   Tool definition (unused).
	 * @return array Result with pending status on success.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/create-github-issue' ) : null;

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'GitHub issue creation ability not registered. Ensure WordPress 6.9+ is active.',
				'create_github_issue'
			);
		}

		$input = array(
			'title'  => $parameters['title'] ?? '',
			'repo'   => $parameters['repo'] ?? '',
			'body'   => $parameters['body'] ?? '',
			'labels' => $parameters['labels'] ?? array(),
		);

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'create_github_issue'
			);
		}

		if ( is_int( $result ) && $result > 0 ) {
			return array(
				'success'   => true,
				'pending'   => true,
				'job_id'    => $result,
				'message'   => sprintf( 'GitHub issue creation scheduled (Job #%d).', $result ),
				'tool_name' => 'create_github_issue',
			);
		}

		if ( is_array( $result ) && ! empty( $result['error'] ) ) {
			return $this->buildErrorResponse(
				$result['error'],
				'create_github_issue'
			);
		}

		return $this->buildErrorResponse(
			'Failed to schedule GitHub issue creation.',
			'create_github_issue'
		);
	}

	/**
	 * Get tool definition for AI agents.
	 *
	 * @since 0.24.0
	 *
	 * @return array Tool definition array.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handle_tool_call',
			'description' => 'Create a GitHub issue in a repository. Requires a GitHub PAT configured in settings. Use for bug reports, feature requests, and task tracking.',
			'agent_types' => array( 'system', 'chat' ),
			'parameters'  => array(
				'title'  => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Issue title.',
				),
				'repo'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Repository in owner/repo format. Falls back to default repo in settings.',
				),
				'body'   => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Issue body content. Supports GitHub Markdown.',
				),
				'labels' => array(
					'type'        => 'array',
					'required'    => false,
					'description' => 'Labels to apply to the issue.',
				),
			),
		);
	}
}

// Self-register the tool.
new GitHubIssueTool();
