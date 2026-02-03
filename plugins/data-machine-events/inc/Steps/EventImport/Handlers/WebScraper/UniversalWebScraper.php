<?php
/**
 * Universal Web Scraper Handler
 *
 * Prioritizes structured data extraction for accuracy.
 * Falls back to AI-enhanced HTML parsing when structured data is unavailable.
 *
 * Extraction Priority:
 * 1. AEG/AXS JSON (aegwebprod.blob.core.windows.net)
 * 2. Red Rocks (redrocksonline.com)
 * 3. Freshtix (*.freshtix.com)
 * 4. Firebase Realtime Database (firebaseio.com)
 * 5. ICS Feed (direct .ics files, Tockify, Google Calendar exports)
 * 6. Embedded Calendar (Google Calendar iframe → ICS feed)
 * 7. DoStuff Media API (Waterloo Records, Do512)
 * 8. Squarespace context (Static.SQUARESPACE_CONTEXT)
 * 9. Craftpeak/Arryved (craft brewery CMS with Label theme)
 * 10. Dusk.fm/BeatGig venue calendar (data-beatgig-embed → __NEXT_DATA__)
 * 11. SpotHopper API (spothopperapp.com)
 * 12. Gigwell booking platform (gigwell-gigstream)
 * 13. Bandzoogle calendar
 * 14. GoDaddy website builder
 * 15. Timely Event Discovery (FullCalendar.js)
 * 16. Elfsight Events Calendar (shy.elfsight.com API)
 * 17. Schema.org JSON-LD
 * 18. WordPress (Tribe Events, WP REST)
 * 19. Prekindle ticketing
 * 20. Wix Events JSON (wix-warmup-data)
 * 21. RHP Events WordPress plugin HTML
 * 22. OpenDate.io JSON
 * 23. Schema.org microdata
 * 24. AI-enhanced HTML pattern matching (Fallback)
 * 25. AI Vision flyer extraction (Final Fallback)
 *     - Square Online (__BOOTSTRAP_STATE__ JSON images)
 *     - Standard HTML <img> tag detection
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\ExtractorInterface;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\WixEventsExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\JsonLdExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\MicrodataExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\OpenDateExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\RhpEventsExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\AegAxsExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\RedRocksExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\FreshtixExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\FirebaseExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\EmbeddedCalendarExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\SquarespaceExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\SpotHopperExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\PrekindleExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\GoDaddyExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\BandzoogleExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\WordPressExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\TimelyExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\ElfsightEventsExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\GigwellExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\MusicItemExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\CraftpeakExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\DuskFmExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\IcsExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\DoStuffExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\VisionExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\SquareOnlineExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\VisionExtractionProcessor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Paginators\PaginatorInterface;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Paginators\JsonApiPaginator;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Paginators\HtmlLinkPaginator;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Universal web scraper handler with structured data extraction.
 */
class UniversalWebScraper extends EventImportHandler {

	use HandlerRegistrationTrait;

	const MAX_PAGES = 20;

	private StructuredDataProcessor $processor;

	/** @var ExtractorInterface[] */
	private array $extractors;

	/** @var PaginatorInterface[] */
	private array $paginators;

	public function __construct() {
		parent::__construct( 'universal_web_scraper' );

		$this->processor  = new StructuredDataProcessor( $this );
		$this->extractors = $this->getExtractors();
		$this->paginators = $this->getPaginators();

		self::registerHandler(
			'universal_web_scraper',
			'event_import',
			self::class,
			__( 'Universal Web Scraper', 'datamachine-events' ),
			__( 'AI-powered web scraping with Schema.org JSON-LD extraction', 'datamachine-events' ),
			false,
			null,
			UniversalWebScraperSettings::class,
			null
		);
	}

	/**
	 * Get registered extractors in priority order.
	 *
	 * @return ExtractorInterface[]
	 */
	private function getExtractors(): array {
		return array(
			new AegAxsExtractor(),
			new RedRocksExtractor(),
			new FreshtixExtractor(),
			new FirebaseExtractor(),
			new IcsExtractor(),
			new EmbeddedCalendarExtractor(),
			new SquarespaceExtractor(),
			new CraftpeakExtractor(),
			new DuskFmExtractor(),
			new SpotHopperExtractor(),
			new GigwellExtractor(),
			new DoStuffExtractor(),
			new BandzoogleExtractor(),
			new GoDaddyExtractor(),
			new TimelyExtractor(),
			new ElfsightEventsExtractor(),
			new JsonLdExtractor(),
			new WordPressExtractor(),
			new PrekindleExtractor(),
			new WixEventsExtractor(),
			new MusicItemExtractor(),
			new RhpEventsExtractor(),
			new OpenDateExtractor(),
			new MicrodataExtractor(),
		);
	}

