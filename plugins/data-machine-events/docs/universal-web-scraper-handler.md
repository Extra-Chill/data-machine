# Universal Web Scraper Handler

Schema.org-compliant web scraping for extracting event data from any HTML page with automated processing, structured data parsing, and paginated site support.

## Overview

The Universal Web Scraper handler prioritizes structured data extraction for maximum accuracy, falling back to AI-enhanced HTML parsing when structured data is unavailable. Features support for Wix Events JSON, Schema.org JSON-LD, HTML section detection using prioritized XPath selector rules, automatic pagination for multi-page results, and ProcessedItems tracking to prevent duplicate processing.

## Features

### Structured Data Extraction (Priority Order)

1. **Wix Events JSON**: Extracts events from `<script id="wix-warmup-data">` containing Wix platform event data
2. **RHP Events Plugin**: Extracts events from WordPress sites using the RHP Events plugin HTML structure
3. **JSON-LD Parsing**: Extracts event data from `<script type="application/ld+json">` tags containing Schema.org Event objects
4. **Schema.org Microdata**: Extracts events from HTML elements with Schema.org itemtype/itemprop attributes
5. **AI-Enhanced HTML Parsing**: Falls back to AI analysis when structured data is unavailable

### Wix Events Support

The handler automatically detects and parses Wix Events platform data:

- **Automatic Detection**: Identifies `wix-warmup-data` script tags in page HTML
- **Full Event Data**: Extracts title, dates, venue, location, coordinates, ticket URLs, and images
- **Timezone Handling**: Properly converts dates using the event's configured timezone
- **Ticketing Integration**: Captures external ticket URLs (Ticketbud, Eventbrite, etc.)

### RHP Events Plugin Support

The handler extracts events from WordPress sites using the RHP Events plugin:

- **Automatic Detection**: Identifies `.rhpSingleEvent` elements in page HTML
- **Event Data**: Extracts title, date, doors/show times, venue, price, age restriction, and images
- **Year Detection**: Parses month separators (e.g., "December 2025") to determine event year
- **Ticket Links**: Captures external ticket URLs (Etix, etc.) and event detail URLs
- **Price Parsing**: Handles advance and day-of pricing formats

### Schema.org JSON-LD Extraction

- **Standard Compliance**: Fully compatible with Schema.org Event specification
- **Comprehensive Coverage**: Supports all major Schema.org Event properties
- **Nested Structures**: Handles @graph structures and arrays of events

### AI-Enhanced HTML Parsing (Fallback)

- **Intelligent Content Detection**: AI identifies event-related content sections in HTML when structured data is unavailable
- **Contextual Understanding**: AI understands event context and relationships for unstructured content
- **Multi-Format Support**: Works with various website layouts and content structures

### Processing Features

- **Engine Data Integration**: Stores extracted venue and event data in pipeline engine data
- **Duplicate Prevention**: Uses EventIdentifierGenerator for consistent duplicate detection
- **Automatic Pagination**: Follows "next page" links to traverse multi-page event listings (MAX_PAGES=20)
- **XPath Selector Rules**: Prioritized selector system for identifying candidate event sections

### Data Flow

When structured data (Wix or JSON-LD) is found:

1. Extract and normalize event data to standardized format
2. Apply venue config override if configured in handler settings
3. Store venue metadata via `EventEngineData::storeVenueContext()`
4. Store core event fields (title, dates, ticketUrl, etc.) in engine data
5. Create DataPacket with both `event` (normalized) and `raw_source` (original)
6. AI step receives pre-extracted data for description generation and enrichment

When falling back to HTML parsing:

1. EventSectionFinder locates event HTML sections using XPath rules
2. Raw HTML is sent to AI for extraction
3. AI extracts all event fields from unstructured content

## Configuration

### Required Settings

- **Target URL**: The web page URL to scrape for event data

### Optional Settings

- **Venue Selection**: Select existing venue or create new with address details
- **Keyword Filtering**: Include/exclude events based on keywords

### Venue Configuration

The handler uses VenueFieldsTrait for venue configuration:

- **Venue Dropdown**: Select from existing venue taxonomy terms
- **Create New Venue**: Enter venue name and address details
- **Address Autocomplete**: Google Places integration for address entry
- **Override Behavior**: Handler venue config overrides extracted venue data

## Usage Examples

### Basic Web Scraping

```php
$config = [
    'source_url' => 'https://venue-website.com/events'
];
```

