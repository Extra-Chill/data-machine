<?php
/**
 * WP-CLI GitHub Command
 *
 * Provides CLI access to GitHub integration: listing issues, PRs, repos,
 * and managing issues (view, close, comment). Wraps GitHubAbilities.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.33.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\Fetch\GitHubAbilities;

defined( 'ABSPATH' ) || exit;

class GitHubCommand extends BaseCommand {

	/**
	 * List issues from a GitHub repository.
	 *
	 * ## OPTIONS
	 *
	 * [--repo=<repo>]
	 * : Repository in owner/repo format. Falls back to default repo in settings.
	 *
	 * [--state=<state>]
	 * : Issue state: open, closed, all (default: open).
	 *
	 * [--labels=<labels>]
	 * : Comma-separated label names to filter by.
	 *
	 * [--assignee=<assignee>]
	 * : Filter by assignee username.
	 *
	 * [--per_page=<count>]
	 * : Results per page (default: 30, max: 100).
	 *
	 * [--page=<page>]
	 * : Page number (default: 1).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List open issues for a repo
	 *     $ wp datamachine github issues --repo=Extra-Chill/data-machine
	 *
	 *     # List issues with specific labels
	 *     $ wp datamachine github issues --repo=Extra-Chill/data-machine --labels=enhancement
	 *
	 *     # List closed issues
	 *     $ wp datamachine github issues --repo=Extra-Chill/data-machine --state=closed --per_page=10
	 *
	 * @subcommand issues
	 */
	public function issues( array $args, array $assoc_args ): void {
		$this->requireConfig();

		$input = $this->buildInput( $assoc_args, array( 'repo', 'state', 'labels', 'assignee', 'per_page', 'page' ) );
		$input = $this->resolveRepo( $input );

		$result = GitHubAbilities::listIssues( $input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to list issues.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( \wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$issues = $result['issues'] ?? array();

		if ( empty( $issues ) ) {
			WP_CLI::log( 'No issues found.' );
			return;
		}

		$items = array();
		foreach ( $issues as $issue ) {
			$items[] = array(
				'number'     => $issue['number'],
				'state'      => $issue['state'],
				'title'      => mb_substr( $issue['title'], 0, 60 ),
				'labels'     => implode( ', ', $issue['labels'] ?? array() ),
				'assignees'  => implode( ', ', $issue['assignees'] ?? array() ),
				'comments'   => $issue['comments'],
				'created_at' => $this->shortDate( $issue['created_at'] ),
			);
		}

		$this->format_items( $items, array( 'number', 'state', 'title', 'labels', 'comments', 'created_at' ), $assoc_args );
		WP_CLI::log( sprintf( '%d issue(s) returned.', count( $items ) ) );
	}

	/**
	 * View a single GitHub issue.
	 *
	 * ## OPTIONS
	 *
	 * <issue_number>
	 * : Issue number.
	 *
	 * [--repo=<repo>]
	 * : Repository in owner/repo format.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp datamachine github view 413 --repo=Extra-Chill/data-machine
	 *
	 * @subcommand view
	 */
	public function view( array $args, array $assoc_args ): void {
		$this->requireConfig();

		$issue_number = \absint( $args[0] ?? 0 );
		if ( $issue_number <= 0 ) {
			WP_CLI::error( 'Please provide a valid issue number.' );
			return;
		}

		$input         = array( 'issue_number' => $issue_number );
		$input['repo'] = $assoc_args['repo'] ?? '';
		$input         = $this->resolveRepo( $input );

		$result = GitHubAbilities::getIssue( $input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to fetch issue.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( \wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$issue = $result['issue'];

		WP_CLI::log( WP_CLI::colorize( sprintf( '%%G#%d%%n %s', $issue['number'], $issue['title'] ) ) );
		WP_CLI::log( sprintf( 'State: %s | Author: %s | Comments: %d', $issue['state'], $issue['user'], $issue['comments'] ) );

		if ( ! empty( $issue['labels'] ) ) {
			WP_CLI::log( 'Labels: ' . implode( ', ', $issue['labels'] ) );
		}
		if ( ! empty( $issue['assignees'] ) ) {
			WP_CLI::log( 'Assignees: ' . implode( ', ', $issue['assignees'] ) );
		}

		WP_CLI::log( 'Created: ' . $issue['created_at'] );
		WP_CLI::log( 'URL: ' . $issue['html_url'] );

		if ( ! empty( $issue['body'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( $issue['body'] );
		}
	}

	/**
	 * Close a GitHub issue.
	 *
	 * ## OPTIONS
	 *
	 * <issue_number>
	 * : Issue number to close.
	 *
	 * [--repo=<repo>]
	 * : Repository in owner/repo format.
	 *
	 * [--comment=<comment>]
	 * : Optional comment to add before closing.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp datamachine github close 315 --repo=Extra-Chill/data-machine
	 *
	 *     $ wp datamachine github close 315 --repo=Extra-Chill/data-machine --comment="Fixed in v0.33.0"
	 *
	 * @subcommand close
	 */
	public function close_issue( array $args, array $assoc_args ): void {
		$this->requireConfig();

		$issue_number = \absint( $args[0] ?? 0 );
		if ( $issue_number <= 0 ) {
			WP_CLI::error( 'Please provide a valid issue number.' );
			return;
		}

		$input = $this->resolveRepo( array(
			'repo'         => $assoc_args['repo'] ?? '',
			'issue_number' => $issue_number,
		) );

		// Add comment first if provided.
		if ( ! empty( $assoc_args['comment'] ) ) {
			$comment_result = GitHubAbilities::commentOnIssue( array(
				'repo'         => $input['repo'],
				'issue_number' => $issue_number,
				'body'         => $assoc_args['comment'],
			) );

			if ( empty( $comment_result['success'] ) ) {
				WP_CLI::warning( 'Failed to add comment: ' . ( $comment_result['error'] ?? 'Unknown error' ) );
			}
		}

		$input['state'] = 'closed';
		$result         = GitHubAbilities::updateIssue( $input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to close issue.' );
			return;
		}

		WP_CLI::success( sprintf( 'Issue #%d closed.', $issue_number ) );
	}

	/**
	 * Comment on a GitHub issue.
	 *
	 * ## OPTIONS
	 *
	 * <issue_number>
	 * : Issue number to comment on.
	 *
	 * <body>
	 * : Comment body (supports Markdown).
	 *
	 * [--repo=<repo>]
	 * : Repository in owner/repo format.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp datamachine github comment 315 "Investigating this now."
	 *
	 * @subcommand comment
	 */
	public function comment( array $args, array $assoc_args ): void {
		$this->requireConfig();

		$issue_number = \absint( $args[0] ?? 0 );
		$body         = $args[1] ?? '';

		if ( $issue_number <= 0 || empty( $body ) ) {
			WP_CLI::error( 'Required: <issue_number> <body>' );
			return;
		}

		$input = $this->resolveRepo( array(
			'repo'         => $assoc_args['repo'] ?? '',
			'issue_number' => $issue_number,
			'body'         => $body,
		) );

		$result = GitHubAbilities::commentOnIssue( $input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to add comment.' );
			return;
		}

		WP_CLI::success( sprintf( 'Comment added to issue #%d.', $issue_number ) );
	}

	/**
	 * List pull requests from a repository.
	 *
	 * ## OPTIONS
	 *
	 * [--repo=<repo>]
	 * : Repository in owner/repo format.
	 *
	 * [--state=<state>]
	 * : PR state: open, closed, all (default: open).
	 *
	 * [--per_page=<count>]
	 * : Results per page (default: 30, max: 100).
	 *
	 * [--page=<page>]
	 * : Page number (default: 1).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp datamachine github pulls --repo=Extra-Chill/data-machine
	 *
	 * @subcommand pulls
	 */
	public function pulls( array $args, array $assoc_args ): void {
		$this->requireConfig();

		$input = $this->buildInput( $assoc_args, array( 'repo', 'state', 'per_page', 'page' ) );
		$input = $this->resolveRepo( $input );

		$result = GitHubAbilities::listPulls( $input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to list pull requests.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( \wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$pulls = $result['pulls'] ?? array();

		if ( empty( $pulls ) ) {
			WP_CLI::log( 'No pull requests found.' );
			return;
		}

		$items = array();
		foreach ( $pulls as $pr ) {
			$status = $pr['state'];
			if ( ! empty( $pr['merged'] ) ) {
				$status = 'merged';
			} elseif ( ! empty( $pr['draft'] ) ) {
				$status = 'draft';
			}

			$items[] = array(
				'number'     => $pr['number'],
				'status'     => $status,
				'title'      => mb_substr( $pr['title'], 0, 60 ),
				'branch'     => $pr['head'] ?? '',
				'user'       => $pr['user'] ?? '',
				'created_at' => $this->shortDate( $pr['created_at'] ),
			);
		}

		$this->format_items( $items, array( 'number', 'status', 'title', 'branch', 'user', 'created_at' ), $assoc_args );
		WP_CLI::log( sprintf( '%d PR(s) returned.', count( $items ) ) );
	}

	/**
	 * List repositories for a user or organization.
	 *
	 * ## OPTIONS
	 *
	 * <owner>
	 * : GitHub user or organization name.
	 *
	 * [--sort=<sort>]
	 * : Sort by: created, updated, pushed, full_name (default: updated).
	 *
	 * [--per_page=<count>]
	 * : Results per page (default: 30, max: 100).
	 *
	 * [--page=<page>]
	 * : Page number (default: 1).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp datamachine github repos Extra-Chill
	 *
	 *     $ wp datamachine github repos chubes4 --sort=pushed
	 *
	 * @subcommand repos
	 */
	public function repos( array $args, array $assoc_args ): void {
		$this->requireConfig();

		$owner = $args[0] ?? '';
		if ( empty( $owner ) ) {
			WP_CLI::error( 'Required: <owner> (GitHub user or organization name).' );
			return;
		}

		$input          = $this->buildInput( $assoc_args, array( 'sort', 'per_page', 'page' ) );
		$input['owner'] = $owner;

		$result = GitHubAbilities::listRepos( $input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to list repositories.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( \wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$repos = $result['repos'] ?? array();

		if ( empty( $repos ) ) {
			WP_CLI::log( 'No repositories found.' );
			return;
		}

		$items = array();
		foreach ( $repos as $repo ) {
			$items[] = array(
				'repo'        => $repo['full_name'],
				'language'    => $repo['language'] ?? '',
				'stars'       => $repo['stargazers_count'],
				'open_issues' => $repo['open_issues'],
				'private'     => $repo['private'] ? 'yes' : 'no',
				'last_push'   => $this->shortDate( $repo['pushed_at'] ),
			);
		}

		$this->format_items( $items, array( 'repo', 'language', 'stars', 'open_issues', 'private', 'last_push' ), $assoc_args );
		WP_CLI::log( sprintf( '%d repo(s) returned.', count( $items ) ) );
	}

	/**
	 * Check GitHub integration status.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp datamachine github status
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc_args ): void {
		$configured   = GitHubAbilities::isConfigured();
		$default_repo = GitHubAbilities::getDefaultRepo();

		$items = array(
			array(
				'setting' => 'GitHub PAT',
				'value'   => $configured ? 'Configured' : 'Not configured',
			),
			array(
				'setting' => 'Default Repository',
				'value'   => $default_repo ? $default_repo : 'Not set',
			),
		);

		$this->format_items( $items, array( 'setting', 'value' ), $assoc_args );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Require GitHub to be configured.
	 */
	private function requireConfig(): void {
		if ( ! GitHubAbilities::isConfigured() ) {
			WP_CLI::error( 'GitHub PAT not configured. Set github_pat in Data Machine settings.' );
		}
	}

	/**
	 * Resolve repo — use provided value or fall back to default.
	 *
	 * @param array $input Input array with optional 'repo' key.
	 * @return array Input with resolved repo.
	 */
	private function resolveRepo( array $input ): array {
		if ( empty( $input['repo'] ) ) {
			$input['repo'] = GitHubAbilities::getDefaultRepo();
		}
		if ( empty( $input['repo'] ) ) {
			WP_CLI::error( 'Required: --repo=<owner/repo> (or set a default repo in settings).' );
		}
		return $input;
	}

	/**
	 * Build ability input from assoc_args.
	 *
	 * @param array $assoc_args Command arguments.
	 * @param array $keys       Keys to extract.
	 * @return array
	 */
	private function buildInput( array $assoc_args, array $keys ): array {
		$input = array();
		foreach ( $keys as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$input[ $key ] = $assoc_args[ $key ];
			}
		}
		return $input;
	}

	/**
	 * Format an ISO date to a shorter form.
	 *
	 * @param string $date ISO 8601 date string.
	 * @return string Short date (Y-m-d).
	 */
	private function shortDate( string $date ): string {
		if ( empty( $date ) ) {
			return '';
		}
		$timestamp = strtotime( $date );
		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : $date;
	}
}
