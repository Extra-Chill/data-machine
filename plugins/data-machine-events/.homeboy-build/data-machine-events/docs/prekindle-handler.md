# Prekindle Handler

The Prekindle handler (`inc/Steps/EventImport/Handlers/Prekindle/Prekindle.php`, `PrekindleSettings`) plugs into `EventImportStep` through `HandlerRegistrationTrait` so it can be selected inside a Data Machine pipeline. Each execution follows the single-item pattern: it imports events from Prekindle organizer widget pages using org_id, combining JSON-LD event data with HTML-extracted start times.

## Configuration & Authentication

- **Org ID** (`org_id`): Prekindle organizer ID used by the embedded calendar widget (required, example: `531433528849134007`).
- **Venue Fields**: Optional static venue data configuration using `VenueFieldsTrait` to override extracted venue information.
- **Keyword Filtering**: `search` for include keywords and `exclude_keywords` for filtering event titles and descriptions.
- **Authentication**: No authentication required - accesses public Prekindle widget data.

## Data Mapping

- **Event Details**: Title, start/end dates, descriptions, performers, organizers, and pricing from JSON-LD structured data.
- **Time Information**: Start times extracted from HTML listing and matched to events by title.
- **Venue Metadata**: Address components, coordinates, and venue details from JSON-LD location data, with optional override via handler settings.
- **Pricing & Tickets**: Price ranges, currencies, availability, and purchase URLs from offers data.
- **Images & Media**: Event images from JSON-LD and stored in `EventEngineData` for download by `WordPressPublishHelper`.
- **Taxonomy Info**: Performer names and organizer information for promoter taxonomy integration.

## Unique Capabilities

Prekindle combines JSON-LD structured data with HTML-parsed time information for comprehensive event extraction. The handler constructs widget URLs from org_id, extracts events from Schema.org JSON-LD, matches start times from the HTML listing using title-based correlation, and provides flexible venue override capabilities. It handles multiple performer names, complex pricing structures, and organizer information from the Prekindle widget format.

## Data Extraction Process

The handler processes Prekindle widget pages in two phases:

```php
// Phase 1: JSON-LD Event Extraction
// - Fetches widget HTML from constructed URL
// - Parses application/ld+json script tags
// - Extracts event objects from @graph or direct arrays

// Phase 2: Time Matching
// - Parses HTML event blocks with name="pk-eachevent"
// - Extracts titles from pk-headline divs
// - Matches times from pk-times divs by normalized title
// - Correlates JSON-LD events with HTML times
```

This dual-extraction approach ensures complete event data capture from Prekindle's widget format.

## Event Flow

1. `EventImportStep` instantiates `Prekindle` and reads `PrekindleSettings` values.
2. Handler constructs widget URL from org_id and fetches HTML content.
3. Extracts JSON-LD event data and HTML time listings.
4. Maps Prekindle events to standardized format with title-based time matching.
5. Applies keyword filtering and venue configuration overrides.
6. Normalizes identity via `EventIdentifierGenerator`, checks processing status.
7. Stores venue metadata and image URLs in `EventEngineData`.
8. Returns first eligible `DataPacket` for incremental processing.
9. `EventUpsert` receives the data and creates/updates the WordPress event.

Every EventUpsert run uses the same identifier hash to prevent duplicates, and venue metadata stays consistent through `VenueService`/`Venue_Taxonomy` helpers.

## JSON-LD Mapping Details

### Event Properties
- **Title**: `name` field from JSON-LD event object
- **Dates**: `startDate`/`endDate` in ISO format
- **Description**: `description` field with HTML content
- **Location**: `location` object with address components
- **Performers**: `performer` array with individual artist names
- **Organizer**: `organizer` object with name and URL
- **Offers**: `offers` object with pricing and availability
- **Images**: `image` field for event artwork

### Address Resolution
- **Street Address**: `location.address.streetAddress`
- **City**: `location.address.addressLocality`
- **State**: `location.address.addressRegion`
- **ZIP**: `location.address.postalCode`
- **Country**: `location.address.addressCountry`

### Time Extraction
- Parses "Start HH:MM am/pm" patterns from HTML
- Matches times to events by normalized title comparison
- Handles various time format variations
- Falls back to empty time if no match found

### Venue Override
- Optional static venue configuration overrides extracted data
- Useful when Prekindle location data is incomplete
- Maintains flexibility for venue standardization</content>
<parameter name="filePath">docs/prekindle-handler.md