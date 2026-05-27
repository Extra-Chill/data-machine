# Public Integration API

Stable function and action surface for downstream plugins and themes that
consume Data Machine Events. **Consumers should target this contract — not
internal classes — for forward-compatibility.**

## Stability promise

Everything in this document is part of the supported public API. Breaking
changes are governed by semver and called out in `docs/CHANGELOG.md`.

Anything **not** in this document is internal. That includes (non-exhaustive):

- Every PHP class under `DataMachineEvents\…` (e.g. `Blocks\Calendar\…`,
  `Core\…`, `Steps\…`, `Abilities\…`).
- File paths under `inc/` and `inc/Blocks/Calendar/`.
- Block-render template files under `inc/Blocks/Calendar/templates/`.

Internal classes may be renamed, moved across namespaces, or refactored at
any time. **Do not gate consumer code on `class_exists()` against an internal
class name.** That pattern has produced repeated silent breakage in
downstream plugins (renames early-return guards without a fatal, so the
integration just stops working without any visible signal).

## How to consume the API

### 1. Gate on the loaded action, not on classes

```php
// Bad — couples to an internal class name. Renames break this silently.
if ( class_exists( '\DataMachineEvents\Blocks\Calendar\Taxonomy\Badges' ) ) {
    add_filter( 'data_machine_events_badge_classes', 'my_callback', 10, 4 );
}

// Good — gates on the documented public action.
add_action( 'data_machine_events_loaded', function () {
    add_filter( 'data_machine_events_badge_classes', 'my_callback', 10, 4 );
} );

// Also good — for code that runs after init priority 30 you can use:
if ( data_machine_events_is_loaded() ) {
    echo data_machine_events_render_taxonomy_badges( $post_id );
}
```

### 2. Call public functions, not internal classes

```php
// Bad — couples to an internal class.
$data = \DataMachineEvents\Blocks\Calendar\Data\EventHydrator::parse_event_data( $post );

// Good — uses the public function.
$data = data_machine_events_parse_event_data( $post );
```

## Public actions

### `data_machine_events_loaded`

Fired once at `init` priority 30, after post types (0), blocks (15),
taxonomies (20), and Data Machine integration (25) have registered. Consumers
should hook here to register filters or perform any setup that depends on
events infrastructure being available.

```php
add_action( 'data_machine_events_loaded', function () {
    add_filter( 'data_machine_events_badge_classes', 'my_callback', 10, 4 );
    add_filter( 'data_machine_events_excluded_taxonomies', 'my_exclude', 10, 2 );
} );
```

`did_action( 'data_machine_events_loaded' )` returns truthy from any code
running after `init` priority 30.

## Public filters

These filters are part of the supported API surface. Their names and
signatures are stable.

### Calendar block filters

| Filter | Signature | Purpose |
|---|---|---|
| `data_machine_events_badge_wrapper_classes` | `(array $classes, int $post_id)` | Modify wrapper `<div>` classes on the badge container. |
| `data_machine_events_badge_classes` | `(array $classes, string $taxonomy, WP_Term $term, int $post_id)` | Modify per-badge CSS classes. |
| `data_machine_events_excluded_taxonomies` | `(array $excluded, string $context)` | Exclude taxonomies from badge / modal display. `$context` is `'badge'` or `'modal'`. |
| `data_machine_events_more_info_button_classes` | `(array $classes)` | Modify CSS classes on the calendar card "More Info" link. |
| `data_machine_events_modal_button_classes` | `(array $classes, string $variant)` | Modify CSS classes on filter modal buttons. `$variant` is `'primary'` or `'secondary'`. |
| `data_machine_events_calendar_query_args` | `(array $args, array $params)` | Modify the WP_Query args used by the calendar block. |

### Event Details block filters

| Filter | Signature | Purpose |
|---|---|---|
| `data_machine_events_ticket_button_classes` | `(array $classes)` | Modify CSS classes on the ticket button. |
| `data_machine_events_max_occurrence_display` | `(int $max)` | Cap on multi-occurrence display rows. |
| `data_machine_events_non_ticket_price_patterns` | `(array $patterns)` | Strings that suppress the ticket button. |

### Events Map block filters

| Filter | Signature | Purpose |
|---|---|---|
| `data_machine_events_map_center` | `(array $center, array $context)` | Override the map's initial center coordinates. |
| `data_machine_events_map_user_location` | `(?array $location, array $context)` | Provide a default user location override. |
| `data_machine_events_map_show_location_search` | `(bool $show, array $context)` | Toggle the location search input. |
| `data_machine_events_map_summary` | `(string $html, array $venues, array $context)` | Inject custom summary HTML above the map. |
| `data_machine_events_map_query_args` | `(array $query_args, array $params)` | Modify the resolved venue-map query args before the database query runs. `$query_args['include_ids']` is the candidate venue term ID set (null = unrestricted, empty array = zero results, array of ints = intersected with existing filters). Mirrors `data_machine_events_calendar_query_args`. |
| `data_machine_events_map_venues` | `(array $venues, array $params)` | Modify the final venue array before it is returned to the caller. Runs after sort + cap. Consumers may mutate per-venue fields, inject `upcoming_events_at_venue` payloads from custom sources (when the built-in `include_events` + taxonomy/term gating does not apply), re-order, or remove venues. Cannot expand beyond `MAX_VENUES`. |

### Event Details / button-row actions

