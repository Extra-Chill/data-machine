# Chill Events - WordPress Events Plugin

A comprehensive WordPress events plugin designed as a complete replacement for bloated event plugins like Tribe Events Calendar. Built with a **block-first architecture** and modular extensibility, it provides a full events solution with native API integrations, visual import management, and a modern calendar interface.

## 🎯 Core Philosophy

**KISS, DRY, and modular extensibility** - built for the AI/customization era. We prioritize modern WordPress standards, including a block-first approach where content and data are managed directly within the Gutenberg editor for a seamless user experience.

## ✨ Key Features

### 🎪 Complete Events Solution
- **Block-First Architecture:** Event data (date, time, venue, etc.) is managed via the `Event Details` block, making it the single source of truth.
- **Full Frontend System:** Main calendar page, event displays, filtering, and search powered by Gutenberg blocks.
- **Import Modules System:** Visual admin interface for automated event imports from multiple sources.
- **Native API Integrations:** Ticketmaster, Dice FM, Eventbrite, and iCal built into core.
- **Child Theme Extensions:** Site-specific scrapers and custom data sources.
- **Tribe Events Replacement:** Complete migration tools for seamless transition

### 🔄 Import Modules System
- **Admin-Configured Modules:** Each import is a separate, configurable module
- **Data Source Selection:** Choose from core APIs or child theme data sources
- **Taxonomy Mapping:** Map imported data to existing site taxonomies
- **Global Schedule Execution:** All active modules run together on a single cron-based schedule
- **Centralized Tracking:** Single analytics dashboard for all modules
- **Responsive Design:** Mobile-optimized interface

### 🏢 Smart Venue Management
- **Venue Taxonomy:** Normalized venue data with term meta storage
- **Rich Venue Data:** Address, phone, website, capacity, coordinates
- **SEO Benefits:** Venue archive pages, structured data
- **Import Efficiency:** Avoid duplicate venue creation

### 🎨 Modern Frontend
- **Gutenberg Calendar Block:** Comprehensive event display with filtering
- **Real-time Search:** Search by event title, venue, or artist
- **Date Filtering:** Filter by current month, next month, next 3 months
- **View Toggle:** Switch between list and grid layouts

### 🏗️ Block-First Architecture
Chill Events uses a modern, block-first approach. All event-specific data (start/end times, venue, artist, price, etc.) is stored directly within the `chill-events/event-details` block.

- **Single Source of Truth:** The block's attributes are the canonical source for all event data, eliminating the need for traditional post meta boxes.
- **Seamless Editing:** Manage all event information directly in the Gutenberg editor for an intuitive workflow.
- **Optimized Performance:** A background process, `Event_Data_Manager`, automatically syncs the event's start date to a separate meta field (`_chill_event_start_date_utc`). This enables fast and efficient sorting for the calendar block without sacrificing the benefits of a block-based architecture.

## 🚀 Quick Start

### Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/yourusername/chill-events.git
   cd chill-events
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Activate the plugin:**
   - Upload to `/wp-content/plugins/chill-events/`
   - Activate through the 'Plugins' menu in WordPress

### Configuration

1. **API Setup:**
   - Go to Chill Events → API Configuration
   - Add your Ticketmaster, Eventbrite, or Dice FM API keys

2. **Create Import Modules:**
   - Go to Chill Events → Import Modules
   - Click "Create New Import Module"
   - Select data source and configure settings

3. **Add Calendar Block:**
   - Edit any page/post
   - Add the "Chill Events Calendar" block
   - Configure display settings

## 📁 Project Structure

```
chill-events/
├── assets/                    # Frontend assets
│   ├── css/                  # Stylesheets
│   └── js/                   # JavaScript files
├── includes/                 # Core plugin files
│   ├── admin/               # Admin interface
│   ├── blocks/              # Gutenberg blocks
│   ├── data-sources/        # API integrations
│   ├── events/              # Event management
│   └── utils/               # Utility classes
├── events-scraping/         # Legacy scrapers (deprecated - replaced by Import Modules system)
├── languages/               # Translation files
├── vendor/                  # Composer dependencies
└── chill-events.php        # Main plugin file
```

## 🔧 Development

### Requirements
- WordPress 6.0+
- PHP 8.0+
- Composer

### Development Setup
1. Clone the repository
2. Run `composer install`
3. Activate the plugin in WordPress
4. Configure API keys for testing

### Architecture Overview

#### **StandardizedEvent Class**
During the import process, all event data is normalized into a `StandardizedEvent` object. This ensures consistency across all data sources before the data is saved into the `Event Details` block on post creation.

```php
$event = new StandardizedEvent([
    'id' => 'G5eVZb0QUJ4cJ',
    'title' => 'Mary Chapin Carpenter / Brandy Clark',
    'start_date' => '2025-09-30T23:00:00Z',
    'venue_name' => 'The Charleston Music Hall',
    'venue_phone' => '(843) 853-2252',
    'venue_website' => 'https://charlestonmusichall.com',
    'artist_name' => 'Mary Chapin Carpenter, Brandy Clark',
    'price' => '$61.91 - $78.48',
    'ticket_url' => 'https://ticketmaster.com/event/2D006246B6995B66',
]);
```

#### **Import Modules System**
Each import module is a configurable instance that:
- Maps to any available data source (API or custom scraper)
- Configures taxonomy mappings for venue, artist, location, etc.
- Runs on a global schedule with detailed logging
- Provides real-time import status and analytics

#### **Venue Taxonomy System**
Venue data is normalized using WordPress taxonomies:
- Venue information stored as term meta
- Reused across multiple events
- Rich venue data (address, phone, website, capacity)
- SEO-friendly venue archive pages

## 📚 API Integrations

### Core APIs (Built-in)
- **Ticketmaster API:** Complete Live Nation venue network access
- **Eventbrite API:** Community and local event integration  
- **Dice FM API:** Independent venue and event coverage

### Custom Data Sources
Create site-specific scrapers in your child theme:
```php
// wp-content/themes/your-child-theme/chill-events/data-sources/scrapers/
class MyVenueScraper extends \ChillEvents\BaseDataSource {
    public function get_info() {
        return [
            'name' => 'My Venue Scraper',
            'type' => 'scraper',
            'description' => 'Scrapes events from My Venue.',
        ];
    }

    public function get_events($settings = array()) {
        // Return array of StandardizedEvent objects
    }
}
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📄 License

This project is licensed under the GPL v2 or later - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- Built with ❤️ by [Chris Huber](https://chubes.net)
- Designed as a modern alternative to Tribe Events Calendar
- Inspired by the need for lightweight, extensible event management

## 📞 Support

For support, feature requests, or bug reports:
- Create an issue on GitHub
- Contact: [chubes.net](https://chubes.net)

---

**Chill Events** - Making WordPress event management simple, powerful, and extensible. 