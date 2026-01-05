# Universal Web Scraper Test Command

WP-CLI command for testing the Universal Web Scraper handler with any target URL.

## Command Names

- `wp datamachine-events test-scraper`
- `wp datamachine-events test-scraper-url`

Both commands are aliases and function identically.

```bash
wp datamachine-events universal-web-scraper-test --target_url=<url> [--upsert] [--pipeline_id=<id>] [--flow_id=<id>] [--flow_step_id=<uuid>] [--venue_name=<name>] [--max=<count>]
```

## Parameters

### Required

- `--target_url=<url>`: The web page URL to scrape for event data

### Optional

- `--upsert`: If set, upserts discovered events to the database
- `--pipeline_id=<id>`: Pipeline ID for job creation (default: 1)
- `--flow_id=<id>`: Flow ID for job creation (default: 1)
- `--flow_step_id=<uuid>`: Custom flow step UUID (auto-generated if omitted)
- `--venue_name=<name>`: Venue name override for reliable address/geocoding
- `--max=<count>`: Maximum number of packets to display (default: 3)

## Examples

### Basic Test

```bash
wp datamachine-events universal-web-scraper-test --target_url=https://example.com/events
```

### Test with Upsert

```bash
wp datamachine-events universal-web-scraper-test --target_url=https://example.com/events --upsert
```

### Test with Venue Override

```bash
wp datamachine-events universal-web-scraper-test --target_url=https://example.com/events --venue_name="The Fillmore"
```

### Show More Results

```bash
wp datamachine-events universal-web-scraper-test --target_url=https://example.com/events --max=10
```

### Full Options

```bash
wp datamachine-events universal-web-scraper-test \
  --target_url=https://example.com/events \
  --upsert \
  --pipeline_id=5 \
  --flow_id=3 \
  --venue_name="Red Rocks Amphitheatre" \
  --max=5
```

## Output

The command displays:

1. **Target Information**: Target URL, Job ID, Flow Step ID, Packet count
2. **Packet Details**: For each packet:
   - Packet title
   - Source type (e.g., `wix_events`, `json_ld`, `raw_html`)
   - Extraction method
   - Event title and start date
   - Venue name and address
3. **Status**: OK (complete venue/address coverage) or WARNING (incomplete coverage)
4. **Warnings**: Any extraction warnings encountered
5. **Upsert Results**: If `--upsert` is set, shows action taken (insert/update) and post_id

## Venue Coverage Warnings

The command evaluates venue data completeness:

- **Missing venue name**: Set `--venue_name` override
- **Missing address fields**: Address, city, and state are required for geocoding

Raw HTML packets indicate AI extraction is needed for venue data.

## Exit Codes

- `0`: Command completed successfully
- `1`: Error (missing required参数, job creation failed, etc.)

## Use Cases

- **Handler Testing**: Verify the scraper works on a new venue website
- **Extraction Debugging**: Inspect raw extraction results before running a full pipeline. If extraction fails, the command outputs the full raw HTML to assist in troubleshooting.
- **Coverage Assessment**: Check if venue data will be complete after import
- **Platform Detection**: Identify which extractor is being used (Wix, Squarespace, etc.)

## Reliability & Debugging

The test command is essential for verifying the scraper's **Smart Fallback** and **Browser Spoofing** capabilities. When testing URLs known to have strict bot detection, observe the logs for "retrying with standard mode" to confirm the fallback is functioning correctly. Increased reliability for platforms like Squarespace and embedded Google Calendars can be verified by checking for successfully decoded IDs and block-based extraction results in the command output.
