<?php
/** Business Discovery fetch primitive. @package DataMachineSocials */
namespace DataMachineSocials\Abilities\Instagram;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Steps\Fetch\FreshCandidateCollector;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Handlers\Facebook\FacebookAuth;
use DataMachineSocials\Handlers\Instagram\InstagramAccountSettings;
use DataMachineSocials\PublishAuthorization;

defined( 'ABSPATH' ) || exit;

class FetchInstagramAccountAbility extends AbstractSocialAbility {
	protected static bool $registered = false;

	public function __construct() {
		$this->registerAbility( function () {
			wp_register_ability( 'datamachine-socials/fetch-instagram-account', array(
				'label'               => __( 'Fetch public Instagram account', 'data-machine-socials' ),
				'description'         => __( 'Read Business/Creator posts using Business Discovery and existing Instagram authentication.', 'data-machine-socials' ),
				'category'            => 'datamachine-socials',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'username' ),
					'properties' => array(
						'username'       => array( 'type' => 'string' ),
						'max_items'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 500,
							'default' => 10,
						),
						'recent_context' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'maximum'     => 12,
							'default'     => 0,
							'description' => 'Other recent posts from the same fetched page, appended as context only; no extra requests.',
						),
						'days_back'      => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'search'         => array(
							'type'        => 'string',
							'description' => 'Comma-separated caption keywords; match any.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'items'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'meta'                => array( 'show_in_rest' => true ),
			) );
		}, true );
	}

	public function checkPermission(): bool {
		return PublishAuthorization::can_edit() && PermissionHelper::can( 'use_tools' );
	}

	public function execute( array $input ): array|\WP_Error {
		return $this->runFetch( $input, null );
	}

	public function executeWithCollector( array $input, FreshCandidateCollector $collector ): array|\WP_Error {
		return $this->runFetch( $input, $collector );
	}

	private function runFetch( array $input, ?FreshCandidateCollector $collector ): array|\WP_Error {
		$username = InstagramAccountSettings::normalizeUsername( (string) ( $input['username'] ?? '' ) );
		$limit    = (int) ( $input['max_items'] ?? 10 );
		if ( ! $username || $limit < 1 || $limit > 500 ) {
			return new \WP_Error( 'invalid_param', 'A valid Instagram username and max_items between 1 and 500 are required.' );
		}
		$auth    = ( new AuthAbilities() )->getProvider( 'instagram' );
		$token   = $auth ? $auth->get_valid_access_token() : '';
		$user_id = $auth ? $auth->get_user_id() : '';
		if ( ! $token || ! $user_id ) {
			return new \WP_Error( 'instagram_auth', 'Instagram authentication is missing or expired. Reconnect Instagram.' );
		}

		$recent_context = max( 0, min( 12, (int) ( $input['recent_context'] ?? 0 ) ) );
		$cutoff         = ! empty( $input['days_back'] ) ? time() - max( 0, (int) $input['days_back'] ) * DAY_IN_SECONDS : 0;
		$keywords       = array_filter( array_map( 'trim', explode( ',', $input['search'] ?? '' ) ) );
		$items          = array();
		$seen           = array();
		$cursors        = array();
		$after          = '';
		do {
			$media    = 'media.limit(50)' . ( $after ? '.after(' . $after . ')' : '' );
			$fields   = "business_discovery.username({$username}){username,name,followers_count,media_count,{$media}{id,timestamp,media_type,caption,media_url,thumbnail_url,permalink,children{media_url,media_type}}}";
			$url      = 'https://graph.facebook.com/' . FacebookAuth::GRAPH_API_VERSION . '/' . rawurlencode( $user_id );
			$response = wp_remote_get( add_query_arg( array( 'fields' => $fields ), $url ), array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			) );
			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'instagram_api', 'Instagram request failed. Please retry.' );
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $body['error'] ) ) {
				return $this->graphError( $body['error'] );
			}
			$account = $body['business_discovery'] ?? null;
			if ( wp_remote_retrieve_response_code( $response ) !== 200 || ! is_array( $account ) ) {
				return new \WP_Error( 'instagram_api', 'Invalid Business Discovery response from Instagram.' );
			}
			$context_posts = $recent_context ? $this->recentPosts( $account['media']['data'] ?? array() ) : array();
			$old           = false;
			foreach ( $account['media']['data'] ?? array() as $post ) {
				$id = (string) ( $post['id'] ?? '' );
				if ( ! $id || isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				if ( $cutoff && strtotime( $post['timestamp'] ?? '' ) < $cutoff ) {
					$old = true;
					continue;
				}
				$caption = $post['caption'] ?? '';
				if ( $keywords && ! array_filter( $keywords, static fn( $keyword ) => false !== stripos( $caption, $keyword ) ) ) {
					continue;
				}
				$candidate = $this->mapPost( $post, $account, $username );
				if ( $recent_context ) {
					$candidate['data']['content'] .= $this->contextSection( $context_posts, $id, $recent_context );
				}
				if ( $collector ) {
					$collector->offer( $id, $candidate );
				} else {
					$items[] = $candidate;
				}
				if ( $collector ? $collector->isFull() : count( $items ) >= $limit ) {
					break;
				}
			}
			$after = $account['media']['paging']['cursors']['after'] ?? '';
			if ( $old || ! $after || empty( $account['media']['data'] ) ) {
				if ( $collector ) {
					$collector->markExhausted();
				}
				break;
			}
			// Cursors are embedded in Graph field syntax, never follow paging URLs.
			if ( isset( $cursors[ $after ] ) || ! preg_match( '/^[a-zA-Z0-9_=-]+$/', $after ) ) {
				break;
			}
			$cursors[ $after ] = true;
			$item_count        = count( $items );
		} while ( ! ( $collector ? $collector->isFull() : $item_count >= $limit ) );
		return array(
			'success' => true,
			'items'   => $collector ? $collector->getAccepted() : $items,
		);
	}

	/** Sort the already-fetched page independently of candidate filters and collector state. */
	private function recentPosts( array $posts ): array {
		$unique = array();
		foreach ( $posts as $post ) {
			$id = (string) ( $post['id'] ?? '' );
			if ( $id && ! isset( $unique[ $id ] ) ) {
				$unique[ $id ] = $post;
			}
		}
		$posts = array_values( $unique );
		usort( $posts, static fn( $a, $b ) => (int) strtotime( (string) ( $b['timestamp'] ?? '' ) ) <=> (int) strtotime( (string) ( $a['timestamp'] ?? '' ) ) );
		return $posts;
	}

	private function contextSection( array $posts, string $item_id, int $limit ): string {
		$entries = array();
		foreach ( $posts as $post ) {
			if ( (string) $post['id'] === $item_id ) {
				continue;
			}
			$entries[] = 'Timestamp: ' . ( $post['timestamp'] ?? '' ) . "\nPermalink: " . ( $post['permalink'] ?? '' ) . "\nCaption: " . ( $post['caption'] ?? '' );
			if ( count( $entries ) >= $limit ) {
				break;
			}
		}
		return $entries ? "\n\nOther recent posts from this account (context only — do not create items from these alone)\n\n" . implode( "\n\n", $entries ) : '';
	}

	private function mapPost( array $post, array $account, string $username ): array {
		$image = 'VIDEO' === ( $post['media_type'] ?? '' ) ? ( $post['thumbnail_url'] ?? '' ) : ( $post['media_url'] ?? '' );
		if ( 'CAROUSEL_ALBUM' === ( $post['media_type'] ?? '' ) ) {
			foreach ( $post['children']['data'] ?? array() as $child ) {
				if ( 'IMAGE' === ( $child['media_type'] ?? '' ) && ! empty( $child['media_url'] ) ) {
					$image = $child['media_url'];
					break;
				}
			}
		}
		return array(
			'item_id'    => (string) $post['id'],
			'source_url' => $post['permalink'] ?? '',
			'data'       => array(
				'title'      => $username . ' — ' . ( $post['timestamp'] ?? '' ),
				'content'    => $post['caption'] ?? '',
				'image_info' => array( 'url' => $image ),
				'metadata'   => array(
					'source_type'      => 'instagram_account',
					'source_url'       => $post['permalink'] ?? '',
					'item_identifier'  => (string) $post['id'],
					'media_id'         => (string) $post['id'],
					'timestamp'        => $post['timestamp'] ?? '',
					'media_type'       => $post['media_type'] ?? '',
					'account_username' => $account['username'] ?? $username,
					'account_name'     => $account['name'] ?? '',
				),
			),
		);
	}

	private function graphError( array $error ): \WP_Error {
		$code = (int) ( $error['code'] ?? 0 );
		if ( in_array( $code, array( 4, 17, 32, 613 ), true ) ) {
			return new \WP_Error( 'instagram_rate_limit', 'Instagram Graph API rate limit reached. Retry later.', array(
				'status'        => 429,
				'upstream_code' => $code,
			) );
		}
		if ( in_array( $code, array( 190, 102 ), true ) ) {
			return new \WP_Error( 'instagram_auth', 'Instagram authentication is missing or expired. Reconnect Instagram.' );
		}
		if ( false !== stripos( $error['message'] ?? '', 'Invalid user id' ) ) {
			return new \WP_Error( 'instagram_account_unavailable', 'Instagram account not found or is a personal account. Business Discovery requires a public Business/Creator account.' );
		}
		return new \WP_Error( 'instagram_api', 'Instagram Business Discovery request failed.', array( 'upstream_code' => $code ) );
	}
}
