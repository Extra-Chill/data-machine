<?php
/**
 * Smoke coverage for strict image delivery across text-capable platforms.
 *
 * Run with: php tests/strict-image-publishing-smoke.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MB_IN_BYTES', 1048576 );
	$GLOBALS['strict_image_http_calls'] = array();

	class WP_Error {
		public function __construct( private string $code, private string $message, private array $data = array() ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function wp_json_encode( $value ): string {
		return (string) json_encode( $value );
	}

	function attachment_url_to_postid( string $url ): int {
		return 'https://fresh.example.test/image.jpg' === $url ? 42 : 0;
	}

	function wp_get_attachment_url( int $attachment_id ): string|false {
		return 42 === $attachment_id ? 'https://fresh.example.test/image.jpg' : false;
	}

	function get_attached_file( int $attachment_id, bool $unfiltered = false ): string|false {
		unset( $unfiltered );
		return 42 === $attachment_id ? __FILE__ : false;
	}

	function get_post_mime_type( int $attachment_id ): string|false {
		return 42 === $attachment_id ? 'image/jpeg' : false;
	}
}

namespace DataMachineSocials\Abilities {
	abstract class AbstractSocialAbility {}
}

namespace DataMachineSocials\Handlers\Facebook {
	class FacebookAuth {
		public const GRAPH_API_URL = 'https://graph.facebook.test';
	}
}

namespace DataMachine\Abilities {
	class AuthAbilities {
		public function getProvider( string $platform ): object {
			return 'facebook' === $platform ? new \StrictFacebookProvider() : new \StrictBlueskyProvider();
		}
	}

	class PermissionHelper {
		public static function can( string $capability ): bool {
			unset( $capability );
			return true;
		}
	}
}

namespace DataMachine\Core {
	class EngineData {}

	class HttpClient {
		public static function get( string $url, array $args = array() ): array {
			$GLOBALS['strict_image_http_calls'][] = array( 'GET', $url, $args );
			return array( 'success' => false, 'error' => 'download failed' );
		}

		public static function post( string $url, array $args = array() ): array {
			$GLOBALS['strict_image_http_calls'][] = array( 'POST', $url, $args );
			if ( str_contains( $url, 'uploadBlob' ) ) {
				return array( 'success' => true, 'data' => json_encode( array( 'blob' => array( '$type' => 'blob' ) ) ) );
			}
			if ( str_contains( $url, 'createRecord' ) ) {
				return array( 'success' => true, 'data' => json_encode( array( 'uri' => 'at://did:plc:test/app.bsky.feed.post/local-attachment' ) ) );
			}
			return array( 'success' => false, 'error' => 'upload failed' );
		}
	}
}

namespace {
	final class StrictFacebookProvider {
		public function is_authenticated(): bool {
			return true;
		}
		public function get_page_id(): string {
			return 'page-1';
		}
		public function get_page_access_token(): string {
			return 'token';
		}
	}

	final class StrictBlueskyProvider {
		public function is_authenticated(): bool {
			return true;
		}
		public function get_session(): array {
			return array( 'handle' => 'example.test', 'did' => 'did:plc:test', 'accessJwt' => 'token' );
		}
	}

	require_once dirname( __DIR__ ) . '/inc/Abilities/Facebook/FacebookPublishAbility.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Bluesky/BlueskyPublishAbility.php';
	require_once dirname( __DIR__ ) . '/inc/Abilities/Twitter/TwitterPublishAbility.php';

	$input = array(
		'content'    => 'Strict image delivery.',
		'image_url'  => 'https://example.test/image.jpg',
		'media_kind' => 'image',
	);
	$facebook = \DataMachineSocials\Abilities\Facebook\FacebookPublishAbility::execute_publish( $input );
	$bluesky  = \DataMachineSocials\Abilities\Bluesky\BlueskyPublishAbility::execute_publish( $input );
	$local_bluesky = \DataMachineSocials\Abilities\Bluesky\BlueskyPublishAbility::execute_publish(
		array_replace( $input, array( 'image_url' => 'https://fresh.example.test/image.jpg' ) )
	);
	$twitter  = \DataMachineSocials\Abilities\Twitter\TwitterPublishAbility::execute_publish(
		array(
			'content'    => 'Strict image delivery.',
			'media_kind' => 'image',
		)
	);
	$missing_twitter_file = \DataMachineSocials\Abilities\Twitter\TwitterPublishAbility::execute_publish(
		array(
			'content'    => 'Strict image delivery.',
			'media_kind' => 'image',
			'media_path' => __DIR__ . '/missing-image.jpg',
		)
	);

	$failures = array();
	if ( ! is_wp_error( $facebook ) || 'media_upload_failed' !== $facebook->get_error_code() ) {
		$failures[] = 'Facebook image upload failure must stop feed publishing.';
	}
	if ( ! is_wp_error( $bluesky ) || 'media_upload_failed' !== $bluesky->get_error_code() ) {
		$failures[] = 'Bluesky image upload failure must stop record publishing.';
	}
	if ( is_wp_error( $local_bluesky ) || 'local-attachment' !== ( $local_bluesky['post_id'] ?? '' ) ) {
		$failures[] = 'Bluesky must upload a canonical local attachment without a loopback download.';
	}
	$local_downloads = array_filter( $GLOBALS['strict_image_http_calls'], static fn( array $call ): bool => 'GET' === $call[0] && 'https://fresh.example.test/image.jpg' === $call[1] );
	if ( $local_downloads ) {
		$failures[] = 'Canonical local attachments must not use stale or loopback transport URLs.';
	}
	if ( ! is_wp_error( $twitter ) || 'missing_param' !== $twitter->get_error_code() ) {
		$failures[] = 'Twitter image publishing must require media.';
	}
	if ( ! is_wp_error( $missing_twitter_file ) || 'invalid_media_url' !== $missing_twitter_file->get_error_code() ) {
		$failures[] = 'Twitter image publishing must reject an unavailable media path.';
	}
	$create_record_calls = 0;
	foreach ( $GLOBALS['strict_image_http_calls'] as $call ) {
		if ( str_contains( $call[1], '/feed' ) ) {
			$failures[] = 'A platform silently degraded failed image delivery to a text/link post.';
		}
		if ( str_contains( $call[1], 'createRecord' ) ) {
			++$create_record_calls;
		}
	}
	if ( 1 !== $create_record_calls ) {
		$failures[] = 'Only the locally resolved Bluesky image may reach record publishing.';
	}

	if ( $failures ) {
		foreach ( $failures as $failure ) {
			echo "FAIL: {$failure}\n";
		}
		exit( 1 );
	}

	echo "All 6 strict image publishing assertions passed.\n";
}
