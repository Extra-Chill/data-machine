# Progressive Calendar Rendering

## Problem

The calendar homepage server-renders ALL events for a page's date range in a single PHP response. At current scale (25,540 upcoming events), page 1 covers 5 days containing **1,499 events**, producing a **4.9MB HTML response** with **9,500+ DOM nodes**. Each event requires full hydration (WP_Query, post meta, taxonomy badges, display vars) server-side.

The existing "lazy render" system is cosmetic — it serializes complete event JSON into `data-event-json` DOM attributes and swaps skeletons on scroll. The server still does 100% of the work. The 4.9MB payload contains all the JSON blobs.

## Solution: Progressive Day-Level Loading

Render date group **shells** (headers + event counts) server-side. Load each day's events client-side via REST as the user scrolls to it. The day is the natural loading unit — each date group is a self-contained visual section identified by `data-date`.

### Architecture

```
Server renders (fast, small HTML):
┌──────────────────────────────────────────────┐
│ Filter bar                                    │
│ ┌──────────────────────────────────────────┐ │
│ │ Saturday, March 22nd — 345 events        │ │
│ │ [first 5 events fully rendered]          │ │
│ │ [loading placeholder for remaining 340]  │ │
│ └──────────────────────────────────────────┘ │
│ ┌──────────────────────────────────────────┐ │
│ │ Sunday, March 23rd — 186 events          │ │
│ │ [empty container, data-date="2026-03-23"]│ │
│ └──────────────────────────────────────────┘ │
│ ┌──────────────────────────────────────────┐ │
│ │ Monday, March 24th — 270 events          │ │
│ │ [empty container, data-date="2026-03-24"]│ │
│ └──────────────────────────────────────────┘ │
│ ... more date groups ...                     │
│ Pagination / Navigation                      │
└──────────────────────────────────────────────┘

Client loads (per day, on scroll):
  IntersectionObserver detects date group entering viewport
  → GET /datamachine/v1/events/calendar?date_start=2026-03-23&date_end=2026-03-23&archive_taxonomy=...
  → Response contains rendered HTML for that day's events
  → innerHTML swap into the date group container
```

## What Already Exists

Everything needed for this is already built — it just needs to be wired together differently.

### Server-side (PHP)

| Component | File | Status |
|-----------|------|--------|
| `PageBoundary` | `Pagination/PageBoundary.php` | **Exists.** Computes date boundaries per page. Returns `events_per_date` counts. |
| `CalendarAbilities` | `Abilities/CalendarAbilities.php` | **Exists.** Accepts `date_start`/`date_end` params. Setting both to same date = single day query. |
| `EventQueryBuilder` | `Query/EventQueryBuilder.php` | **Exists.** Handles date range, taxonomy, geo, search filters. |
| REST endpoint | `Api/Controllers/Calendar.php` | **Exists.** Thin wrapper: `GET /datamachine/v1/events/calendar?date_start=X&date_end=X`. Returns rendered HTML. |
| `EventRenderer` | `Display/EventRenderer.php` | **Exists.** Renders date groups with lazy placeholder threshold (`LAZY_RENDER_THRESHOLD = 5`). |
| `date-group.php` template | `templates/date-group.php` | **Exists.** Renders date header with `data-date` attribute and event count. |

### Client-side (TypeScript)

| Component | File | Status |
|-----------|------|--------|
| `api-client.ts` | `src/modules/api-client.ts` | **Exists.** `fetchCalendarEvents()` calls REST endpoint, handles DOM updates for content, pagination, counter, navigation. |
| `lazy-render.ts` | `src/modules/lazy-render.ts` | **Exists.** IntersectionObserver pattern for `.data-machine-events-wrapper` elements. Currently hydrates JSON from DOM — needs to fetch from REST instead. |
| `geo-sync.ts` | `src/modules/geo-sync.ts` | **Exists.** Already fetches full calendar pages via REST on map interactions. Proof that the REST→DOM flow works. |
| Types | `src/types.ts` | **Exists.** `CalendarResponse`, `ArchiveContext`, etc. |

## Implementation Plan

### Phase 1: Server — Render shells with deferred day containers

