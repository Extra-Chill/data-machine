<?php
/**
 * Universal Schema.org Compliant Web Scraper Handler
 *
 * Prioritizes Schema.org Event microdata and JSON-LD extraction for maximum accuracy.
 * Falls back to AI-enhanced HTML parsing when structured data is unavailable.
 *
 * Extraction Priority:
 * 1. JSON-LD structured data (highest accuracy)
 * 2. Schema.org microdata parsing
 * 3. AI-enhanced HTML pattern matching
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema.org compliant universal web scraper handler
 *
 * Extracts event data using Schema.org standards with intelligent fallbacks.
 * Fully compatible with DataMachineEventsSchema for proper JSON-LD output generation.
 */
class UniversalWebScraper extends EventImportHandler {

    use HandlerRegistrationTrait;

    public function __construct() {
        parent::__construct('universal_web_scraper');

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
     * Execute web scraper with AI event extraction
     * 
     * @param int $pipeline_id Pipeline ID
     * @param array $config Handler configuration
     * @param string|null $flow_step_id Flow step ID
     * @param int $flow_id Flow ID
     * @param string|null $job_id Job ID
     * @return array Processed items array
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
            return $this->emptyResponse() ?? [];
        }
        
        $this->log('info', 'Universal Web Scraper: Starting event extraction', [
            'url' => $url,
            'flow_step_id' => $flow_step_id
        ]);
        
        // Fetch HTML content
        $html_content = $this->fetch_html($url);
        if (empty($html_content)) {
            return $this->emptyResponse() ?? [];
        }
        
        // Find first potential event section
        $event_section = $this->extract_event_sections($html_content, $url, $flow_step_id);
        if (empty($event_section)) {
            $this->log('info', 'Universal Web Scraper: No event sections found', [
                'url' => $url,
                'content_length' => strlen($html_content)
            ]);
            return $this->emptyResponse() ?? [];
        }

        // Process single event section (Data Machine single-item model)
        $this->log('info', 'Universal Web Scraper: Processing single event section for eligible item', [
            'section_identifier' => $event_section['identifier'],
            'pipeline_id' => $pipeline_id
        ]);

        // Process HTML section to prepare raw data for AI step
        $raw_html_data = $this->extract_raw_html_section($event_section['html'], $url, $config);

        // Skip if no valid HTML data extracted
        if (!$raw_html_data) {
            return $this->emptyResponse() ?? [];
        }

        // Mark as processed and return immediately (single event processing)
        $this->markItemProcessed($event_section['identifier'], $flow_step_id, $job_id);

        $this->log('info', 'Universal Web Scraper: Found eligible HTML section', [
            'source_url' => $url,
            'section_identifier' => $event_section['identifier'],
            'pipeline_id' => $pipeline_id
        ]);

        // Create DataPacket
        $dataPacket = new DataPacket(
            [
                'title' => 'Raw HTML Event Section',
                'body' => wp_json_encode([
                    'raw_html' => $raw_html_data,
                    'source_url' => $url,
                    'import_source' => 'universal_web_scraper',
                    'section_identifier' => $event_section['identifier']
                ], JSON_PRETTY_PRINT)
            ],
            [
                'source_type' => 'universal_web_scraper',
                'pipeline_id' => $pipeline_id,
                'flow_id' => $flow_id,
                'original_title' => 'HTML Section from ' . parse_url($url, PHP_URL_HOST),
                'event_identifier' => $event_section['identifier'],
                'import_timestamp' => time()
            ],
            'event_import'
        );

