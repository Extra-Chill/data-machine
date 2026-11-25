<?php
/** Fetch public Business/Creator posts by username. @package DataMachineSocials */
namespace DataMachineSocials\Handlers\Instagram;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\FreshCandidateCollector;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineSocials\Abilities\Instagram\FetchInstagramAccountAbility;

defined( 'ABSPATH' ) || exit;

class InstagramAccount extends FetchHandler {
	use HandlerRegistrationTrait;

	/**
	 * Item-shape guidance for AI steps reading this handler's packets
	 * (`meta['ai_guidance']`, data-machine#4053). Domain rules (what counts as
	 * an event, titles, reject criteria) belong to the consuming flow.
	 */
	public const AI_GUIDANCE = 'Each item is one public Instagram post from this account, with its caption and a stored image (often a flyer). Other recent posts from the same account may follow the caption as context only: use them to fill in details this post leaves out, such as a date, time, or lineup, but never create an item from a context post itself. Read the image carefully: flyers usually carry the date, time, venue, lineup, and ticket info. Relative dates such as "TONIGHT" or "TOMORROW" are relative to the post timestamp, which also gives the year when the image omits it. Use the post permalink as the source link.';

	/**
	 * Top-level instagram.com paths that are not profiles.
	 */
	private const RESERVED_PATHS = array( 'p', 'reel', 'reels', 'tv', 'explore', 'stories', 'accounts', 'direct', 'about', 'legal', 'developer', 'web', 'challenge', 'privacy', 'emails', 'session', 'oauth', 'graphql', 'api', 'static' );

	public function __construct() {
		parent::__construct( 'instagram_account' );
		// Unknown meta is ignored by Data Machine versions without URL claims
		// (data-machine#4052) or handler AI guidance (data-machine#4053).
		$meta = array(
			'url_claim'          => array( self::class, 'claimUrl' ),
			'url_claim_priority' => 10,
			'ai_guidance'        => self::AI_GUIDANCE,
		);
		self::registerHandler( 'instagram_account', 'fetch', self::class, 'Instagram account (public)', 'Fetch public Business/Creator posts by username', true, InstagramAuth::class, InstagramAccountSettings::class, null, 'instagram', $meta );
	}

	/**
	 * Claim an Instagram profile URL or bare `@handle`. Parses only; no network I/O.
	 *
	 * @param string $input Source URL or handle.
	 * @return array{username: string}|null Handler config, or null when not a profile.
	 */
	public static function claimUrl( string $input ): ?array {
		$input = trim( $input );
		if ( '' === $input ) {
			return null;
		}
		if ( '@' === $input[0] ) {
			$username = InstagramAccountSettings::normalizeUsername( $input );
			return ( $username && strtolower( $input ) === '@' . $username ) ? array( 'username' => $username ) : null;
		}
		$parts = wp_parse_url( $input );
		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		if ( ! in_array( strtolower( $parts['host'] ?? '' ), array( 'instagram.com', 'www.instagram.com' ), true ) ) {
			return null;
		}
		$segments = array_values( array_filter( explode( '/', (string) ( $parts['path'] ?? '' ) ), static fn( string $segment ): bool => '' !== $segment ) );
		if ( 1 !== count( $segments ) || in_array( strtolower( $segments[0] ), self::RESERVED_PATHS, true ) ) {
			return null;
		}
		$username = InstagramAccountSettings::normalizeUsername( $segments[0] );
		return $username ? array( 'username' => $username ) : null;
	}

	protected function executeFetch( array $config, ExecutionContext $context ): array|\WP_Error {
		$config['max_items'] = (int) ( $config['max_items'] ?? $this->getDefaultMaxItems() );
		$collector           = new FreshCandidateCollector( $context, $config['max_items'] );
		$result              = ( new FetchInstagramAccountAbility() )->executeWithCollector( $config, $collector );
		if ( is_wp_error( $result ) ) {
			$context->log( 'error', $result->get_error_message(), array( 'code' => $result->get_error_code() ) );
			// Provider/auth failure fails the job (data-machine#3748, #4028).
			return $result;
		}
		$items = array();
		foreach ( $result['items'] as $candidate ) {
			$data       = $candidate['data'];
			$url        = $data['image_info']['url'] ?? '';
			$extension  = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$mime_types = array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'webp' => 'image/webp',
			);
			if ( ! isset( $mime_types[ $extension ] ) ) {
				$extension = 'jpg';
			}
			$stored = $url ? $context->downloadFile( $url, 'instagram_image_' . $candidate['item_id'] . '.' . $extension, array( 'timeout' => 30 ) ) : null;
			// Never emit a caption-only item: downstream vision requires a local flyer.
			if ( ! $stored ) {
				$context->log( 'warning', 'Instagram: Unable to store post image', array( 'media_id' => $candidate['item_id'] ) );
				continue;
			}
			$data['file_info'] = array(
				'file_path' => $stored['path'],
				'file_name' => $stored['filename'],
				'file_size' => $stored['size'],
				'mime_type' => $mime_types[ $extension ],
			);
			unset( $data['image_info'] );
			$data['metadata']['_engine_data'] = array(
				'source_url'      => $candidate['source_url'],
				'image_file_path' => $stored['path'],
			);
			$items[]                          = $data;
		}
		return $items ? array( 'items' => $items ) : array();
	}

	public static function get_label(): string {
		return 'Instagram account (public)';
	}
}
