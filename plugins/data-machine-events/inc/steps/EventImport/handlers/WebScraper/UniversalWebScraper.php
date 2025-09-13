<?php
/**
 * Universal AI-Powered Web Scraper Handler
 * 
 * Uses AI to extract event data from HTML pages.
 *
 * @package DmEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DmEvents\Steps\EventImport\Handlers\WebScraper;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI-powered universal web scraper handler
 */
class UniversalWebScraper {
    
    /** CSS selectors for individual event items */
    private const EVENT_SELECTORS = [
        // Individual event items with specific patterns
        '.event-item', '.event-card', '.event-entry', '.event-listing',
        '.show-item', '.show-card', '.show-entry', '.show-listing',
        '.concert-item', '.concert-card', '.concert-entry',
        '.performance-item', '.performance-entry',
        '.gig-item', '.gig-card', '.gig-entry',
        '.calendar-event', '.calendar-item', '.calendar-entry',
        
        // List items within event containers
        '.events li', '.shows li', '.concerts li', '.performances li',
        '.event-list li', '.show-list li', '.calendar li',
        
        // Article elements for individual events
        'article[class*="event"]', 'article[class*="show"]', 'article[class*="concert"]',
        
        // Div elements with event-specific classes
        'div[class*="event-"]:not([class*="events"])', 
        'div[class*="show-"]:not([class*="shows"])',
        
        // Schema.org individual events
        '[itemtype*="Event"]', '[typeof*="Event"]',
        
        // Common individual event patterns
        '.single-event', '.individual-event', '.event-block',
        '.show-details', '.concert-details', '.performance-details'
    ];
    
    /**
     * Execute web scraper with AI event extraction
     * 
     * @param array $parameters Flat parameter structure from Data Machine
     * @return array Updated data packet array
     */
    public function execute(array $parameters): array {
        // Extract from flat parameter structure (matches Data Machine pattern)
        $job_id = $parameters['job_id'];
        $flow_step_id = $parameters['flow_step_id'];
        $data = $parameters['data'] ?? [];
        $flow_step_config = $parameters['flow_step_config'] ?? [];
        
        do_action('dm_log', 'debug', 'Universal Web Scraper: All parameters received', [
            'parameter_keys' => array_keys($parameters),
            'flow_step_config_keys' => array_keys($flow_step_config),
            'parameters_debug' => $parameters
        ]);
        
        // Extract handler configuration
        $handler_settings = $flow_step_config['handler']['settings'] ?? [];
        $config = $handler_settings['universal_web_scraper'] ?? [];
        
        $pipeline_id = $flow_step_config['pipeline_id'] ?? null;
        $url = $config['source_url'] ?? '';
        
        if (empty($url)) {
            do_action('dm_log', 'error', 'Universal Web Scraper: No URL configured', [
                'config' => $config
            ]);
            return $data;
        }
        
        do_action('dm_log', 'info', 'Universal Web Scraper: Starting event extraction', [
            'url' => $url,
            'flow_step_id' => $flow_step_id
        ]);
        
        // Fetch HTML content
        $html_content = $this->fetch_html($url);
        if (empty($html_content)) {
            return $data;
        }
        
        // Find all potential event sections
        $event_sections = $this->extract_event_sections($html_content, $url);
        if (empty($event_sections)) {
            do_action('dm_log', 'info', 'Universal Web Scraper: No event sections found', [
                'url' => $url,
                'content_length' => strlen($html_content)
            ]);
            return $data;
        }
        
        // Process first unprocessed event section
        foreach ($event_sections as $section) {
            $section_identifier = $section['identifier'];
            
            // Check if section already processed
            $is_section_processed = apply_filters('dm_is_item_processed', false, $flow_step_id, 'universal_ai_scraper_section', $section_identifier);
            
            if ($is_section_processed) {
                do_action('dm_log', 'debug', 'Universal Web Scraper: Skipping already processed section', [
                    'url' => $url,
                    'section_identifier' => $section_identifier
                ]);
                continue;
            }
            
            // Mark as processed to prevent retries
            if ($flow_step_id && $job_id) {
                do_action('dm_mark_item_processed', $flow_step_id, 'universal_ai_scraper_section', $section_identifier, $job_id);
            }
            
            // Create data packet for AI processing
            $html_entry = [
                'type' => 'html_section',
                'handler' => 'universal_web_scraper',
                'content' => [
                    'title' => 'Individual Event Section for AI Processing',
                    'body' => $section['html']
                ],
                'metadata' => [
                    'source_type' => 'universal_web_scraper',
                    'source_url' => $url,
                    'section_identifier' => $section_identifier,
                    'section_length' => strlen($section['html']),
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_step_config['flow_id'] ?? null,
                    'import_timestamp' => time(),
                    'html_section' => $section['html'], // Include in metadata for AI tool access
                    '_ai_processing_required' => true
                ],
                'attachments' => [],
                'timestamp' => time()
            ];
            
            do_action('dm_log', 'debug', 'Universal Web Scraper: Prepared individual event section for AI step', [
                'url' => $url,
                'section_identifier' => $section_identifier,
                'section_length' => strlen($section['html']),
                'selector' => $section['selector'] ?? 'unknown'
            ]);
            
            // Return first section for single event processing
            array_unshift($data, $html_entry);
            return $data;
        }
        
        // No unprocessed sections found
        do_action('dm_log', 'info', 'Universal Web Scraper: No unprocessed sections found', [
            'url' => $url,
            'total_sections' => count($event_sections)
        ]);
        
        return $data;
    }
    
