# Data Machine Events - WordPress Events Plugin

Frontend-focused WordPress events plugin with **block-first architecture**. Integrates with Data Machine plugin for automated event imports while providing elegant event display and management through Gutenberg blocks.

## Features

### Events
- **Block-First Architecture:** Event data managed via `Event Details` block (single source of truth)
- **Calendar Display:** Gutenberg block with filtering and search
- **Performance Optimized:** Background sync to meta fields for efficient queries
- **Data Machine Ready:** Works with Data Machine plugin for automated imports

### Venues
- **Rich Taxonomy:** Address, phone, website, capacity data
- **Admin Interface:** Custom term meta management
- **SEO Ready:** Archive pages and structured data

### Development
- **PSR-4 Autoloading:** `DmEvents\` namespace
- **Separate Build Systems:** Calendar (webpack), Event Details (@wordpress/scripts)
- **REST API Support:** Event metadata exposed
- **WordPress Standards:** Native hooks and security practices

### Architecture
**Data Flow:** Data Machine → Event Details Block → Background Sync → Calendar Display

**Core Classes:**
- `DmEvents\Admin` - Settings interface  
- `DmEvents\Admin\Status_Detection` - Data Machine status detection
- `DmEvents\Events\Event_Data_Manager` - Block-to-meta sync for performance
- `DmEvents\Events\Venues\Venue_Term_Meta` - Venue administration with full CRUD
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsPublisher` - AI-driven event creation
- `DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsSettings` - Publisher configuration
- `DmEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterAuth` - API key authentication

## Quick Start

### Installation
```bash
# Clone repository (replace with actual repository URL)
git clone https://github.com/chubes4/dm-events.git
cd dm-events
composer install
```
Upload to `/wp-content/plugins/dm-events/` and activate.

### Usage
1. **Automated Import:** Configure Data Machine plugin for Ticketmaster (API key), Dice FM, or web scraper imports
2. **AI-Driven Publishing:** Data Machine AI creates events with descriptions, venues, and taxonomy assignments
3. **Manual Events:** Add Event post → Insert "Event Details" block → Fill event data  
4. **Display Events:** Add "Data Machine Events Calendar" block to any page/post
5. **Manage Venues:** Events → Venues → Add venue details (auto-populated via AI imports)

## Project Structure

```
dm-events/
├── dm-events.php            # Main plugin file with PSR-4 autoloader
├── inc/
│   ├── admin/               # Settings interface and status detection
│   ├── blocks/
│   │   ├── calendar/        # Calendar block (webpack)
│   │   └── event-details/   # Event details block (@wordpress/scripts)
│   ├── events/              # Event data management
│   │   └── venues/          # Venue taxonomy with comprehensive term meta
│   └── steps/               # Data Machine integration
│       ├── event-import/    # Import handlers (Ticketmaster, Dice FM, scrapers)
│       └── publish/         # AI-driven event publishing handlers
├── assets/
│   ├── css/                 # Admin and frontend CSS
│   └── js/                  # Admin JavaScript
└── composer.json            # PHP dependencies
```

## Development

**Requirements:** WordPress 6.0+, PHP 8.0+, Composer, Node.js (for blocks)

**Setup:**
```bash
composer install
# Build blocks
cd inc/blocks/calendar && npm install && npm run build
cd ../event-details && npm install && wp-scripts build
```

**Production Build:**
```bash
# Create optimized package with versioned .zip file in /dist directory
# VSCode tasks.json file required for development workflow
```

**Block Development:**
```bash
# Calendar (webpack)
cd inc/blocks/calendar
npm run start    # Development watch

# Event Details (@wordpress/scripts)  
cd inc/blocks/event-details
wp-scripts start  # Development watch
npm run lint:js && npm run lint:css
```

### Code Examples

**Block Attributes (Primary Data):**
```json
{
  "startDate": "2025-09-30",
  "startTime": "19:00", 
  "venue": "The Charleston Music Hall",
  "artist": "Mary Chapin Carpenter"
}
```

**Performance Sync:**
```php
// Performance meta field for calendar queries
update_post_meta($post_id, '_dm_event_start_date_utc', $utc_date);

// REST API meta fields (synced automatically)
update_post_meta($post_id, '_dm_event_venue_name', $venue_name);
update_post_meta($post_id, '_dm_event_artist_name', $artist_name);
```

## AI Integration

**Event Creation Flow:**
1. Data Machine imports event data from sources (Ticketmaster, Dice FM, scrapers)
2. AI generates event descriptions and handles taxonomy assignments
3. DmEventsPublisher creates WordPress posts with Event Details blocks
4. Event_Data_Manager syncs block data to meta fields for performance

**AI Publisher Features:**
- **Automatic Venue Creation:** AI creates venue taxonomy terms with comprehensive meta (10 fields: address, phone, website, etc.)
- **Smart Descriptions:** AI generates engaging event descriptions from import data
- **Taxonomy Management:** Configurable AI vs pre-selected term assignments
- **Status Detection:** Integrated Data Machine status monitoring
- **Security:** Full WordPress security compliance (nonces, capabilities, sanitization)

## Technical Details

**Block Registration:**
```php
register_block_type($path, array(
    'render_callback' => array($this, 'render_block'),
    'attributes' => array(
        'startDate' => array('type' => 'string'),
        'venue' => array('type' => 'string')
    )
));
```

**AI Event Creation:**
```php
// DmEventsPublisher handles AI tool calls
$post_id = $this->create_event_post($parameters);
$this->handle_taxonomy_assignments($post_id, $parameters, $tool_def);
Event_Data_Manager::sync_event_meta($post_id);
```

**REST API:**
```php
register_rest_field('dm_events', 'event_meta', array(
    'get_callback' => array($this, 'get_event_meta_for_rest')
));
```

## Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/name`)
3. Commit changes (`git commit -m 'Add feature'`)
4. Push to branch (`git push origin feature/name`) 
5. Open Pull Request

## License

GPL v2 or later - see [LICENSE](LICENSE) file.

## Support

- GitHub Issues
- Contact: [chubes.net](https://chubes.net) 