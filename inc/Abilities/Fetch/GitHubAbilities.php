<?php
/**
 * GitHub Abilities
 *
 * Core business logic for GitHub API interactions: listing issues, PRs,
 * repos, and managing issues (update, close, comment). All GitHub
 * operations — CLI, REST, chat tools, fetch handler — route through here.
 *
 * Auth: Uses the github_pat stored in PluginSettings.
 *
 * @package DataMachine\Abilities\Fetch
 * @since 0.33.0
 */

namespace DataMachine\Abilities\Fetch;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class GitHubAbilities {

	private static bool $registered = false;

	/**
	 * GitHub API base URL.
	 *
	 * @var string
	 */
	const API_BASE = 'https://api.github.com';

	/**
	 * Default per_page for API requests.
	 *
	 * @var int
	 */
	const DEFAULT_PER_PAGE = 30;

	/**
	 * Maximum per_page for API requests.
	 *
	 * @var int
	 */
	const MAX_PER_PAGE = 100;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}
		if ( self::$registered ) {
			return;
		}
		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/list-github-issues',
				array(
					'label'               => 'List GitHub Issues',
					'description'         => 'List issues from a GitHub repository with optional filters',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo' ),
						'properties' => array(
							'repo'     => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'state'    => array(
								'type'        => 'string',
								'description' => 'Issue state: open, closed, all (default: open).',
							),
							'labels'   => array(
								'type'        => 'string',
								'description' => 'Comma-separated list of label names to filter by.',
							),
							'assignee' => array(
								'type'        => 'string',
								'description' => 'Filter by assignee username.',
							),
							'since'    => array(
								'type'        => 'string',
								'description' => 'ISO 8601 timestamp to filter issues updated after this date.',
							),
							'per_page' => array(
								'type'        => 'integer',
								'description' => 'Results per page (default: 30, max: 100).',
							),
							'page'     => array(
								'type'        => 'integer',
								'description' => 'Page number for pagination (default: 1).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'issues'  => array( 'type' => 'array' ),
							'count'   => array( 'type' => 'integer' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'listIssues' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/get-github-issue',
				array(
					'label'               => 'Get GitHub Issue',
					'description'         => 'Get a single GitHub issue with full details',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'issue_number' ),
						'properties' => array(
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'issue_number' => array(
								'type'        => 'integer',
								'description' => 'Issue number.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'issue'   => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'getIssue' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/update-github-issue',
				array(
					'label'               => 'Update GitHub Issue',
					'description'         => 'Update a GitHub issue (title, body, labels, assignees, state)',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'issue_number' ),
						'properties' => array(
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'issue_number' => array(
								'type'        => 'integer',
								'description' => 'Issue number.',
							),
							'title'        => array(
								'type'        => 'string',
								'description' => 'New issue title.',
							),
							'body'         => array(
								'type'        => 'string',
								'description' => 'New issue body.',
							),
							'state'        => array(
								'type'        => 'string',
								'description' => 'Issue state: open or closed.',
							),
							'labels'       => array(
								'type'        => 'array',
								'description' => 'Labels to set on the issue (replaces existing).',
							),
							'assignees'    => array(
								'type'        => 'array',
								'description' => 'Assignees to set on the issue (replaces existing).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'issue'   => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'updateIssue' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/comment-github-issue',
				array(
					'label'               => 'Comment on GitHub Issue',
					'description'         => 'Add a comment to a GitHub issue',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo', 'issue_number', 'body' ),
						'properties' => array(
							'repo'         => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'issue_number' => array(
								'type'        => 'integer',
								'description' => 'Issue number.',
							),
							'body'         => array(
								'type'        => 'string',
								'description' => 'Comment body (supports GitHub Markdown).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'comment' => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'commentOnIssue' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/list-github-pulls',
				array(
					'label'               => 'List GitHub Pull Requests',
					'description'         => 'List pull requests from a GitHub repository',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'repo' ),
						'properties' => array(
							'repo'     => array(
								'type'        => 'string',
								'description' => 'Repository in owner/repo format.',
							),
							'state'    => array(
								'type'        => 'string',
								'description' => 'PR state: open, closed, all (default: open).',
							),
							'per_page' => array(
								'type'        => 'integer',
								'description' => 'Results per page (default: 30, max: 100).',
							),
							'page'     => array(
								'type'        => 'integer',
								'description' => 'Page number (default: 1).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'pulls'   => array( 'type' => 'array' ),
							'count'   => array( 'type' => 'integer' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'listPulls' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/list-github-repos',
				array(
					'label'               => 'List GitHub Repositories',
					'description'         => 'List repositories for a user or organization',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'owner' ),
						'properties' => array(
							'owner'    => array(
								'type'        => 'string',
								'description' => 'GitHub user or organization name.',
							),
							'type'     => array(
								'type'        => 'string',
								'description' => 'For orgs: all, public, private, forks, sources, member (default: all). For users: all, owner, member (default: owner).',
							),
							'sort'     => array(
								'type'        => 'string',
								'description' => 'Sort by: created, updated, pushed, full_name (default: updated).',
							),
							'per_page' => array(
								'type'        => 'integer',
								'description' => 'Results per page (default: 30, max: 100).',
							),
							'page'     => array(
								'type'        => 'integer',
								'description' => 'Page number (default: 1).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'repos'   => array( 'type' => 'array' ),
							'count'   => array( 'type' => 'integer' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'listRepos' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// -------------------------------------------------------------------------
	// Ability Callbacks
	// -------------------------------------------------------------------------

	/**
	 * List issues from a GitHub repository.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function listIssues( array $input ): array {
		$repo = sanitize_text_field( $input['repo'] ?? '' );
		if ( empty( $repo ) ) {
			return array(
				'success' => false,
				'error'   => 'Repository is required (owner/repo format).',
			);
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$query_params = array(
			'state'    => sanitize_text_field( $input['state'] ?? 'open' ),
			'per_page' => self::clampPerPage( $input['per_page'] ?? self::DEFAULT_PER_PAGE ),
			'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
		);

		if ( ! empty( $input['labels'] ) ) {
			$query_params['labels'] = sanitize_text_field( $input['labels'] );
		}
		if ( ! empty( $input['assignee'] ) ) {
			$query_params['assignee'] = sanitize_text_field( $input['assignee'] );
		}
		if ( ! empty( $input['since'] ) ) {
			$query_params['since'] = sanitize_text_field( $input['since'] );
		}

		$url      = sprintf( '%s/repos/%s/issues', self::API_BASE, $repo );
		$response = self::apiGet( $url, $query_params, $pat );

		if ( ! $response['success'] ) {
			return $response;
		}

		// GitHub issues API includes PRs — filter them out.
		$issues = array_filter( $response['data'], function ( $item ) {
			return empty( $item['pull_request'] );
		} );
		$issues = array_values( $issues );

		$normalized = array_map( array( self::class, 'normalizeIssue' ), $issues );

		return array(
			'success' => true,
			'issues'  => $normalized,
			'count'   => count( $normalized ),
		);
	}

	/**
	 * Get a single GitHub issue.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function getIssue( array $input ): array {
		$repo         = sanitize_text_field( $input['repo'] ?? '' );
		$issue_number = (int) ( $input['issue_number'] ?? 0 );

		if ( empty( $repo ) || $issue_number <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'Repository (owner/repo) and issue_number are required.',
			);
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$url      = sprintf( '%s/repos/%s/issues/%d', self::API_BASE, $repo, $issue_number );
		$response = self::apiGet( $url, array(), $pat );

		if ( ! $response['success'] ) {
			return $response;
		}

		return array(
			'success' => true,
			'issue'   => self::normalizeIssue( $response['data'] ),
		);
	}

	/**
	 * Update a GitHub issue.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function updateIssue( array $input ): array {
		$repo         = sanitize_text_field( $input['repo'] ?? '' );
		$issue_number = (int) ( $input['issue_number'] ?? 0 );

		if ( empty( $repo ) || $issue_number <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'Repository (owner/repo) and issue_number are required.',
			);
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$body = array();
		if ( isset( $input['title'] ) ) {
			$body['title'] = $input['title'];
		}
		if ( isset( $input['body'] ) ) {
			$body['body'] = $input['body'];
		}
		if ( isset( $input['state'] ) ) {
			$body['state'] = $input['state'];
		}
		if ( isset( $input['labels'] ) && is_array( $input['labels'] ) ) {
			$body['labels'] = $input['labels'];
		}
		if ( isset( $input['assignees'] ) && is_array( $input['assignees'] ) ) {
			$body['assignees'] = $input['assignees'];
		}

		if ( empty( $body ) ) {
			return array(
				'success' => false,
				'error'   => 'No fields to update. Provide title, body, state, labels, or assignees.',
			);
		}

		$url      = sprintf( '%s/repos/%s/issues/%d', self::API_BASE, $repo, $issue_number );
		$response = self::apiRequest( 'PATCH', $url, $body, $pat );

		if ( ! $response['success'] ) {
			return $response;
		}

		return array(
			'success' => true,
			'issue'   => self::normalizeIssue( $response['data'] ),
			'message' => sprintf( 'Issue #%d updated.', $issue_number ),
		);
	}

	/**
	 * Add a comment to a GitHub issue.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function commentOnIssue( array $input ): array {
		$repo         = sanitize_text_field( $input['repo'] ?? '' );
		$issue_number = (int) ( $input['issue_number'] ?? 0 );
		$body         = $input['body'] ?? '';

		if ( empty( $repo ) || $issue_number <= 0 || empty( $body ) ) {
			return array(
				'success' => false,
				'error'   => 'Repository, issue_number, and body are required.',
			);
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$url      = sprintf( '%s/repos/%s/issues/%d/comments', self::API_BASE, $repo, $issue_number );
		$response = self::apiRequest( 'POST', $url, array( 'body' => $body ), $pat );

		if ( ! $response['success'] ) {
			return $response;
		}

		return array(
			'success' => true,
			'comment' => array(
				'id'         => $response['data']['id'] ?? 0,
				'html_url'   => $response['data']['html_url'] ?? '',
				'created_at' => $response['data']['created_at'] ?? '',
			),
			'message' => sprintf( 'Comment added to issue #%d.', $issue_number ),
		);
	}

	/**
	 * List pull requests from a repository.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function listPulls( array $input ): array {
		$repo = sanitize_text_field( $input['repo'] ?? '' );
		if ( empty( $repo ) ) {
			return array(
				'success' => false,
				'error'   => 'Repository is required (owner/repo format).',
			);
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$query_params = array(
			'state'    => sanitize_text_field( $input['state'] ?? 'open' ),
			'per_page' => self::clampPerPage( $input['per_page'] ?? self::DEFAULT_PER_PAGE ),
			'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
		);

		$url      = sprintf( '%s/repos/%s/pulls', self::API_BASE, $repo );
		$response = self::apiGet( $url, $query_params, $pat );

		if ( ! $response['success'] ) {
			return $response;
		}

		$normalized = array_map( array( self::class, 'normalizePull' ), $response['data'] );

		return array(
			'success' => true,
			'pulls'   => $normalized,
			'count'   => count( $normalized ),
		);
	}

	/**
	 * List repositories for a user or organization.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function listRepos( array $input ): array {
		$owner = sanitize_text_field( $input['owner'] ?? '' );
		if ( empty( $owner ) ) {
			return array(
				'success' => false,
				'error'   => 'Owner (user or org) is required.',
			);
		}

		$pat = self::getPat();
		if ( empty( $pat ) ) {
			return self::patError();
		}

		$query_params = array(
			'per_page' => self::clampPerPage( $input['per_page'] ?? self::DEFAULT_PER_PAGE ),
			'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'sort'     => sanitize_text_field( $input['sort'] ?? 'updated' ),
		);

		if ( ! empty( $input['type'] ) ) {
			$query_params['type'] = sanitize_text_field( $input['type'] );
		}

		// Try org endpoint first, fall back to user.
		$url      = sprintf( '%s/orgs/%s/repos', self::API_BASE, $owner );
		$response = self::apiGet( $url, $query_params, $pat );

		if ( ! $response['success'] ) {
			// Not an org — try user endpoint.
			$url      = sprintf( '%s/users/%s/repos', self::API_BASE, $owner );
			$response = self::apiGet( $url, $query_params, $pat );

			if ( ! $response['success'] ) {
				return $response;
			}
		}

		$normalized = array_map( array( self::class, 'normalizeRepo' ), $response['data'] );

		return array(
			'success' => true,
			'repos'   => $normalized,
			'count'   => count( $normalized ),
		);
	}

	// -------------------------------------------------------------------------
	// HTTP Helpers
	// -------------------------------------------------------------------------

	/**
	 * Make a GET request to the GitHub API.
	 *
	 * @param string $url          API endpoint URL.
	 * @param array  $query_params Query parameters.
	 * @param string $pat          Personal Access Token.
	 * @return array
	 */
	public static function apiGet( string $url, array $query_params, string $pat ): array {
		if ( ! empty( $query_params ) ) {
			$url = add_query_arg( $query_params, $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => self::getHeaders( $pat ),
				'timeout' => 30,
			)
		);

		return self::parseResponse( $response );
	}

	/**
	 * Make a POST/PATCH/DELETE request to the GitHub API.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    API endpoint URL.
	 * @param array  $body   Request body.
	 * @param string $pat    Personal Access Token.
	 * @return array
	 */
	public static function apiRequest( string $method, string $url, array $body, string $pat ): array {
		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => self::getHeaders( $pat ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		return self::parseResponse( $response );
	}

	/**
	 * Parse a GitHub API response.
	 *
	 * @param array|\WP_Error $response WordPress HTTP response.
	 * @return array Normalized result with 'success' and 'data' or 'error'.
	 */
	private static function parseResponse( $response ): array {
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => 'GitHub API request failed: ' . $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code >= 400 ) {
			$message = $body['message'] ?? 'Unknown error';
			return array(
				'success' => false,
				'error'   => sprintf( 'GitHub API error (%d): %s', $status_code, $message ),
			);
		}

		return array(
			'success' => true,
			'data'    => $body,
		);
	}

	/**
	 * Get standard GitHub API headers.
	 *
	 * @param string $pat Personal Access Token.
	 * @return array
	 */
	private static function getHeaders( string $pat ): array {
		return array(
			'Authorization' => 'token ' . $pat,
			'Accept'        => 'application/vnd.github.v3+json',
			'User-Agent'    => 'DataMachine',
			'Content-Type'  => 'application/json',
		);
	}

	// -------------------------------------------------------------------------
	// Normalizers
	// -------------------------------------------------------------------------

	/**
	 * Normalize a GitHub issue to a consistent shape.
	 *
	 * @param array $issue Raw GitHub API issue data.
	 * @return array
	 */
	public static function normalizeIssue( array $issue ): array {
		return array(
			'number'     => $issue['number'] ?? 0,
			'title'      => $issue['title'] ?? '',
			'state'      => $issue['state'] ?? '',
			'body'       => $issue['body'] ?? '',
			'html_url'   => $issue['html_url'] ?? '',
			'user'       => $issue['user']['login'] ?? '',
			'labels'     => array_map( function ( $label ) {
				return $label['name'] ?? '';
			}, $issue['labels'] ?? array() ),
			'assignees'  => array_map( function ( $a ) {
				return $a['login'] ?? '';
			}, $issue['assignees'] ?? array() ),
			'comments'   => $issue['comments'] ?? 0,
			'created_at' => $issue['created_at'] ?? '',
			'updated_at' => $issue['updated_at'] ?? '',
			'closed_at'  => $issue['closed_at'] ?? '',
		);
	}

	/**
	 * Normalize a GitHub pull request.
	 *
	 * @param array $pr Raw GitHub API PR data.
	 * @return array
	 */
	public static function normalizePull( array $pr ): array {
		return array(
			'number'     => $pr['number'] ?? 0,
			'title'      => $pr['title'] ?? '',
			'state'      => $pr['state'] ?? '',
			'body'       => $pr['body'] ?? '',
			'html_url'   => $pr['html_url'] ?? '',
			'user'       => $pr['user']['login'] ?? '',
			'head'       => $pr['head']['ref'] ?? '',
			'base'       => $pr['base']['ref'] ?? '',
			'draft'      => $pr['draft'] ?? false,
			'merged'     => ! empty( $pr['merged_at'] ),
			'labels'     => array_map( function ( $label ) {
				return $label['name'] ?? '';
			}, $pr['labels'] ?? array() ),
			'created_at' => $pr['created_at'] ?? '',
			'updated_at' => $pr['updated_at'] ?? '',
			'closed_at'  => $pr['closed_at'] ?? '',
			'merged_at'  => $pr['merged_at'] ?? '',
		);
	}

	/**
	 * Normalize a GitHub repository.
	 *
	 * @param array $repo Raw GitHub API repo data.
	 * @return array
	 */
	public static function normalizeRepo( array $repo ): array {
		return array(
			'full_name'        => $repo['full_name'] ?? '',
			'description'      => $repo['description'] ?? '',
			'html_url'         => $repo['html_url'] ?? '',
			'private'          => $repo['private'] ?? false,
			'fork'             => $repo['fork'] ?? false,
			'language'         => $repo['language'] ?? '',
			'stargazers_count' => $repo['stargazers_count'] ?? 0,
			'open_issues'      => $repo['open_issues_count'] ?? 0,
			'default_branch'   => $repo['default_branch'] ?? 'main',
			'pushed_at'        => $repo['pushed_at'] ?? '',
			'updated_at'       => $repo['updated_at'] ?? '',
		);
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	/**
	 * Get the GitHub PAT from settings.
	 *
	 * @return string
	 */
	public static function getPat(): string {
		return trim( PluginSettings::get( 'github_pat', '' ) );
	}

	/**
	 * Check if GitHub integration is configured.
	 *
	 * @return bool
	 */
	public static function isConfigured(): bool {
		return ! empty( self::getPat() );
	}

	/**
	 * Get the default repo from settings.
	 *
	 * @return string
	 */
	public static function getDefaultRepo(): string {
		return trim( PluginSettings::get( 'github_default_repo', '' ) );
	}

	/**
	 * Return standard PAT-not-configured error.
	 *
	 * @return array
	 */
	private static function patError(): array {
		return array(
			'success' => false,
			'error'   => 'GitHub Personal Access Token not configured. Set github_pat in Data Machine settings.',
		);
	}

	/**
	 * Clamp per_page to valid range.
	 *
	 * @param int|string $per_page Requested per_page.
	 * @return int Clamped value.
	 */
	private static function clampPerPage( $per_page ): int {
		return max( 1, min( self::MAX_PER_PAGE, (int) $per_page ) );
	}
}
