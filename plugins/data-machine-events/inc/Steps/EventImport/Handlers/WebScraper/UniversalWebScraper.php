<?php
/**
 * Universal Web Scraper Handler
 *
 * Prioritizes structured data extraction for accuracy.
 * Falls back to AI-enhanced HTML parsing when structured data is unavailable.
 *
 * Extraction Priority:
 * 1. Wix Events JSON (wix-warmup-data)
 * 2. RHP Events WordPress plugin HTML
 * 3. Schema.org JSON-LD
 * 4. Schema.org microdata
 * 5. AI-enhanced HTML pattern matching
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\ExtractorInterface;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\WixEventsExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\JsonLdExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\MicrodataExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\OpenDateExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\RhpEventsExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\AegAxsExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\RedRocksExtractor;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
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

    public function __construct() {
        parent::__construct('universal_web_scraper');

        $this->processor = new StructuredDataProcessor($this);
        $this->extractors = $this->getExtractors();

        self::registerHandler(
            'universal_web_scraper',
            'event_import',
            self::class,
            __('Universal Web Scraper', 'datamachine-events'),
            __('AI-powered web scraping with Schema.org JSON-LD extraction', 'datamachine-events'),
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
        return [
            new AegAxsExtractor(),
            new RedRocksExtractor(),
            new WixEventsExtractor(),
            new RhpEventsExtractor(),
            new OpenDateExtractor(),
            new JsonLdExtractor(),
            new MicrodataExtractor(),
        ];
    }

    /**
     * Execute web scraper with structured data extraction and AI fallback.
     */
    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $this->log('debug', 'Universal Web Scraper: Payload received', [
            'config_keys' => array_keys($config)
        ]);
        
        $url = $config['source_url'] ?? '';
        
        if (empty($url)) {
            $this->log('error', 'Universal Web Scraper: No URL configured', [
                'config' => $config
            ]);
            return [];
        }
        
        $this->log('info', 'Universal Web Scraper: Starting event extraction', [
            'url' => $url,
            'flow_step_id' => $flow_step_id
        ]);

        $current_url = $url;
        $current_page = 1;
        $visited_urls = [];

        while ($current_page <= self::MAX_PAGES) {
            $url_hash = md5($current_url);
            if (isset($visited_urls[$url_hash])) {
                $this->log('debug', 'Universal Web Scraper: Already visited URL, ending pagination', [
                    'url' => $current_url
                ]);
                break;
            }
            $visited_urls[$url_hash] = true;

            $html_content = $this->fetch_html($current_url);
            if (empty($html_content)) {
                if ($current_page === 1) {
                    return [];
                }
                break;
            }

            // Try structured data extraction first
            $structured_result = $this->tryStructuredDataExtraction(
                $html_content,
                $current_url,
                $config,
                $pipeline_id,
                $flow_id,
                $flow_step_id,
                $job_id
            );

            if ($structured_result !== null) {
                return $structured_result;
            }
            
            // Fall back to HTML section extraction
            $html_result = $this->tryHtmlSectionExtraction(
                $html_content,
                $current_url,
                $config,
                $pipeline_id,
                $flow_id,
                $flow_step_id,
                $job_id,
                $current_page
            );

            if ($html_result !== null) {
                return $html_result;
            }

            // No eligible events on this page - look for next page
            $this->log('info', 'Universal Web Scraper: No unprocessed events on page, checking for next page', [
                'page' => $current_page,
                'url' => $current_url
            ]);

            $next_url = $this->find_next_page_url($html_content, $current_url);
            
            if (empty($next_url)) {
                $this->log('info', 'Universal Web Scraper: No more pages to process', [
                    'pages_checked' => $current_page
                ]);
                break;
            }

            $current_url = $next_url;
            $current_page++;

            $this->log('info', 'Universal Web Scraper: Moving to next page', [
                'page' => $current_page,
                'next_url' => $next_url
            ]);
        }

        return [];
    }

    /**
     * Try structured data extraction using registered extractors.
     */
    private function tryStructuredDataExtraction(
        string $html_content,
        string $current_url,
        array $config,
        int $pipeline_id,
        int $flow_id,
        ?string $flow_step_id,
        ?string $job_id
    ): ?array {
        foreach ($this->extractors as $extractor) {
            if (!$extractor->canExtract($html_content)) {
                continue;
            }

            $events = $extractor->extract($html_content, $current_url);
            if (empty($events)) {
                continue;
            }

            $this->log('info', 'Universal Web Scraper: Found structured data', [
                'extractor' => $extractor->getMethod(),
                'event_count' => count($events),
                'source_url' => $current_url
            ]);

            $result = $this->processor->process(
                $events,
                $extractor->getMethod(),
                $current_url,
                $config,
                $pipeline_id,
                $flow_id,
                $flow_step_id,
                $job_id
            );

            if ($result !== null) {
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
        int $pipeline_id,
        int $flow_id,
        ?string $flow_step_id,
        ?string $job_id,
        int $current_page
    ): ?array {
        $skipped_identifiers = [];

        while (true) {
            $event_section = $this->extract_event_sections($html_content, $current_url, (string) $flow_step_id, $skipped_identifiers);

            if (empty($event_section)) {
                break;
            }

            $this->log('info', 'Universal Web Scraper: Processing event section', [
                'section_identifier' => $event_section['identifier'],
                'page' => $current_page,
                'pipeline_id' => $pipeline_id
            ]);

            $raw_html_data = $this->extract_raw_html_section($event_section['html'], $current_url, $config);

            if (!$raw_html_data) {
                $skipped_identifiers[$event_section['identifier']] = true;
                continue;
            }

            $section_title = $this->extract_section_title($raw_html_data);
            if ($section_title !== '' && $this->shouldSkipEventTitle($section_title)) {
                $skipped_identifiers[$event_section['identifier']] = true;
                continue;
            }

            $search_text = html_entity_decode(wp_strip_all_tags($raw_html_data));

            if (!$this->applyKeywordSearch($search_text, $config['search'] ?? '')) {
                $this->log('debug', 'Universal Web Scraper: Skipping event section (include keywords)', [
                    'section_identifier' => $event_section['identifier'],
                    'source_url' => $current_url,
                ]);
                $skipped_identifiers[$event_section['identifier']] = true;
                continue;
            }

            if ($this->applyExcludeKeywords($search_text, $config['exclude_keywords'] ?? '')) {
                $this->log('debug', 'Universal Web Scraper: Skipping event section (exclude keywords)', [
                    'section_identifier' => $event_section['identifier'],
                    'source_url' => $current_url,
                ]);
                $skipped_identifiers[$event_section['identifier']] = true;
                continue;
            }

            $this->markItemProcessed($event_section['identifier'], $flow_step_id, $job_id);

            $this->log('info', 'Universal Web Scraper: Found eligible HTML section', [
                'source_url' => $current_url,
                'section_identifier' => $event_section['identifier'],
                'page' => $current_page,
                'pipeline_id' => $pipeline_id
            ]);

            $dataPacket = new DataPacket(
                [
                    'title' => 'Raw HTML Event Section',
                    'body' => wp_json_encode([
                        'raw_html' => $raw_html_data,
                        'source_url' => $current_url,
                        'import_source' => 'universal_web_scraper',
                        'section_identifier' => $event_section['identifier']
                    ], JSON_PRETTY_PRINT)
                ],
                [
                    'source_type' => 'universal_web_scraper',
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_id,
                    'original_title' => 'HTML Section from ' . parse_url($current_url, PHP_URL_HOST),
                    'event_identifier' => $event_section['identifier'],
                    'import_timestamp' => time()
                ],
                'event_import'
            );

            return [$dataPacket];
        }

        return null;
    }

    /**
     * Fetch HTML content from URL.
     */
    private function fetch_html(string $url): string {
        $result = \DataMachine\Core\HttpClient::get($url, [
            'timeout' => 30,
            'browser_mode' => true,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
            'context' => 'Universal Web Scraper'
        ]);

        if (!$result['success']) {
            $this->log('error', 'Universal Web Scraper: HTTP request failed', [
                'url' => $url,
                'error' => $result['error'] ?? 'Unknown error',
            ]);
            return '';
        }

        if (empty($result['data'])) {
            $this->log('error', 'Universal Web Scraper: Empty response body', [
                'url' => $url,
            ]);
            return '';
        }

        return $result['data'];
    }

    /**
     * Extract first non-processed event HTML section from content.
     */
    private function extract_event_sections(string $html_content, string $url, string $flow_step_id, array $skipped_identifiers = []): ?array {
        $finder = new EventSectionFinder(
            fn (string $identifier, string $step_id): bool => isset($skipped_identifiers[$identifier]) || $this->isItemProcessed($identifier, $step_id),
            fn (string $html): string => $this->clean_html_for_ai($html),
            fn (string $ymd): bool => $this->isPastEvent($ymd)
        );

        $event_section = $finder->find_first_eligible_section($html_content, $url, $flow_step_id);
        if ($event_section !== null) {
            $this->log('debug', 'Universal Web Scraper: Matched event section selector', [
                'selector' => $event_section['selector'] ?? '',
                'url' => $url,
            ]);
        }

        return $event_section;
    }

    /**
     * Clean HTML for AI processing.
     */
    private function clean_html_for_ai(string $html): string {
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('/\s+/', ' ', $html);
        return trim($html);
    }

    /**
     * Extract a potential title from HTML section for early filtering.
     */
    private function extract_section_title(string $html): string {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<meta charset="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $queries = [
            "//h1",
            "//h2",
            "//h3",
            "//*[contains(@class, 'title')]",
            "//*[contains(@class, 'event-name')]",
            "//*[@itemprop='name']",
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                $text = trim($nodes->item(0)->textContent);
                if (!empty($text)) {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * Find next page URL from pagination links in HTML.
     */
    private function find_next_page_url(string $html, string $current_url): ?string {
        $current_host = parse_url($current_url, PHP_URL_HOST);
        if (empty($current_host)) {
            return null;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Priority 1: Standard HTML5 rel="next" on links
        $next_links = $xpath->query('//a[@rel="next"]');
        if ($next_links->length > 0) {
            $href = $this->extract_valid_href($next_links->item(0), $current_url, $current_host);
            if ($href) {
                return $href;
            }
        }

        // Priority 2: Link element with rel="next" (SEO pagination)
        $link_next = $xpath->query('//link[@rel="next"]');
        if ($link_next->length > 0) {
            $node = $link_next->item(0);
            if ($node instanceof \DOMElement) {
                $href = $node->getAttribute('href');
                $resolved = $this->resolve_url($href, $current_url, $current_host);
                if ($resolved) {
                    return $resolved;
                }
            }
        }

        // Priority 3: Links with "next" in class name
        $next_class_patterns = [
            '//a[contains(@class, "next")]',
            '//a[contains(@class, "pagination-next")]',
            '//a[contains(@class, "page-next")]',
        ];

        foreach ($next_class_patterns as $pattern) {
            $nodes = $xpath->query($pattern);
            foreach ($nodes as $node) {
                $href = $this->extract_valid_href($node, $current_url, $current_host);
                if ($href) {
                    return $href;
                }
            }
        }

        // Priority 4: Links within pagination containers
        $pagination_containers = [
            '//*[contains(@class, "pagination")]//a',
            '//*[contains(@class, "pager")]//a',
            '//nav[@aria-label="pagination"]//a',
            '//*[@role="navigation"]//a',
        ];

        foreach ($pagination_containers as $container_pattern) {
            $nodes = $xpath->query($container_pattern);
            foreach ($nodes as $node) {
                if (!($node instanceof \DOMElement)) {
                    continue;
                }

                $text = strtolower(trim($node->textContent));
                $class = strtolower($node->getAttribute('class'));
                $aria_label = strtolower($node->getAttribute('aria-label'));

                if (
                    strpos($text, 'next') !== false ||
                    strpos($class, 'next') !== false ||
                    strpos($aria_label, 'next') !== false ||
                    $text === '>' ||
                    $text === '>>' ||
                    $text === '→'
                ) {
                    $href = $this->extract_valid_href($node, $current_url, $current_host);
                    if ($href) {
                        return $href;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract and validate href from DOM node.
     */
    private function extract_valid_href(\DOMNode $node, string $current_url, string $current_host): ?string {
        if (!($node instanceof \DOMElement)) {
            return null;
        }

        $href = $node->getAttribute('href');
        if (empty($href) || $href === '#') {
            return null;
        }

        return $this->resolve_url($href, $current_url, $current_host);
    }

    /**
     * Resolve relative URL and validate same-domain.
     */
    private function resolve_url(string $href, string $current_url, string $current_host): ?string {
        if (strpos($href, 'javascript:') === 0) {
            return null;
        }

        // Handle protocol-relative URLs
        if (strpos($href, '//') === 0) {
            $href = 'https:' . $href;
        }

        // Handle relative URLs
        if (strpos($href, '/') === 0) {
            $scheme = parse_url($current_url, PHP_URL_SCHEME) ?? 'https';
            $href = $scheme . '://' . $current_host . $href;
        } elseif (!preg_match('/^https?:\/\//i', $href)) {
            $base_path = dirname(parse_url($current_url, PHP_URL_PATH) ?: '/');
            $scheme = parse_url($current_url, PHP_URL_SCHEME) ?? 'https';
            $href = $scheme . '://' . $current_host . $base_path . '/' . $href;
        }

        // Validate same domain
        $href_host = parse_url($href, PHP_URL_HOST);
        if ($href_host !== $current_host) {
            return null;
        }

        // Skip if it's the same as current URL
        $current_normalized = strtok($current_url, '#');
        $href_normalized = strtok($href, '#');
        if ($current_normalized === $href_normalized) {
            return null;
        }

        return $href;
    }

    /**
     * Extract raw HTML section for AI processing.
     */
    private function extract_raw_html_section(string $section_html, string $source_url, array $config = []): ?string {
        $cleaned = $this->clean_html_for_ai($section_html);

        if (empty($cleaned) || strlen($cleaned) < 50) {
            $this->log('debug', 'Universal Web Scraper: HTML section too short after cleaning', [
                'source_url' => $source_url,
                'cleaned_length' => strlen($cleaned)
            ]);
            return null;
        }

        if (strlen($cleaned) > 50000) {
            $cleaned = substr($cleaned, 0, 50000);
            $this->log('debug', 'Universal Web Scraper: Truncated HTML section to 50KB', [
                'source_url' => $source_url
            ]);
        }

        return $cleaned;
    }
}