    /**
     * @param string $url Target URL
     * @return string HTML content or empty string on failure
     */
    private function fetch_html(string $url): string {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress Event Scraper)',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9'
            ]
        ]);
        
        if (is_wp_error($response)) {
            do_action('dm_log', 'error', 'Universal AI Scraper: Failed to fetch URL', [
                'url' => $url,
                'error' => $response->get_error_message()
            ]);
            return '';
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            do_action('dm_log', 'error', 'Universal AI Scraper: HTTP error when fetching URL', [
                'url' => $url,
                'status_code' => $status_code
            ]);
            return '';
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            do_action('dm_log', 'error', 'Universal AI Scraper: Empty response body', [
                'url' => $url
            ]);
            return '';
        }
        
        return $body;
    }
    
    /**
     * Extract individual event sections from HTML
     * 
     * @param string $html_content Full HTML content
     * @param string $url Source URL for section identification
     * @return array Array of individual event sections with HTML and unique identifiers
     */
    private function extract_event_sections(string $html_content, string $url): array {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $xpath = new \DOMXPath($dom);
        $sections = [];
        
        // Try each selector to find event elements
        foreach (self::EVENT_SELECTORS as $index => $selector) {
            $xpath_query = $this->css_to_xpath($selector);
            $nodes = $xpath->query($xpath_query);
            
            foreach ($nodes as $node_index => $node) {
                $section_html = $dom->saveHTML($node);
                
                // Ensure section has meaningful content
                if (!empty($section_html) && strlen($section_html) > 30) {
                    $cleaned_html = $this->clean_html_for_ai($section_html);
                    if (!empty($cleaned_html) && strlen($cleaned_html) > 20) {
                        // Create unique identifier
                        $content_hash = md5($cleaned_html);
                        $section_identifier = md5($url . $content_hash . $node_index);
                        
                        $is_duplicate = false;
                        foreach ($sections as $existing_section) {
                            if (similar_text($existing_section['html'], $cleaned_html) > 0.8 * strlen($cleaned_html)) {
                                $is_duplicate = true;
                                break;
                            }
                        }
                        
                        if (!$is_duplicate) {
                            $sections[] = [
                                'html' => $cleaned_html,
                                'identifier' => $section_identifier,
                                'selector' => $selector,
                                'node_index' => $node_index
                            ];
                        }
                    }
                }
            }
            
            // Stop if we found enough individual events
            if (count($sections) >= 20) {
                break;
            }
        }
        
        return array_slice($sections, 0, 20);
    }
    
    /**
     * Convert CSS selector to XPath
     * 
     * @param string $css_selector CSS selector
     * @return string XPath query
     */
    private function css_to_xpath(string $css_selector): string {
        $xpath = $css_selector;
        
        // Class selectors
        $xpath = preg_replace('/\.([a-zA-Z0-9_-]+)/', "[contains(@class, '$1')]", $xpath);
        
        // ID selectors  
        $xpath = preg_replace('/#([a-zA-Z0-9_-]+)/', "[@id='$1']", $xpath);
        
        // Attribute selectors
        $xpath = preg_replace('/\[([a-zA-Z0-9_-]+)\*=(["\'])([^"\']*)\2\]/', "[contains(@$1, '$3')]", $xpath);
        
        // Convert to XPath format
        if (strpos($xpath, '[') !== false) {
            // Complex selector with conditions
            $parts = explode('[', $xpath, 2);
            $element = trim($parts[0]);
            $condition = rtrim($parts[1], ']');
            
            if (empty($element) || $element === '*') {
                $xpath = "//*[$condition]";
            } else {
                $xpath = "//$element[$condition]";
            }
        } else {
            // Simple element selector
            $xpath = "//$xpath";
        }
        
        return $xpath;
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
     * Handle AI tool call for event extraction
     * 
     * Called directly by Data Machine AI step when `extract_event_from_html` tool is invoked.
     * Processes HTML section with processed items tracking and returns structured event data.
     * 
     * @param array $parameters AI tool parameters from Data Machine
     * @param array $tool_def Tool definition including handler config
     * @return array Tool execution result for Data Machine
     */
    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        $html_section = $parameters['html_section'] ?? '';
        $source_url = $parameters['source_url'] ?? '';
        $flow_step_id = $parameters['flow_step_id'] ?? null;
        $job_id = $parameters['job_id'] ?? null;
        
        if (empty($html_section)) {
            $error_msg = 'Universal AI Scraper tool: Missing required html_section parameter';
            do_action('dm_log', 'error', $error_msg, [
                'provided_parameters' => array_keys($parameters)
            ]);
            
            return [
                'success' => false,
                'error' => $error_msg,
                'tool_name' => 'extract_event_from_html'
            ];
        }
        
        // Create section identifier for processed items tracking
        $section_identifier = md5($html_section . ($source_url ?: 'unknown'));
        
        // Check if this section has already been processed
        if ($flow_step_id) {
            $is_processed = apply_filters('dm_is_item_processed', false, $flow_step_id, 'universal_ai_scraper_section', $section_identifier);
            if ($is_processed) {
                do_action('dm_log', 'debug', 'Universal AI Scraper tool: Section already processed', [
                    'source_url' => $source_url,
                    'section_identifier' => $section_identifier
                ]);
                
                return [
                    'success' => false,
                    'error' => 'HTML section already processed',
                    'tool_name' => 'extract_event_from_html'
                ];
            }
            
            // Mark section as processed BEFORE processing to prevent retries
            if ($job_id) {
                do_action('dm_mark_item_processed', $flow_step_id, 'universal_ai_scraper_section', $section_identifier, $job_id);
            }
        }
        
        // Get handler configuration from tool_def if available
        $handler_config = $tool_def['handler_config'] ?? [];
        
        // Extract structured event data using AI parameters directly
        $extracted_event = $this->extract_structured_event_data($parameters, $source_url, $handler_config);
        
        if (!$extracted_event) {
            do_action('dm_log', 'debug', 'Universal AI Scraper tool: No valid event extracted', [
                'source_url' => $source_url,
                'section_identifier' => $section_identifier
            ]);
            
            return [
                'success' => false,
                'error' => 'No valid event found in HTML section',
                'tool_name' => 'extract_event_from_html'
            ];
        }
        
        do_action('dm_log', 'info', 'Universal AI Scraper tool: Successfully extracted event', [
            'source_url' => $source_url,
            'event_title' => $extracted_event['title'],
            'section_identifier' => $section_identifier
        ]);
        
        return [
            'success' => true,
            'data' => $extracted_event,
            'tool_name' => 'extract_event_from_html'
        ];
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
            do_action('dm_log', 'debug', 'Universal AI Scraper: Missing required title field', [
                'source_url' => $source_url,
                'parameter_keys' => array_keys($parameters)
            ]);
            return null;
        }
        
        // Check for future date requirement
        $start_date = $parameters['startDate'] ?? '';
        if (!empty($start_date) && strtotime($start_date) < strtotime('today')) {
            do_action('dm_log', 'debug', 'Universal AI Scraper: Skipping past event', [
                'source_url' => $source_url,
                'title' => $parameters['title'],
                'start_date' => $start_date
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
    
    /**
     * Extract event using internal processing (for get_events method)
     * 
     * @param string $section_html HTML section to analyze
     * @param string $source_url Source URL for context
     * @return array|null Standardized event data or null if extraction failed
     */
    private function extract_event_with_ai(string $section_html, string $source_url): ?array {
        // This method is called from get_events() but since AI processing happens in AI step,
        // we should just pass the HTML section to the AI step via data packets
        // For now, return null to indicate no processing done at fetch level
        do_action('dm_log', 'debug', 'Universal AI Scraper: HTML section prepared for AI step processing', [
            'source_url' => $source_url,
            'section_length' => strlen($section_html)
        ]);
        
        return null;
    }
}