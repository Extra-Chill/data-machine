# Event Details Block

Comprehensive event data management with block-first architecture for WordPress events.

## Overview

The Event Details block serves as the single source of truth for all event data in Data Machine Events. It provides 15+ attributes for complete event information management and supports InnerBlocks for rich content editing.

## Features

### Rich Event Data Model
- **Dates & Times**: startDate, endDate, startTime, endTime
- **Venue Information**: venue, address, venueCity, venueState, venueZip, venueCountry, venueCoordinates, venueCapacity, venuePhone, venueWebsite
- **Pricing**: price, priceCurrency, offerAvailability
- **People**: performer, performerType, organizer, organizerType, organizerUrl
- **Event Status**: eventStatus, previousStartDate
- **Display Controls**: showVenue, showPrice, showTicketLink

### InnerBlocks Support
- Add rich content, images, galleries, and custom layouts within events
- Full WordPress block editor compatibility
- Content renders on frontend with proper styling

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
- `venue`: Venue name
- `address`: Full venue address
- `venueCity`: Venue city
- `venueState`: Venue state/province
- `venueZip`: Venue postal code
- `venueCountry`: Venue country
- `venueCoordinates`: GPS coordinates (lat,lng)
- `venueCapacity`: Venue capacity
- `venuePhone`: Venue phone number
- `venueWebsite`: Venue website URL

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
- **Venue Taxonomy**: Automatic venue creation and assignment
- **Schema Generator**: JSON-LD structured data output
- **Meta Storage**: Background sync for performance
- **REST API**: Event data available via endpoints

All event data is stored as block attributes and synchronized to post meta for efficient querying and calendar display.