# SpotHopper Event Import Handler

Import events from SpotHopper venue management platform.

## Overview

The SpotHopper handler imports events from SpotHopper's public JSON API. No authentication is required - the handler accesses public event data using only a spot ID.

## Configuration

### Required Settings

| Setting | Type | Description |
|---------|------|-------------|
| `spot_id` | text | The SpotHopper spot ID for the venue. Find this in the venue's SpotHopper URL or admin panel. |

### Optional Settings

| Setting | Type | Description |
|---------|------|-------------|
| `venue_name_override` | text | Custom venue name instead of the SpotHopper spot name. Useful for sub-venues like "The Rickhouse @ Cannon Distillery". |

## Features

### Public API Integration
- No authentication or API key required
- Direct access to SpotHopper's public JSON endpoint
- Uses standard WordPress HTTP API for requests

### Full Venue Metadata Extraction
Automatically extracts complete venue information:
- Venue name (or override)
- Street address
- City, state, zip, country
- Phone number
- Website URL
- GPS coordinates (latitude, longitude) for map display

### Event Image Support
- Extracts event images from SpotHopper CDN
- Supports full and large image URLs
- Images stored in engine data for downstream processing

### Single-Item Processing
- Processes one event per job execution
- Uses EventIdentifierGenerator for consistent deduplication
- Marks events as processed to prevent duplicates across runs

## API Details

### Endpoint
```
GET https://www.spothopperapp.com/api/spots/{spot_id}/events
```

### Response Structure
The API returns events with linked venue (spot) data:
```json
{
  "events": [
    {
      "name": "Event Title",
      "text": "Event description",
      "event_date": "2025-01-15",
      "start_time": "19:00",
      "duration_minutes": 120,
      "links": {
        "images": [1234]
      }
    }
  ],
  "linked": {
    "spots": [
      {
        "name": "Venue Name",
        "address": "123 Main St",
        "city": "Charleston",
        "state": "SC",
        "zip": "29401",
        "country": "US",
        "phone_number": "555-1234",
        "website_url": "https://venue.com",
        "latitude": 32.7765,
        "longitude": -79.9311
      }
    ],
    "images": [
      {
        "id": 1234,
        "urls": {
          "full": "https://cdn.spothopperapp.com/...",
          "large": "https://cdn.spothopperapp.com/..."
        }
      }
    ]
  }
}
```

## Event Data Mapping

| SpotHopper Field | Event Field |
|------------------|-------------|
| `name` | `title` |
| `text` | `description` |
| `event_date` | `startDate` |
| `start_time` | `startTime` |
| calculated from duration | `endTime` |
| `linked.spots[0].name` | `venue` |
| `linked.spots[0].address` | `venueAddress` |
| `linked.spots[0].city` | `venueCity` |
| `linked.spots[0].state` | `venueState` |
| `linked.spots[0].zip` | `venueZip` |
| `linked.spots[0].country` | `venueCountry` |
| `linked.spots[0].phone_number` | `venuePhone` |
| `linked.spots[0].website_url` | `venueWebsite` |
| `latitude,longitude` | `venueCoordinates` |

## Usage Example

### Pipeline Configuration
1. Add Event Import step to pipeline
2. Select "SpotHopper Events" as the handler
3. Enter the venue's spot ID
4. Optionally provide a venue name override
5. Add Event Upsert step to create/update events

### Finding Spot ID
The spot ID is found in:
- SpotHopper venue admin panel URL
- Public venue page URL (typically a numeric ID)
- Example: `https://www.spothopperapp.com/spots/101982` uses spot ID `101982`

## Integration with EventUpsert

SpotHopper events integrate with the EventUpsert handler:
- Full venue metadata enables automatic venue term creation
- Coordinates enable map display on event pages
- Images support featured image attachment
- EventIdentifierGenerator ensures consistent event identity across imports

## Error Handling

| Error | Cause | Resolution |
|-------|-------|------------|
| "requires spot_id configuration" | Missing spot ID | Add spot_id to handler settings |
| "API request failed" | Network/connection issue | Check server connectivity |
| "non-200 status" | Invalid spot ID or API error | Verify spot ID is correct |
| "invalid JSON" | API response parsing error | Contact support if persistent |

## Version History

- **v0.4.1**: Initial release with full venue metadata extraction and image support
