# Universal Web Scraper Test Command

WP-CLI command for testing the Universal Web Scraper handler with any target URL. Supports web pages, ICS feeds, and JSON APIs.

```bash
wp data-machine-events test-event-scraper --target_url=<url>
```

## Parameters

### Required

- `--target_url=<url>`: The web page URL, ICS feed, or JSON API to test

## Examples

### Web Page Scraping
```bash
wp data-machine-events test-event-scraper --target_url=https://example.com/events
```

### ICS Calendar Feeds
```bash
wp data-machine-events test-event-scraper --target_url=https://tockify.com/api/feeds/ics/calendar-name
```

### Google Calendar Export
```bash
wp data-machine-events test-event-scraper --target_url=webcal://calendar.google.com/calendar/ical/...
```

## Output

The command displays:

1. **Target URL**: The URL being tested
2. **Extraction Details**:
   - Packet title
   - Source type (e.g., `wix_events`, `json_ld`, `raw_html`)
   - Extraction method
   - Event title and start date
   - Venue name and address
3. **Status**: OK (complete venue/address coverage) or WARNING (incomplete coverage)
4. **Warnings**: Any extraction warnings encountered

## Venue Coverage Warnings

The command evaluates venue data completeness:

- **Missing venue name**: Venue override required in flow configuration
- **Missing address fields**: Address, city, and state are required for geocoding

Raw HTML packets indicate AI extraction is needed for venue data.

## Exit Codes

- `0`: Command completed successfully
- `1`: Error (missing required parameter)

## Use Cases

- **Handler Testing**: Verify the scraper works on a new venue website
- **Extraction Debugging**: Inspect raw extraction results before running a full pipeline. If extraction fails, the command outputs the full raw HTML to assist in troubleshooting.
- **Coverage Assessment**: Check if venue data will be complete after import
- **Platform Detection**: Identify which extractor is being used (Wix, Squarespace, etc.)

## Reliability & Debugging

The test command is essential for verifying the scraper's **Smart Fallback** and **Browser Spoofing** capabilities. When testing URLs known to have strict bot detection, observe the logs for "retrying with standard mode" to confirm the fallback is functioning correctly.

## ICS Calendar Feed Support

The Universal Web Scraper now directly supports ICS/iCal feed URLs, replacing the deprecated ICS Calendar handler.

### Supported ICS Formats

- Direct `.ics` files
- Tockify feeds
- Google Calendar exports
- Apple Calendar exports
- Outlook calendar exports
- Any standard ICS/iCal feed

### ICS Handler Migration

The legacy **ICS Calendar** handler is deprecated. Migrate existing flows using:

```bash
wp data-machine-events migrate-handlers --handler=ics_calendar --dry-run
```

To perform the migration:

```bash
wp data-machine-events migrate-handlers --handler=ics_calendar
```

The migration tool automatically:
- Updates flow handler from `ics_calendar` to `universal_web_scraper`
- Maps `feed_url` config to `source_url`
- Preserves all venue override and keyword filtering settings
- Validates configuration before applying changes

### ICS-Specific Output

When testing ICS feeds, the command displays:

- **Source type**: `ics_feed`
- **Timezone information**: Calendar timezone and event-specific timezone
- **Venue data**: From ICS location field (optional venue override available)
- **Time coverage warnings**: For missing start/end times (common in all-day events)
