# Venue Tier Term Meta

Closed-vocabulary `_venue_tier` term meta describing **what kind of room** a venue is. Venue-scoped classification per data-machine-events#786: set once per venue, inherited by every event there.

## Overview

A duo on a coffee-shop patio and a headlining show at a large hall are both "events with a venue term". Event-level signals (price presence, ticketing platform, venue-name heuristics) measure which extractor ran, not the room. Concert-vs-bar-gig is a **venue** fact.

`Venue_Taxonomy` therefore carries a `tier` field (`_venue_tier` term meta) backed by a closed vocabulary:

- Values outside the resolved vocabulary sanitize to `''` (unclassified is valid).
- The AI import path structurally cannot write tier — it is human/CLI-owned.
- The calendar accepts a `venue_tier` filter param that resolves through the venue taxonomy filter path.

This mirrors the closed-vocabulary pattern of `Event_Type_Taxonomy` (#761), but tiers are **term meta values, not taxonomy terms**: there is no seeding and no `datamachine_taxonomy_*` enforcement hook surface.

## Location

`inc/Core/Venue_Taxonomy.php`

## Default vocabulary

Generic room classifications only — site-specific tier definitions belong in the consumer, never in this repo.

| Slug | Label |
|---|---|
| `bar_gig` | Bar / restaurant gig — music incidental to the business |
| `listening_room` | Small, music-first, seated/attentive |
| `club` | Standing music venue, ticketed shows |
| `concert_hall` | Large ticketed room |
| `amphitheater` | Outdoor large-format |

Plus an unset / empty state meaning "unclassified".

## Replacing the vocabulary

```php
add_filter(
    'data_machine_events_venue_tier_vocabulary',
    function ( $vocabulary ): array {
        return array(
            'coffee_shop' => 'Coffee shop with occasional music',
            array( 'slug' => 'arena', 'label' => 'Arena' ),
        );
    }
);
```

Accepted entry shapes: `'slug' => 'Label'` pairs, or array entries with `slug`/`label` keys (a string key provides the slug when `slug` is absent). Entries are deduped by slug; malformed entries are dropped; an empty filtered vocabulary falls back to the defaults.

## Storage and sanitization

`register_term_meta( 'venue', '_venue_tier', ... )` with a sanitize callback that resolves the value against the active vocabulary:

- Known slug (any case) or known label → canonical slug.
- Anything else → `''` (unclassified).

`tier` is a canonical field in `Venue_Taxonomy::$meta_fields`, so `get_venue_data()`, `VenueProfileMutations::updateSystem()`, the admin save handler, and the venue revision fingerprint all pick it up through the existing structures.

## Admin UI

The venue add/edit term screens render a `<select>` over the resolved vocabulary with an **Unclassified** empty option (the default state). The select always submits, so choosing Unclassified clears the value through the normal save path.

## AI lockout (venue tier is human/CLI-owned)

Three structural layers, none of which require runtime branching:

1. `VenueParameterProvider::$TOOL_PARAMETERS` / `$PARAMETER_TO_META_MAP` contain no `venueTier`/`tier` entry — the model is never offered the parameter, and `extractFromParameters()` / `mergeEngineOverParameters()` are driven by that map.
2. `EventTaxonomyAssigner::assignVenueTaxonomy()` builds its venue metadata from an explicit field whitelist without tier.
3. `Venue_Taxonomy::find_or_create_venue()` — the choke point every AI/scraper venue write funnels through — unconditionally strips `tier` from `$venue_data` before matching or merging.

Human/CLI writes go through `Venue_Taxonomy::update_venue_meta()` directly (or the admin term screen). Out-of-vocabulary values still sanitize to `''` at the meta layer.

## Calendar `venue_tier` filter dimension

Term meta cannot ride the `tax_filter[taxonomy][]=term_id` wire contract (tier resolves to venue term IDs at query time and would be stale if precomputed), so it travels as its own scalar param:

- Wire: `?venue_tier=club` on the calendar block render path, `venue_tier` on `GET /wp-json/datamachine/v1/events/calendar`, and `venue_tier` on the `query-events` / `get-calendar-page` abilities.
- Query: `EventDateQueryAbilities::applyVenueTierConstraint()` resolves the tier to venue term IDs (`Venue_Taxonomy::get_venue_term_ids_by_tier()`) and merges them into the existing `venue` taxonomy filter (intersecting with an explicit venue term selection when both are present). Date buckets, count SQL, bounded candidate queries, and the grouped filter-count SQL all consume the merged map, so list/count never drift.
- Failure: an unknown tier, or a known tier no venue carries yet, fails closed to an impossible term list (mirroring the empty-geo handling).

### Known UI gap

The calendar filter modal renders taxonomy term controls only. Tier is term meta, not a taxonomy, so it cannot appear in the modal without a second filter framework — which #786 explicitly rules out. A hand-written `?venue_tier=` URL filter is honored on server-rendered loads and REST requests, but the JS filter-state layer does not preserve unknown URL params across modal interactions, so a client-side filter change can drop the constraint. Consumer UI (and the JS passthrough, if wanted) is follow-up work; the query-side contract is complete and stable.

## Read exposure

- `Venue_Taxonomy::get_venue_data()` returns `tier`.
- Calendar REST data envelope: `venue.tier` on serialized venue objects (`DATA_SCHEMA_VERSION` bumped to 5; contract fixture regenerated).
- Venue map ability payloads: `tier` on every venue entry.
