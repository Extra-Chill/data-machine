# Event_Type_Taxonomy

Closed-vocabulary `event_type` taxonomy describing what **format** an event is. Promotes the former `eventType` block attribute to a real, queryable taxonomy (data-machine-events#761).

## Overview

`eventType` used to live only as a serialized block attribute inside `post_content`. It could not be queried, filtered, counted, or browsed — filtering meant a `post_content LIKE` full scan — and its only consumer was JSON-LD `@type`.

`Event_Type_Taxonomy` replaces that with a real taxonomy carrying a **closed vocabulary**:

- Every term maps to a Schema.org `@type` through the `_schema_type` term meta value.
- The AI can only pick from the vocabulary, and an AI-supplied value can never create a term.
- The block attribute becomes derived output, not input.

## Location

`inc/Core/Event_Type_Taxonomy.php`

## Taxonomy Registration

| Property | Value |
|---|---|
| Slug | `event_type` |
| Object type | `data_machine_events` |
| `public` | `true` |
| `hierarchical` | `false` |
| `show_in_rest` | `true` |
| Rewrite slug | `event-type` |

Because the taxonomy is public, `FilterAbilities::get_taxonomies_with_counts()` picks it up automatically — it appears in the calendar filter modal with cross-filtered counts, and in `Taxonomy/Badges.php`, with no calendar code changes.

## Default vocabulary

Seeded from `EventSchemaProvider::default_event_type_vocabulary()`, which derives directly from the existing `EventSchemaProvider::EVENT_TYPES` const:

`Event`, `MusicEvent`, `Festival`, `ComedyEvent`, `DanceEvent`, `TheaterEvent`, `SportsEvent`, `ExhibitionEvent`

Each term is **self-mapped**: its `_schema_type` term meta equals its own name. `Event` is the declared default. Out of the box this gives a working, standards-correct 8-term taxonomy with no consumer configuration.

Seeding is idempotent and guarded by a hash of the resolved vocabulary stored in the `data_machine_events_event_type_seeded` option, so it only runs when the vocabulary actually changes.

## Replacing the vocabulary

```php
add_filter(
    'data_machine_events_event_type_vocabulary',
    function ( array $vocabulary ): array {
        return array(
            array( 'name' => 'Live Music', 'schema_type' => 'MusicEvent', 'default' => true ),
            array( 'name' => 'Comedy',     'schema_type' => 'ComedyEvent' ),
        );
    }
);
```

Accepted entry shapes:

- `array( 'name' => ..., 'schema_type' => ..., 'slug' => ..., 'default' => ... )` — `slug` defaults to `sanitize_title( name )`; the first entry becomes the default when none is flagged.
- `'Term Name' => 'SchemaType'` — name keyed to its Schema.org `@type`.

Consumers may express editorial event formats that have no Schema.org equivalent, as long as each entry still maps to a valid `@type`. **Site-specific editorial vocabulary belongs in the consumer, never in this plugin** (see #437 / #478); this repo declares Schema.org terms only.

## Closed-vocabulary enforcement

Data Machine's generic `TaxonomyHandler` creates unknown AI values by default. Two core hooks (DM 0.131.0) close that path:

### `datamachine_taxonomy_tool_parameter`

Rewrites the AI tool schema for `event_type`:

- `type` becomes `string` (not an array of free-form terms) and `items` is removed — `event_type` is single-valued.
- `enum` is derived from the resolved vocabulary, so the AI is structurally unable to emit an outside value.
- The description names every allowed value and states that inventing one is forbidden.

### `datamachine_taxonomy_assign_value`

Runs **before** `processTerms()` / `findOrCreateTerm()`:

- A recognized value resolves to that vocabulary term's existing ID.
- An unrecognized value logs a warning and resolves to the declared default term's ID — it never reaches `wp_insert_term()`.
- An empty value returns `''`, which DM treats as "skip assignment silently".

Because the enum is derived from the vocabulary and the assign filter is a second gate, slop is structurally impossible rather than prompt-dependent.

## Schema.org `@type` derivation

`EventSchemaProvider::generateSchemaOrg()` resolves `@type` in this order:

1. The `_schema_type` meta on the event's assigned `event_type` term (authoritative).
2. The legacy `eventType` block attribute, resolved *through the vocabulary* — so an out-of-vocabulary legacy value cannot leak into JSON-LD.
3. `Event`.

The block attribute written by `EventBlockContentBuilder` is likewise the resolved Schema.org `@type`, never an editorial label.

## Key methods

### `register(): void`
Registers the taxonomy, hooks the two enforcement filters, and seeds the vocabulary when it changed.

### `get_vocabulary(): array`
Returns the resolved vocabulary as normalized entries (`name`, `slug`, `schema_type`, `default`).

### `get_vocabulary_names(): string[]`
The term names exposed to the AI as the closed `enum`.

### `resolve_schema_type( mixed $value ): string`
Resolves any value (term name, slug, Schema.org type, term ID, or single-element array) to the Schema.org `@type` it represents, or `''`. This is the validation primitive that replaced the raw `in_array( $value, EVENT_TYPES )` checks in `EventUpdateAbilities` and `EventUpsertValidator`.

### `is_valid_value( mixed $value ): bool`
Whether a value belongs to the active vocabulary.

### `resolve_term_id( mixed $value, bool $fallback_to_default = true ): int`
Resolves a value to an **existing** term ID. Never creates a term for unrecognized input: it either falls back to the default entry or returns `0`.

### `get_schema_type_for_post( int $post_id ): string`
Reads the Schema.org `@type` the event's assigned term maps to.

### `seed_vocabulary( ?array $vocabulary = null ): array`
Creates any missing vocabulary terms and (re)stamps their `_schema_type`. The only path in this class that may create a term.

## Scope boundary

`event_type` describes event **format** only. It deliberately does not distinguish a duo on a coffee-shop patio from a headliner in a 2,500-cap room — that is a venue property (Extra-Chill/extrachill-events#801).
