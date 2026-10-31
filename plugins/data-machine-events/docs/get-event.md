# Public event detail ability

`data-machine-events/get-event` is a public, REST-visible read ability for notification, search and deep-link navigation.

Execute through the WordPress Abilities API with `{"event_id": 123}`. Missing IDs, non-event posts, unpublished events, password-protected events and events without a usable occurrence return `event_not_found` with HTTP status 404.

The response includes the canonical event fields from `contracts/calendar-occurrence-v1.json`, plus:

- `datetime` and `end_datetime`: venue-local ISO 8601 timestamps with offsets; an absent end is an empty string.
- `occurrence`: the calendar occurrence context and display block. Detail chooses the first occurrence, including for past events, without calendar pagination or upcoming-only filtering.
- `occurrence_display`: the canonical occurrence's `display` block.
- `ticket_url`: the canonical ticket URL. Affiliate-gated tickets remain empty; use `ticket.is_affiliate` and the event ID with the existing ticket resolution flow.
- `permalink`: the event's canonical URL.
- `content_html`: rendered post content.

Venue, performer, ticket and occurrence display values are produced by the calendar serializer, not an independent detail schema. `venue` can be null. Existing calendar responses and the pinned contract artifact are unchanged.
