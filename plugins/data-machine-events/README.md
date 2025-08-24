# Chill Events - WordPress Events Plugin

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
- **PSR-4 Autoloading:** `ChillEvents\` namespace
- **Separate Build Systems:** Calendar (webpack), Event Details (@wordpress/scripts)
- **REST API Support:** Event metadata exposed
- **WordPress Standards:** Native hooks and security practices

### Architecture
**Data Flow:** Data Machine → Event Details Block → Background Sync → Calendar Display

**Core Classes:**
- `ChillEvents\Admin` - Settings interface  
- `ChillEvents\Events\Event_Data_Manager` - Performance sync
- `ChillEvents\Events\Venues\Venue_Term_Meta` - Venue administration
- `ChillEvents\Events\Event_Duplicate_Checker` - Prevents duplicate imports

## Quick Start

### Installation
```bash
git clone https://github.com/yourusername/chill-events.git
cd chill-events
composer install
```
Upload to `/wp-content/plugins/chill-events/` and activate.

### Usage
1. **Automated Import:** Configure Data Machine plugin for Ticketmaster, Dice FM, or web scraper imports
2. **Manual Events:** Add Event post → Insert "Event Details" block → Fill event data  
3. **Display Events:** Add "Chill Events Calendar" block to any page/post
4. **Manage Venues:** Events → Venues → Add venue details

## Project Structure

```
chill-events/
├── inc/
│   ├── admin/               # Settings interface
│   ├── blocks/
│   │   ├── calendar/        # Calendar block (webpack)
│   │   └── event-details/   # Event details block (@wordpress/scripts)
│   ├── events/              # Event data management & duplicate checking
│   │   └── venues/          # Venue taxonomy
│   └── steps/               # Data Machine integration
│       ├── event-import/    # Import handlers (Ticketmaster, Dice FM, scrapers)
│       └── publish/         # Event publishing handlers
├── assets/                  # Frontend CSS (blocks handle own JS)
└── chill-events.php        # Main file with autoloader
```

## Development

**Requirements:** WordPress 6.0+, PHP 8.0+, Composer

**Setup:**
```bash
composer install
# Build blocks
cd inc/blocks/calendar && npm install && npm run build
cd ../event-details && npm install && npm run build
```

**Block Development:**
```bash
# Calendar (webpack)
cd inc/blocks/calendar
npm run start    # Development watch

# Event Details (@wordpress/scripts)  
cd inc/blocks/event-details
npm run start    # Development watch
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
// Meta fields for queries only
update_post_meta($post_id, '_chill_event_start_date_utc', $utc_date);
```

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

**REST API:**
```php
register_rest_field('chill_events', 'event_meta', array(
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