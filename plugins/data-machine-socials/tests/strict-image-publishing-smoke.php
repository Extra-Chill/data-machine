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
	if ( ! is_wp_error( $twitter ) || 'missing_param' !== $twitter->get_error_code() ) {
		$failures[] = 'Twitter image publishing must require media.';
	}
	if ( ! is_wp_error( $missing_twitter_file ) || 'invalid_media_url' !== $missing_twitter_file->get_error_code() ) {
		$failures[] = 'Twitter image publishing must reject an unavailable media path.';
	}
	foreach ( $GLOBALS['strict_image_http_calls'] as $call ) {
		if ( str_contains( $call[1], '/feed' ) || str_contains( $call[1], 'createRecord' ) ) {
			$failures[] = 'A platform silently degraded failed image delivery to a text/link post.';
		}
	}

	if ( $failures ) {
		foreach ( $failures as $failure ) {
			echo "FAIL: {$failure}\n";
		}
		exit( 1 );
	}

	echo "All 4 strict image publishing assertions passed.\n";
}
