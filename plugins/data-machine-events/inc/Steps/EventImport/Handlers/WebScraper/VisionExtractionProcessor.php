<?php
/**
 * Vision Extraction Processor
 *
 * Processes flyer images using AI vision to extract event information.
 * Acts as the final fallback when structured data and HTML extraction fail.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 * @since   0.9.18
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\VisionExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VisionExtractionProcessor {

	private EventImportHandler $handler;

	public function __construct( EventImportHandler $handler ) {
		$this->handler = $handler;
	}

	/**
	 * Process HTML content for flyer images and extract events via AI vision.
	 *
	 * @param string           $html               HTML content
	 * @param string           $url                Page URL
	 * @param array            $config             Handler configuration
	 * @param ExecutionContext $context            Execution context
	 * @param array|null       $pre_found_candidates Optional pre-found image candidates (e.g., from SquareOnlineExtractor)
	 * @return array|null Array of normalized event data or null if no events found
	 */
	public function process(
		string $html,
		string $url,
		array $config,
		ExecutionContext $context,
		?array $pre_found_candidates = null
	): ?array {
		if ( null !== $pre_found_candidates ) {
			$candidates = $pre_found_candidates;
		} else {
			$extractor  = new VisionExtractor();
			$candidates = $extractor->getCandidates( $html, $url );
		}

		if ( empty( $candidates ) ) {
			$context->log(
				'debug',
				'VisionExtractor: No viable image candidates found',
				array( 'url' => $url )
			);
			return null;
		}

		$context->log(
			'info',
			'VisionExtractor: Found image candidates for vision analysis',
			array(
				'url'             => $url,
				'candidate_count' => count( $candidates ),
				'top_score'       => $candidates[0]['score'] ?? 0,
			)
		);

		foreach ( $candidates as $candidate ) {
			$image_url = $candidate['url'];

			// Generate content-based identifier for cross-run tracking.
			$image_identifier = md5( $url . $image_url );

			// Check if already processed (replaces in-memory tracking).
			if ( $context->isItemProcessed( $image_identifier ) ) {
				$context->log(
					'debug',
					'VisionExtractor: Skipping processed image',
					array( 'image_url' => $image_url )
				);
				continue;
			}

			$context->log(
				'debug',
				'VisionExtractor: Analyzing image',
				array(
					'image_url' => $image_url,
					'score'     => $candidate['score'],
				)
			);

			$events = $this->analyzeImageWithVision( $image_url, $url, $context );

			// Mark as processed AFTER analysis attempt (success or fail).
			$this->handler->markItemAsProcessed( $context, $image_identifier );

			if ( ! empty( $events ) ) {
				$context->log(
					'info',
					'VisionExtractor: Extracted events from flyer',
					array(
						'image_url'   => $image_url,
						'event_count' => count( $events ),
					)
				);
				return $events;
			}

			// No events found from this image - return null, next run will try next image.
			$context->log(
				'debug',
				'VisionExtractor: No events extracted from image, will try next candidate on next run',
				array( 'image_url' => $image_url )
			);
			return null;
		}

		// All candidates already processed.
		$context->log(
			'info',
			'VisionExtractor: All image candidates already processed',
			array( 'url' => $url )
		);
		return null;
	}

	/**
	 * Analyze a single image using AI vision.
	 *
	 * @param string           $image_url Image URL to analyze
	 * @param string           $page_url  Source page URL for context
	 * @param ExecutionContext $context   Execution context
	 * @return array Array of extracted events or empty array
	 */
	private function analyzeImageWithVision( string $image_url, string $page_url, ExecutionContext $context ): array {
		$temp_file = $this->downloadImage( $image_url, $context );
		if ( null === $temp_file ) {
			return array();
		}

		try {
			$result = $this->callVisionApi( $temp_file, $page_url, $context );
			return $this->parseVisionResponse( $result, $context );
		} finally {
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
		}
	}

	/**
	 * Download image to temporary file.
	 *
	 * @param string           $url     Image URL
	 * @param ExecutionContext $context Execution context
	 * @return string|null Path to temp file or null on failure
	 */
	private function downloadImage( string $url, ExecutionContext $context ): ?string {
		$result = \DataMachine\Core\HttpClient::get(
			$url,
			array(
				'timeout'      => 30,
				'browser_mode' => true,
				'context'      => 'VisionExtractor Image Download',
			)
		);

		if ( ! $result['success'] || empty( $result['data'] ) ) {
			$context->log(
				'warning',
				'VisionExtractor: Failed to download image',
				array(
					'url'   => $url,
					'error' => $result['error'] ?? 'Unknown error',
				)
			);
			return null;
		}

		$extension = $this->getImageExtension( $url, $result['headers'] ?? array() );
		$temp_file = wp_tempnam( 'vision_' ) . '.' . $extension;

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem->put_contents( $temp_file, $result['data'], FS_CHMOD_FILE ) ) {
			$context->log(
				'warning',
				'VisionExtractor: Failed to write temp file',
				array( 'url' => $url )
			);
			return null;
		}

		return $temp_file;
	}

	/**
	 * Call AI vision API with the image.
	 *
	 * @param string           $image_path Path to image file
	 * @param string           $page_url   Source page URL for context
	 * @param ExecutionContext $context    Execution context
	 * @return array|null API response or null on failure
	 */
	private function callVisionApi( string $image_path, string $page_url, ExecutionContext $context ): ?array {
		$prompt = $this->getVisionPrompt();

		$request = array(
			'model'      => 'claude-sonnet-4-20250514',
			'max_tokens' => 4096,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'      => 'file',
							'file_path' => $image_path,
							'mime_type' => mime_content_type( $image_path ),
						),
						array(
							'type' => 'text',
							'text' => $prompt,
						),
					),
				),
			),
		);

		$context->log(
			'debug',
			'VisionExtractor: Calling vision API',
			array(
				'image_path' => $image_path,
				'page_url'   => $page_url,
			)
		);

		$result = apply_filters( 'chubes_ai_request', null, $request, 'anthropic' );

		if ( ! $result || ! isset( $result['success'] ) || ! $result['success'] ) {
			$context->log(
				'error',
				'VisionExtractor: Vision API call failed',
				array(
					'error' => $result['error'] ?? 'Unknown error',
				)
			);
			return null;
		}

		return $result;
	}

	/**
	 * Parse vision API response into normalized event data.
	 *
	 * @param array|null       $result  API response
	 * @param ExecutionContext $context Execution context
	 * @return array Array of normalized events
	 */
	private function parseVisionResponse( ?array $result, ExecutionContext $context ): array {
		if ( null === $result || empty( $result['data']['content'] ) ) {
			return array();
		}

		$content = $result['data']['content'];

		// Extract JSON from response (may be wrapped in markdown code block)
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $content, $matches ) ) {
			$content = trim( $matches[1] );
		}

		$data = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$context->log(
				'warning',
				'VisionExtractor: Failed to parse JSON from vision response',
				array(
					'error'   => json_last_error_msg(),
					'content' => substr( $content, 0, 500 ),
				)
			);
			return array();
		}

		if ( empty( $data['events'] ) || ! is_array( $data['events'] ) ) {
			$context->log(
				'debug',
				'VisionExtractor: No events in vision response',
				array(
					'confidence' => $data['confidence'] ?? 'unknown',
					'notes'      => $data['notes'] ?? '',
				)
			);
			return array();
		}

		$events = array();

		foreach ( $data['events'] as $raw_event ) {
			$event = $this->normalizeEvent( $raw_event );
			if ( ! empty( $event['title'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Normalize event data from vision response to standard format.
	 *
	 * @param array $raw_event Raw event from vision API
	 * @return array Normalized event data
	 */
	private function normalizeEvent( array $raw_event ): array {
		return array(
			'title'        => sanitize_text_field( $raw_event['title'] ?? '' ),
			'startDate'    => sanitize_text_field( $raw_event['startDate'] ?? '' ),
			'startTime'    => sanitize_text_field( $raw_event['startTime'] ?? '' ),
			'endTime'      => sanitize_text_field( $raw_event['endTime'] ?? '' ),
			'venue'        => sanitize_text_field( $raw_event['venue'] ?? '' ),
			'venueAddress' => sanitize_text_field( $raw_event['venueAddress'] ?? '' ),
			'venueCity'    => sanitize_text_field( $raw_event['venueCity'] ?? '' ),
			'venueState'   => sanitize_text_field( $raw_event['venueState'] ?? '' ),
			'price'        => sanitize_text_field( $raw_event['price'] ?? '' ),
			'ticketUrl'    => esc_url_raw( $raw_event['ticketUrl'] ?? '' ),
			'performer'    => sanitize_text_field( $raw_event['performer'] ?? '' ),
			'description'  => sanitize_text_field( $raw_event['description'] ?? '' ),
			'imageUrl'     => '',  // Flyer image could be set here if needed
		);
	}

	/**
	 * Get the vision prompt for extracting event information.
	 *
	 * @return string Vision prompt
	 */
	private function getVisionPrompt(): string {
		return 'Extract ALL event information from this promotional flyer, poster, or event graphic.

If this is a calendar flyer showing multiple events, extract EVERY event visible.

RESPONSE FORMAT (JSON only, no other text):
{
    "events": [
        {
            "title": "Event/headliner name",
            "startDate": "YYYY-MM-DD",
            "startTime": "HH:MM (24-hour)",
            "endTime": "HH:MM if visible",
            "venue": "Venue name",
            "venueAddress": "Street address if visible",
            "venueCity": "City",
            "venueState": "State",
            "price": "$XX or range",
            "ticketUrl": "URL if visible",
            "performer": "Supporting acts",
            "description": "Age restrictions, notes"
        }
    ],
    "confidence": "high|medium|low",
    "notes": "Any ambiguities"
}

Return empty events array if image is not an event flyer.
Do not guess information that is not visible.';
	}

	/**
	 * Determine image extension from URL or headers.
	 *
	 * @param string       $url     Image URL
	 * @param array|object $headers Response headers (array or CaseInsensitiveDictionary)
	 * @return string File extension
	 */
	private function getImageExtension( string $url, $headers ): string {
		// Try Content-Type header first (handle both array and object access)
		$content_type = '';
		if ( is_array( $headers ) && isset( $headers['content-type'] ) ) {
			$content_type = $headers['content-type'];
		} elseif ( is_object( $headers ) && isset( $headers['content-type'] ) ) {
			$content_type = $headers['content-type'];
		}
		$type_map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);

		foreach ( $type_map as $mime => $ext ) {
			if ( strpos( $content_type, $mime ) !== false ) {
				return $ext;
			}
		}

		// Fall back to URL extension
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
			return $ext;
		}

		return 'jpg';  // Default
	}
}
