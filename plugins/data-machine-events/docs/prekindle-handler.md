# Prekindle Handler (Deprecated)

This handler has been consolidated into the Universal Web Scraper as the `PrekindleExtractor`.

Previously the handler lived at `inc/Steps/EventImport/Handlers/Prekindle/Prekindle.php` with `PrekindleSettings`. That standalone handler and its settings were removed; existing flows should migrate to the scraper-based extractor by reconfiguring the fetch step to use the `UniversalWebScraper` handler, which auto-detects Prekindle widgets.

See `docs/universal-web-scraper-handler.md` for extraction behavior and `inc/Steps/EventImport/Handlers/WebScraper/Extractors/PrekindleExtractor.php` for the implementation.

## Migration

The `PrekindleExtractor` (since v0.8.12) handles Prekindle pages within the Universal Web Scraper:

1. Auto-detects Prekindle widgets (`pk-cal-widget`) or organizer links in page HTML.
2. Automatically extracts the `org_id` from widget attributes or script source URLs.
3. Uses hybrid extraction: fetches the Prekindle mobile grid widget for both JSON-LD event data and HTML time blocks.
4. Scrapes `pk-times` HTML blocks for precise door/show times missing from standard JSON-LD.

No manual `org_id` configuration is needed — the extractor auto-detects it from the page.

## Data Mapping

The extractor uses the same dual-phase extraction as the legacy handler:

| Source | Field | Event Details Attribute |
|--------|-------|------------------------|
| JSON-LD | `name` | title |
| JSON-LD | `startDate` / `endDate` | date fields |
| JSON-LD | `description` | description |
| JSON-LD | `location.*` | venue + address |
| JSON-LD | `performer[].name` | artists |
| JSON-LD | `offers.*` | pricing |
| HTML | `pk-times` | precise door/show times |
