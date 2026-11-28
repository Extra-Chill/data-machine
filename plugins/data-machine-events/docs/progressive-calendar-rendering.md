# Progressive Calendar Rendering

The initial PHP render uses date-group templates. On dense pages it can render the first day with cards and later days as deferred shells. Initial event placeholders carry display data for the same card renderer used by the client.

The day loader prefetches deferred days in display order. It requests structured calendar data with matching `date_start` and `date_end` and preserves the calendar's current query constraints. An intersection observer reveals the date's cards when needed; requests are canceled when the initial view is replaced. Failed days offer an inline retry. Carousel updates are scoped to the affected date group.

After the initial render, filters, map updates, month navigation and Load More consume the [calendar REST schema](calendar-data-schema.md) and render from data. They do not fetch PHP templates or restart the initial lazy and deferred-day loaders.