**File: `Display/EventRenderer.php`**

Change `render_date_groups()` to accept a render mode:

```php
const RENDER_MODE_FULL = 'full';         // Current behavior (all events)
const RENDER_MODE_PROGRESSIVE = 'progressive';  // First day full, rest deferred

public static function render_date_groups(
    array $paged_date_groups,
    array $gaps_detected = array(),
    bool $include_gaps = true,
    string $render_mode = self::RENDER_MODE_FULL
): string
```

In `RENDER_MODE_PROGRESSIVE`:
- **First date group**: render normally (full events with lazy threshold). This is the above-the-fold content — visible immediately, good for SEO.
- **Subsequent date groups**: render the date header + event count, but output an empty `data-machine-events-wrapper` container with `data-deferred="true"` and a loading skeleton. No event data serialized.

```php
// Deferred day container (no events rendered)
<div class="data-machine-events-wrapper" data-deferred="true" data-event-count="<?= $events_count ?>">
    <div class="data-machine-deferred-skeleton">
        <?php for ($i = 0; $i < min($events_count, 5); $i++) : ?>
            <div class="data-machine-skeleton-item">
                <div class="data-machine-skeleton-title"></div>
                <div class="data-machine-skeleton-meta"></div>
            </div>
        <?php endfor; ?>
    </div>
</div>
```

**File: `render.php` (Calendar block)**

Pass `render_mode` to `CalendarAbilities` based on context. Homepage and high-event-count pages get progressive mode. Small pages (< 50 events) stay full.

**File: `CalendarAbilities.php`**

When `render_mode = progressive`, the ability still runs `PageBoundary` (to get date boundaries and event counts per date) but only runs `WP_Query` + hydration for the first day. The `events_per_date` array is passed to the renderer so it can show accurate counts in deferred headers.

New response field:
```php
$result['deferred_dates'] = array_slice($unique_dates_in_page, 1); // Dates to load client-side
```

### Phase 2: Client — Fetch days on scroll

**File: `src/modules/lazy-render.ts`** (or new `src/modules/day-loader.ts`)

Replace the current placeholder hydration with day-level fetching:

```typescript
export function initDayLoader(calendar: HTMLElement): void {
    const archiveContext = getArchiveContext(calendar);

    const deferredWrappers = calendar.querySelectorAll<HTMLElement>(
        '.data-machine-events-wrapper[data-deferred="true"]'
    );

    if (!deferredWrappers.length) return;

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    loadDayEvents(entry.target as HTMLElement, archiveContext);
                    observer.unobserve(entry.target);
                }
            });
        },
        { rootMargin: '400px' }  // Start loading 400px before visible
    );

    deferredWrappers.forEach((wrapper) => observer.observe(wrapper));
}

async function loadDayEvents(
    wrapper: HTMLElement,
    archiveContext: Partial<ArchiveContext>
): Promise<void> {
    const dateGroup = wrapper.closest<HTMLElement>('.data-machine-date-group');
    if (!dateGroup) return;

    const date = dateGroup.dataset.date;
    if (!date) return;

    const params = new URLSearchParams();
    params.set('date_start', date);
    params.set('date_end', date);

    // Preserve current filters from URL
    const urlParams = new URLSearchParams(window.location.search);
    for (const [key, value] of urlParams.entries()) {
        if (['event_search', 'scope', 'past'].includes(key)) {
            params.set(key, value);
        }
        if (key.startsWith('tax_filter')) {
            params.append(key, value);
        }
    }

    if (archiveContext.taxonomy && archiveContext.term_id) {
        params.set('archive_taxonomy', archiveContext.taxonomy);
        params.set('archive_term_id', String(archiveContext.term_id));
    }

    try {
        const response = await fetch(
            `/wp-json/datamachine/v1/events/calendar?${params.toString()}`
        );
        const data = await response.json();

        if (data.success && data.html) {
            // The response HTML contains date-group + wrapper for a single day.
            // Extract just the events wrapper content.
            const temp = document.createElement('div');
            temp.innerHTML = data.html;
            const eventsWrapper = temp.querySelector('.data-machine-events-wrapper');

            if (eventsWrapper) {
                wrapper.innerHTML = eventsWrapper.innerHTML;
                wrapper.removeAttribute('data-deferred');
                // Re-init lazy render for placeholders within this day
                initLazyRender(wrapper.closest('.data-machine-events-calendar')!);
            }
        }
    } catch (error) {
        wrapper.innerHTML = '<p class="data-machine-events-error">Failed to load events</p>';
    }
}
```

