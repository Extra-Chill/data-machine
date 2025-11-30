# Event Details Block

Comprehensive event data management with block-first architecture for WordPress events.

## Overview

The Event Details block serves as the single source of truth for all event data in Data Machine Events. It provides 15+ attributes for complete event information management and supports InnerBlocks for rich content editing.

## Features

### Rich Event Data Model
- **Dates & Times**: startDate, endDate, startTime, endTime
- **Venue Reference**: venue (name), address (display fallback)
- **Pricing**: price, priceCurrency, offerAvailability
- **People**: performer, performerType, organizer, organizerType, organizerUrl
- **Event Status**: eventStatus, previousStartDate
- **Display Controls**: showVenue, showPrice, showTicketLink

> **Note**: Venue metadata (city, state, zip, phone, website, coordinates, capacity) is stored in the **Venue Taxonomy**, not block attributes. The venue taxonomy is the single source of truth for all venue details.

### InnerBlocks Support
- Add rich content, images, galleries, and custom layouts within events
- Full WordPress block editor compatibility
- Content renders on frontend with proper styling
- Fixed content persistence through save/edit cycles

### Schema Generation
- Automatic Google Event JSON-LD structured data
- SEO-friendly markup for search engines
- Combines block attributes with venue taxonomy data

## Usage

1. **Create Event**: Add new Event post → Insert "Event Details" block
2. **Fill Event Data**: Complete all relevant attributes in block sidebar
3. **Add Rich Content**: Use InnerBlocks to add descriptions, images, and details
4. **Configure Display**: Toggle venue, price, and ticket link visibility
5. **Publish**: Event automatically generates structured data and venue maps

## Display Options

The block provides flexible display controls:
- **Show Venue**: Display venue information and map
- **Show Price**: Show ticket pricing information  
- **Show Ticket Link**: Display purchase ticket button

## Integration

### Theme Compatibility
- Uses theme's `single.php` template for event pages
- Block handles all data rendering and presentation
- Themes control layout while block provides content

### Structured Data
- Google Event schema automatically generated
- Venue data combined with event attributes
- Enhanced SEO and search appearance

## Attributes Reference

### Date & Time Attributes
- `startDate`: Event start date (YYYY-MM-DD format)
- `endDate`: Event end date (YYYY-MM-DD format) 
- `startTime`: Event start time (HH:MM format)
- `endTime`: Event end time (HH:MM format)

### Venue Attributes
- `venue`: Venue name (links to venue taxonomy term)
- `address`: Full venue address (display fallback)

> **Venue Taxonomy Fields**: All other venue metadata (city, state, zip, country, coordinates, capacity, phone, website) is stored in the venue taxonomy term meta. Edit venue details via **Events → Venues** in the WordPress admin.

### Pricing Attributes
- `price`: Ticket price
- `priceCurrency`: Currency code (USD, EUR, etc.)
- `offerAvailability`: Availability status (InStock, SoldOut, etc.)

### People Attributes
- `performer`: Performer name
- `performerType`: Performer type (MusicGroup, Person, etc.)
- `organizer`: Event organizer name
- `organizerType`: Organizer type (Organization, Person, etc.)
- `organizerUrl`: Organizer website URL

### Status Attributes
- `eventStatus`: Event status (EventScheduled, EventCancelled, etc.)
- `previousStartDate`: Original date for rescheduled events

### Display Control Attributes
- `showVenue`: Boolean to show/hide venue info
- `showPrice`: Boolean to show/hide pricing
- `showTicketLink`: Boolean to show/hide ticket button

## Developer Notes

The Event Details block integrates with:
- **Venue Taxonomy**: Single source of truth for all venue metadata (city, state, zip, phone, website, coordinates, capacity)
- **Schema Generator**: JSON-LD structured data combining block attributes with venue taxonomy data
- **Meta Storage**: Background sync for performance (`_datamachine_event_datetime`)
- **REST API**: Event data available via endpoints

### Data Architecture
- **Block Attributes**: Event-specific data (dates, times, price, performer, organizer, etc.)
- **Venue Taxonomy**: Shared venue data (address details, contact info, coordinates) - editable via taxonomy term management
- **Post Meta**: `_datamachine_event_datetime` synced from block for SQL query performance