# Event Details Block

The Event Details block is the single source of truth for Data Machine Events. It stores every attribute that matters for a datamachine event, keeps venue and promoter taxonomies in sync, and ships metadata to progressive calendars, REST endpoints, and structured data feeds.

## Data Model & Attributes

- **Dates & Times**: `startDate`, `endDate`, `startTime`, `endTime`, `previousStartDate`, and `eventStatus` capture timeline and rescheduling states.
- **Venue & Location**: `venue`, `venueAddress`, `venueCity`, `venueState`, `venueZip`, `venueCountry`, and `venueCoordinates` map directly to venue taxonomy terms; `venue`/`promoter` taxonomies auto-sync through EventUpsert and `VenueService`.
- **Pricing & Tickets**: `price`, `priceCurrency`, `offerAvailability`, `ticketUrl`, and `ticketButtonText` cover ticket data, availability, and CTA control.
- **People & Organizers**: `performer`, `performerType`, `organizer`, `organizerType`, and `organizerUrl` describe talent and organizers.
- **Display Controls**: `showVenue`, `showPrice`, `showTicketLink`, and InnerBlocks support let editors control what appears on the frontend.

The block exposes 15+ attributes (dates, venue, price, performer/organizer metadata, display toggles) plus InnerBlocks for rich editor content, ensuring every event detail flows through a single block-based pipeline.

## InnerBlocks & Rendering

- InnerBlocks allow editors to drop Gutenberg content such as rich text, galleries, or reusable patterns inside the Event Details block while preserving schema data.
- Event content renders using block markup plus shared root CSS tokens from `inc/Blocks/root.css`, guaranteeing consistent spacing, typography, and color tokens across Calendar and Event Details blocks.

## Structured Data & Maps

- `EventSchemaProvider` merges block attributes with venue metadata to generate Schema.org JSON-LD that accompanies block rendering and REST responses.
- `_datamachine_event_datetime` meta is synced in `inc/Core/meta-storage.php`, keeping calendar queries performant and enabling day-based pagination and REST filtering.
- Leaflet assets (`leaflet.css`, `leaflet.js`, `assets/js/venue-map.js`) load on event detail views via `enqueue_root_styles()` whenever the block or a `datamachine_events` post renders, so venue maps always display with consistent markers.

## Venue & Taxonomy Integration

- Venue metadata lives in the `venue` taxonomy (address, city, state, zip, country, phone, website, capacity, coordinates) and surfaces across the REST API and Event Details block; `Venue_Taxonomy` and `VenueService` ensure find-or-create workflows keep term meta complete.
- `venue` and `promoter` relationships auto-sync from block attributes to taxonomy terms during imports and manual edits, so lists, filters, and badges always reflect the latest data.
- Additional metadata is exposed via REST endpoints for venue editors, and shared design tokens from `root.css` keep the block visually consistent with the Calendar block.
