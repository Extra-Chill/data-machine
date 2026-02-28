# Event Flyer Handler

Extract event data from promotional flyer and poster images using AI vision capabilities.

## Overview

The Event Flyer handler processes flyer and poster images using vision model capabilities to extract event information. It implements a "fill OR AI extracts" pattern where users can pre-populate known fields and let AI extract the remaining details from the image.

## Features

### AI Vision Extraction
- **Image Analysis**: Processes JPG, PNG, GIF, and WebP images
- **Field Extraction**: Extracts title, date, time, venue, price, and performer information
- **Smart Defaults**: Uses configured values when provided, falls back to AI extraction

### "Fill OR AI Extracts" Pattern
Each configuration field follows a simple rule:
- **Field populated**: Uses the configured value
- **Field empty**: AI extracts from the flyer image

### Integration with Data Machine Files
- Reads images from the Data Machine Files handler storage
- Processes one image per job execution
- Tracks processed files to prevent duplicates

## Configuration

### Available Fields

| Field | Description | Format |
|-------|-------------|--------|
| `title` | Event title or headliner | Text |
| `venue` | Venue name | Text |
| `venueAddress` | Street address | Text |
| `city` | City name | Text |
| `state` | State/province | Text |
| `startDate` | Event date | YYYY-MM-DD |
| `startTime` | Start time | HH:MM (24-hour) |
| `endTime` | End time | HH:MM (24-hour) |
| `price` | Ticket price | Text (e.g., "$20") |
| `ticketUrl` | Ticket purchase URL | URL |
| `performer` | Supporting acts | Text |

### Example Configuration
```php
$config = [
    'venue' => 'The Music Hall',        // Pre-filled - AI won't extract
    'city' => 'Charleston',             // Pre-filled - AI won't extract
    'state' => 'SC',                    // Pre-filled - AI won't extract
    'title' => '',                      // Empty - AI extracts from flyer
    'startDate' => '',                  // Empty - AI extracts from flyer
    'startTime' => '',                  // Empty - AI extracts from flyer
    'price' => ''                       // Empty - AI extracts from flyer
];
```

## AI Extraction Guidance

### Vision Prompt
The handler provides structured guidance for AI extraction:

```
Extract event information from this promotional flyer, poster, or event graphic.

Look for and extract:
- Event title or headliner (usually the largest, most prominent text)
- Date and time information (parse into standard formats)
- Venue name and address
- Ticket prices (advance, door, VIP tiers if shown)
- Supporting acts, opening bands, or additional performers
- Ticket purchase URLs if visible
- Any age restrictions (21+, All Ages, etc.)

Format guidelines:
- Dates should be in YYYY-MM-DD format
- Times should be in HH:MM 24-hour format
- If information is not clearly visible, leave the field empty
- Do not guess or infer information that is not present on the flyer
```

### AI Field Descriptions
Fields available for AI extraction include detailed descriptions:

| Field | AI Extraction Description |
|-------|--------------------------|
| title | Event title or headliner name (usually the largest text on the flyer) |
| venue | Venue name where the event takes place |
| venueAddress | Street address of the venue |
| city | City name |
| state | State or province abbreviation (e.g., SC, NY, CA) |
| startDate | Event date in YYYY-MM-DD format |
| startTime | Event start time in HH:MM 24-hour format |
| endTime | Event end time in HH:MM 24-hour format (if visible) |
| price | Ticket price (e.g., "$20" or "$15 adv / $20 dos") |
| ticketUrl | Ticket purchase URL if visible on the flyer |
| performer | Supporting acts, opening bands, or additional performers |
| description | Any additional event details visible on the flyer |

## Integration

### Handler Registration
Uses `HandlerRegistrationTrait` for self-registration:
```php
self::registerHandler(
    'event_flyer',
    'event_import',
    self::class,
    __('Event Flyer', 'data-machine-events'),
    __('Extract event data from flyer/poster images using AI vision', 'data-machine-events'),
    false,
    null,
    EventFlyerSettings::class,
    null
);
```

### File Storage Integration
Reads from Data Machine's file storage system:
```php
$storage = $this->getFileStorage();
$repo_files = $storage->get_all_files($context);
```

### Supported Image Formats
- JPEG/JPG
- PNG
- GIF
- WebP

### Engine Data Storage
Stores image information for downstream processing:
```php
datamachine_merge_engine_data($job_id, [
    'image_file_path' => $image_path,
    'image_url' => $image_url,
]);
```

## Usage Workflow

1. **Upload Images**: Add flyer images to the pipeline's file repository
2. **Configure Handler**: Set known fields (venue, city, state) or leave empty for AI extraction
3. **Run Pipeline**: Handler processes one image per execution
4. **AI Extraction**: Vision model extracts missing fields from the flyer
5. **Event Creation**: EventUpsert creates the event with extracted data

## Best Practices

### Pre-Fill Known Information
When you know the venue or location, pre-fill those fields:
- Reduces AI extraction errors
- Ensures consistent venue naming
- Improves venue matching and deduplication

### Image Quality
For best AI extraction results:
- Use high-resolution images
- Ensure text is legible
- Avoid heavily stylized or distorted text
- Include the full flyer in the image

### Date Handling
- AI extracts dates in YYYY-MM-DD format
- For ambiguous dates (e.g., "Friday the 13th"), provide context in the date field
- Multi-date events may require manual configuration

## Limitations

- Requires Data Machine Files handler for image storage
- Processes one image per job execution
- AI extraction accuracy depends on flyer design clarity
- Cannot extract information not visible on the flyer