	/**
	 * Get registered paginators in priority order.
	 *
	 * @return PaginatorInterface[]
	 */
	private function getPaginators(): array {
		return array(
			new JsonApiPaginator(),
			new HtmlLinkPaginator(),
		);
	}

	/**
	 * Execute web scraper with structured data extraction and AI fallback.
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$context->log(
			'debug',
			'Universal Web Scraper: Payload received',
			array(
				'config_keys' => array_keys( $config ),
			)
		);

		$url = $config['source_url'] ?? '';

		if ( empty( $url ) ) {
			$context->log(
				'error',
				'Universal Web Scraper: No URL configured',
				array(
					'config' => $config,
				)
			);
			return array();
		}

		$context->log(
			'info',
			'Universal Web Scraper: Starting event extraction',
			array(
				'url' => $url,
			)
		);

		// ICS feeds: single fetch, no pagination
		if ( preg_match( '/\.ics($|\?)/i', $url ) ) {
			$context->log(
				'info',
				'Universal Web Scraper: Direct ICS feed URL detected',
				array(
					'url' => $url,
				)
			);

			$content = $this->fetch_html( $url, $context );
			if ( ! empty( $content ) ) {
				$result = $this->tryStructuredDataExtraction(
					$content,
					$url,
					$config,
					$context
				);

				if ( null !== $result ) {
					return $result;
				}
			}
			return array();
		}

		// Unified pagination loop
		$current_url  = $url;
		$current_page = 1;
		$visited_urls = array();

		while ( $current_page <= self::MAX_PAGES ) {
			$url_hash = md5( $current_url );
			if ( isset( $visited_urls[ $url_hash ] ) ) {
				$context->log(
					'debug',
					'Universal Web Scraper: Already visited URL, ending pagination',
					array(
						'url' => $current_url,
					)
				);
				break;
			}
			$visited_urls[ $url_hash ] = true;

			$html_content = $this->fetch_html( $current_url, $context );
			if ( empty( $html_content ) ) {
				if ( 1 === $current_page ) {
					// If initial page fails, try WordPress API discovery as a last resort
					$discovered_api = $this->attemptWordPressApiDiscovery( $current_url, $context );
					if ( $discovered_api ) {
						$context->log(
							'info',
							'Universal Web Scraper: Fallback API discovery successful',
							array(
								'api_url' => $discovered_api,
							)
						);
						$api_content = $this->fetch_html( $discovered_api, $context );
						if ( ! empty( $api_content ) ) {
							return $this->tryStructuredDataExtraction(
								$api_content,
								$discovered_api,
								$config,
								$context
							) ?? array();
						}
					}
					return array();
				}
				break;
			}

			// Try structured data extraction first
			$structured_result = $this->tryStructuredDataExtraction(
				$html_content,
				$current_url,
				$config,
				$context
			);

			if ( null !== $structured_result ) {
				return $structured_result;
			}

			// Fall back to HTML section extraction
			$html_result = $this->tryHtmlSectionExtraction(
				$html_content,
				$current_url,
				$config,
				$context,
				$current_page
			);

			if ( null !== $html_result ) {
				return $html_result;
			}

			// Final fallback: AI Vision flyer extraction
			$vision_result = $this->tryVisionExtraction(
				$html_content,
				$current_url,
				$config,
				$context
			);

			if ( null !== $vision_result ) {
				return $vision_result;
			}

			// Find next page via paginators
			$next_url = $this->findNextPage( $current_url, $html_content, $context );
			if ( null === $next_url ) {
				$context->log(
					'info',
					'Universal Web Scraper: No more pages to process',
					array(
						'pages_checked' => $current_page,
					)
				);
				break;
			}

			$current_url = $next_url;
			++$current_page;

			$context->log(
				'info',
				'Universal Web Scraper: Moving to next page',
				array(
					'page'     => $current_page,
					'next_url' => $next_url,
				)
			);
		}

		return array();
	}

	/**
	 * Try structured data extraction using registered extractors.
	 */
	private function tryStructuredDataExtraction(
		string $html_content,
		string $current_url,
		array $config,
		ExecutionContext $context
	): ?array {
		foreach ( $this->extractors as $extractor ) {
			if ( ! $extractor->canExtract( $html_content ) ) {
				continue;
			}

			$events = $extractor->extract( $html_content, $current_url );
			if ( empty( $events ) ) {
				continue;
			}

			$context->log(
				'info',
				'Universal Web Scraper: Found structured data',
				array(
					'extractor'   => $extractor->getMethod(),
					'event_count' => count( $events ),
					'source_url'  => $current_url,
				)
			);

			$result = $this->processor->process(
				$events,
				$extractor->getMethod(),
				$current_url,
				$config,
				$context
			);

			if ( null !== $result ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Try HTML section extraction (AI fallback).
	 */
	private function tryHtmlSectionExtraction(
		string $html_content,
		string $current_url,
		array $config,
		ExecutionContext $context,
		int $current_page
	): ?array {
		$skipped_identifiers = array();

		while ( true ) {
			$event_section = $this->extract_event_sections( $html_content, $current_url, $context, $skipped_identifiers );

			if ( empty( $event_section ) ) {
				break;
			}

			$context->log(
				'info',
				'Universal Web Scraper: Processing event section',
				array(
					'section_identifier' => $event_section['identifier'],
					'page'               => $current_page,
				)
			);

			$raw_html_data = $this->extract_raw_html_section( $event_section['raw_html'], $current_url, $context, $config );

			if ( ! $raw_html_data ) {
				$skipped_identifiers[ $event_section['identifier'] ] = true;
				continue;
			}

			$section_title = $this->extract_section_title( $raw_html_data );
			if ( '' !== $section_title && $this->shouldSkipEventTitle( $section_title ) ) {
				$skipped_identifiers[ $event_section['identifier'] ] = true;
				continue;
			}

			$search_text = html_entity_decode( wp_strip_all_tags( $raw_html_data ) );

			if ( ! $this->applyKeywordSearch( $search_text, $config['search'] ?? '' ) ) {
				$context->log(
					'debug',
					'Universal Web Scraper: Skipping event section (include keywords)',
					array(
						'section_identifier' => $event_section['identifier'],
						'source_url'         => $current_url,
					)
				);
				$skipped_identifiers[ $event_section['identifier'] ] = true;
				continue;
			}

			if ( $this->applyExcludeKeywords( $search_text, $config['exclude_keywords'] ?? '' ) ) {
				$context->log(
					'debug',
					'Universal Web Scraper: Skipping event section (exclude keywords)',
					array(
						'section_identifier' => $event_section['identifier'],
						'source_url'         => $current_url,
					)
				);
				$skipped_identifiers[ $event_section['identifier'] ] = true;
				continue;
			}

			$this->markItemAsProcessed( $context, $event_section['identifier'] );

			$context->log(
				'info',
				'Universal Web Scraper: Found eligible HTML section',
				array(
					'source_url'         => $current_url,
					'section_identifier' => $event_section['identifier'],
					'page'               => $current_page,
				)
			);

			$dataPacket = new DataPacket(
				array(
					'title' => 'Raw HTML Event Section',
					'body'  => wp_json_encode(
						array(
							'raw_html'           => $raw_html_data,
							'source_url'         => $current_url,
							'import_source'      => 'universal_web_scraper',
							'section_identifier' => $event_section['identifier'],
						),
						JSON_PRETTY_PRINT
					),
				),
				array(
					'source_type'      => 'universal_web_scraper',
					'pipeline_id'      => $context->getPipelineId(),
					'flow_id'          => $context->getFlowId(),
					'original_title'   => 'HTML Section from ' . parse_url( $current_url, PHP_URL_HOST ),
					'event_identifier' => $event_section['identifier'],
					'import_timestamp' => time(),
				),
				'event_import'
			);

			return array( $dataPacket );
		}

		return null;
	}

	/**
	 * Try vision-based extraction from flyer images (final fallback).
	 *
	 * Analyzes potential event flyer images using AI vision when both
	 * structured data and HTML section extraction have failed.
	 *
	 * Checks for Square Online embedded images first (via SquareOnlineExtractor),
	 * then falls back to standard HTML image detection (via VisionExtractor).
	 *
	 * @since 0.9.18
	 * @since 0.9.19 Added Square Online support via SquareOnlineExtractor
	 */
	private function tryVisionExtraction(
		string $html_content,
		string $current_url,
		array $config,
		ExecutionContext $context
	): ?array {
		$candidates        = null;
		$extraction_method = 'vision';

		$squareExtractor = new SquareOnlineExtractor();
		if ( $squareExtractor->canExtract( $html_content ) ) {
			$candidates = $squareExtractor->getImageCandidates( $html_content, $current_url );
			if ( ! empty( $candidates ) ) {
				$extraction_method = $squareExtractor->getMethod();
				$context->log(
					'info',
					'Universal Web Scraper: Found Square Online embedded images',
					array(
						'url'             => $current_url,
						'candidate_count' => count( $candidates ),
					)
				);
			}
		}

		if ( empty( $candidates ) ) {
			$visionExtractor = new VisionExtractor();

			if ( ! $visionExtractor->canExtractWithUrl( $html_content, $current_url ) ) {
				$context->log(
					'debug',
					'Universal Web Scraper: Vision extraction skipped - no viable image candidates',
					array( 'url' => $current_url )
				);
				return null;
			}
		}

		$context->log(
			'info',
			'Universal Web Scraper: Attempting vision extraction fallback',
			array(
				'url'    => $current_url,
				'method' => $extraction_method,
			)
		);

		$visionProcessor = new VisionExtractionProcessor( $this );
		$result          = $visionProcessor->process( $html_content, $current_url, $config, $context, $candidates );

		if ( empty( $result ) ) {
			return null;
		}

		// VisionExtractionProcessor stores image_file_path in engine data.
		// Return a DataPacket for the AI step to process.
		$vision_data = $result[0];

		$dataPacket = new DataPacket(
			array(
				'title' => 'Vision Flyer Analysis',
				'body'  => wp_json_encode(
					array(
						'source_type'       => 'vision_flyer',
						'image_url'         => $vision_data['image_url'] ?? '',
						'page_url'          => $vision_data['page_url'] ?? $current_url,
						'extraction_method' => $extraction_method,
						'venue_config'      => array(
							'venue'      => $config['venue'] ?? '',
							'venue_name' => $config['venue_name'] ?? '',
						),
					),
					JSON_PRETTY_PRINT
				),
			),
			array(
				'source_type'       => 'vision_flyer',
				'extraction_method' => $extraction_method,
				'pipeline_id'       => $context->getPipelineId(),
				'flow_id'           => $context->getFlowId(),
				'import_timestamp'  => time(),
			),
			'event_import'
		);

		return array( $dataPacket );
	}

	/**
	 * Fetch HTML content from URL.
	 *
	 * Tries with browser spoofing first, then falls back to standard headers
	 * if it encounters a 403 or a captcha challenge.
	 */
	private function fetch_html( string $url, ExecutionContext $context ): string {
		$result = \DataMachine\Core\HttpClient::get(
			$url,
			array(
				'timeout'      => 30,
				'browser_mode' => true,
				'context'      => 'Universal Web Scraper',
			)
		);

		$is_captcha = isset( $result['data'] ) && (
			strpos( $result['data'], 'sgcaptcha' ) !== false ||
			strpos( $result['data'], 'cloudflare-challenge' ) !== false ||
			strpos( $result['data'], 'Checking your browser' ) !== false
		);

		if ( ! $result['success'] || $is_captcha ) {
			$context->log(
				'info',
				'Universal Web Scraper: Browser mode blocked or captcha detected, retrying with standard mode',
				array(
					'url'         => $url,
					'status_code' => $result['status_code'] ?? 'unknown',
					'is_captcha'  => $is_captcha,
				)
			);

			$result = \DataMachine\Core\HttpClient::get(
				$url,
				array(
					'timeout'      => 30,
					'browser_mode' => false,
					'context'      => 'Universal Web Scraper (Fallback)',
				)
			);
		}

		if ( ! $result['success'] ) {
			$context->log(
				'error',
				'Universal Web Scraper: HTTP request failed',
				array(
					'url'   => $url,
					'error' => $result['error'] ?? 'Unknown error',
				)
			);
			return '';
		}

		if ( empty( $result['data'] ) ) {
			$context->log(
				'error',
				'Universal Web Scraper: Empty response body',
				array(
					'url' => $url,
				)
			);
			return '';
		}

		return $result['data'];
	}

	/**
	 * Extract first non-processed event HTML section from content.
	 */
	private function extract_event_sections( string $html_content, string $url, ExecutionContext $context, array $skipped_identifiers = array() ): ?array {
		$finder = new EventSectionFinder(
			fn ( string $identifier ): bool => isset( $skipped_identifiers[ $identifier ] ) || $context->isItemProcessed( $identifier ),
			fn ( string $html ): string => $this->clean_html_for_ai( $html ),
			fn ( string $ymd ): bool => $this->isPastEvent( $ymd )
		);

		$event_section = $finder->find_first_eligible_section( $html_content, $url, $context );
		if ( null !== $event_section ) {
			$context->log(
				'debug',
				'Universal Web Scraper: Matched event section selector',
				array(
					'selector' => $event_section['selector'] ?? '',
					'url'      => $url,
				)
			);
		}

		return $event_section;
	}

	/**
	 * Attempt to discover WordPress API endpoint if initial fetch fails.
	 */
	private function attemptWordPressApiDiscovery( string $url, ExecutionContext $context ): ?string {
		$parsed = parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return null;
		}

		$base_url  = ( $parsed['scheme'] ?? 'https' ) . '://' . $parsed['host'];
		$endpoints = array(
			$base_url . '/wp-json/tribe/events/v1/events?per_page=100',
			$base_url . '/wp-json/wp/v2/events?per_page=100',
		);

		foreach ( $endpoints as $endpoint ) {
			$result = \DataMachine\Core\HttpClient::get(
				$endpoint,
				array(
					'timeout'      => 10,
					'browser_mode' => true,
					'context'      => 'Universal Web Scraper (API Fallback)',
				)
			);

			if ( $result['success'] && ! empty( $result['data'] ) ) {
				$data = json_decode( $result['data'], true );
				if ( is_array( $data ) && ( isset( $data['events'] ) || ( isset( $data[0] ) && isset( $data[0]['id'] ) ) ) ) {
					return $endpoint;
				}
			}
		}

		return null;
	}

	/**
	 * Find next page URL using registered paginators.
	 *
	 * @param string           $url     Current page URL.
	 * @param string           $content Current page content.
	 * @param ExecutionContext $context Execution context for logging.
	 * @return string|null Next page URL, or null if no more pages.
	 */
	private function findNextPage( string $url, string $content, ExecutionContext $context ): ?string {
		foreach ( $this->paginators as $paginator ) {
			if ( $paginator->canPaginate( $url, $content ) ) {
				$next = $paginator->getNextPageUrl( $url, $content );
				if ( null !== $next ) {
					$context->log(
						'debug',
						'Universal Web Scraper: Pagination via ' . $paginator->getMethod(),
						array(
							'next_url' => $next,
						)
					);
					return $next;
				}
			}
		}
		return null;
	}

	/**
	 * Clean HTML for AI processing.
	 */
	private function clean_html_for_ai( string $html ): string {
		$html = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $html );
		$html = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $html );
		$html = preg_replace( '/<!--.*?-->/s', '', $html );
		$html = preg_replace( '/\s+/', ' ', $html );
		return trim( $html );
	}

