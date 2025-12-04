# Dice FM Handler

The Dice FM handler (`inc/Steps/EventImport/Handlers/DiceFm/DiceFm.php`, `DiceFmSettings`) registers itself via `HandlerRegistrationTrait` and is discovered by `EventImportStep` when you select it in a pipeline. Each run follows Data Machine’s single-item loop: it normalizes title/date/venue through `Utilities/EventIdentifierGenerator::generate($title, $startDate, $venue)`, checks whether the identifier was already processed via `datamachine_is_item_processed`, marks it, and immediately returns the first eligible `DataPacket` so imports stay incremental.

## Configuration

- **City** (`city`): Required Dice FM market.
- **API Key** (`api_key`): Dice FM authentication token.
- **Include Keywords** (`include_keywords`): Comma-separated keywords to restrict imported events.
- **Exclude Keywords** (`exclude_keywords`): Skip events containing these terms.
- **Date Range** (`date_range`): Controls how far ahead the handler scans.

## Data Mapping

- **Event Details**: Maps title, description, start/end dates, times, and search-friendly fields to Event Details block attributes.
- **Venue Metadata**: Name, street address, city/state/zip/country, and phone/website populate the venue taxonomy via `VenueService`/`Venue_Taxonomy`, keeping term meta synced for REST endpoints and blocks.
- **Pricing & Tickets**: Ticket URLs, pricing, and availability feed the block’s pricing and offer fields.
- **Images & Media**: Banner/cover images travel through `EventEngineData` and are downloaded later by `WordPressPublishHelper` when the handler config enables `include_image`.
- **Taxonomies**: Categories/genres become taxonomy terms through `TaxonomyHandler` so badges, filters, and the Event Details block reflect the Dice FM classifications.

## Unique Capabilities

Dice FM delivers curated gig data with keyword filtering, city-specific scopes, and classification support straight from the public JSON API. The handler is optimized for large result sets by requesting 100 events per page yet still stopping after the first suitable event to avoid timeouts.

## Event Handoff

After extraction, `EventEngineData` stores the structured payload. `EventUpsert` merges engine data with AI parameters, runs change detection across every field, assigns venue/promoter terms via `TaxonomyHandler` using `VenueService`/`Venue_Taxonomy`, syncs `_datamachine_event_datetime`, and optionally downloads featured images through `WordPressPublishHelper`. This keeps Event Details, REST responses, and Calendar pagination consistent across imports.