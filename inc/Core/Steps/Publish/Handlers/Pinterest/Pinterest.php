<?php
/**
 * Pinterest publishing handler with bearer token auth and AI tool integration.
 *
 * Creates pins on Pinterest via API v5 with:
 * - Image URL from WordPress media
 * - Title and description
 * - Link back to source URL
 * - Board selection (configurable default or AI override)
 *
 * @package DataMachine\Core\Steps\Publish\Handlers\Pinterest
 * @since 0.3.0
 */

namespace DataMachine\Core\Steps\Publish\Handlers\Pinterest;

use DataMachine\Abilities\AuthAbilities;
use DataMachine\Abilities\Pinterest\PinterestAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Pinterest Publishing Handler
 *
 * Publishes content to Pinterest as pins via API v5.
 * Supports image URLs, titles, descriptions, and link-back to source.
 */
class Pinterest extends PublishHandler {

	use HandlerRegistrationTrait;

	/** @var PinterestAuth Authentication handler */
	private $auth;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'pinterest' );

		self::registerHandler(
			'pinterest_publish',
			'publish',
			self::class,
			'Pinterest',
			'Pin content to Pinterest with image and link back to source',
			true,
			PinterestAuth::class,
			PinterestSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'pinterest_publish' === $handler_slug ) {
					$board_id_description = 'Pinterest board ID override (uses default if omitted)';

					// Inject cached board names when AI decides mode is active.
					$mode = $handler_config['board_selection_mode'] ?? 'pre_selected';
					if ( 'ai_decides' === $mode ) {
						$cached_boards = PinterestAbilities::get_cached_boards();
						if ( ! empty( $cached_boards ) ) {
							$board_list = implode( ', ', array_map( function ( $b ) {
								return $b['name'] . ' (' . $b['id'] . ')';
							}, $cached_boards ) );
							$board_id_description = "Pinterest board ID. Available boards: {$board_list}";
						}
					}

					$tools['pinterest_publish'] = array(
						'class'       => self::class,
						'method'      => 'handle_tool_call',
						'handler'     => 'pinterest_publish',
						'description' => 'Pin content to Pinterest with image, title, description, and link.',
						'parameters'  => array(
							'type'       => 'object',
							'properties' => array(
								'title'       => array(
									'type'        => 'string',
									'description' => 'Pin title (max 100 characters)',
								),
								'description' => array(
									'type'        => 'string',
									'description' => 'Pin description (max 500 characters)',
								),
								'board_id'    => array(
									'type'        => 'string',
									'description' => $board_id_description,
								),
							),
							'required'   => array( 'title', 'description' ),
						),
					);
				}
				return $tools;
			},
			'pinterest'
		);
	}

	/**
	 * Lazy-load auth provider.
	 *
	 * @return PinterestAuth|null Auth provider instance or null if unavailable.
	 */
	private function get_auth() {
		if ( $this->auth === null ) {
			$auth_abilities = new AuthAbilities();
			$this->auth     = $auth_abilities->getProvider( 'pinterest' );

			if ( $this->auth === null ) {
				$this->log(
					'error',
					'Pinterest Handler: Authentication service not available',
					array(
						'handler'             => 'pinterest',
						'available_providers' => array_keys( $auth_abilities->getAllProviders() ),
					)
				);
			}
		}
		return $this->auth;
	}

	/**
	 * Execute Pinterest publishing.
	 *
	 * @param array $parameters Tool parameters including title, description, board_id.
	 * @param array $handler_config Handler configuration from settings.
	 * @return array {
	 *     @type bool   $success Whether the pin was created.
	 *     @type string $error   Error message if failed.
	 *     @type string $tool_name Tool identifier.
	 *     @type array  $data    Pin data on success (pin_id, pin_url).
	 * }
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		$title       = $parameters['title'] ?? '';
		$description = $parameters['description'] ?? '';

		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			$engine = new EngineData( $parameters['engine_data'] ?? array(), $parameters['job_id'] ?? null );
		}

		$source_url = $engine->getSourceUrl();
		$image_url  = $this->resolve_image_url( $engine );

		if ( empty( $image_url ) ) {
			return $this->errorResponse(
				'No publicly accessible image URL found for pin',
				array( 'source_url' => $source_url )
			);
		}

		$auth = $this->get_auth();
		if ( ! $auth ) {
			return $this->errorResponse( 'Pinterest authentication not configured', array(), 'critical' );
		}

		$config = $auth->get_config();
		$token  = $config['access_token'] ?? '';

		if ( empty( $token ) ) {
			return $this->errorResponse( 'Pinterest access token is empty', array(), 'critical' );
		}

		$board_id = $this->resolve_board_id( $parameters, $handler_config, $engine );

		if ( empty( $board_id ) ) {
			return $this->errorResponse(
				'No board_id provided. Set a default in handler settings or pass board_id parameter.',
				array()
			);
		}

		$payload = array(
			'board_id'     => $board_id,
			'title'        => substr( $title, 0, 100 ),
			'description'  => substr( $description, 0, 500 ),
			'link'         => $source_url,
			'media_source' => array(
				'source_type' => 'image_url',
				'url'         => $image_url,
			),
		);

		$this->log(
			'info',
			'Pinterest: Creating pin',
			array(
				'board_id'   => $board_id,
				'title'      => $payload['title'],
				'source_url' => $source_url,
				'image_url'  => $image_url,
			)
		);

		try {
			$result = $this->httpPost(
				'https://api.pinterest.com/v5/pins',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $payload ),
					'context' => 'Pinterest Pin Creation',
				)
			);

			if ( ! $result['success'] ) {
				return $this->errorResponse(
					'Pinterest API request failed: ' . ( $result['error'] ?? 'Unknown error' ),
					array( 'result' => $result )
				);
			}

			$status_code   = $result['status_code'] ?? 0;
			$response_data = json_decode( $result['data'] ?? '', true );

			if ( 201 === $status_code && ! empty( $response_data['id'] ) ) {
				$pin_id = $response_data['id'];

				$this->log(
					'info',
					'Pinterest: Pin created successfully',
					array(
						'pin_id'   => $pin_id,
						'board_id' => $board_id,
					)
				);

				return $this->successResponse(
					array(
						'pin_id'  => $pin_id,
						'pin_url' => 'https://www.pinterest.com/pin/' . $pin_id . '/',
					)
				);
			}

			$error_msg = 'Pinterest API error';
			if ( ! empty( $response_data['message'] ) ) {
				$error_msg .= ': ' . $response_data['message'];
			}

			return $this->errorResponse(
				$error_msg,
				array(
					'http_code'    => $status_code,
					'api_response' => $result['data'] ?? '',
				)
			);
		} catch ( \Exception $e ) {
			return $this->errorResponse(
				$e->getMessage(),
				array( 'exception_type' => get_class( $e ) )
			);
		}
	}

	/**
	 * Resolve the board ID using the configured selection mode.
	 *
	 * Resolution order:
	 * 1. AI parameter board_id (from tool call)
	 * 2. Category mapping lookup (post categories → board_id)
	 * 3. Default board_id from handler config
	 *
	 * @since 0.3.0
	 *
	 * @param array           $parameters    Tool parameters.
	 * @param array           $handler_config Handler configuration.
	 * @param EngineData|null $engine         Engine data instance.
	 * @return string|null Board ID or null if none resolved.
	 */
	public function resolve_board_id( array $parameters, array $handler_config, ?EngineData $engine ): ?string {
		// 1. Explicit board_id from AI tool call.
		if ( ! empty( $parameters['board_id'] ) ) {
			$this->log( 'info', 'Pinterest: Board ID from AI parameter', array( 'board_id' => $parameters['board_id'] ) );
			return $parameters['board_id'];
		}

		// 2. Delegate to abilities for mode-based resolution.
		$post_id = 0;
		if ( $engine ) {
			$source_url = $engine->getSourceUrl();
			if ( ! empty( $source_url ) ) {
				$post_id = url_to_postid( $source_url );
			}
		}

		$board_id = PinterestAbilities::resolve_board_id( $post_id, $handler_config );
		if ( $board_id ) {
			$this->log( 'info', 'Pinterest: Board ID from abilities resolution', array( 'board_id' => $board_id ) );
			return $board_id;
		}

		return null;
	}

	/**
	 * Get cached Pinterest boards.
	 *
	 * @since 0.3.0
	 *
	 * @return array Array of cached boards.
	 */
	public function get_cached_boards(): array {
		return PinterestAbilities::get_cached_boards();
	}

	/**
	 * Resolve a publicly accessible image URL for the pin.
	 *
	 * Checks engine data for image_url, then falls back to the WordPress
	 * post's featured image URL.
	 *
	 * @param EngineData $engine Engine data instance.
	 * @return string|null Public image URL or null if unavailable.
	 */
	private function resolve_image_url( EngineData $engine ): ?string {
		// Check engine data for a stored image URL.
		$image_url = $engine->get( 'image_url' );
		if ( ! empty( $image_url ) && filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
			return $image_url;
		}

		// Fall back to WordPress post featured image.
		$source_url = $engine->getSourceUrl();
		if ( ! empty( $source_url ) ) {
			$post_id = url_to_postid( $source_url );
			if ( $post_id > 0 ) {
				$thumbnail_url = get_the_post_thumbnail_url( $post_id, 'full' );
				if ( ! empty( $thumbnail_url ) ) {
					return $thumbnail_url;
				}
			}
		}

		// Check for attachment_url in engine data.
		$attachment_url = $engine->get( 'attachment_url' );
		if ( ! empty( $attachment_url ) && filter_var( $attachment_url, FILTER_VALIDATE_URL ) ) {
			return $attachment_url;
		}

		return null;
	}

	/**
	 * Get handler display label.
	 *
	 * @return string Translated label.
	 */
	public static function get_label(): string {
		return __( 'Pin to Pinterest', 'data-machine' );
	}
}
