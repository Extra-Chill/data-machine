<?php
/**
 * Smoke coverage for Publisher's public URL contract with Twitter.
 *
 * Run with: php tests/twitter-publisher-media-contract-smoke.php
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MB_IN_BYTES', 1048576 );
	$GLOBALS['twitter_media_deleted'] = array();

	class WP_Error {
		public function __construct( public string $code, public string $message, public array $data = array() ) {}
		public function get_error_message(): string {
			return $this->message;
		}
	}

	function is_wp_error( $value ): bool {
		return is_object( $value ) && $value instanceof WP_Error;
	}

	function wp_http_validate_url( string $url ): string|false {
		return str_starts_with( $url, 'https://' ) ? $url : false;
	}

	function wp_tempnam( string $url ): string {
		unset( $url );
		return __FILE__;
	}

	function wp_safe_remote_get( string $url, array $args ): array {
		unset( $url, $args );
		return array( 'response' => array( 'code' => 200 ) );
	}

	function wp_remote_retrieve_response_code( array $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}

	function wp_delete_file( string $path ): void {
		$GLOBALS['twitter_media_deleted'][] = $path;
	}

	function wp_get_ability( string $slug ): object {
		unset( $slug );
		return $GLOBALS['twitter_publish_ability'];
	}
}

namespace DataMachineSocials\Abilities {
	abstract class AbstractSocialAbility {}
}

namespace DataMachine\Abilities {
	class AuthAbilities {
		public function getProvider( string $platform ): object {
			unset( $platform );
			return $GLOBALS['twitter_media_provider'];
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
}

namespace DataMachine\Core\FilesRepository {
	class VideoValidator {
		public static function is_video_extension( string $path ): bool {
			unset( $path );
			return false;
		}
	}

	class ImageValidator {
		public function validate_repository_file( string $path ): array {
			return array(
				'valid'     => is_file( $path ),
				'mime_type' => 'image/jpeg',
				'size'      => filesize( $path ),
			);
		}
	}
}

namespace DataMachineSocials\Tracking {
	class SocialShareTracker {
		public static function is_safe_platform_reference( string $platform, string $platform_post_id, string $platform_url ): bool {
			return 'twitter' === $platform && '' !== $platform_post_id && str_starts_with( $platform_url, 'https://twitter.com/' );
		}

		public static function extract_platform_post_id( string $platform, array $result ): string {
			unset( $platform );
			return (string) ( $result['tweet_id'] ?? '' );
		}

		public static function extract_platform_url( string $platform, array $result ): string {
			unset( $platform );
			return (string) ( $result['tweet_url'] ?? '' );
		}
	}
}

namespace {
	final class TwitterMediaConnection {
		public int $http_code = 0;
		public array $media_ids = array();
		public array $tweet_payload = array();

		public function setApiVersion( string $version ): void {
			unset( $version );
		}

		public function post( string $endpoint, array $payload, array $options = array() ): object {
			unset( $options );
			if ( 'media/upload' === $endpoint && 'INIT' === ( $payload['command'] ?? '' ) ) {
				$id                = 'media-' . ( count( $this->media_ids ) + 1 );
				$this->media_ids[] = $id;
				return (object) array( 'media_id_string' => $id );
			}
			if ( 'media/upload' === $endpoint && 'FINALIZE' === ( $payload['command'] ?? '' ) ) {
				return (object) array( 'media_id_string' => $payload['media_id'] );
			}
			if ( 'tweets' === $endpoint ) {
				$this->tweet_payload = $payload;
				$this->http_code     = 201;
				return (object) array( 'data' => (object) array( 'id' => 'tweet-1' ) );
			}

			return (object) array();
		}

		public function getLastHttpCode(): int {
			return $this->http_code;
		}
	}

	final class TwitterMediaProvider {
		public function __construct( private TwitterMediaConnection $connection ) {}
		public function is_authenticated(): bool {
			return true;
		}
		public function get_connection(): TwitterMediaConnection {
			return $this->connection;
		}
		public function get_username(): string {
			return 'publisher-test';
		}
	}

	final class TwitterPublisherAbility {
		public function execute( array $input ): array|WP_Error {
			return \DataMachineSocials\Abilities\Twitter\TwitterPublishAbility::execute_publish( $input );
		}
	}

	$connection = new TwitterMediaConnection();
	$GLOBALS['twitter_media_provider'] = new TwitterMediaProvider( $connection );

	require_once dirname( __DIR__ ) . '/inc/Abilities/Twitter/TwitterPublishAbility.php';
	require_once dirname( __DIR__ ) . '/inc/Publisher.php';
	$GLOBALS['twitter_publish_ability'] = new TwitterPublisherAbility();

	$result = \DataMachineSocials\Publisher::post_to_platform(
		'twitter',
		array( array( 'url' => 'https://example.test/one.jpg' ), array( 'url' => 'https://example.test/two.jpg' ) ),
		'Publisher media contract.',
		'https://example.test/source'
	);

	$failures = array();
	if ( empty( $result['success'] ) || 'tweet-1' !== ( $result['platform_post_id'] ?? '' ) ) {
		$failures[] = 'Twitter publish succeeds with public image_urls.';
	}
	if ( array( 'media-1', 'media-2' ) !== ( $connection->tweet_payload['media']['media_ids'] ?? null ) ) {
		$failures[] = 'Every downloaded Publisher image reaches the Twitter media payload.';
	}
	if ( 2 !== count( $GLOBALS['twitter_media_deleted'] ) ) {
		$failures[] = 'Downloaded temporary media is always cleaned up.';
	}

	if ( $failures ) {
		foreach ( $failures as $failure ) {
			echo "FAIL: {$failure}\n";
		}
		exit( 1 );
	}

	echo "All 3 Twitter Publisher media assertions passed.\n";
}
