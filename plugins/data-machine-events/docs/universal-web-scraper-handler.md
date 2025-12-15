# Universal Web Scraper Handler

Schema.org-compliant web scraping for extracting event data from any HTML page with automated processing, structured data parsing, and paginated site support.

## Overview

The Universal Web Scraper handler prioritizes Schema.org structured data extraction (JSON-LD and microdata) for maximum accuracy, falling back to AI-enhanced HTML parsing when structured data is unavailable. Features HTML section detection using prioritized XPath selector rules, automatic pagination for multi-page results, and ProcessedItems tracking to prevent duplicate processing of the same web content.

## Features

### Schema.org Structured Data Extraction (Priority 1)
- **JSON-LD Parsing**: Extracts event data from `<script type="application/ld+json">` tags containing Schema.org Event objects
- **Microdata Parsing**: Parses Schema.org microdata using `itemtype` and `itemprop` attributes for comprehensive event details
- **Structured Data Accuracy**: Provides the most reliable extraction method when Schema.org markup is present
- **Standard Compliance**: Fully compatible with Schema.org Event specification

### AI-Enhanced HTML Parsing (Fallback)
- **Intelligent Content Detection**: AI identifies event-related content sections in HTML when structured data is unavailable
- **Contextual Understanding**: AI understands event context and relationships for unstructured content
- **Multi-Format Support**: Works with various website layouts and content structures

### Processing Features
- **HTML Hash Tracking**: Uses ProcessedItems to track processed web pages by content hash
- **Duplicate Prevention**: Prevents re-processing of unchanged web content
- **Incremental Updates**: Only processes new or changed content
- **Content Change Detection**: Detects when web content has been updated
- **Automatic Pagination**: Follows "next page" links to traverse multi-page event listings (MAX_PAGES=20)
- **XPath Selector Rules**: Prioritized selector system for identifying candidate event sections

### XPath Selector Rules System
- **Prioritized Rules**: 20+ XPath selectors ordered by extraction reliability and specificity
- **Schema.org Priority**: Microdata elements get highest priority for structured data accuracy
- **Table Pattern Detection**: Specialized rules for table-based event calendars with header row skipping
- **Widget Support**: Specific selectors for Google Calendar widgets, SeeTickets, and Turntable Tickets
- **Event Container Detection**: Rules for common event listing patterns (articles, list items, cards)
- **Extensible System**: Filterable via `datamachine_events_universal_web_scraper_selector_rules` hook

### Schema.org Data Extraction
The handler extracts comprehensive event information from Schema.org structured data:

- **Core Event Properties**: name, description, startDate, endDate, startTime, endTime
- **Venue Information**: location details including address, city, state, zip, country, phone, website, coordinates
- **Performer & Organizer**: Artist and promoter information with proper attribution
- **Pricing & Tickets**: Price information and ticket purchase URLs
- **Images**: Event imagery and promotional materials
- **Event Status**: Availability and status information

### Pagination Features
- **Automatic Link Following**: Detects "next page" links using multiple patterns (rel="next", common navigation selectors)
- **Domain Validation**: Only follows links on the same domain to prevent crawl drift
- **URL Tracking**: Maintains visited URL tracking to prevent re-scraping the same page
- **Max Pages Limit**: Respects MAX_PAGES=20 constant to prevent infinite loops on sites with pagination issues

### Technical Features
- **Single-Item Processing**: Processes one event per job execution
- **Event Identity Normalization**: Uses EventIdentifierGenerator for consistent duplicate detection
- **Flexible Configuration**: Adaptable to different website structures

## Configuration

### Required Settings
- **Target URL**: The web page URL to scrape for event data
- **Content Selector** (optional): CSS selector to target specific content areas

### Optional Settings
- **Venue Override**: Override venue assignment for consistency (when Schema.org data is incomplete)
- **Date Filtering**: Filter events by date ranges
- **Keyword Filtering**: Include/exclude events based on keywords

## Usage Examples

