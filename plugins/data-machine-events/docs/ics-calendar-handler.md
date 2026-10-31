# ICS Calendar Handler (Deprecated)

This handler has been consolidated into the Universal Web Scraper as the `IcsExtractor`.

Previously the handler lived at `inc/Steps/EventImport/Handlers/IcsCalendar/IcsCalendar.php` with `IcsCalendarSettings.php`. That standalone handler and its settings were removed; existing flows should migrate to the scraper-based extractor by reconfiguring the fetch step to use the `UniversalWebScraper` handler.

See `docs/universal-web-scraper-handler.md` for extraction behavior and `inc/Steps/EventImport/Handlers/WebScraper/Extractors/IcsExtractor.php` for the implementation.

## Migration

The `IcsExtractor` handles ICS/iCal feeds within the Universal Web Scraper:

1. Detects ICS feeds by URL extension (`.ics`) or `webcal://` protocol.
2. Converts `webcal://` to `https://` automatically.
3. Parses VEVENT components using the `johngrogg/ics-parser` library.
4. Applies keyword filtering and venue overrides via standard handler settings.
5. Supports RRULE recurring events, EXDATE exception dates, and RDATE additional dates.

## Configuration

When using the Universal Web Scraper with an ICS feed:

- **`source_url`**: The ICS feed URL (`.ics` or `webcal://` links)
- **`search` / `exclude_keywords`**: Comma-separated filters applied before normalization
- **Venue override fields**: Available via `VenueFieldsTrait` in handler settings

## Data Mapping

| ICS Property | Event Details Attribute |
|--------------|------------------------|
| `SUMMARY` | title |
| `DESCRIPTION` | description |
| `DTSTART` / `DTEND` | start/end dates and times |
| `LOCATION` | venue |
| `TZID` | timezone |
