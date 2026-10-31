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
- InnerBlocks content extracts to plain text for Schema.org `description` field via `wp_strip_all_tags()`, ensuring HTML markup doesn't contaminate structured data while preserving the description text for search engines.
- Event content renders using block markup plus shared root CSS tokens from `inc/Blocks/root.css`, guaranteeing consistent spacing, typography, and color tokens across Calendar and Event Details blocks.

## Structured Data & Maps

- `EventSchemaProvider` merges block attributes with venue metadata to generate Schema.org JSON-LD that accompanies block rendering and REST responses.
- Event dates are synced to the `datamachine_event_dates` table in `inc/Core/event-dates-sync.php`, keeping calendar queries performant, powering schema fallbacks, and enabling day-based pagination and REST filtering. Use global `datamachine_get_event_dates( $post_id )` to read.
- Leaflet assets (`leaflet.css`, `leaflet.js`, `assets/js/venue-map.js`) load on event detail views via `enqueue_root_styles()` whenever the block or a `data_machine_events` post renders, so venue maps always display with consistent markers.

## Tense-Aware Rendering

The block derives an event's timing state once per render — `'upcoming'`, `'ongoing'`, or `'past'` — via the public `data_machine_events_get_timing( $post_id )` helper (same source-of-truth logic as the calendar's upcoming/past SQL filters). That single fact drives two things:

- **Its own CTAs adapt to tense.** On a `past` event the block's ticket / event-link button and the Add-to-Calendar dropdown are suppressed by default — buying tickets to or calendaring a finished show are dead actions. Both defaults are filterable: `data_machine_events_show_ticket_button` and `data_machine_events_show_add_to_calendar` each receive `(bool $show, int $post_id, string $timing)` and default to `false` on `past`. A site that overrides the ticket button back on gets a `ticket-button--past` modifier class for de-emphasis styling.
- **Consumers receive the timing without re-deriving it.** The `data_machine_events_action_buttons` and `data_machine_events_after_price_display` actions both pass `$timing` as their final argument, so downstream buttons (share, RSVP, and any consumer-supplied action) can compose tense-aware UI without re-querying the event-dates table. The argument is additive — callbacks registered with the old two-/one-arg signatures keep working unchanged.

See `docs/integration-api.md` for the full filter/action signatures and the helper contract.

## Venue & Taxonomy Integration

- Venue metadata lives in the `venue` taxonomy (address, city, state, zip, country, phone, website, capacity, coordinates) and surfaces across the REST API and Event Details block; `Venue_Taxonomy` and `VenueService` ensure find-or-create workflows keep term meta complete.
- `venue` and `promoter` relationships auto-sync from block attributes to taxonomy terms during imports and manual edits, so lists, filters, and badges always reflect the latest data.
- Additional metadata is exposed via REST endpoints for venue editors, and shared design tokens from `root.css` keep the block visually consistent with the Calendar block.
