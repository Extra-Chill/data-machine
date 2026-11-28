<?php
/**
 * Fetch repository files and report deletions from complete GitHub trees.
 *
 * @package DataMachineBusiness
 */

namespace DataMachineBusiness\Handlers\GitHub;

use DataMachine\Core\Database\TrackedItems\TrackedItems;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineBusiness\Api\GitHubClient;
use DataMachineBusiness\OAuth\Providers\GitHubAuth;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GitHubFetch extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'github' );
		// Core uses this auth flag to expose credential settings. Fetching itself permits anonymous access.
		self::registerHandler(
			'github',
			'fetch',
			self::class,
			__( 'GitHub Files', 'data-machine-business' ),
			__( 'Fetch files from a GitHub repository. A token is optional for public repositories.', 'data-machine-business' ),
			true,
			GitHubAuth::class,
			GitHubFetchSettings::class,
			null,
			'github',
			array( 'data_source' => 'files' )
		);
	}

	protected function getDefaultMaxItems(): int {
		return 0;
	}

	protected function executeFetch( array $config, ExecutionContext $context ): array|WP_Error {
		$repo    = trim( (string) ( $config['repo'] ?? '' ) );
		$branch  = trim( (string) ( $config['branch'] ?? '' ) );
		$prefix  = trim( (string) ( $config['file_path'] ?? '' ), '/' );
		$pattern = (string) ( $config['file_pattern'] ?? '' );
		$limit   = min( GitHubFetchSettings::MAX_FILE_SIZE, max( 1, (int) ( $config['max_file_size'] ?? GitHubFetchSettings::DEFAULT_MAX_FILE_SIZE ) ) );

		$provider   = $this->getAuthProvider( 'github' );
		$token      = $provider instanceof GitHubAuth ? $provider->get_token( $config ) : '';
		$auth_error = $provider instanceof GitHubAuth ? $provider->get_last_decryption_error() : null;
		if ( null !== $auth_error ) {
			return $auth_error;
		}
		$client = new GitHubClient( $token );
		if ( '' === $branch ) {
			$branch = $this->default_branch( $client, $repo, $token );
			if ( is_wp_error( $branch ) ) {
				return $branch;
			}
		}

		$head = $client->get_commit( $repo, $branch );
		if ( is_wp_error( $head ) ) {
			return $head;
		}
		$commit   = $head['sha'];
		$tree_sha = $head['commit']['tree']['sha'];
		$tree     = $client->get_recursive_tree( $repo, $tree_sha );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$scope         = wp_json_encode( array( $context->getFlowStepId(), strtolower( $repo ), $branch, $prefix, $pattern ), JSON_INVALID_UTF8_SUBSTITUTE );
		$namespace     = 'github-files:' . hash( 'sha256', (string) $scope );
		$ledger        = $context->isFlow() && $context->getFlowStepId() ? new TrackedItems() : null;
		$snapshot      = $ledger?->get( $namespace, 'snapshot' );
		$previous      = $snapshot['metadata']['paths'] ?? array();
		$pending       = $snapshot['metadata']['removals'] ?? array();
		$paths         = array();
		$items         = array();
		$candidates    = array();
		$prefix_exists = '' === $prefix;

		foreach ( $tree as $entry ) {
			$path = $entry['path'];
			if ( '' !== $prefix && $path !== $prefix && ! str_starts_with( $path, $prefix . '/' ) ) {
				continue;
			}
			$prefix_exists = true;
			if ( 'blob' !== $entry['type'] ) {
				continue;
			}
			// The file is still present even if it exceeds the configured download limit.
			unset( $pending[ $path ] );
			$relative = self::relative_path( $path, $prefix );
			$subject  = str_contains( $pattern, '/' ) ? $relative : self::filename( $relative );
			if ( '' !== $pattern && ! fnmatch( $pattern, $subject, FNM_PATHNAME ) ) {
				continue;
			}
			if ( $entry['size'] > $limit ) {
				if ( isset( $previous[ $path ] ) ) {
					$paths[ $path ] = $previous[ $path ];
				}
				continue;
			}

			$sha            = $entry['sha'];
			$before         = $previous[ $path ] ?? null;
			$change         = null === $before ? 'added' : ( $sha === $before['sha'] ? $before['change'] : 'modified' );
			$paths[ $path ] = array(
				'sha'    => $sha,
				'change' => $change,
				'commit' => $commit,
			);
			$candidates[ self::item_identifier( self::dedup_key( $repo, $path, $sha ) ) ] = $path;
		}

		if ( ! $prefix_exists ) {
			return new WP_Error(
				'github_missing_file_path',
				/* translators: %s: configured repository path. */
				sprintf( __( 'GitHub file_path "%s" does not exist in the repository tree.', 'data-machine-business' ), $prefix )
			);
		}

		// Hold tombstones until the downstream pipeline marks them processed so
		// caps, in-flight claims and downstream failures cannot drop removals.
		foreach ( array_diff_key( $previous, $paths ) as $path => $entry ) {
			$pending[ $path ] = array(
				'sha'        => $entry['sha'],
				'old_commit' => $entry['commit'],
				'commit'     => $commit,
			);
		}
		foreach ( $pending as $path => $entry ) {
			$path = (string) $path;
			$item = $this->file_item( $repo, $branch, $entry['commit'], $path, self::relative_path( $path, $prefix ), $entry['sha'], 'removed', $entry['old_commit'] );
			if ( $context->isItemProcessed( $item['metadata']['item_identifier'] ) ) {
				unset( $pending[ $path ] );
				continue;
			}
			$items[] = $item;
		}

		// Match the core batch cap without downloading blobs outside the batch.
		$remaining = max( 0, (int) ( $config['max_items'] ?? 0 ) );
		if ( $remaining > 0 ) {
			$removals   = $context->classifySourceItems( array_column( array_column( $items, 'metadata' ), 'item_identifier' ) );
			$remaining -= count( $removals['selected_identifiers'] );
			if ( $remaining <= 0 ) {
				$candidates = array();
			}
		}
		$selection = $context->classifySourceItems( array_keys( $candidates ), max( 0, $remaining ) );
		foreach ( $selection['selected_identifiers'] as $identifier ) {
			$path    = $candidates[ $identifier ];
			$entry   = $paths[ $path ];
			$content = $client->get_blob( $repo, $entry['sha'], $limit );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$item            = $this->file_item( $repo, $branch, $commit, $path, self::relative_path( $path, $prefix ), $entry['sha'], $entry['change'], $commit );
			$item['content'] = $content;
			$items[]         = $item;
		}

		$state = array(
			'paths'    => $paths,
			'removals' => $pending,
		);
		if ( $ledger && ( $snapshot['metadata'] ?? null ) !== $state && null === $ledger->upsert(
			array(
				'namespace'       => $namespace,
				'item_id'         => 'snapshot',
				'item_type'       => 'github_files_snapshot',
				'source_ref'      => $repo,
				'source_revision' => $commit,
				'metadata'        => $state,
			)
		) ) {
			return new WP_Error( 'github_snapshot_error', __( 'Could not save the GitHub file snapshot for this flow step.', 'data-machine-business' ) );
		}

		return array( 'items' => $items );
	}

	private function default_branch( GitHubClient $client, string $repo, string $token ): string|WP_Error {
		$key    = 'datamachine_github_branch_' . hash( 'sha256', strtolower( $repo ) . "\0" . $token );
		$branch = get_transient( $key );
		if ( is_string( $branch ) && '' !== $branch ) {
			return $branch;
		}
		$branch = $client->get_default_branch( $repo );
		if ( ! is_wp_error( $branch ) ) {
			set_transient( $key, $branch, 300 );
		}
		return $branch;
	}

	private static function relative_path( string $path, string $prefix ): string {
		if ( '' === $prefix ) {
			return $path;
		}
		return $path === $prefix ? self::filename( $path ) : substr( $path, strlen( $prefix ) + 1 );
	}

	private static function filename( string $path ): string {
		$slash = strrpos( $path, '/' );
		return false === $slash ? $path : substr( $path, $slash + 1 );
	}

	private static function dedup_key( string $repo, string $path, string $sha ): string {
		return "github_{$repo}_files_{$path}_{$sha}";
	}

	private static function item_identifier( string $key, string $removed_at = '' ): string {
		// Core's processed-items index has a length limit and case-insensitive collation.
		return hash( 'sha256', '' === $removed_at ? $key : "removed\0{$key}\0{$removed_at}" );
	}

	private function file_item( string $repo, string $branch, string $commit, string $path, string $relative, string $sha, string $change, string $url_commit ): array {
		$key = self::dedup_key( $repo, $path, $sha );
		$url = 'https://github.com/' . GitHubClient::encode_path( $repo ) . '/blob/' . rawurlencode( $url_commit ) . '/' . GitHubClient::encode_path( $path );

		return array(
			'title'    => 'removed' === $change
				/* translators: %s: removed repository path. */
				? sprintf( __( 'Removed: %s', 'data-machine-business' ), $path )
				: $path,
			'content'  => '',
			'metadata' => array(
				'source_type'               => 'github',
				'github_repo'               => $repo,
				'github_branch'             => $branch,
				'github_commit_sha'         => $commit,
				'github_file_path'          => $path,
				'github_relative_file_path' => $relative,
				'github_file_sha'           => $sha,
				'github_change'             => $change,
				'github_url'                => $url,
				'source_url'                => $url,
				'dedup_key'                 => $key,
				'original_id'               => $key,
				'item_identifier'           => self::item_identifier( $key, 'removed' === $change ? $commit : '' ),
			),
		);
	}
}
