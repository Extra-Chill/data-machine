# Data Machine Events

WordPress events plugin with AI-powered import automation and a block-first calendar system.

## What It Does

Data Machine Events brings full-featured event management to WordPress:

- **Smart event imports** — Pull from 15+ sources with automatic deduplication
- **Visual calendar** — Responsive carousel with filtering, search, and date navigation
- **Venue management** — Geocoding, maps, and automatic venue creation
- **Block-first design** — Native Gutenberg blocks for events and calendars

## How It Works

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   IMPORT    │ ──▶ │   PROCESS   │ ──▶ │   DISPLAY   │
│ Ticketmaster│     │  Normalize, │     │  Calendar,  │
│ Eventbrite  │     │  Dedupe,    │     │  Details,   │
│ ICS, RSS... │     │  Upsert     │     │  Maps       │
└─────────────┘     └─────────────┘     └─────────────┘
```

**Pipelines** fetch events from external sources. **Handlers** normalize the data. **Blocks** display the results.

## Import Sources

| Category | Handlers |
|----------|----------|
| **Ticketing** | Ticketmaster, Eventbrite, AEG/AXS, Prekindle, Freshtix |
| **Calendars** | ICS, Google Calendar (Embedded), Timely |
| **Platforms** | Dice FM, DoStuff Media, OpenDate, Red Rocks |
| **Universal** | Web Scraper (auto-detects 10+ site types) |

The Universal Web Scraper handles Squarespace, Wix, WordPress (Tribe), Bandzoogle, and more with automatic pagination.

## Blocks

### Calendar Block
Responsive carousel display with:
- Day grouping and time-gap separators
- Taxonomy filtering (venues, genres, etc.)
- Date range picker
- Search with debounce
- URL state for shareable filtered views

Works without JavaScript (server-rendered), enhanced when scripts load.

### Event Details Block
Rich event display with 15+ attributes:
- Dates, times, pricing
- Venue with Leaflet map integration
- Performer and organizer metadata
- Status badges and display toggles

## REST API

| Endpoint | Purpose |
|----------|---------|
| `GET /events/calendar` | Calendar data with pagination |
| `GET /events/filters` | Taxonomy terms with counts |
| `GET /events/venues/{id}` | Venue details and coordinates |
| `POST /events/geocode/search` | OpenStreetMap venue lookup |

Full API documentation: [docs/rest-api.md](docs/rest-api.md)

## Requirements

- WordPress 6.9+
- PHP 8.2+
- Data Machine (core plugin)
- Action Scheduler

## Development

Run these commands from `plugins/data-machine-events` with Node.js 24 for the JS tooling:

```bash
# PHP dependencies
composer install --no-dev --optimize-autoloader

# Build Calendar, Event Details, and Events Map
npm ci
npm run build

# Run the block JavaScript tests
npm test
```

Each block still emits its assets under its own `inc/Blocks/<Block>/build/` directory. The shared Release workflow runs the plugin-level build and packages those assets into the plugin ZIP.

## Documentation

- [docs/](docs/) — Handler guides and feature documentation
- [docs/rest-api.md](docs/rest-api.md) — API reference
- [docs/calendar-block.md](docs/calendar-block.md) — Calendar block usage
- [docs/venue-management.md](docs/venue-management.md) — Venue features
