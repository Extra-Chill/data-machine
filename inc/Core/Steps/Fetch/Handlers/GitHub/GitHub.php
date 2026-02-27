<?php
/**
 * GitHub Fetch Handler
 *
 * Fetches issues or pull requests from a GitHub repository and returns
 * them as DataPackets for pipeline processing. Supports deduplication
 * via processed items, timeframe filtering, and keyword search.
 *
 * @package DataMachine\Core\Steps\Fetch\Handlers\GitHub
 * @since 0.33.0
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\GitHub;

use DataMachine\Abilities\Fetch\GitHubAbilities;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHub extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'github' );

		self::registerHandler(
			'github',
			'fetch',
			self::class,
			'GitHub',
			'Fetch issues and pull requests from GitHub repositories',
			false,
			null,
			GitHubSettings::class,
			null
		);
	}

	/**
	 * Fetch GitHub data and return as a DataPacket-compatible array.
	 *
	 * @param array            $config  Handler configuration.
	 * @param ExecutionContext  $context Execution context.
	 * @return array DataPacket-compatible array or empty on no data.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$repo        = $config['repo'] ?? '';
		$data_source = $config['data_source'] ?? 'issues';
		$state       = $config['state'] ?? 'open';
		$labels      = $config['labels'] ?? '';

		if ( empty( $repo ) ) {
			$repo = GitHubAbilities::getDefaultRepo();
		}

		if ( empty( $repo ) ) {
			$context->log( 'error', 'GitHub: No repository configured and no default repo set.' );
			return array();
		}

		if ( ! GitHubAbilities::isConfigured() ) {
			$context->log( 'error', 'GitHub: Personal Access Token not configured.' );
			return array();
		}

		$context->log( 'debug', 'GitHub: Fetching data', array(
			'repo'        => $repo,
			'data_source' => $data_source,
			'state'       => $state,
		) );

		$input = array(
			'repo'     => $repo,
			'state'    => $state,
			'per_page' => GitHubAbilities::MAX_PER_PAGE,
		);

		if ( ! empty( $labels ) ) {
			$input['labels'] = $labels;
		}

		if ( 'pulls' === $data_source ) {
			$result = GitHubAbilities::listPulls( $input );
			$items  = $result['pulls'] ?? array();
		} else {
			$result = GitHubAbilities::listIssues( $input );
			$items  = $result['issues'] ?? array();
		}

		if ( ! $result['success'] ) {
			$context->log( 'error', 'GitHub: API error — ' . ( $result['error'] ?? 'Unknown' ) );
			return array();
		}

		if ( empty( $items ) ) {
			$context->log( 'info', 'GitHub: No items found.' );
			return array();
		}

		$context->log( 'info', sprintf( 'GitHub: Found %d %s.', count( $items ), $data_source ) );

		// Find first unprocessed item (deduplication).
		$search           = $config['search'] ?? '';
		$exclude_keywords = $config['exclude_keywords'] ?? '';
		$timeframe_limit  = $config['timeframe_limit'] ?? 'all_time';

		foreach ( $items as $item ) {
			$guid = sprintf( 'github_%s_%s_%d', $repo, $data_source, $item['number'] );

			// Check if already processed.
			if ( $context->isItemProcessed( $guid ) ) {
				continue;
			}

			$searchable_text = ( $item['title'] ?? '' ) . ' ' . ( $item['body'] ?? '' );

			// Apply keyword search filter.
			if ( ! empty( $search ) && ! $this->applyKeywordSearch( $searchable_text, $search ) ) {
				continue;
			}

			// Apply exclude keywords filter.
			if ( ! empty( $exclude_keywords ) && ! $this->applyExcludeKeywords( $searchable_text, $exclude_keywords ) ) {
				continue;
			}

			// Apply timeframe filter.
			if ( 'all_time' !== $timeframe_limit && ! empty( $item['created_at'] ) ) {
				$timestamp = strtotime( $item['created_at'] );
				if ( $timestamp && ! $this->applyTimeframeFilter( $timestamp, $timeframe_limit ) ) {
					continue;
				}
			}

			// Mark as processed.
			$context->markItemProcessed( $guid );

			// Build the DataPacket-compatible return.
			$labels_str = ! empty( $item['labels'] ) ? implode( ', ', $item['labels'] ) : '';

			if ( 'pulls' === $data_source ) {
				$title   = sprintf( 'PR #%d: %s', $item['number'], $item['title'] );
				$content = $this->buildPrContent( $item );
			} else {
				$title   = sprintf( 'Issue #%d: %s', $item['number'], $item['title'] );
				$content = $this->buildIssueContent( $item );
			}

			$context->storeEngineData( array(
				'source_url'   => $item['html_url'] ?? '',
				'github_repo'  => $repo,
				'github_type'  => $data_source,
				'issue_number' => $item['number'],
			) );

			return array(
				'title'    => $title,
				'content'  => $content,
				'metadata' => array(
					'source_type'       => 'github',
					'original_id'       => $guid,
					'original_title'    => $item['title'] ?? '',
					'original_date_gmt' => $item['created_at'] ?? '',
					'github_repo'       => $repo,
					'github_type'       => $data_source,
					'github_number'     => $item['number'],
					'github_state'      => $item['state'] ?? '',
					'github_labels'     => $labels_str,
					'github_user'       => $item['user'] ?? '',
					'github_url'        => $item['html_url'] ?? '',
				),
			);
		}

		$context->log( 'info', 'GitHub: All items already processed or filtered out.' );
		return array();
	}

	/**
	 * Build content string for an issue.
	 *
	 * @param array $issue Normalized issue data.
	 * @return string
	 */
	private function buildIssueContent( array $issue ): string {
		$parts = array();

		$parts[] = sprintf( '**State:** %s', $issue['state'] ?? 'unknown' );
		$parts[] = sprintf( '**Author:** %s', $issue['user'] ?? 'unknown' );

		if ( ! empty( $issue['labels'] ) ) {
			$parts[] = sprintf( '**Labels:** %s', implode( ', ', $issue['labels'] ) );
		}
		if ( ! empty( $issue['assignees'] ) ) {
			$parts[] = sprintf( '**Assignees:** %s', implode( ', ', $issue['assignees'] ) );
		}
		$parts[] = sprintf( '**Comments:** %d', $issue['comments'] ?? 0 );
		$parts[] = sprintf( '**Created:** %s', $issue['created_at'] ?? '' );
		$parts[] = sprintf( '**URL:** %s', $issue['html_url'] ?? '' );
		$parts[] = '';

		if ( ! empty( $issue['body'] ) ) {
			$parts[] = $issue['body'];
		}

		return implode( "\n", $parts );
	}

	/**
	 * Build content string for a pull request.
	 *
	 * @param array $pr Normalized PR data.
	 * @return string
	 */
	private function buildPrContent( array $pr ): string {
		$parts = array();

		$parts[] = sprintf( '**State:** %s', $pr['state'] ?? 'unknown' );
		$parts[] = sprintf( '**Author:** %s', $pr['user'] ?? 'unknown' );
		$parts[] = sprintf( '**Branch:** %s → %s', $pr['head'] ?? '?', $pr['base'] ?? '?' );

		if ( ! empty( $pr['draft'] ) ) {
			$parts[] = '**Draft:** Yes';
		}
		if ( ! empty( $pr['merged'] ) ) {
			$parts[] = sprintf( '**Merged:** %s', $pr['merged_at'] ?? 'Yes' );
		}
		if ( ! empty( $pr['labels'] ) ) {
			$parts[] = sprintf( '**Labels:** %s', implode( ', ', $pr['labels'] ) );
		}
		$parts[] = sprintf( '**Created:** %s', $pr['created_at'] ?? '' );
		$parts[] = sprintf( '**URL:** %s', $pr['html_url'] ?? '' );
		$parts[] = '';

		if ( ! empty( $pr['body'] ) ) {
			$parts[] = $pr['body'];
		}

		return implode( "\n", $parts );
	}

	/**
	 * Get the display label for the GitHub handler.
	 *
	 * @return string
	 */
	public static function get_label(): string {
		return __( 'GitHub', 'data-machine' );
	}
}