| Action | Signature | Purpose |
|---|---|---|
| `data_machine_events_after_price_display` | `(int $post_id, string $price)` | Render extra UI after the price line. |
| `data_machine_events_action_buttons` | `(int $post_id, string $ticket_url)` | Add buttons to the event action row (alongside the ticket button). |
| `data_machine_events_map_after_summary` | `(array $venues, array $context)` | Render extra UI after the map summary block. |

## Public functions

All functions are global (no namespace) and idempotently declared with
`function_exists()` guards.

### `data_machine_events_is_loaded(): bool`

Returns true once the `data_machine_events_loaded` action has fired.
Convenience wrapper around `did_action()`.

### `data_machine_events_parse_event_data( WP_Post $post ): ?array`

Parse and hydrate event data for a given event post. Returns the event's
block-attribute payload (start/end dates and times, venue, promoter,
performer, ticket URL, price, etc.) hydrated from the authoritative storage
layer. Returns `null` if the post has no parseable Event Details block.

```php
$data = data_machine_events_parse_event_data( $post );
if ( $data ) {
    echo esc_html( $data['startDate'] );
    echo esc_html( $data['venue'] ?? '' );
}
```

### `data_machine_events_render_taxonomy_badges( int $post_id ): string`

Render the calendar block's taxonomy badge markup for an event post. Returns
HTML; empty string when no taxonomies apply. Honors all badge filters above.

### `data_machine_events_group_by_date( array $paged_events, bool $show_past = false, string $date_start = '', string $date_end = '' ): array`

Group a list of events by date. Wraps the calendar block's date grouper so
callers can produce calendar-shaped output without importing the internal
class.

### `data_machine_events_query_events( array $params ): array`

Convenience wrapper around `EventDateQueryAbilities::executeQueryEvents()`
for callers that need a list of event posts filtered by scope, taxonomy
filters, and date range.

Pass-through parameters mirror the underlying ability:

| Param | Type | Notes |
|---|---|---|
| `scope` | string | e.g. `'upcoming'`, `'past'`. |
| `tax_filters` | array | `[ taxonomy_slug => [ term_id, … ] ]`. |
| `exclude` | int[] | Post IDs to exclude. |
| `per_page` | int | |
| `order` | string | `'ASC'` \| `'DESC'`. |
| `date_start` | string | ISO date. |
| `date_end` | string | ISO date. |

Returns `[ 'posts' => WP_Post[], 'total' => int, … ]`.

### `data_machine_events_get_venue_data( int $term_id ): ?array`

Return the structured venue data array stored against a `venue` term —
address fields, lat/lng, website, etc. Returns `null` when the term does
not exist or has no venue data. Use this instead of calling
`Venue_Taxonomy::get_venue_data()` directly.

### `data_machine_events_get_venue_address( int $term_id, ?array $venue_data = null ): string`

Return the formatted single-line address for a venue term. Pass
`$venue_data` if you already have it to avoid an extra meta read. Use this
instead of `Venue_Taxonomy::get_formatted_address()`.

### `data_machine_events_get_promoter_data( int $term_id ): ?array`

Return the structured promoter data array stored against a `promoter` term.
Use this instead of `Promoter_Taxonomy::get_promoter_data()`.

### `data_machine_events_get_event_datetime( int $post_id ): string`

Return the start datetime string for an event, or empty string when no row
exists for the post in the `datamachine_event_dates` table. Equivalent to
calling the existing `datamachine_get_event_dates()` and reading
`start_datetime`.

### Pre-existing helpers (kept stable)

These functions predate this document and remain part of the public API:

- `datamachine_get_event_dates( int $post_id ): ?object` — full event-dates
  row from the `datamachine_event_dates` table.
- `datamachine_get_event_timing( int $post_id ): string` — `'upcoming'` |
  `'ongoing'` | `'past'`.

## Public constants

| Constant | Value | Use instead of |
|---|---|---|
| `DATA_MACHINE_EVENTS_POST_TYPE` | `'data_machine_events'` | `\DataMachineEvents\Core\Event_Post_Type::POST_TYPE` |
| `DATA_MACHINE_EVENTS_VENUE_TAXONOMY` | `'venue'` | Hardcoded `'venue'` strings or `Venue_Taxonomy::TAXONOMY` references |
| `DATA_MACHINE_EVENTS_PROMOTER_TAXONOMY` | `'promoter'` | Hardcoded `'promoter'` strings or `Promoter_Taxonomy::TAXONOMY` references |
| `DATA_MACHINE_EVENTS_VERSION` | semver string | Plugin version. |

## Migration guide

Replacing legacy `class_exists()` guards in a downstream consumer:

```php
// Before — couples to an internal class.
function my_plugin_init_calendar_integration() {
    if ( ! class_exists( '\DataMachineEvents\Blocks\Calendar\Taxonomy\Badges' ) ) {
        return;
    }

    add_filter( 'data_machine_events_badge_classes', 'my_callback', 10, 4 );
}
add_action( 'init', 'my_plugin_init_calendar_integration' );

// After — gates on the public action.
add_action( 'data_machine_events_loaded', function () {
    add_filter( 'data_machine_events_badge_classes', 'my_callback', 10, 4 );
} );
```

```php
// Before — direct internal-class call.
if ( class_exists( '\DataMachineEvents\Blocks\Calendar\Data\EventHydrator' ) ) {
    $event_data = \DataMachineEvents\Blocks\Calendar\Data\EventHydrator::parse_event_data( $post );
}

// After — public function.
$event_data = data_machine_events_parse_event_data( $post );
```
