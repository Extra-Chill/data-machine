# Pipeline Components JavaScript

Custom React field components and hooks for Data Machine pipeline modals in the Data Machine Events plugin.

## Overview

The pipeline components provide enhanced user experience for configuring event import handlers through custom React components that integrate with WordPress's Data Machine pipeline system.

## Components

### AddressAutocompleteField

A sophisticated address autocomplete component using OpenStreetMap Nominatim API.

**Features:**
- Real-time address suggestions as user types
- Debounced API requests (1 second delay)
- Keyboard navigation (arrow keys, enter, escape)
- Automatic population of related fields (city, state, zip, country)
- Caching to reduce API calls
- Error handling and loading states
- Attribution to OpenStreetMap

**Usage:**
```javascript
// Automatically registered for 'address-autocomplete' field type
addFilter(
    'datamachine.handlerSettings.fieldComponent',
    'datamachine-events/address-autocomplete',
    function( component, fieldType, fieldKey, handlerSlug ) {
        if ( fieldType === 'address-autocomplete' ) {
            return AddressAutocompleteField;
        }
        return component;
    }
);
```

**API Integration:**
- Uses Nominatim API: `https://nominatim.openstreetmap.org/search`
- User agent: `ExtraChill-Events/1.0 (https://extrachill.com)`
- Rate limiting: 1 second between requests
- Result limit: 5 suggestions per query

## Hooks

### Venue Enrichment Hook

Automatically populates venue fields when a venue is selected in Universal Web Scraper settings.

**Features:**
- Fetches venue data from REST API on venue selection
- Populates all venue-related form fields automatically
- Clears fields when "Create New Venue" is selected
- Error handling for failed API requests

**Hook:**
```javascript
addFilter(
    'datamachine.handlerSettings.init',
    'datamachine-events/venue-enrichment',
    async function( settingsPromise, handlerSlug, fieldsSchema ) {
        // Fetches and populates venue data on modal open
    }
);
```

### Venue Change Hook

Handles venue dropdown changes in Universal Web Scraper settings.

**Features:**
- Dynamic venue data loading on selection change
- Batch field updates for seamless UX
- Automatic field clearing for new venues
- REST API integration for venue data retrieval

**Hook:**
```javascript
addFilter(
    'datamachine.handlerSettings.fieldChange',
    'datamachine-events/venue-change',
    async function( changesPromise, fieldKey, value, handlerSlug, currentData ) {
        // Handles venue selection changes
    }
);
```

## REST API Integration

### Venue Data Endpoint

Retrieves venue metadata for form population:

```javascript
const response = await apiFetch( {
    path: '/datamachine/v1/events/venues/' + venueId,
} );
```

**Response Format:**
```json
{
    "success": true,
    "data": {
        "name": "The Blue Note",
        "address": "123 Main St",
        "city": "New York",
        "state": "NY",
        "zip": "10001",
        "country": "US",
        "phone": "(555) 123-4567",
        "website": "https://bluenote.com",
        "capacity": "500"
    }
}
```

## Architecture

### WordPress Integration

- Uses `@wordpress/hooks` for filter system
- Uses `@wordpress/api-fetch` for REST API calls
- Uses `@wordpress/element` for React components
- Uses `@wordpress/components` for UI elements
- Uses `@wordpress/i18n` for internationalization

### Component Registration

Components are registered via WordPress filters, allowing them to be dynamically loaded by the Data Machine pipeline system based on field configuration.

### Error Handling

- Network request failures are logged to console
- User-friendly error messages for autocomplete failures
- Graceful degradation when API is unavailable
- Loading states prevent user confusion

## Dependencies

- WordPress 5.0+ (for React integration)
- Data Machine plugin (for pipeline system)
- OpenStreetMap Nominatim API (for geocoding)
- No build process required (vanilla JavaScript with WordPress globals)

## Security

- Input sanitization through WordPress REST API
- Proper escaping of user inputs
- Rate limiting on external API calls
- User agent identification for API requests</content>
<parameter name="filePath">docs/pipeline-components-js.md