### Basic Web Scraping
```php
$config = [
    'url' => 'https://venue-website.com/events',
    'content_selector' => '.event-listing'
];
```

### Advanced Configuration
```php
$config = [
    'url' => 'https://festival-site.com/schedule',
    'venue_override' => 'Festival Grounds',
    'include_keywords' => 'concert,music',
    'exclude_keywords' => 'cancelled,sold-out'
];
```

## Event Processing

### Three-Tier Extraction System

The handler uses a prioritized extraction approach for maximum accuracy:

1. **Schema.org JSON-LD**: Parses `<script type="application/ld+json">` containing Event objects
2. **Schema.org Microdata**: Extracts data from HTML elements with `itemtype="https://schema.org/Event"`
3. **AI-Enhanced HTML Parsing**: Falls back to AI analysis when structured data is unavailable

### Content Extraction
- **Structured Data Priority**: Leverages Schema.org markup for reliable, standards-compliant extraction
- **HTML Section Detection**: Uses EventSectionFinder and prioritized XPath rules to locate event content
- **Table Detection**: Identifies and extracts events from HTML tables used by many venue websites
- **Event Detection**: Identifies individual events within page content using multiple XPath patterns
- **Data Mapping**: Extracts title, dates, venue, description, and pricing from structured or unstructured content
- **Content Hashing**: Generates hash of processed content for change detection

### Pagination Handling
The handler automatically discovers and follows pagination links to collect events from multi-page websites:

```php
// Handler respects MAX_PAGES = 20 to prevent infinite loops
// Follows next page links using rel="next" and common nav selectors
// Skips already-visited URLs during same job execution
// Returns immediately after finding first unprocessed event
```

When a page contains only already-processed events, the handler automatically advances to the next page (if available) to search for new content. This enables incremental syncing of venue websites with multiple event pages.

### Duplicate Prevention
```php
// Track processed content by HTML hash
$content_hash = md5($html_content);

if (apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'web_scraper', $content_hash)) {
    continue; // Content already processed
}

// Process new content
$events = $this->extract_events_with_ai($html_content);

// Mark content as processed
do_action('datamachine_mark_item_processed', $flow_step_id, 'web_scraper', $content_hash, $job_id);
```

### Event Identity
```php
use DataMachineEvents\Utilities\EventIdentifierGenerator;

// Generate consistent event identifier
$event_identifier = EventIdentifierGenerator::generate($title, $startDate, $venue);

// Check event-level duplicates
if (apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'web_scraper', $event_identifier)) {
    continue;
}
```

## Integration Architecture

### Handler Structure
- **UniversalWebScraper.php**: Main handler implementing three-tier extraction (Schema.org → AI fallback)
- **EventSectionFinder.php**: Locates eligible event sections using XPath selector rules
- **EventSectionSelectors.php**: Defines prioritized XPath rules for event section detection
- **UniversalWebScraperSettings.php**: Admin configuration interface using VenueFieldsTrait

### Data Flow
1. **URL Retrieval**: Downloads target web page content with browser-like headers
2. **Section Detection**: EventSectionFinder uses XPath rules to identify candidate event sections
3. **Extraction Priority**: Attempts Schema.org JSON-LD → microdata → AI-enhanced HTML parsing
4. **Raw HTML Preparation**: Prepares cleaned HTML for pipeline processing (when using AI fallback)
5. **Content Tracking**: Uses event-specific identifiers for processed item tracking
6. **Pipeline Integration**: Passes DataPacket to subsequent AI processing steps when needed
7. **Event Upsert**: Creates/updates events using EventUpsert handler with extracted data

## Schema.org Integration

### Structured Data Compliance
- **Schema.org Standards**: Fully compliant with Schema.org Event specification
- **JSON-LD Support**: Parses Event objects from structured data scripts
- **Microdata Support**: Extracts data from HTML microdata attributes
- **Comprehensive Coverage**: Supports all major Schema.org Event properties

### AI Fallback Integration
When Schema.org structured data is unavailable, the handler falls back to AI processing:

