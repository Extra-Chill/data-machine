# Universal Web Scraper Handler

Web scraping handler that extracts event data from arbitrary HTML pages using structured-data extractors first, then an HTML-section fallback.

## Overview

The Universal Web Scraper handler prioritizes structured data extraction for maximum accuracy, falling back to HTML-section extraction when structured data is unavailable. It supports multiple extractor implementations, XPath-based event section detection, automatic pagination (up to `MAX_PAGES = 20`), and ProcessedItems tracking to prevent duplicate processing.

## Features

### Structured Data Extraction (Priority Order)

1. **AEG/AXS JSON feed** (`AegAxsExtractor`): Extracts events from AEG/AXS venue JSON feeds
2. **Red Rocks** (`RedRocksExtractor`): Parses Red Rocks Amphitheatre event pages (@since v0.8.0)
3. **Freshtix** (`FreshtixExtractor`): Parses embedded JavaScript event objects on Freshtix platform pages (@since v0.8.0)
4. **Wix Events JSON** (`WixEventsExtractor`): Extracts events from `<script id="wix-warmup-data">`
5. **RHP Events plugin HTML** (`RhpEventsExtractor`): Extracts events from `.rhpSingleEvent` markup
6. **OpenDate.io** (`OpenDateExtractor`): Two-step extraction (listing → detail page). Prioritizes React JSON datetime values over JSON-LD for improved time accuracy.
7. **Schema.org JSON-LD** (`JsonLdExtractor`): Parses `<script type="application/ld+json">`
8. **Schema.org Microdata** (`MicrodataExtractor`): Parses itemtype/itemprop markup
9. **HTML section extraction** (fallback): Uses XPath selector rules to extract one candidate section at a time for downstream processing

### Wix Events Support

The handler automatically detects and parses Wix Events platform data:

- **Automatic Detection**: Identifies `wix-warmup-data` script tags in page HTML
- **Full Event Data**: Extracts title, dates, venue, location, coordinates, ticket URLs, and images
- **Timezone Handling**: Properly converts dates using the event's configured timezone
- **Ticket URL capture**: Extractors capture ticket URLs when present in source data

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

### HTML Section Extraction (Fallback)

- EventSectionFinder selects candidate event HTML sections using XPath rules
- Extracted HTML is sent downstream for AI extraction

### Processing Features

- **Engine Data Integration**: Stores extracted venue and event data in pipeline engine data
- **Duplicate Prevention**: Uses ProcessedItems tracking (via handler base methods) to skip already-processed identifiers
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
- **Address Autocomplete**: Venue fields support coordinate lookup via the plugin's OpenStreetMap/Nominatim-based geocoding endpoint
- **Override Behavior**: Handler venue config overrides extracted venue data

## Configuration Notes

- **Required**: `source_url`
- **Optional**: `search`, `exclude_keywords`, and venue override fields (via `VenueFieldsTrait` in the settings UI)

This handler returns a single eligible event per run (or a single HTML section for downstream extraction), keeping pipeline imports incremental.

## Supported Platforms

### Wix Events

Websites built on Wix platform with the Wix Events widget:

- Event listings with embedded JSON data
- Full venue and location information
- External ticketing links when present
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
- **AegAxsExtractor.php**: Parses AEG/AXS JSON feeds
- **RedRocksExtractor.php**: Parses Red Rocks Amphitheatre event pages
- **FreshtixExtractor.php**: Parses Freshtix platform pages
- **WixEventsExtractor.php**: Parses Wix warmup-data JSON
- **RhpEventsExtractor.php**: Parses RHP Events plugin HTML
- **OpenDateExtractor.php**: Handles OpenDate.io listing/detail extraction
- **JsonLdExtractor.php**: Parses Schema.org JSON-LD script tags
- **MicrodataExtractor.php**: Parses Schema.org microdata attributes

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

## Pagination Details

- **Automatic Link Following**: Detects "next page" links using multiple patterns (rel="next", common navigation selectors)
- **Domain Validation**: Only follows links on the same domain to prevent crawl drift
- **URL Tracking**: Maintains visited URL tracking to prevent re-scraping the same page
- **Max Pages Limit**: Respects MAX_PAGES=20 constant to prevent infinite loops

## Data Output

- Structured extraction returns a `DataPacket` with JSON `body` containing:
  - `event`
  - `venue_metadata`
  - `import_source`
  - `extraction_method`
- HTML section fallback returns a `DataPacket` containing `raw_html` + `source_url` for downstream extraction.

## Supported Sources

- Wix (Wix Events)
- Red Rocks Amphitheatre
- Freshtix platform sites
- WordPress sites using the RHP Events plugin
- OpenDate.io calendars
- Sites with Schema.org Event JSON-LD or microdata
- AEG/AXS venue feeds embedded/linked from venue pages