### With Venue Override

```php
$config = [
    'source_url' => 'https://venue-website.com/events',
    'venue' => 123,  // Existing venue term ID
    'search' => 'concert,music',
    'exclude_keywords' => 'cancelled,sold-out'
];
```

## Supported Platforms

### Wix Events

Websites built on Wix platform with the Wix Events widget:

- Event listings with embedded JSON data
- Full venue and location information
- External ticketing links (Ticketbud, Eventbrite, etc.)
- Event images and descriptions

### RHP Events (WordPress Plugin)

WordPress sites using the RHP Events plugin for venue event management:

- Structured HTML with consistent CSS classes
- Event listings with date, time, price, and venue information
- External ticket links (Etix, Ticketmaster, etc.)
- Age restriction information

### Schema.org Compliant Sites

Any website implementing Schema.org Event markup:

- JSON-LD structured data in script tags
- Schema.org microdata in HTML elements
- Standard Event, MusicEvent, Festival, etc. types

### Custom HTML Sites

Websites without structured data (AI fallback):

- Table-based event calendars
- Event card/list layouts
- Widget-based calendars (Google Calendar, SeeTickets, Turntable Tickets)

## Integration Architecture

### Handler Structure

- **UniversalWebScraper.php**: Main handler with structured data extraction and AI fallback
- **StructuredDataProcessor.php**: Common processing logic for all extractors
- **EventSectionFinder.php**: Locates eligible event sections using XPath selector rules
- **EventSectionSelectors.php**: Defines prioritized XPath rules for event section detection
- **UniversalWebScraperSettings.php**: Admin configuration interface using VenueFieldsTrait

### Extractors (Extractors/ directory)

- **ExtractorInterface.php**: Contract for all extractor implementations
- **WixEventsExtractor.php**: Parses Wix warmup-data JSON
- **RhpEventsExtractor.php**: Parses RHP Events WordPress plugin HTML
- **JsonLdExtractor.php**: Parses Schema.org JSON-LD script tags
- **MicrodataExtractor.php**: Parses Schema.org HTML microdata attributes

### DataPacket Format

When structured data is extracted:

```json
{
  "event": {
    "title": "Event Name",
    "startDate": "2024-01-15",
    "startTime": "19:00",
    "venue": "Venue Name",
    "ticketUrl": "https://..."
  },
  "raw_source": { /* Original Wix/JSON-LD data */ },
  "venue_metadata": {
    "venueAddress": "123 Main St",
    "venueCity": "City",
    "venueState": "ST"
  },
  "import_source": "universal_web_scraper",
  "extraction_method": "wix_events"
}
```

When using HTML fallback:

```json
{
  "raw_html": "<div class=\"event\">...</div>",
  "source_url": "https://...",
  "import_source": "universal_web_scraper"
}
```

## Pagination Features

- **Automatic Link Following**: Detects "next page" links using multiple patterns (rel="next", common navigation selectors)
- **Domain Validation**: Only follows links on the same domain to prevent crawl drift
- **URL Tracking**: Maintains visited URL tracking to prevent re-scraping the same page
- **Max Pages Limit**: Respects MAX_PAGES=20 constant to prevent infinite loops

## Error Handling

### Network Errors

- **Connection Issues**: Timeout and retry logic for network problems
- **Invalid URLs**: Validation and error reporting for malformed URLs

### Content Errors

- **Parse Failures**: Handling of malformed HTML/JSON content
- **Missing Content**: Graceful handling when expected content is absent
- **AI Extraction Errors**: Fallback strategies for extraction failures

## Troubleshooting

### Common Issues

- **Content Structure Changes**: Websites updating layouts breaking extraction
- **Rate Limiting**: Being blocked by target websites
- **Dynamic Content**: JavaScript-rendered content not accessible
- **Encoding Issues**: Non-UTF8 content causing parsing problems

### Debug Information

- Check logs for extraction method used (wix_events, rhp_events, jsonld, microdata, html_section)
- Review engine data for stored venue/event fields
- Verify DataPacket contents in pipeline execution logs

### Best Practices

- **Wix Sites**: No configuration needed - automatic detection and extraction
- **RHP Events Sites**: No configuration needed - automatic detection and extraction
- **Schema.org Sites**: Automatic detection - verify with Google's Rich Results Test
- **Custom Sites**: May need keyword filtering to focus on relevant content
- **Rate Limiting**: Implement delays between scrapes to be respectful to target sites