- **Natural Language Processing**: Understands event descriptions and context
- **Pattern Recognition**: Identifies common event data patterns in unstructured HTML
- **Contextual Extraction**: Maintains relationships between event elements
- **Multi-Language Support**: Handles various language content

### Extraction Capabilities (Schema.org + AI)
- **Event Titles**: Extracts from structured data or identifies in HTML content
- **Date/Time Parsing**: Parses Schema.org date formats or recognizes various HTML date formats
- **Venue Detection**: Uses structured location data or extracts from HTML content
- **Pricing Information**: Leverages Schema.org offers or identifies ticket prices in HTML
- **Description Extraction**: Uses structured descriptions or pulls from HTML content

## Error Handling

### Network Errors
- **Connection Issues**: Timeout and retry logic for network problems
- **Invalid URLs**: Validation and error reporting for malformed URLs
- **Rate Limiting**: Respectful crawling with appropriate delays

### Content Errors
- **Parse Failures**: Handling of malformed HTML content
- **Missing Content**: Graceful handling when expected content is absent
- **AI Extraction Errors**: Fallback strategies for extraction failures

## Performance Features

### Efficient Processing
- **Content Caching**: Avoids re-processing unchanged web content
- **Incremental Updates**: Only processes new or modified content
- **Memory Management**: Efficient handling of large HTML documents

### Optimization
- **Selective Processing**: Focuses on relevant content sections
- **Batch Operations**: Processes multiple events from single page efficiently
- **Resource Management**: Controls memory usage and processing time

## Troubleshooting

### Common Issues
- **Content Structure Changes**: Websites updating layouts breaking extraction
- **Rate Limiting**: Being blocked by target websites
- **Dynamic Content**: JavaScript-rendered content not accessible
- **Encoding Issues**: Non-UTF8 content causing parsing problems

### Debug Information
- **Content Analysis**: Review AI extraction results
- **HTML Parsing**: Check content selector effectiveness
- **Hash Generation**: Verify content change detection
- **Event Mapping**: Review extracted data structure

### Best Practices
- **Schema.org Implementation**: Encourage websites to implement Schema.org structured data for most accurate extraction
- **XPath Selectors**: The handler automatically uses optimized XPath rules - manual selectors rarely needed
- **Table Layouts**: Handler automatically detects and processes table-based event listings with date filtering
- **Pagination**: Websites with paginated content are automatically traversed (20-page limit)
- **Rate Limiting**: Implement delays between scrapes to be respectful to target sites
- **Structured Data Validation**: Use Google's Rich Results Test to verify Schema.org markup
- **Fallback Readiness**: AI processing provides reliable backup when structured data is unavailable

## Common Website Patterns

### Schema.org Structured Data
Modern websites implementing Schema.org markup provide the most reliable extraction:
- JSON-LD scripts: `<script type="application/ld+json">` with Event objects
- Microdata markup: HTML elements with `itemtype="https://schema.org/Event"`
- Comprehensive event data: titles, dates, venues, performers, pricing, and descriptions

### Widget-Based Calendars
The handler supports specialized extraction for popular event widgets:
- **Google Calendar Widgets**: Base64-encoded event data in `data-calendar-event` attributes
- **SeeTickets Widgets**: Event containers with `seetickets-list-event-container` classes
- **Turntable Tickets**: Event cards with `show-card` class patterns

### Table-Based Calendars
Many venue websites use HTML tables for event listings. The XPath selector system detects:
- Tables with calendar/events/schedule classes
- Table rows with event-date or event-name columns
- Automatic header row skipping and date-based filtering
- Complex nested table structures within calendar sections

### Paginated Sites
Websites with "next page" navigation are handled automatically. The handler follows:
- Standard HTML5 `rel="next"` links and `link` elements
- Common "Next" buttons and links in navigation areas
- Pagination controls at bottom of event lists
- Stops after MAX_PAGES=20 to prevent runaway crawls

The Universal Web Scraper provides standards-compliant event extraction from any website, prioritizing Schema.org structured data for accuracy while maintaining robust AI fallback capabilities.