	/**
	 * Extract a potential title from HTML section for early filtering.
	 */
	private function extract_section_title( string $html ): string {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<meta charset="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );

		$queries = array(
			'//h1',
			'//h2',
			'//h3',
			"//*[contains(@class, 'title')]",
			"//*[contains(@class, 'event-name')]",
			"//*[contains(@class, 'EventLink')]//a",
			"//*[@itemprop='name']",
		);

		foreach ( $queries as $query ) {
			$nodes = $xpath->query( $query );
			if ( false !== $nodes && $nodes->length > 0 ) {
				$text = trim( $nodes->item( 0 )->textContent );
				if ( ! empty( $text ) ) {
					return $text;
				}
			}
		}

		return '';
	}

	/**
	 * Extract raw HTML section for AI processing.
	 */
	private function extract_raw_html_section( string $section_html, string $source_url, ExecutionContext $context, array $config = array() ): ?string {
		$cleaned = $this->clean_html_for_ai( $section_html );

		if ( empty( $cleaned ) || strlen( $cleaned ) < 50 ) {
			$context->log(
				'debug',
				'Universal Web Scraper: HTML section too short after cleaning',
				array(
					'source_url'     => $source_url,
					'cleaned_length' => strlen( $cleaned ),
				)
			);
			return null;
		}

		if ( strlen( $cleaned ) > 50000 ) {
			$cleaned = substr( $cleaned, 0, 50000 );
			$context->log(
				'debug',
				'Universal Web Scraper: Truncated HTML section to 50KB',
				array(
					'source_url' => $source_url,
				)
			);
		}

		return $cleaned;
	}
}
