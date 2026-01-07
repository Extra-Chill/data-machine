# Universal Web Scraper Test Command

WP-CLI command for testing the Universal Web Scraper handler with any target URL.

## Command Names

- `wp datamachine-events test-scraper`
- `wp datamachine-events test-scraper-url`

Both commands are aliases and function identically.

```bash
wp datamachine-events test-scraper-url --target_url=<url>
```

## Parameters

### Required

- `--target_url=<url>`: The web page URL to scrape for event data

## Example

```bash
wp datamachine-events test-scraper-url --target_url=https://example.com/events
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
