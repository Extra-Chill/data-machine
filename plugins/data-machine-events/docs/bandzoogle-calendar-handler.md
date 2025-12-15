# BandzoogleCalendar Handler

The BandzoogleCalendar handler (`inc/Steps/EventImport/Handlers/BandzoogleCalendar/BandzoogleCalendar.php`, `BandzoogleCalendarSettings`) plugs into `EventImportStep` through `HandlerRegistrationTrait` so it can be selected inside a Data Machine pipeline. Each execution follows the single-item pattern: it crawls Bandzoogle calendar month pages forward-only and imports single occurrences from `/go/events` popup HTML (title, date, time, notes, image).

## Configuration & Authentication

- **Calendar URL** (`calendar_url`): Bandzoogle calendar page URL (required, example: `https://elephantroom.com/calendar`).
- **Venue Fields**: Static venue data configuration using `VenueFieldsTrait` (name, address, city, state, zip, country, phone, website).
- **Keyword Filtering**: `search` for include keywords and `exclude_keywords` for filtering event titles and descriptions.
- **Authentication**: No authentication required - crawls public Bandzoogle calendar pages.

## Data Mapping

- **Event Details**: Title, start/end dates, start/end times, descriptions, and images extracted from Bandzoogle popup HTML.
- **Venue Metadata**: Configured statically through handler settings, passed to `VenueService`/`Venue_Taxonomy` for term meta synchronization.
- **Images & Media**: Event images extracted from popup HTML and stored in `EventEngineData` for later download by `WordPressPublishHelper`.
- **Navigation**: Forward-only month pagination with MAX_PAGES=20 limit to prevent infinite crawling.

## Unique Capabilities

BandzoogleCalendar crawls calendar month pages sequentially, extracts occurrence URLs from the calendar grid, fetches detailed popup HTML for each event, and handles cross-month date resolution when events span month boundaries. It uses specific CSS selectors to parse event titles, dates, times, notes, and images from Bandzoogle's popup format. The handler automatically appends `popup=1` parameter to occurrence URLs and follows "next month" links while respecting the pagination limit.

## Pagination & Crawling

The handler implements forward-only calendar crawling:

```php
// Crawls calendar month pages sequentially
// Extracts occurrence URLs from calendar grid HTML
// Fetches popup HTML for each event occurrence
// Handles cross-month date resolution
// Respects MAX_PAGES = 20 to prevent infinite crawling
// Returns immediately after finding first unprocessed event
```

This ensures incremental imports continue discovering new events as they're added to the Bandzoogle calendar.

## Event Flow

1. `EventImportStep` instantiates `BandzoogleCalendar` and reads `BandzoogleCalendarSettings` values.
2. Handler parses current month context from calendar HTML or URL path.
3. Extracts all occurrence URLs from the calendar grid.
4. Fetches popup HTML for each occurrence and parses event details.
5. Normalizes identity via `EventIdentifierGenerator`, checks processing status.
6. Stores venue metadata via `VenueParameterProvider` helpers and image URLs in `EventEngineData`.
7. Returns first eligible `DataPacket` for incremental processing.
8. `EventUpsert` receives the data, merges engine parameters, and creates/updates the WordPress event.

Every EventUpsert run uses the same identifier hash so duplicates never occur, and venue metadata stays consistent thanks to `VenueService`/`Venue_Taxonomy` helpers.

## HTML Parsing Details

### Month Context Detection
- Parses `<span class="month-name">` for current month/year
- Falls back to URL path parsing: `/go/calendar/{id}/{year}/{month}`
- Handles cross-month date resolution for events spanning boundaries

### Occurrence URL Extraction
- Matches `href="/go/events/{id}?occurrence_id={oid}"` patterns
- Automatically appends `popup=1` parameter for popup HTML
- Resolves relative URLs to absolute Bandzoogle URLs

### Event Data Extraction
- **Title**: `<h2 class="event-title">` or `<a href="...">title</a>` within title element
- **Date/Time**: `<time class="from"><span class="date">@<span class="time">` pattern
- **Description**: `<div class="event-notes">` content with HTML cleaning
- **Images**: `<div class="event-image"><img src="...">` extraction
- **Links**: Event source URLs from title links

### Date Resolution
- Parses "Month Day" format (e.g., "December 15")
- Resolves year context based on current month and event month
- Handles year transitions (December→January, January→December)</content>
<parameter name="filePath">docs/bandzoogle-calendar-handler.md