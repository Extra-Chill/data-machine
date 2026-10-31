# Universal Web Scraper

The Universal Web Scraper is a high-performance fetch handler in the `datamachine-events` plugin designed to retrieve event data from external websites with maximum accuracy and reliability.

## Architectural Overview

The scraper employs a multi-layered extraction strategy, prioritizing high-fidelity structured data before falling back to AI-enhanced pattern matching.

### 1. Unified Coordination (`UniversalWebScraper`)
The main handler manages the overall execution flow:
- **Pagination Control**: Navigates up to 20 pages of results automatically.
- **Deduplication**: Integrated with the system-wide processed items tracking to ensure only new events are captured.
- **Fallback Logic**: Orchestrates the transition between structured extractors and HTML section parsing.

### 2. Multi-Layered Extraction
The system attempts extraction in the following priority:

#### Tier 1: Specialized Extractors
17+ dedicated extractors target specific platform footprints (JSON-LD, Microdata, or proprietary JSON blobs):
- **High-Priority APIs**: AEG/AXS, Red Rocks, Freshtix, Firebase, SpotHopper.
- **Platform Footprints**: Squarespace (Static.SQUARESPACE_CONTEXT), Wix (warmup-data), GoDaddy, Bandzoogle.
- **CMS/Plugin Hooks**: WordPress (The Events Calendar, WP REST API), Timely (FullCalendar.js).

#### Tier 2: Generic Structured Data
Fallback extractors that parse standardized semantic markup:
- **Schema.org JSON-LD**
- **Schema.org Microdata**

#### Tier 3: AI-Enhanced HTML Fallback (`EventSectionFinder`)
When no structured data is found, the system identifies candidate event HTML sections using `EventSectionSelectors`. These sections are cleaned and passed to AI steps as raw HTML for structured extraction.

### 3. Centralized Processing (`StructuredDataProcessor`)
All extracted data is normalized through a unified processor that handles:
- **Venue Overrides**: Applies static venue data or taxonomy-based overrides from handler settings.
- **Engine Data Storage**: Stores critical event fields (ticket URLs, images, dates) in the centralized engine data store.
- **Deduplication Check**: Generates unique identifiers based on title, date, and venue to prevent duplicate processing.

## Extension Guide

### Adding a New Extractor
To support a new platform, create a class implementing `ExtractorInterface` in the datamachine-events plugin under its Web Scraper extractors directory:

```php
interface ExtractorInterface {
    public function canExtract(string $html): bool;
    public function extract(string $html, string $url): array;
    public function getMethod(): string;
}
```

Register the new extractor in `UniversalWebScraper::getExtractors()`.

## Single Item Execution Model

The Universal Web Scraper strictly follows the **Single Item Execution Model**:
1. It fetches the source URL and iterates through events (or pages).
2. For each event, it generates a unique identifier.
3. It checks if the identifier has already been processed for the current flow step.
4. It processes and returns **exactly one** new event per execution cycle.
5. This ensures that even if a site contains 100 events, they are processed reliably one by one, isolating potential failures and preventing timeouts.

## Captcha and Block Handling

The scraper implements a **Smart Fallback** mechanism for handling bot detection:
- It initially attempts to fetch pages using advanced browser-like headers (spoofing `User-Agent`, `Sec-Fetch-*`, `Accept-Language`, etc.).
- If a request returns an HTTP 403 or contains common captcha signatures (SiteGround, Cloudflare, "Checking your browser"), it automatically retries the request using standard non-browser headers.
- This dual-mode approach maximizes success rates across a wide variety of hosting environments.

## Configuration Settings

- **Source URL**: The primary events listing page or API endpoint.
- **Search Keywords**: Required keywords for inclusion.
- **Exclude Keywords**: Keywords to filter out unwanted events.
- **Venue Override**: Optional settings to force a specific venue for all events found at this source.