        return $this->successResponse([$dataPacket]);
    }
    
    /**
     * @param string $url Target URL
     * @return string HTML content or empty string on failure
     */
    private function fetch_html(string $url): string {
        $result = $this->httpGet($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
            'browser_mode' => true,
        ]);
        
        if (!$result['success']) {
            $this->log('error', 'Universal AI Scraper: Failed to fetch URL', [
                'url' => $url,
                'error' => $result['error'] ?? 'Unknown error',
            ]);
            return '';
        }
        
        $status_code = $result['status_code'];
        if ($status_code !== 200) {
            $this->log('error', 'Universal AI Scraper: HTTP error when fetching URL', [
                'url' => $url,
                'status_code' => $status_code,
            ]);
            return '';
        }
        
        $body = $result['data'];
        if (empty($body)) {
            $this->log('error', 'Universal AI Scraper: Empty response body', [
                'url' => $url,
            ]);
            return '';
        }
        
        return $body;
    }
    
    /**
     * Extract first non-processed event HTML section from content
     *
     * Finds potential event HTML elements using pattern matching and returns
     * the first unprocessed event as raw HTML for AI analysis.
     *
     * @param string $html_content Raw HTML content
     * @param string $url Source URL for context
     * @param string $flow_step_id Flow step ID for processed item tracking
     * @return array|null Single event section or null if none found
     */
    private function extract_event_sections(string $html_content, string $url, string $flow_step_id): ?array {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Universal CSS selectors for event detection - no configuration needed
        $selectors = [
            // Schema.org microdata (HIGHEST PRIORITY)
            '//*[contains(@itemtype, "Event")]',

            // SeeTickets widget patterns (used by Resound Presents, etc.)
            '//*[contains(@class, "seetickets-list-event-container")]',
            '//*[contains(@class, "seetickets-calendar-event")]',

            // Specific event listing patterns (HIGH PRIORITY)
            '//*[contains(@class, "eventlist-event")]',
            '//article[contains(@class, "eventlist-event")]',

            // Article elements with event-related classes
            '//article[contains(@class, "event")]',
            '//article[contains(@class, "show")]',
            '//article[contains(@class, "concert")]',

            // Common event class patterns
            '//*[contains(@class, "event-content-row")]',
            '//*[contains(@class, "event-item")]',
            '//*[contains(@class, "show-item")]',
            '//*[contains(@class, "concert-item")]',
            '//*[contains(@class, "calendar-event")]',
            '//*[contains(@class, "event-card")]',
            '//*[contains(@class, "event-entry")]',
            '//*[contains(@class, "event-listing")]',

            // List items within event containers
            '//*[contains(@class, "events")]//li',
            '//*[contains(@class, "shows")]//li',
            '//*[contains(@class, "calendar")]//li'
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);

            foreach ($nodes as $node) {
                // Skip structural elements (body, header, footer, nav)
                $tag_name = strtolower($node->nodeName);
                if (in_array($tag_name, ['body', 'header', 'footer', 'nav', 'aside', 'main'])) {
                    continue;
                }

                $raw_html = $dom->saveHTML($node);

                // Skip if too short or empty
                if (strlen($raw_html) < 50) {
                    continue;
                }

                // Create unique identifier for this event
                $content_hash = md5($raw_html);
                $event_identifier = md5($url . $content_hash);

                // Check if already processed
                if ($this->isItemProcessed($event_identifier, $flow_step_id)) {
                    continue;
                }

                // Clean HTML for AI processing
                $cleaned_html = $this->clean_html_for_ai($raw_html);
                if (empty($cleaned_html) || strlen($cleaned_html) < 30) {
                    continue;
                }

                // Return first eligible event immediately
                return [
                    'html' => $cleaned_html,
                    'raw_html' => $raw_html,
                    'identifier' => $event_identifier,
                    'selector' => $selector,
                    'url' => $url
                ];
            }
        }

        return null; // No unprocessed events found
    }
    
    /**
     * Clean HTML for AI processing
     * 
     * @param string $html Raw HTML content
     * @return string Cleaned HTML suitable for AI analysis
     */
    private function clean_html_for_ai(string $html): string {
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        
        $html = preg_replace('/\s+/', ' ', $html);
        
        // Truncate for AI token limits
        if (strlen($html) > 3000) {
            $html = substr($html, 0, 3000) . '...';
        }
        
        return trim($html);
    }
    
    
    /**
     * Extract raw HTML section for AI processing in subsequent pipeline steps
     *
     * Prepares cleaned HTML content for processing by AI steps later in the pipeline.
     * Does not perform any AI processing - just returns cleaned HTML data.
     *
     * @param string $section_html Raw HTML section containing event information
     * @param string $source_url Source URL for context
     * @param array $config Handler configuration (unused for universal scraping)
     * @return string|null Cleaned HTML data or null if processing failed
     */
    private function extract_raw_html_section(string $section_html, string $source_url, array $config = []): ?string {
        $this->log('debug', 'Universal Web Scraper: Preparing raw HTML for pipeline processing', [
            'source_url' => $source_url,
            'section_length' => strlen($section_html)
        ]);

        // Clean HTML for processing (remove scripts, styles, comments)
        $cleaned_html = $this->clean_html_for_ai($section_html);

        if (empty($cleaned_html) || strlen($cleaned_html) < 30) {
            $this->log('debug', 'Universal Web Scraper: HTML section too short after cleaning');
            return null;
        }

        $this->log('info', 'Universal Web Scraper: Successfully prepared HTML section', [
            'source_url' => $source_url,
            'cleaned_length' => strlen($cleaned_html)
        ]);

        return $cleaned_html;
    }


    /**
     * Extract Schema.org microdata from HTML element
     *
     * Parses Schema.org Event microdata using itemtype and itemprop attributes.
     * Provides more accurate data extraction than CSS parsing.
     *
     * @param string $html HTML content containing Schema.org microdata
     * @param string $source_url Source URL for context
     * @return array|null Structured event data or null if no valid microdata found
     */
    private function extract_schema_microdata(string $html, string $source_url): ?array {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Find Event microdata elements
        $event_elements = $xpath->query("//*[@itemtype='https://schema.org/Event' or @itemtype='http://schema.org/Event']");

        if ($event_elements->length === 0) {
            return null;
        }

        $event_element = $event_elements->item(0);
        $event_data = [];

        // Extract basic event properties
        $name = $xpath->query(".//*[@itemprop='name']", $event_element);
        if ($name->length > 0) {
            $event_data['title'] = trim($name->item(0)->textContent);
        }

        $start_date = $xpath->query(".//*[@itemprop='startDate']", $event_element);
        if ($start_date->length > 0) {
            $start_node = $start_date->item(0);
            $datetime = '';
            if ($start_node instanceof \DOMElement) {
                $datetime = $start_node->getAttribute('datetime') ?: $start_node->textContent;
            } elseif ($start_node) {
                $datetime = $start_node->textContent;
            }

            if (!empty($datetime)) {
                $parsed_date = date('Y-m-d', strtotime($datetime));
                $parsed_time = date('H:i', strtotime($datetime));
                $event_data['startDate'] = $parsed_date;
                $event_data['startTime'] = $parsed_time !== '00:00' ? $parsed_time : '';
            }
        }

        $end_date = $xpath->query(".//*[@itemprop='endDate']", $event_element);
        if ($end_date->length > 0) {
            $end_node = $end_date->item(0);
            $datetime = '';
            if ($end_node instanceof \DOMElement) {
                $datetime = $end_node->getAttribute('datetime') ?: $end_node->textContent;
            } elseif ($end_node) {
                $datetime = $end_node->textContent;
            }

            if (!empty($datetime)) {
                $event_data['endDate'] = date('Y-m-d', strtotime($datetime));
                $event_data['endTime'] = date('H:i', strtotime($datetime));
            }
        }

        $description = $xpath->query(".//*[@itemprop='description']", $event_element);
        if ($description->length > 0) {
            $event_data['description'] = trim($description->item(0)->textContent);
        }

        // Extract performer data (Schema.org compliant)
        $performer = $xpath->query(".//*[@itemprop='performer']", $event_element);
        if ($performer->length > 0) {
            $performer_name = $xpath->query(".//*[@itemprop='name']", $performer->item(0));
            if ($performer_name->length > 0) {
                $event_data['performer'] = trim($performer_name->item(0)->textContent);
            } else {
                $event_data['performer'] = trim($performer->item(0)->textContent);
            }
        }

        // Extract organizer data
        $organizer = $xpath->query(".//*[@itemprop='organizer']", $event_element);
        if ($organizer->length > 0) {
            $organizer_name = $xpath->query(".//*[@itemprop='name']", $organizer->item(0));
            if ($organizer_name->length > 0) {
                $event_data['organizer'] = trim($organizer_name->item(0)->textContent);
            } else {
                $event_data['organizer'] = trim($organizer->item(0)->textContent);
            }
        }

        // Extract location (venue) data
        $location = $xpath->query(".//*[@itemprop='location']", $event_element);
        if ($location->length > 0) {
            $location_element = $location->item(0);

            // Venue name
            $venue_name = $xpath->query(".//*[@itemprop='name']", $location_element);
            if ($venue_name->length > 0) {
                $event_data['venue'] = trim($venue_name->item(0)->textContent);
            }

            // Address components
            $address = $xpath->query(".//*[@itemprop='address']", $location_element);
            if ($address->length > 0) {
                $address_element = $address->item(0);

                $street_address = $xpath->query(".//*[@itemprop='streetAddress']", $address_element);
                if ($street_address->length > 0) {
                    $event_data['venueAddress'] = trim($street_address->item(0)->textContent);
                }

                $locality = $xpath->query(".//*[@itemprop='addressLocality']", $address_element);
                if ($locality->length > 0) {
                    $event_data['venueCity'] = trim($locality->item(0)->textContent);
                }

                $region = $xpath->query(".//*[@itemprop='addressRegion']", $address_element);
                if ($region->length > 0) {
                    $event_data['venueState'] = trim($region->item(0)->textContent);
                }

                $postal_code = $xpath->query(".//*[@itemprop='postalCode']", $address_element);
                if ($postal_code->length > 0) {
                    $event_data['venueZip'] = trim($postal_code->item(0)->textContent);
                }

                $country = $xpath->query(".//*[@itemprop='addressCountry']", $address_element);
                if ($country->length > 0) {
                    $event_data['venueCountry'] = trim($country->item(0)->textContent);
                }
            }

            // Venue phone
            $telephone = $xpath->query(".//*[@itemprop='telephone']", $location_element);
            if ($telephone->length > 0) {
                $event_data['venuePhone'] = trim($telephone->item(0)->textContent);
            }

            // Venue website
            $url = $xpath->query(".//*[@itemprop='url']", $location_element);
            if ($url->length > 0) {
                $url_node = $url->item(0);
                $website = '';
                if ($url_node instanceof \DOMElement) {
                    $website = $url_node->getAttribute('href') ?: $url_node->textContent;
                } elseif ($url_node) {
                    $website = $url_node->textContent;
                }

                if (!empty($website)) {
                    $event_data['venueWebsite'] = trim($website);
                }
            }

            // Geo coordinates
            $geo = $xpath->query(".//*[@itemprop='geo']", $location_element);
            if ($geo->length > 0) {
                $geo_element = $geo->item(0);
                $latitude = $xpath->query(".//*[@itemprop='latitude']", $geo_element);
                $longitude = $xpath->query(".//*[@itemprop='longitude']", $geo_element);
                if ($latitude->length > 0 && $longitude->length > 0) {
                    $lat = trim($latitude->item(0)->textContent);
                    $lng = trim($longitude->item(0)->textContent);
                    $event_data['venueCoordinates'] = $lat . ',' . $lng;
                }
            }
        }

        // Extract offers (pricing) data
        $offers = $xpath->query(".//*[@itemprop='offers']", $event_element);
        if ($offers->length > 0) {
            $offers_element = $offers->item(0);

            $price = $xpath->query(".//*[@itemprop='price']", $offers_element);
            if ($price->length > 0) {
                $event_data['price'] = trim($price->item(0)->textContent);
            }

            $ticket_url = $xpath->query(".//*[@itemprop='url']", $offers_element);
            if ($ticket_url->length > 0) {
                $ticket_node = $ticket_url->item(0);
                $candidate_url = '';
                if ($ticket_node instanceof \DOMElement) {
                    $candidate_url = $ticket_node->getAttribute('href') ?: $ticket_node->textContent;
                } elseif ($ticket_node) {
                    $candidate_url = $ticket_node->textContent;
                }

                if (!empty($candidate_url)) {
                    $event_data['ticketUrl'] = trim($candidate_url);
                }
            }
        }

        // Extract image
        $image = $xpath->query(".//*[@itemprop='image']", $event_element);
        if ($image->length > 0) {
            $image_node = $image->item(0);
            $image_value = '';
            if ($image_node instanceof \DOMElement) {
                $image_value = $image_node->getAttribute('src') ?: $image_node->getAttribute('href') ?: $image_node->textContent;
            } elseif ($image_node) {
                $image_value = $image_node->textContent;
            }

            if (!empty($image_value)) {
                $event_data['image'] = trim($image_value);
            }
        }

        // Require at least title and startDate for valid event
        if (empty($event_data['title']) || empty($event_data['startDate'])) {
            $this->log('debug', 'Universal Web Scraper: Invalid Schema.org microdata - missing title or startDate', [
                'source_url' => $source_url,
                'has_title' => !empty($event_data['title']),
                'has_start_date' => !empty($event_data['startDate'])
            ]);
            return null;
        }

        $this->log('info', 'Universal Web Scraper: Successfully extracted Schema.org microdata', [
            'source_url' => $source_url,
            'title' => $event_data['title'],
            'start_date' => $event_data['startDate'],
            'venue' => $event_data['venue'] ?? 'N/A'
        ]);

        return $event_data;
    }

    /**
     * Extract Schema.org JSON-LD structured data from HTML
     *
     * Parses <script type="application/ld+json"> tags for Schema.org Event objects.
     * Provides the most accurate extraction method when available.
     *
     * @param string $html HTML content containing JSON-LD scripts
     * @param string $source_url Source URL for context
     * @return array|null Structured event data or null if no valid JSON-LD found
     */
    private function extract_jsonld_events(string $html, string $source_url): ?array {
        // Find all JSON-LD script tags
        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);

        if (empty($matches[1])) {
            return null;
        }

        foreach ($matches[1] as $json_content) {
            $data = json_decode(trim($json_content), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            // Handle single Event object
            if (isset($data['@type']) && $data['@type'] === 'Event') {
                return $this->parse_jsonld_event($data, $source_url);
            }

            // Handle array of objects
            if (is_array($data)) {
                foreach ($data as $item) {
                    if (isset($item['@type']) && $item['@type'] === 'Event') {
                        return $this->parse_jsonld_event($item, $source_url);
                    }
                }
            }

            // Handle graph structure
            if (isset($data['@graph']) && is_array($data['@graph'])) {
                foreach ($data['@graph'] as $item) {
                    if (isset($item['@type']) && $item['@type'] === 'Event') {
                        return $this->parse_jsonld_event($item, $source_url);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse individual JSON-LD Event object
     *
     * @param array $event_data JSON-LD Event object
     * @param string $source_url Source URL for context
     * @return array|null Structured event data
     */
    private function parse_jsonld_event(array $event_data, string $source_url): ?array {
        $parsed_event = [];

        // Extract basic event properties
        $parsed_event['title'] = $event_data['name'] ?? '';
        $parsed_event['description'] = $event_data['description'] ?? '';

        // Parse start date and time
        if (!empty($event_data['startDate'])) {
            $start_datetime = $event_data['startDate'];
            $parsed_event['startDate'] = date('Y-m-d', strtotime($start_datetime));
            $parsed_time = date('H:i', strtotime($start_datetime));
            $parsed_event['startTime'] = $parsed_time !== '00:00' ? $parsed_time : '';
        }

        // Parse end date and time
        if (!empty($event_data['endDate'])) {
            $end_datetime = $event_data['endDate'];
            $parsed_event['endDate'] = date('Y-m-d', strtotime($end_datetime));
            $parsed_event['endTime'] = date('H:i', strtotime($end_datetime));
        }

        // Extract performer (Schema.org compliant)
        if (!empty($event_data['performer'])) {
            $performer = $event_data['performer'];
            if (is_array($performer)) {
                $parsed_event['performer'] = $performer['name'] ?? $performer[0]['name'] ?? '';
            } else {
                $parsed_event['performer'] = $performer;
            }
        }

        // Extract organizer
        if (!empty($event_data['organizer'])) {
            $organizer = $event_data['organizer'];
            if (is_array($organizer)) {
                $parsed_event['organizer'] = $organizer['name'] ?? $organizer[0]['name'] ?? '';
            } else {
                $parsed_event['organizer'] = $organizer;
            }
        }

        // Extract location (venue) data
        if (!empty($event_data['location'])) {
            $location = $event_data['location'];

            // Venue name
            $parsed_event['venue'] = $location['name'] ?? '';

            // Address data
            if (!empty($location['address'])) {
                $address = $location['address'];
                $parsed_event['venueAddress'] = $address['streetAddress'] ?? '';
                $parsed_event['venueCity'] = $address['addressLocality'] ?? '';
                $parsed_event['venueState'] = $address['addressRegion'] ?? '';
                $parsed_event['venueZip'] = $address['postalCode'] ?? '';
                $parsed_event['venueCountry'] = $address['addressCountry'] ?? '';
            }

            // Additional venue data
            $parsed_event['venuePhone'] = $location['telephone'] ?? '';
            $parsed_event['venueWebsite'] = $location['url'] ?? '';

            // Geo coordinates
            if (!empty($location['geo'])) {
                $geo = $location['geo'];
                $lat = $geo['latitude'] ?? '';
                $lng = $geo['longitude'] ?? '';
                if ($lat && $lng) {
                    $parsed_event['venueCoordinates'] = $lat . ',' . $lng;
                }
            }
        }

        // Extract offers (pricing) data
        if (!empty($event_data['offers'])) {
            $offers = $event_data['offers'];
            if (is_array($offers) && isset($offers[0])) {
                $offers = $offers[0]; // Use first offer
            }

            $parsed_event['price'] = $offers['price'] ?? '';
            $parsed_event['ticketUrl'] = $offers['url'] ?? '';
        }

        // Extract image
        if (!empty($event_data['image'])) {
            $image = $event_data['image'];
            if (is_array($image)) {
                $parsed_event['image'] = $image[0] ?? '';
            } else {
                $parsed_event['image'] = $image;
            }
        }

        // Require at least title and startDate for valid event
        if (empty($parsed_event['title']) || empty($parsed_event['startDate'])) {
            $this->log('debug', 'Universal Web Scraper: Invalid JSON-LD Event - missing title or startDate', [
                'source_url' => $source_url,
                'has_title' => !empty($parsed_event['title']),
                'has_start_date' => !empty($parsed_event['startDate'])
            ]);
            return null;
        }

        $this->log('info', 'Universal Web Scraper: Successfully extracted JSON-LD Event', [
            'source_url' => $source_url,
            'title' => $parsed_event['title'],
            'start_date' => $parsed_event['startDate'],
            'venue' => $parsed_event['venue'] ?? 'N/A'
        ]);

        return $parsed_event;
    }

    /**
     * Extract structured event data from AI tool parameters
     * 
     * @param array $parameters AI tool parameters containing event data
     * @param string $source_url Source URL for context
     * @param array $handler_config Handler configuration containing static venue data
     * @return array|null Standardized event data or null if extraction failed
     */
    private function extract_structured_event_data(array $parameters, string $source_url, array $handler_config = []): ?array {
        // Validate required fields
        if (empty($parameters['title'])) {
            $this->log('debug', 'Universal AI Scraper: Missing required title field', [
                'source_url' => $source_url,
                'parameter_keys' => array_keys($parameters)
            ]);
            return null;
        }
        
        if ($this->isPastEvent($parameters['startDate'] ?? '')) {
            $this->log('debug', 'Universal AI Scraper: Skipping past event', [
                'source_url' => $source_url,
                'title' => $parameters['title'],
                'start_date' => $parameters['startDate'] ?? ''
            ]);
            return null;
        }
        
        // Get handler config for venue settings
        $config = $handler_config['universal_web_scraper'] ?? [];
        
        // Standardize and sanitize event data from AI parameters
        $standardized_event = [
            'title' => sanitize_text_field($parameters['title']),
            'startDate' => sanitize_text_field($parameters['startDate'] ?? ''),
            'endDate' => sanitize_text_field($parameters['endDate'] ?? $parameters['startDate'] ?? ''),
            'startTime' => sanitize_text_field($parameters['startTime'] ?? ''),
            'endTime' => sanitize_text_field($parameters['endTime'] ?? ''),
            'address' => sanitize_text_field($parameters['address'] ?? ''),
            'price' => sanitize_text_field($parameters['price'] ?? ''),
            'ticketUrl' => esc_url_raw($parameters['ticketUrl'] ?? ''),
            'performer' => sanitize_text_field($parameters['performer'] ?? ''),
            'organizer' => sanitize_text_field($parameters['organizer'] ?? ''),
            'description' => sanitize_textarea_field($parameters['description'] ?? ''),
            'image' => esc_url_raw($parameters['image'] ?? ''),
            'source_url' => $source_url
        ];
        
        // Handle venue data - use static config or let AI extract (same parameter names as Ticketmaster)
        if (!empty($config['venue'])) {
            // Use static venue data from handler config (same flow as Ticketmaster)
            $standardized_event['venue'] = sanitize_text_field($config['venue']);
            $standardized_event['venueAddress'] = sanitize_text_field($config['venueAddress'] ?? '');
            $standardized_event['venueCity'] = sanitize_text_field($config['venueCity'] ?? '');
            $standardized_event['venueState'] = sanitize_text_field($config['venueState'] ?? '');
            $standardized_event['venueZip'] = sanitize_text_field($config['venueZip'] ?? '');
            $standardized_event['venueCountry'] = sanitize_text_field($config['venueCountry'] ?? '');
            $standardized_event['venuePhone'] = sanitize_text_field($config['venuePhone'] ?? '');
            $standardized_event['venueWebsite'] = esc_url_raw($config['venueWebsite'] ?? '');
            $standardized_event['venueCoordinates'] = sanitize_text_field($config['venueCoordinates'] ?? '');
        } else {
            // Let AI extract venue data from HTML (existing behavior)
            $standardized_event['venue'] = sanitize_text_field($parameters['venue'] ?? '');
            $standardized_event['venueAddress'] = sanitize_text_field($parameters['venueAddress'] ?? '');
            $standardized_event['venueCity'] = sanitize_text_field($parameters['venueCity'] ?? '');
            $standardized_event['venueState'] = sanitize_text_field($parameters['venueState'] ?? '');
            $standardized_event['venueZip'] = sanitize_text_field($parameters['venueZip'] ?? '');
            $standardized_event['venueCountry'] = sanitize_text_field($parameters['venueCountry'] ?? '');
            $standardized_event['venuePhone'] = sanitize_text_field($parameters['venuePhone'] ?? '');
            $standardized_event['venueWebsite'] = esc_url_raw($parameters['venueWebsite'] ?? '');
            $standardized_event['venueCoordinates'] = sanitize_text_field($parameters['venueCoordinates'] ?? '');
        }
        
        return $standardized_event;
    }
    
}