# Universal Web Scraper Handler

AI-powered web scraping for extracting event data from any HTML page with automated processing and content extraction.

## Overview

The Universal Web Scraper handler uses AI capabilities to extract structured event data from any website. Features HTML section detection, automated content extraction, and ProcessedItems tracking to prevent duplicate processing of the same web content.

## Features

### AI-Powered Extraction
- **Intelligent Content Detection**: AI identifies event-related content sections in HTML
- **Structured Data Extraction**: Automatically extracts event details from unstructured web content
- **Multi-Format Support**: Works with various website layouts and content structures
- **Contextual Understanding**: AI understands event context and relationships

### Processing Features
- **HTML Hash Tracking**: Uses ProcessedItems to track processed web pages by content hash
- **Duplicate Prevention**: Prevents re-processing of unchanged web content
- **Incremental Updates**: Only processes new or changed content
- **Content Change Detection**: Detects when web content has been updated

### Technical Features
- **Single-Item Processing**: Processes one event per job execution
- **Event Identity Normalization**: Uses EventIdentifierGenerator for consistent duplicate detection
- **Flexible Configuration**: Adaptable to different website structures

## Configuration

### Required Settings
- **Target URL**: The web page URL to scrape for event data
- **Content Selector** (optional): CSS selector to target specific content areas

### Optional Settings
- **AI Prompts**: Custom prompts for content extraction
- **Venue Override**: Override venue assignment for consistency
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

### Content Extraction
- **HTML Analysis**: AI analyzes page structure and content
- **Event Detection**: Identifies individual events within page content
- **Data Mapping**: Extracts title, dates, venue, description, and pricing
- **Content Hashing**: Generates hash of processed content for change detection

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
- **UniversalWebScraper.php**: Main scraping handler with AI integration
- **UniversalWebScraperSettings.php**: Admin configuration interface

### Data Flow
1. **URL Retrieval**: Downloads target web page content
2. **Content Analysis**: AI analyzes HTML structure and identifies event data
3. **Event Extraction**: Extracts structured event information
4. **Content Tracking**: Uses HTML hash for processed content tracking
5. **Event Processing**: Maps extracted data to Data Machine event structure
6. **Duplicate Check**: Uses EventIdentifierGenerator for event identity verification
7. **Event Upsert**: Creates/updates events using EventUpsert handler

## AI Integration

### Content Understanding
- **Natural Language Processing**: Understands event descriptions and context
- **Pattern Recognition**: Identifies common event data patterns
- **Contextual Extraction**: Maintains relationships between event elements
- **Multi-Language Support**: Handles various language content

### Extraction Capabilities
- **Event Titles**: Identifies and extracts event names
- **Date/Time Parsing**: Recognizes various date and time formats
- **Venue Detection**: Extracts location and venue information
- **Pricing Information**: Identifies ticket prices and availability
- **Description Extraction**: Pulls detailed event descriptions

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
- **Content Selectors**: Use specific CSS selectors when possible
- **Rate Limiting**: Implement delays between scrapes
- **Change Monitoring**: Regularly check for website layout changes
- **Fallback Strategies**: Have backup extraction methods

The Universal Web Scraper provides flexible, AI-powered event extraction from any website with intelligent content analysis and efficient duplicate prevention.