### Phase 3: REST endpoint — single-day response optimization

**File: `Api/Controllers/Calendar.php`**

The existing endpoint already works for single-day queries. However, it currently also runs `PageBoundary` (pagination computation) even for a single day, which is wasted work.

Add a `mode=day` parameter that skips pagination computation:

```php
if ('day' === $request->get_param('mode')) {
    // Skip PageBoundary — we know the exact date range
    // Skip event_counts — not needed for day loading
    // Only run WP_Query + hydrate + render
}
```

This would reduce a day-fetch REST call from ~200ms to ~50ms.

### Phase 4: Batching (optional optimization)

Instead of one REST call per day, batch adjacent days. The `api-client.ts` could detect 2-3 deferred days near the viewport and fetch them in a single call with `date_start=2026-03-23&date_end=2026-03-25`.

The REST endpoint already supports date ranges, so this works out of the box. The client splits the response HTML by `data-machine-date-group` elements and inserts each into its corresponding container.

## Payload Impact

| Metric | Current | After Phase 1+2 |
|--------|---------|-----------------|
| **Initial HTML** | 4.9 MB (1,499 events) | ~150 KB (1 day + 4 skeleton shells) |
| **DOM nodes** | 9,500+ | ~1,500 |
| **Server work** | Hydrate 1,499 events | Hydrate ~345 events (first day only) |
| **Total data loaded** | 4.9 MB on page load | ~150 KB initial + ~100 KB per day as user scrolls |
| **Perceived performance** | All or nothing (4.9 MB TTFB) | First day visible in <200ms, rest progressive |

## SEO Considerations

- **First day's events are fully server-rendered** — Googlebot sees real `<a href>` links to event pages for crawl discovery.
- Individual event pages (`/events/artist-at-venue/`) are the indexed content, not the calendar listing. The calendar is a navigation tool.
- Google renders JS and would eventually see deferred days too, but the first day provides sufficient internal link structure.
- The current 4.9MB / 9,500-node page actively **hurts** Core Web Vitals (LCP, DOM Size audit, TBT from hydrating 1,499 JSON blobs).

## Migration Path

1. **Phase 1**: Ship behind a feature flag (`progressive_render` setting or filter). Default off.
2. **Phase 2**: Enable on events homepage first. Monitor REST endpoint load.
3. **Phase 3**: Enable globally once validated. Remove old full-render code path if desired, or keep as fallback.
4. **Phase 4**: Batching optimization based on real usage patterns.

## Files to Change

### data-machine-events plugin

| File | Change |
|------|--------|
| `Display/EventRenderer.php` | Add `RENDER_MODE_PROGRESSIVE`, render shells for deferred days |
| `Abilities/CalendarAbilities.php` | Support partial hydration, return `deferred_dates` |
| `render.php` | Pass render mode, emit deferred date metadata |
| `Api/Controllers/Calendar.php` | Add `mode=day` fast path |
| `templates/date-group.php` | No change needed (already has `data-date` and count) |
| `src/modules/day-loader.ts` | New module: IntersectionObserver → REST fetch → DOM insert |
| `src/modules/lazy-render.ts` | Unchanged (still handles within-day placeholder hydration) |
| `src/frontend.ts` | Init `dayLoader` alongside existing modules |
| `src/types.ts` | Add `DayLoaderConfig` type |

### No changes needed

- `PageBoundary.php` — still computes date boundaries, now also provides `events_per_date` for shell rendering
- `EventQueryBuilder.php` — no changes, used by REST endpoint for day queries
- `DateGrouper.php` — no changes
- `EventHydrator.php` — no changes
- `filter-modal.ts`, `date-picker.ts`, `navigation.ts` — no changes
- `api-client.ts` — the day loader uses `fetch()` directly for simplicity, but could use this module
