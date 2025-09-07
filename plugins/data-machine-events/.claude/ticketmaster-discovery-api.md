# Discovery API 2.0

[V 2.0](/products-and-docs/apis/discovery-api/v2/)

The Ticketmaster Discovery API allows you to search for events, attractions, or venues.

## Overview

### Authentication

To run a successful API call, you will need to pass your API Key in the `apikey` query parameter. **Your API Key should automatically appear in all URLs throughout this portal**.

Example: `https://app.ticketmaster.com/discovery/v2/events.json?apikey={apikey}`

Without a valid API Key, you will receive a `401` Status Code with the following response:

```
{
    "fault": {
        "faultstring": "Invalid ApiKey",
        "detail": {
            "errorcode": "oauth.v2.InvalidApiKey"
        }
    }
}
```

### Root URL

`https://app.ticketmaster.com/discovery/v2/`

### Event Sources

The API provides access to content sourced from various platform, including **Ticketmaster**, **Universe**, **FrontGate Tickets** and **Ticketmaster Resale** (TMR). By default, the API returns events from all sources. To specify a specifc source(s), use the `&source=` parameter. Multiple, comma separated values are OK.

### Event Coverage

With over 230K+ events available in the API, coverage spans different countries, including **United States**, **Canada**, **Mexico**, **Australia**, **New Zealand**, **United Kingdom**, **Ireland**, other European countries, and more. More events and more countries are added on continuous basis.

### Rate Limits

-   The default quota is 5000 API calls per day and rate limitation of 5 requests per second.
-   Deep Paging: we only support retrieving the 1000th item. i.e. ( size \* page < 1000)

![event map](/assets/img/products-and-docs/map.jpg)

### Examples

**Get a list of all events in the United States** `https://app.ticketmaster.com/discovery/v2/events.json?countryCode=US&apikey={apikey}`

**Search for events sourced by Universe in the United States with keyword “devjam”** `https://app.ticketmaster.com/discovery/v2/events.json?keyword=devjam&source=universe&countryCode=US&apikey={apikey}`

**Search for music events in the Los Angeles area** `https://app.ticketmaster.com/discovery/v2/events.json?classificationName=music&dmaId=324&apikey={apikey}`

**Get a list of all events for Adele in Canada** `https://app.ticketmaster.com/discovery/v2/events.json?attractionId=K8vZ917Gku7&countryCode=CA&apikey={apikey}`

### V2

get

**[/discovery/v2/suggest](#anchor_find)**

Find Suggest

Find search suggestions and filter your suggestions by location, source, etc.

### Default

get

**[/discovery/v2/attractions](#anchor_find)**

Find attractions (artists, sports, packages, plays and so on) and filter your search by name, and much more.

get

**[/discovery/v2/attractions/{id}](#anchor_get)**

Get details for a specific attraction using the unique identifier for the attraction.

get

**[/discovery/v2/classifications](#anchor_find)**

Find classifications and filter your search by name, and much more. Classifications help define the nature of attractions and events.

get

**[/discovery/v2/classifications/genres/{id}](#anchor_getGenre)**

Get details for a specific genre using its unique identifier.

get

**[/discovery/v2/classifications/segments/{id}](#anchor_getSegment)**

Get details for a specific segment using its unique identifier.

get

**[/discovery/v2/classifications/subgenres/{id}](#anchor_getSubgenre)**

Get details for a specific sub-genre using its unique identifier.

get

**[/discovery/v2/classifications/{id}](#anchor_get)**

Get details for a specific segment, genre, or sub-genre using its unique identifier.

get

**[/discovery/v2/events](#anchor_find)**

Find events and filter your search by location, date, availability, and much more.

get

**[/discovery/v2/events/{id}](#anchor_get)**

Get details for a specific event using the unique identifier for the event. This includes the venue and location, the attraction(s), and the Ticketmaster Website URL for purchasing tickets for the event.

get

**[/discovery/v2/events/{id}/images](#anchor_getImages)**

Get images for a specific event using the unique identifier for the event.

get

**[/discovery/v2/venues](#anchor_find)**

Find venues and filter your search by name, and much more.

get

**[/discovery/v2/venues/{id}](#anchor_get)**

Get details for a specific venue using the unique identifier for the venue.

## Event Search

**Method:** GET

**Summary:** Event Search

**Description:** Find events and filter your search by location, date, availability, and much more.

/discovery/v2/events

### Query parameters:

Parameter

Description

Type

Default Value

Required

`id`

Filter entities by its id

String

No

`keyword`

Keyword to search on

String

No

`attractionId`

Filter by attraction id

String

No

`venueId`

Filter by venue id

String

No

`postalCode`

Filter by postal code / zipcode

String

No

`latlong`

Filter events by latitude and longitude, this filter is deprecated and maybe removed in a future release, please use geoPoint instead

String

No

`radius`

Radius of the area in which we want to search for events.

String

No

`unit`

Unit of the radius

String enum:\["miles", "km"\]

miles

No

`source`

Filter entities by its primary source name OR publishing source name

String enum:\["ticketmaster", " universe", " frontgate", " tmr"\]

No

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

en

No

`marketId`

Filter by market id

String

No

`startDateTime`

Filter with a start date after this date

String

No

`endDateTime`

Filter with a start date before this date

String

No

`includeTBA`

yes, to include with date to be announce (TBA)

String enum:\["yes", " no", " only"\]

no if date parameter sent, yes otherwise

No

`includeTBD`

yes, to include with a date to be defined (TBD)

String enum:\["yes", " no", " only"\]

no if date parameter sent, yes otherwise

No

`includeTest`

Yes if you want to have entities flag as test in the response. Only, if you only wanted test entities

String enum:\["yes", " no", " only"\]

no

No

`size`

Page size of the response

String

20

No

`page`

Page number

String

0

No

`sort`

Sorting order of the search result. Allowable values : 'name,asc', 'name,desc', 'date,asc', 'date,desc', 'relevance,asc', 'relevance,desc', 'distance,asc', 'name,date,asc', 'name,date,desc', 'date,name,asc', 'date,name,desc', 'distance,date,asc', 'onSaleStartDate,asc', 'id,asc', 'venueName,asc', 'venueName,desc', 'random'

String

relevance,desc

No

`onsaleStartDateTime`

Filter with onsale start date after this date

String

No

`onsaleEndDateTime`

Filter with onsale end date before this date

String

No

`city`

Filter by city

Array

No

`countryCode`

Filter by country code

String

No

`stateCode`

Filter by state code

String

No

`classificationName`

Filter by classification name: name of any segment, genre, sub-genre, type, sub-type. Negative filtering is supported by using the following format '-'. Be aware that negative filters may cause decreased performance.

Array

No

`classificationId`

Filter by classification id: id of any segment, genre, sub-genre, type, sub-type. Negative filtering is supported by using the following format '-'. Be aware that negative filters may cause decreased performance.

Array

No

`dmaId`

Filter by dma id

String

No

`localStartDateTime`

Filter with event local start date time within this range

Array

No

`localStartEndDateTime`

Filter event where event local start and end date overlap this range

Array

No

`startEndDateTime`

Filter event where event start and end date overlap this range

Array

No

`publicVisibilityStartDateTime`

Filter with events with public visibility starting

Array

No

`preSaleDateTime`

Filter events with a presaleFilterTransformer start and end that intersects with this range

Array

No

`onsaleOnStartDate`

Filter with onsale start date on this date

String

No

`onsaleOnAfterStartDate`

Filter with onsale range within this date

String

No

`collectionId`

Filter by collection id

Array

No

`segmentId`

Filter by segment id

Array

No

`segmentName`

Filter by segment name

Array

No

`includeFamily`

Filter by classification that are family-friendly

String enum:\["yes", " no", " only"\]

yes

No

`promoterId`

Filter by promoter id

String

No

`genreId`

Filter by genreId

Array

No

`subGenreId`

Filter by subGenreId

Array

No

`typeId`

Filter by typeId

Array

No

`subTypeId`

Filter by subTypeId

Array

No

`geoPoint`

filter events by geoHash

String

No

`preferredCountry`

Popularity boost by country, default is us.

String enum:\["us", " ca"\]

us

No

`includeSpellcheck`

yes, to include spell check suggestions in the response.

String enum:\["yes", " no"\]

no

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `_links`(object) - links to data sets
    -   `self`(object) - link to this data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
    -   `next`(object) - link to the next data set
        -   `href`(string) - reference.
        -   `templated`(boolean) - ability to be templated
    -   `prev`(object) - link to the previous data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
-   `_embedded`(object) - container
    -   `events`(array)
        -   `{array item object}`
            -   `_links`(object) - links to data sets
                -   `self`(object) - link to this data set
                    -   `href`(string) - reference
                    -   `templated`(boolean) - ability to be templated
                -   `venues`(array) - link to this data set.
                    -   `{array item object}`
                        -   `href`(string) - reference
                        -   `templated`(boolean) - ability to be templated
                -   `attractions`(array) - link to this data set.
                    -   `{array item object}`
                        -   `href`(string) - reference
                        -   `templated`(boolean) - ability to be templated
            -   `_embedded`(object) - container
                -   `venues`(array) - related
                    -   `{array item object}`
                        -   `_links`(object) - links to data sets
                            -   `self`(object) - link to this data set
                                -   `href`(string) - reference
                                -   `templated`(boolean) - ability to be templated
                        -   `type`(string: enum) - Type of the entity
                            -   event
                            -   venue
                            -   attraction
                        -   `distance`(number) - double
                        -   `units`(string)
                        -   `id`(string) - Unique id of the entity in the discovery API
                        -   `locale`(string) - Locale in which the content is returned
                        -   `name`(string) - Name of the entity
                        -   `description`(string) - Description's of the entity
                        -   `address`(object) - Address of the venue
                            -   `line1`(string) - Address first line
                            -   `line2`(string) - Address second line
                            -   `line3`(string) - Address third line
                        -   `city`(object) - City of the venue
                            -   `name`(string) - Name of the entity
                        -   `additionalInfo`(string) - Additional information of the entity
                        -   `state`(object) - State / Province of the venue
                            -   `stateCode`(string) - State code
                            -   `name`(string) - Name of the entity
                        -   `country`(object) - Country of the venue
                            -   `countryCode`(string) - Country code (ISO 3166)
                            -   `name`(string) - Name of the entity
                        -   `url`(string) - URL of a web site detail page of the entity
                        -   `postalCode`(string) - Postal code / zipcode of the venue
                        -   `location`(object) - Location of the venue
                            -   `longitude`(number) - Longitude
                            -   `latitude`(number) - Latitude
                        -   `timezone`(string) - Timezone of the venue
                        -   `currency`(string) - Default currency of ticket prices for events in this venue
                        -   `markets`(array) - Markets of the venue
                            -   `{array item object}`
                                
                                -   `id`(string) - Market's id
                                -   `name`(string) - Name of the entity
                        -   `images`(array) - Images of the entity
                            -   `{array item object}`
                                
                                -   `url`(string) - Public URL of the image
                                -   `ratio`(string: enum) - Aspect ratio of the image
                                    -   16\_9
                                    -   3\_2
                                    -   4\_3
                                -   `width`(integer) - Width of the image
                                -   `height`(integer) - Height of the image
                                -   `fallback`(boolean) - true if the image is not the event's image but a fallbak image
                                -   `attribution`(string) - Attribution of the image
                        -   `dma`(array) - The list of associated DMAs (Designated Market Areas) of the venue
                            -   `{array item object}`
                                
                                -   `id`(integer) - DMS's id
                        -   `social`(object) - Social networks data
                            -   `twitter`(object) - Twitter data
                                -   `handle`(string: enum) - Twitter handle
                                    -   @a Twitter handle
                                -   `hashtags`(array) - Twitter hashtags
                                    -   `[ "string" ]` - No description specified
                        -   `boxOfficeInfo`(object) - Box office informations for the venue
                            -   `phoneNumberDetail`(string) - Venue box office phone number
                            -   `openHoursDetail`(string) - Venue box office opening hours
                            -   `acceptedPaymentDetail`(string) - Venue box office accepted payment details
                            -   `willCallDetail`(string) - Venue box office will call details
                        -   `parkingDetail`(string) - Venue parking info
                        -   `accessibleSeatingDetail`(string) - Venue accessible seating detail
                        -   `generalInfo`(object) - General informations on the venue
                            -   `generalRule`(string) - Venue general rules
                            -   `childRule`(string) - Venue children rule
                        -   `externalLinks`(object) - List of external links
                        -   `test`(boolean) - Indicate if this is a test entity, by default test entities won't appear in discovery API
                        -   `aliases`(array) - List of aliases for entity
                            -   `[ "string" ]` - No description specified
                        -   `localizedAliases`(object) - List of localized aliases for entity
                        -   `upcomingEvents`(object) - number of upcoming events
                        -   `ada`(object) - ADA information
                            -   `adaPhones`(string)
                            -   `adaCustomCopy`(string)
                            -   `adaHours`(string)
                -   `attractions`(array) - related
                    -   `{array item object}`
                        -   `_links`(object) - links to data sets
                            -   `self`(object) - link to this data set
                                -   `href`(string) - reference
                                -   `templated`(boolean) - ability to be templated
                        -   `type`(string: enum) - Type of the entity
                            -   event
                            -   venue
                            -   attraction
                        -   `id`(string) - Unique id of the entity in the discovery API
                        -   `locale`(string) - Locale in which the content is returned
                        -   `name`(string) - Name of the entity
                        -   `description`(string) - Description's of the entity
                        -   `additionalInfo`(string) - Additional information of the entity
                        -   `url`(string) - URL of a web site detail page of the entity
                        -   `images`(array) - Images of the entity
                            -   `{array item object}`
                                
                                -   `url`(string) - Public URL of the image
                                -   `ratio`(string: enum) - Aspect ratio of the image
                                    -   16\_9
                                    -   3\_2
                                    -   4\_3
                                -   `width`(integer) - Width of the image
                                -   `height`(integer) - Height of the image
                                -   `fallback`(boolean) - true if the image is not the event's image but a fallbak image
                                -   `attribution`(string) - Attribution of the image
                        -   `classifications`(array) - Attraction's classifications
                            -   `{array item object}`
                                
                                -   `primary`(boolean)
                                -   `segment`(object) - A Segment is a primary genre for an entity (Music, Sports, Arts, etc)
                                    -   `genres`(array) - List of Genre linked to the Segment
                                        -   `{array item object}`
                                            
                                            -   `subGenres`(array) - List of Tertiary Genre linked to the Secondary Genre
                                                -   `{array item object}`
                                                    
                                                    -   `id`(string) - The ID of the classification's level
                                                    -   `name`(string) - The Name of the classification's level
                                                    -   `locale`(string) - Locale in which the content is returned
                                            -   `id`(string) - The ID of the classification's level
                                            -   `name`(string) - The Name of the classification's level
                                            -   `locale`(string) - Locale in which the content is returned
                                    -   `id`(string) - The ID of the classification's level
                                    -   `name`(string) - The Name of the classification's level
                                    -   `locale`(string) - Locale in which the content is returned
                                -   `genre`(object) - Secondary Genre to further describe an entity (Rock, Classical, Animation, etc)
                                    -   `id`(string) - The ID of the classification's level
                                    -   `name`(string) - The Name of the classification's level
                                    -   `locale`(string) - Locale in which the content is returned
                                -   `subGenre`(object) - Tertiary Genre for additional detail when describring an entity (Alternative Rock, Ambient Pop, etc)
                                    -   `id`(string) - The ID of the classification's level
                                    -   `name`(string) - The Name of the classification's level
                                    -   `locale`(string) - Locale in which the content is returned
                                -   `type`(object) - A Type represents a kind or group of people. (Donation, Group, Individual, Merchandise, Event Style, etc)
                                    -   `subTypes`(array) - List of Sub Types linked to the Type
                                        -   `{array item object}`
                                            
                                            -   `id`(string) - The ID of the classification's level
                                            -   `name`(string) - The Name of the classification's level
                                            -   `locale`(string) - Locale in which the content is returned
                                    -   `id`(string) - The ID of the classification's level
                                    -   `name`(string) - The Name of the classification's level
                                    -   `locale`(string) - Locale in which the content is returned
                                -   `subType`(object) - Secondary Type to further categorize an entity (Band, Choir, Chorus, etc)
                                    -   `id`(string) - The ID of the classification's level
                                    -   `name`(string) - The Name of the classification's level
                                    -   `locale`(string) - Locale in which the content is returned
                                -   `family`(boolean) - True if this is a family classification
                        -   `externalLinks`(object) - List of external links
                        -   `test`(boolean) - Indicate if this is a test entity, by default test entities won't appear in discovery API
                        -   `aliases`(array) - List of aliases for entity
                            -   `[ "string" ]` - No description specified
                        -   `localizedAliases`(object) - List of localized aliases for entity
                        -   `upcomingEvents`(object) - number of upcoming events
            -   `type`(string) - Type of the entity
            -   `distance`(number) - double
            -   `units`(string) - No description specified
            -   `location`(object) - No description specified
                
                -   `longitude`(number) - Longitude
                -   `latitude`(number) - Latitude
            -   `id`(string) - Unique id of the entity in the discovery API
            -   `locale`(string) - Locale in which the content is returned
            -   `name`(string) - Name of the entity
            -   `description`(string) - Description's of the entity
            -   `additionalInfo`(string) - Additional information of the entity
            -   `url`(string) - URL of a web site detail page of the entity
            -   `images`(array) - Images of the entity
                -   `{ array item object }`
                    -   `url`(string) - Public URL of the image
                    -   `ratio`(string: enum) - Aspect ratio of the image
                        -   16\_9
                        -   3\_2
                        -   4\_3
                    -   `width`(integer) - Width of the image
                    -   `height`(integer) - Height of the image
                    -   `fallback`(boolean) - true if the image is not the event's image but a fallbak image
                    -   `attribution`(string) - Attribution of the image
            -   `dates`(object) - Event's dates information
                
                -   `start`(object) - Event's start dates. The date and time when the event will start
                    -   `localDate`(string) - The event start date in local date
                    -   `localTime`(object) - The event end time in local time
                        -   `millisOfSecond`(integer) - int32
                        -   `millisOfDay`(integer) - int32
                        -   `secondOfMinute`(integer) - int32
                        -   `minuteOfHour`(integer) - int32
                        -   `hourOfDay`(integer) - int32
                        -   `chronology`(object)
                            -   `zone`(object)
                                -   `fixed`(boolean)
                                -   `id`(string)
                        -   `values`(array)
                            -   `{array item object}`
                                -   `type` (integer)
                                -   `format` (int32)
                        -   `fieldTypes`(array)
                            -   `{array item object}`
                                
                                -   `durationType`(object)
                                    -   `name`(string)
                                -   `rangeDurationType`(object)
                                    -   `name`(string)
                                -   `name`(string)
                        -   `fields`(array)
                            -   `{array item object}`
                                
                                -   `lenient`(boolean)
                                -   `rangeDurationField`(object)
                                    -   `unitMillis`(integer) - int64
                                    -   `precise`(boolean)
                                    -   `name`(string)
                                    -   `type`(object)
                                        -   `name`(string)
                                    -   `supported`(boolean)
                                -   `durationField`(object)
                                    -   `unitMillis`(integer) - int64
                                    -   `precise`(boolean)
                                    -   `name`(string)
                                    -   `type`(object)
                                        -   `name`(string)
                                    -   `supported`(boolean)
                                -   `minimumValue`(integer) - int32
                                -   `maximumValue`(integer) - int32
                                -   `leapDurationField`(object)
                                    -   `unitMillis`(integer) - int64
                                    -   `precise`(boolean)
                                    -   `name`(string)
                                    -   `type`(object)
                                        -   `name`(string)
                                    -   `supported`(boolean)
                                -   `name`(string)
                                -   `type`(object)
                                    -   `durationType`(object)
                                        -   `name`(string)
                                    -   `rangeDurationType`(object)
                                        -   `name`(string)
                                    -   `name`(string)
                                -   `supported`(boolean)
                    -   `dateTime`(string) - The event start datetime
                    -   `dateTBD`(boolean) - Boolean flag to indicate whether or not the start date is TBD
                    -   `dateTBA`(boolean) - Boolean flag to indicate whether or not the start date is TBA
                    -   `timeTBA`(boolean) - Boolean flag to indicate whether or not the start time is TBA
                    -   `noSpecificTime`(boolean) - Boolean flag to indicate whether or not the event start time has no specific time
                -   `end`(object) - Event's end dates. The date and time when the event will end
                    -   `localDate`(string) - The event end date in local date
                    -   `localTime`(object) - The event end time in local time
                        -   `millisOfSecond`(integer) - int32
                        -   `millisOfDay`(integer) - int32
                        -   `secondOfMinute`(integer) - int32
                        -   `minuteOfHour`(integer) - int32
                        -   `hourOfDay`(integer) - int32
                        -   `chronology`(object)
                            -   `zone`(object)
                                -   `fixed`(boolean)
                                -   `id`(string)
                        -   `values`(array)
                            -   `{array item object}`
                                -   `type` (integer)
                                -   `format` (int32)
                        -   `fieldTypes`(array)
                            -   `{array item object}`
                                
                                -   `durationType`(object)
                                    -   `name`(string)
                                -   `rangeDurationType`(object)
                                    -   `name`(string)
                                -   `name`(string)
                        -   `fields`(array)
                            -   `{array item object}`
                                
                                -   `lenient`(boolean)
                                -   `rangeDurationField`(object)
                                    -   `unitMillis`(integer) - int64
                                    -   `precise`(boolean)
                                    -   `name`(string)
                                    -   `type`(object)
                                        -   `name`(string)
                                    -   `supported`(boolean)
                                -   `durationField`(object)
                                    -   `unitMillis`(integer) - int64
                                    -   `precise`(boolean)
                                    -   `name`(string)
                                    -   `type`(object)
                                        -   `name`(string)
                                    -   `supported`(boolean)
                                -   `minimumValue`(integer) - int32
                                -   `maximumValue`(integer) - int32
                                -   `leapDurationField`(object)
                                    -   `unitMillis`(integer) - int64
                                    -   `precise`(boolean)
                                    -   `name`(string)
                                    -   `type`(object)
                                        -   `name`(string)
                                    -   `supported`(boolean)
                                -   `name`(string)
                                -   `type`(object)
                                    -   `durationType`(object)
                                        -   `name`(string)
                                    -   `rangeDurationType`(object)
                                        -   `name`(string)
                                    -   `name`(string)
                                -   `supported`(boolean)
                    -   `dateTime`(string) - The event end date time
                    -   `approximate`(boolean) - Boolean flag to indicate whether or not the end date is approximated
                    -   `noSpecificTime`(boolean) - Boolean flag to indicate whether or not the event end time has no specific time
                -   `access`(object) - Event's access dates. The date and time the fan can access the event
                    -   `startDateTime`(string) - Event's start access time
                    -   `startApproximate`(boolean) - Boolean flag to indicate whether or not the access start date is approximated
                    -   `endDateTime`(string) - Event's end access time
                    -   `endApproximate`(boolean) - Boolean flag to indicate whether or not the access end date is approximated
                -   `timezone`(string) - Event's timezone
                -   `status`(object) - Status of the event
                    -   `code`(string: enum) - The event's status code
                        -   onsale
                        -   offsale
                        -   canceled
                        -   postponed
                        -   rescheduled
                -   `spanMultipleDays`(boolean) - Flag indicating if date spans of multiple days
            -   `sales`(object) - Event's sales dates information
                
                -   `public`(object) - Public onsale information on this event
                    -   `startDateTime`(string) - Public sale's start dates. The date and time when the public sale will start
                    -   `endDateTime`(string) - Public sale's end dates. The date and time when the public sale will end
                    -   `startTBD`(boolean) - True if the public sale's date is to be determined
                -   `presales`(array) - Presale information on this event
                    -   `{array item object}`
                        
                        -   `name`(string) - Name of the presale
                        -   `description`(string) - Description of the presame
                        -   `url`(string) - Presale link URL
                        -   `startDateTime`(string) - Presale's start dates. The date and time when the presale will start
                        -   `endDateTime`(string) - Presale's end dates. The date and time when the presale will end
            -   `info`(string) - Any information related to the event
            -   `pleaseNote`(string) - Any notes related to the event
            -   `priceRanges`(array) - Price ranges of this event
                -   `{ array item object }`
                    -   `type`(string: enum) - Type of price
                        -   standard
                    -   `currency`(string) - Currency
                    -   `min`(number) - Minimum price
                    -   `max`(number) - Maximum price
            -   `promoter`(object) - Event's promoter
                
                -   `id`(string) - Id of the promoter
                -   `name`(string) - Name of the promoter
                -   `description`(string) - Description of the promoter
            -   `promoters`(array) - Event's promoters
                -   `{ array item object }`
                    -   `id`(string) - Id of the promoter
                    -   `name`(string) - Name of the promoter
                    -   `description`(string) - Description of the promoter
            -   `outlets`(array) - Related outlets informations
                -   `{ array item object }`
                    -   `url`(string) - Outlet's url
                    -   `type`(string) - Outlet's type
            -   `productType`(string) - Product type
            -   `products`(array) - Related products informations
                -   `{ array item object }`
                    -   `name`(string) - Name of the entity
                    -   `id`(string) - Product's primary id
                    -   `url`(string) - Product's url
                    -   `type`(string) - Product's type
            -   `seatmap`(object) - Event's seatmap
                
                -   `staticUrl`(string) - Static Seatmap Url
            -   `accessibility`(object) - Additional information for people who experience disabilities
                
                -   `info`(string) - Accessibility's information
            -   `ticketLimit`(object) - ticket limit
                
                -   `infos`(object) - ticket limits text - multi-lingual fields
                -   `info`(string) - ticket limits text
            -   `classifications`(array) - Event's classifications
                -   `{ array item object }`
                    -   `primary`(boolean)
                    -   `segment`(object) - A Segment is a primary genre for an entity (Music, Sports, Arts, etc)
                        -   `genres`(array) - List of Genre linked to the Segment
                            -   `{array item object}`
                                
                                -   `subGenres`(array) - List of Tertiary Genre linked to the Secondary Genre
                                    -   `{array item object}`
                                        
                                        -   `id`(string) - The ID of the classification's level
                                        -   `name`(string) - The Name of the classification's level
                                        -   `locale`(string) - Locale in which the content is returned
                                -   `id`(string) - The ID of the classification's level
                                -   `name`(string) - The Name of the classification's level
                                -   `locale`(string) - Locale in which the content is returned
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `genre`(object) - Secondary Genre to further describe an entity (Rock, Classical, Animation, etc)
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `subGenre`(object) - Tertiary Genre for additional detail when describring an entity (Alternative Rock, Ambient Pop, etc)
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `type`(object) - A Type represents a kind or group of people. (Donation, Group, Individual, Merchandise, Event Style, etc)
                        -   `subTypes`(array) - List of Sub Types linked to the Type
                            -   `{array item object}`
                                
                                -   `id`(string) - The ID of the classification's level
                                -   `name`(string) - The Name of the classification's level
                                -   `locale`(string) - Locale in which the content is returned
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `subType`(object) - Secondary Type to further categorize an entity (Band, Choir, Chorus, etc)
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `family`(boolean) - True if this is a family classification
            -   `place`(object) - Place has the information on where the event happens. It can be set if there is no venue
                
                -   `area`(object) - Area of the place
                    -   `name`(string) - Name of the entity
                -   `address`(object) - Address of the place
                    -   `line1`(string) - Address first line
                    -   `line2`(string) - Address second line
                    -   `line3`(string) - Address third line
                -   `city`(object) - City of the Place
                    -   `name`(string) - Name of the entity
                -   `state`(object) - State / Province of the place
                    -   `stateCode`(string) - State code
                    -   `name`(string) - Name of the entity
                -   `country`(object) - Country of the place
                    -   `countryCode`(string) - Country code (ISO 3166)
                    -   `name`(string) - Name of the entity
                -   `postalCode`(string) - Postal code / zipcode of the place
                -   `location`(object) - Location of the place
                    -   `longitude`(number) - Longitude
                    -   `latitude`(number) - Latitude
                -   `name`(string) - Name of the entity
            -   `externalLinks`(object) - List of external links
            -   `test`(boolean) - Indicate if this is a test entity, by default test entities won't appear in discovery API
            -   `aliases`(array) - List of aliases for entity
                -   `[ "string" ]`
                    
            -   `localizedAliases`(object) - List of localized aliases for entity
-   `page`(object) - information about current page in data source
    -   `size`(number) - size of page.
    -   `totalElements`(number) - total number of available elements in server
    -   `totalPages`(number) - total number of available pages in server
    -   `number`(number) - current page number counted from 0

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/events.json?size=1&apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \
--include 'https://app.ticketmaster.com/discovery/v2/events.json?size=1&apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/events.json?apikey={apikey}&size=1 HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: https://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Rate-Limit-Over: 0
Content-Length: 5360
Rate-Limit-Available: 4723
Set-Cookie: CMPS=0ytJbt229sTM7UXhHxC5IEvVNguFRwkBBUZ76aK9bmvRvAWZwe/RjM5TSH0yOXNFGd+urQFTC6o=; path=/
Access-Control-Max-Age: 3628800
Access-Control-Allow-Methods: GET, PUT, POST, DELETE
Connection: keep-alive
Server: Apache-Coyote/1.1
Rate-Limit-Reset: 1457417554290
Access-Control-Allow-Headers: origin, x-requested-with, accept
Date: Mon, 07 Mar 2016 10:09:51 GMT
Access-Control-Allow-Origin: *
X-Application-Context: application:local,default,jphx1:8080
Content-Type: application/json;charset=utf-8
X-Unknown-Params: apikey
X-Unknown-Params: api-key
Rate-Limit: 5000

{
  "_links":  {
    "self":  {
      "href": "/discovery/v2/events.json?size=1{&page,sort}",
      "templated": true
    },
    "next":  {
      "href": "/discovery/v2/events.json?page=1&size=1{&sort}",
      "templated": true
    }
  },
  "_embedded":  {
    "events":  [
       {
        "name": "WGC Cadillac Championship - Sunday Ticket",
        "type": "event",
        "id": "vvG1VZKS5pr1qy",
        "test": false,
        "url": "http://ticketmaster.com/event/0E0050681F51BA4C",
        "locale": "en-us",
        "images":  [
           {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_RETINA_LANDSCAPE_16_9.jpg",
            "width": 1136,
            "height": 639,
            "fallback": false
          },
           {
            "ratio": "3_2",
            "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_RETINA_PORTRAIT_3_2.jpg",
            "width": 640,
            "height": 427,
            "fallback": false
          },
           {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_TABLET_LANDSCAPE_LARGE_16_9.jpg",
            "width": 2048,
            "height": 1152,
            "fallback": false
          },
           {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_TABLET_LANDSCAPE_16_9.jpg",
            "width": 1024,
            "height": 576,
            "fallback": false
          },
           {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_EVENT_DETAIL_PAGE_16_9.jpg",
            "width": 205,
            "height": 115,
            "fallback": false
          },
           {
            "ratio": "3_2",
            "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_ARTIST_PAGE_3_2.jpg",
            "width": 305,
            "height": 203,
            "fallback": false
          },
           {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_RETINA_PORTRAIT_16_9.jpg",
            "width": 640,
            "height": 360,
            "fallback": false
          },
           {
            "ratio": "4_3",
            "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_CUSTOM.jpg",
            "width": 305,
            "height": 225,
            "fallback": false
          },
           {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_RECOMENDATION_16_9.jpg",
            "width": 100,
            "height": 56,
            "fallback": false
          },
           {
            "ratio": "3_2",
            "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_TABLET_LANDSCAPE_3_2.jpg",
            "width": 1024,
            "height": 683,
            "fallback": false
          }
        ],
        "sales":  {
          "public":  {
            "startDateTime": "2015-10-02T11:00:00Z",
            "startTBD": false,
            "endDateTime": "2016-03-06T23:00:00Z"
          }
        },
        "dates":  {
          "start":  {
            "localDate": "2016-03-06",
            "dateTBD": false,
            "dateTBA": false,
            "timeTBA": true,
            "noSpecificTime": false
          },
          "timezone": "America/New_York",
          "status":  {
            "code": "offsale"
          }
        },
        "classifications":  [
           {
            "primary": true,
            "segment":  {
              "id": "KZFzniwnSyZfZ7v7nE",
              "name": "Sports"
            },
            "genre":  {
              "id": "KnvZfZ7vAdt",
              "name": "Golf"
            },
            "subGenre":  {
              "id": "KZazBEonSMnZfZ7vFI7",
              "name": "PGA Tour"
            }
          }
        ],
        "promoter":  {
          "id": "682"
        },
        "_links":  {
          "self":  {
            "href": "/discovery/v2/events/vvG1VZKS5pr1qy?locale=en-us"
          },
          "attractions":  [
             {
              "href": "/discovery/v2/attractions/K8vZ917uc57?locale=en-us"
            }
          ],
          "venues":  [
             {
              "href": "/discovery/v2/venues/KovZpZAaEldA?locale=en-us"
            }
          ]
        },
        "_embedded":  {
          "venues":  [
             {
              "name": "Trump National Doral",
              "type": "venue",
              "id": "KovZpZAaEldA",
              "test": false,
              "locale": "en-us",
              "postalCode": "33178",
              "timezone": "America/New_York",
              "city":  {
                "name": "Miami"
              },
              "state":  {
                "name": "Florida",
                "stateCode": "FL"
              },
              "country":  {
                "name": "United States Of America",
                "countryCode": "US"
              },
              "address":  {
                "line1": "4400 NW 87th Avenue"
              },
              "location":  {
                "longitude": "-80.33854298",
                "latitude": "25.81260379"
              },
              "markets":  [
                 {
                  "id": "15"
                }
              ],
              "_links":  {
                "self":  {
                  "href": "/discovery/v2/venues/KovZpZAaEldA?locale=en-us"
                }
              }
            }
          ],
          "attractions":  [
             {
              "name": "Cadillac Championship",
              "type": "attraction",
              "id": "K8vZ917uc57",
              "test": false,
              "locale": "en-us",
              "images":  [
                 {
                  "ratio": "16_9",
                  "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_RETINA_LANDSCAPE_16_9.jpg",
                  "width": 1136,
                  "height": 639,
                  "fallback": false
                },
                 {
                  "ratio": "3_2",
                  "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_RETINA_PORTRAIT_3_2.jpg",
                  "width": 640,
                  "height": 427,
                  "fallback": false
                },
                 {
                  "ratio": "16_9",
                  "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_TABLET_LANDSCAPE_LARGE_16_9.jpg",
                  "width": 2048,
                  "height": 1152,
                  "fallback": false
                },
                 {
                  "ratio": "16_9",
                  "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_TABLET_LANDSCAPE_16_9.jpg",
                  "width": 1024,
                  "height": 576,
                  "fallback": false
                },
                 {
                  "ratio": "16_9",
                  "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_EVENT_DETAIL_PAGE_16_9.jpg",
                  "width": 205,
                  "height": 115,
                  "fallback": false
                },
                 {
                  "ratio": "3_2",
                  "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_ARTIST_PAGE_3_2.jpg",
                  "width": 305,
                  "height": 203,
                  "fallback": false
                },
                 {
                  "ratio": "16_9",
                  "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_RETINA_PORTRAIT_16_9.jpg",
                  "width": 640,
                  "height": 360,
                  "fallback": false
                },
                 {
                  "ratio": "4_3",
                  "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_CUSTOM.jpg",
                  "width": 305,
                  "height": 225,
                  "fallback": false
                },
                 {
                  "ratio": "16_9",
                  "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_RECOMENDATION_16_9.jpg",
                  "width": 100,
                  "height": 56,
                  "fallback": false
                },
                 {
                  "ratio": "3_2",
                  "url": "http://s1.ticketm.net/dam/a/196/6095e742-64d1-4b15-aeac-c9733c52d196_66341_TABLET_LANDSCAPE_3_2.jpg",
                  "width": 1024,
                  "height": 683,
                  "fallback": false
                }
              ],
              "classifications":  [
                 {
                  "primary": true,
                  "segment":  {
                    "id": "KZFzniwnSyZfZ7v7nE",
                    "name": "Sports"
                  },
                  "genre":  {
                    "id": "KnvZfZ7vAdt",
                    "name": "Golf"
                  },
                  "subGenre":  {
                    "id": "KZazBEonSMnZfZ7vFI7",
                    "name": "PGA Tour"
                  }
                }
              ],
              "_links":  {
                "self":  {
                  "href": "/discovery/v2/attractions/K8vZ917uc57?locale=en-us"
                }
              }
            }
          ]
        }
      }
    ]
  },
  "page":  {
    "size": 1,
    "totalElements": 87958,
    "totalPages": 87958,
    "number": 0
  }
}
```

## Get Event Details

**Method:** GET

**Summary:** Get Event Details

**Description:** Get details for a specific event using the unique identifier for the event. This includes the venue and location, the attraction(s), and the Ticketmaster Website URL for purchasing tickets for the event.

/discovery/v2/events/{id}

### URL parameters:

Parameter

Description

Type

Default Value

Required

`id`

ID of the event

String

Yes

### Query parameters:

Parameter

Description

Type

Default Value

Required

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

\*

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `_links`(object) - links to data sets
    -   `self`(object) - link to this data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
    -   `venues`(array) - link to this data set.
        -   `{array item object}`
            -   `href`(string) - reference
            -   `templated`(boolean) - ability to be templated
    -   `attractions`(array) - link to this data set.
        -   `{array item object}`
            -   `href`(string) - reference
            -   `templated`(boolean) - ability to be templated
-   `_embedded`(object) - container
    -   `venues`(array) - related
        -   `{array item object}`
            -   `_links`(object) - links to data sets
                -   `self`(object) - link to this data set
                    -   `href`(string) - reference
                    -   `templated`(boolean) - ability to be templated
                -   `venues`(array) - link to this data set.
                    -   `{array item object}`
                        -   `href`(string) - reference
                        -   `templated`(boolean) - ability to be templated
                -   `attractions`(array) - link to this data set.
                    -   `{array item object}`
                        -   `href`(string) - reference
                        -   `templated`(boolean) - ability to be templated
            -   `_links`(object) - links to data sets
                -   `self`(object) - link to this data set
                    -   `href`(string) - reference
                    -   `templated`(boolean) - ability to be templated
            -   `type`(string: enum) - Type of the entity
                -   event
                -   venue
                -   attraction
            -   `distance`(number) - double
            -   `units`(string)
            -   `id`(string) - Unique id of the entity in the discovery API
            -   `locale`(string) - Locale in which the content is returned
            -   `name`(string) - Name of the entity
            -   `description`(string) - Description's of the entity
            -   `address`(object) - Address of the venue
                -   `line1`(string) - Address first line
                -   `line2`(string) - Address second line
                -   `line3`(string) - Address third line
            -   `city`(object) - City of the venue
                -   `name`(string) - Name of the entity
            -   `additionalInfo`(string) - Additional information of the entity
            -   `state`(object) - State / Province of the venue
                -   `stateCode`(string) - State code
                -   `name`(string) - Name of the entity
            -   `country`(object) - Country of the venue
                -   `countryCode`(string) - Country code (ISO 3166)
                -   `name`(string) - Name of the entity
            -   `url`(string) - URL of a web site detail page of the entity
            -   `postalCode`(string) - Postal code / zipcode of the venue
            -   `location`(object) - Location of the venue
                -   `longitude`(number) - Longitude
                -   `latitude`(number) - Latitude
            -   `timezone`(string) - Timezone of the venue
            -   `currency`(string) - Default currency of ticket prices for events in this venue
            -   `markets`(array) - Markets of the venue
                -   `{array item object}`
                    
                    -   `id`(string) - Market's id
                    -   `name`(string) - Name of the entity
            -   `images`(array) - Images of the entity
                -   `{array item object}`
                    
                    -   `url`(string) - Public URL of the image
                    -   `ratio`(string: enum) - Aspect ratio of the image
                        -   16\_9
                        -   3\_2
                        -   4\_3
                    -   `width`(integer) - Width of the image
                    -   `height`(integer) - Height of the image
                    -   `fallback`(boolean) - true if the image is not the event's image but a fallbak image
                    -   `attribution`(string) - Attribution of the image
            -   `dma`(array) - The list of associated DMAs (Designated Market Areas) of the venue
                -   `{array item object}`
                    
                    -   `id`(integer) - DMS's id
            -   `social`(object) - Social networks data
                -   `twitter`(object) - Twitter data
                    -   `handle`(string: enum) - Twitter handle
                        -   @a Twitter handle
                    -   `hashtags`(array) - Twitter hashtags
                        -   `[ "string" ]` - No description specified
            -   `boxOfficeInfo`(object) - Box office informations for the venue
                -   `phoneNumberDetail`(string) - Venue box office phone number
                -   `openHoursDetail`(string) - Venue box office opening hours
                -   `acceptedPaymentDetail`(string) - Venue box office accepted payment details
                -   `willCallDetail`(string) - Venue box office will call details
            -   `parkingDetail`(string) - Venue parking info
            -   `accessibleSeatingDetail`(string) - Venue accessible seating detail
            -   `generalInfo`(object) - General informations on the venue
                -   `generalRule`(string) - Venue general rules
                -   `childRule`(string) - Venue children rule
            -   `externalLinks`(object) - List of external links
            -   `test`(boolean) - Indicate if this is a test entity, by default test entities won't appear in discovery API
            -   `aliases`(array) - List of aliases for entity
                -   `[ "string" ]` - No description specified
            -   `localizedAliases`(object) - List of localized aliases for entity
            -   `upcomingEvents`(object) - number of upcoming events
            -   `ada`(object) - ADA information
                -   `adaPhones`(string)
                -   `adaCustomCopy`(string)
                -   `adaHours`(string)
    -   `attractions`(array) - related
        -   `{array item object}`
            -   `_links`(object) - links to data sets
                -   `self`(object) - link to this data set
                    -   `href`(string) - reference
                    -   `templated`(boolean) - ability to be templated
                -   `venues`(array) - link to this data set.
                    -   `{array item object}`
                        -   `href`(string) - reference
                        -   `templated`(boolean) - ability to be templated
                -   `attractions`(array) - link to this data set.
                    -   `{array item object}`
                        -   `href`(string) - reference
                        -   `templated`(boolean) - ability to be templated
            -   `_links`(object) - links to data sets
                -   `self`(object) - link to this data set
                    -   `href`(string) - reference
                    -   `templated`(boolean) - ability to be templated
            -   `type`(string: enum) - Type of the entity
                -   event
                -   venue
                -   attraction
            -   `id`(string) - Unique id of the entity in the discovery API
            -   `locale`(string) - Locale in which the content is returned
            -   `name`(string) - Name of the entity
            -   `description`(string) - Description's of the entity
            -   `additionalInfo`(string) - Additional information of the entity
            -   `url`(string) - URL of a web site detail page of the entity
            -   `images`(array) - Images of the entity
                -   `{array item object}`
                    
                    -   `url`(string) - Public URL of the image
                    -   `ratio`(string: enum) - Aspect ratio of the image
                        -   16\_9
                        -   3\_2
                        -   4\_3
                    -   `width`(integer) - Width of the image
                    -   `height`(integer) - Height of the image
                    -   `fallback`(boolean) - true if the image is not the event's image but a fallbak image
                    -   `attribution`(string) - Attribution of the image
            -   `classifications`(array) - Attraction's classifications
                -   `{array item object}`
                    
                    -   `primary`(boolean)
                    -   `segment`(object) - A Segment is a primary genre for an entity (Music, Sports, Arts, etc)
                        -   `genres`(array) - List of Genre linked to the Segment
                            -   `{array item object}`
                                
                                -   `subGenres`(array) - List of Tertiary Genre linked to the Secondary Genre
                                    -   `{array item object}`
                                        
                                        -   `id`(string) - The ID of the classification's level
                                        -   `name`(string) - The Name of the classification's level
                                        -   `locale`(string) - Locale in which the content is returned
                                -   `id`(string) - The ID of the classification's level
                                -   `name`(string) - The Name of the classification's level
                                -   `locale`(string) - Locale in which the content is returned
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `genre`(object) - Secondary Genre to further describe an entity (Rock, Classical, Animation, etc)
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `subGenre`(object) - Tertiary Genre for additional detail when describring an entity (Alternative Rock, Ambient Pop, etc)
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `type`(object) - A Type represents a kind or group of people. (Donation, Group, Individual, Merchandise, Event Style, etc)
                        -   `subTypes`(array) - List of Sub Types linked to the Type
                            -   `{array item object}`
                                
                                -   `id`(string) - The ID of the classification's level
                                -   `name`(string) - The Name of the classification's level
                                -   `locale`(string) - Locale in which the content is returned
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `subType`(object) - Secondary Type to further categorize an entity (Band, Choir, Chorus, etc)
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `family`(boolean) - True if this is a family classification
            -   `externalLinks`(object) - List of external links
            -   `test`(boolean) - Indicate if this is a test entity, by default test entities won't appear in discovery API
            -   `aliases`(array) - List of aliases for entity
                -   `[ "string" ]` - No description specified
            -   `localizedAliases`(object) - List of localized aliases for entity
            -   `upcomingEvents`(object) - number of upcoming events
-   `type`(string) - Type of the entity
-   `distance`(number) - double
-   `units`(string) - No description specified
-   `location`(object) - No description specified
    
    -   `longitude`(number) - Longitude
    -   `latitude`(number) - Latitude
-   `id`(string) - Unique id of the entity in the discovery API
-   `locale`(string) - Locale in which the content is returned
-   `name`(string) - Name of the entity
-   `description`(string) - Description's of the entity
-   `additionalInfo`(string) - Additional information of the entity
-   `url`(string) - URL of a web site detail page of the entity
-   `images`(array) - Images of the entity
    -   `{ array item object }`
        -   `url`(string) - Public URL of the image
        -   `ratio`(string: enum) - Aspect ratio of the image
            -   16\_9
            -   3\_2
            -   4\_3
        -   `width`(integer) - Width of the image
        -   `height`(integer) - Height of the image
        -   `fallback`(boolean) - true if the image is not the event's image but a fallbak image
        -   `attribution`(string) - Attribution of the image
-   `dates`(object) - Event's dates information
    
    -   `start`(object) - Event's start dates. The date and time when the event will start
        -   `localDate`(string) - The event start date in local date
        -   `localTime`(object) - The event end time in local time
            -   `millisOfSecond`(integer) - int32
            -   `millisOfDay`(integer) - int32
            -   `secondOfMinute`(integer) - int32
            -   `minuteOfHour`(integer) - int32
            -   `hourOfDay`(integer) - int32
            -   `chronology`(object)
                -   `zone`(object)
                    -   `fixed`(boolean)
                    -   `id`(string)
            -   `values`(array)
                -   `{array item object}`
                    -   `type` (integer)
                    -   `format` (int32)
            -   `fieldTypes`(array)
                -   `{array item object}`
                    
                    -   `durationType`(object)
                        -   `name`(string)
                    -   `rangeDurationType`(object)
                        -   `name`(string)
                    -   `name`(string)
            -   `fields`(array)
                -   `{array item object}`
                    
                    -   `lenient`(boolean)
                    -   `rangeDurationField`(object)
                        -   `unitMillis`(integer) - int64
                        -   `precise`(boolean)
                        -   `name`(string)
                        -   `type`(object)
                            -   `name`(string)
                        -   `supported`(boolean)
                    -   `durationField`(object)
                        -   `unitMillis`(integer) - int64
                        -   `precise`(boolean)
                        -   `name`(string)
                        -   `type`(object)
                            -   `name`(string)
                        -   `supported`(boolean)
                    -   `minimumValue`(integer) - int32
                    -   `maximumValue`(integer) - int32
                    -   `leapDurationField`(object)
                        -   `unitMillis`(integer) - int64
                        -   `precise`(boolean)
                        -   `name`(string)
                        -   `type`(object)
                            -   `name`(string)
                        -   `supported`(boolean)
                    -   `name`(string)
                    -   `type`(object)
                        -   `durationType`(object)
                            -   `name`(string)
                        -   `rangeDurationType`(object)
                            -   `name`(string)
                        -   `name`(string)
                    -   `supported`(boolean)
        -   `dateTime`(string) - The event start datetime
        -   `dateTBD`(boolean) - Boolean flag to indicate whether or not the start date is TBD
        -   `dateTBA`(boolean) - Boolean flag to indicate whether or not the start date is TBA
        -   `timeTBA`(boolean) - Boolean flag to indicate whether or not the start time is TBA
        -   `noSpecificTime`(boolean) - Boolean flag to indicate whether or not the event start time has no specific time
    -   `end`(object) - Event's end dates. The date and time when the event will end
        -   `localDate`(string) - The event end date in local date
        -   `localTime`(object) - The event end time in local time
            -   `millisOfSecond`(integer) - int32
            -   `millisOfDay`(integer) - int32
            -   `secondOfMinute`(integer) - int32
            -   `minuteOfHour`(integer) - int32
            -   `hourOfDay`(integer) - int32
            -   `chronology`(object)
                -   `zone`(object)
                    -   `fixed`(boolean)
                    -   `id`(string)
            -   `values`(array)
                -   `{array item object}`
                    -   `type` (integer)
                    -   `format` (int32)
            -   `fieldTypes`(array)
                -   `{array item object}`
                    
                    -   `durationType`(object)
                        -   `name`(string)
                    -   `rangeDurationType`(object)
                        -   `name`(string)
                    -   `name`(string)
            -   `fields`(array)
                -   `{array item object}`
                    
                    -   `lenient`(boolean)
                    -   `rangeDurationField`(object)
                        -   `unitMillis`(integer) - int64
                        -   `precise`(boolean)
                        -   `name`(string)
                        -   `type`(object)
                            -   `name`(string)
                        -   `supported`(boolean)
                    -   `durationField`(object)
                        -   `unitMillis`(integer) - int64
                        -   `precise`(boolean)
                        -   `name`(string)
                        -   `type`(object)
                            -   `name`(string)
                        -   `supported`(boolean)
                    -   `minimumValue`(integer) - int32
                    -   `maximumValue`(integer) - int32
                    -   `leapDurationField`(object)
                        -   `unitMillis`(integer) - int64
                        -   `precise`(boolean)
                        -   `name`(string)
                        -   `type`(object)
                            -   `name`(string)
                        -   `supported`(boolean)
                    -   `name`(string)
                    -   `type`(object)
                        -   `durationType`(object)
                            -   `name`(string)
                        -   `rangeDurationType`(object)
                            -   `name`(string)
                        -   `name`(string)
                    -   `supported`(boolean)
        -   `dateTime`(string) - The event end date time
        -   `approximate`(boolean) - Boolean flag to indicate whether or not the end date is approximated
        -   `noSpecificTime`(boolean) - Boolean flag to indicate whether or not the event end time has no specific time
    -   `access`(object) - Event's access dates. The date and time the fan can access the event
        -   `startDateTime`(string) - Event's start access time
        -   `startApproximate`(boolean) - Boolean flag to indicate whether or not the access start date is approximated
        -   `endDateTime`(string) - Event's end access time
        -   `endApproximate`(boolean) - Boolean flag to indicate whether or not the access end date is approximated
    -   `timezone`(string) - Event's timezone
    -   `status`(object) - Status of the event
        -   `code`(string: enum) - The event's status code
            -   onsale
            -   offsale
            -   canceled
            -   postponed
            -   rescheduled
    -   `spanMultipleDays`(boolean) - Flag indicating if date spans of multiple days
-   `sales`(object) - Event's sales dates information
    
    -   `public`(object) - Public onsale information on this event
        -   `startDateTime`(string) - Public sale's start dates. The date and time when the public sale will start
        -   `endDateTime`(string) - Public sale's end dates. The date and time when the public sale will end
        -   `startTBD`(boolean) - True if the public sale's date is to be determined
    -   `presales`(array) - Presale information on this event
        -   `{array item object}`
            
            -   `name`(string) - Name of the presale
            -   `description`(string) - Description of the presame
            -   `url`(string) - Presale link URL
            -   `startDateTime`(string) - Presale's start dates. The date and time when the presale will start
            -   `endDateTime`(string) - Presale's end dates. The date and time when the presale will end
-   `info`(string) - Any information related to the event
-   `pleaseNote`(string) - Any notes related to the event
-   `priceRanges`(array) - Price ranges of this event
    -   `{ array item object }`
        -   `type`(string: enum) - Type of price
            -   standard
        -   `currency`(string) - Currency
        -   `min`(number) - Minimum price
        -   `max`(number) - Maximum price
-   `promoter`(object) - Event's promoter
    
    -   `id`(string) - Id of the promoter
    -   `name`(string) - Name of the promoter
    -   `description`(string) - Description of the promoter
-   `promoters`(array) - Event's promoters
    -   `{ array item object }`
        -   `id`(string) - Id of the promoter
        -   `name`(string) - Name of the promoter
        -   `description`(string) - Description of the promoter
-   `outlets`(array) - Related outlets informations
    -   `{ array item object }`
        -   `url`(string) - Outlet's url
        -   `type`(string) - Outlet's type
-   `productType`(string) - Product type
-   `products`(array) - Related products informations
    -   `{ array item object }`
        -   `name`(string) - Name of the entity
        -   `id`(string) - Product's primary id
        -   `url`(string) - Product's url
        -   `type`(string) - Product's type
-   `seatmap`(object) - Event's seatmap
    
    -   `staticUrl`(string) - Static Seatmap Url
-   `accessibility`(object) - Additional information for people who experience disabilities
    
    -   `info`(string) - Accessibility's information
-   `ticketLimit`(object) - ticket limit
    
    -   `infos`(object) - ticket limits text - multi-lingual fields
    -   `info`(string) - ticket limits text
-   `classifications`(array) - Event's classifications
    -   `{ array item object }`
        -   `primary`(boolean)
        -   `segment`(object) - A Segment is a primary genre for an entity (Music, Sports, Arts, etc)
            -   `genres`(array) - List of Genre linked to the Segment
                -   `{array item object}`
                    
                    -   `subGenres`(array) - List of Tertiary Genre linked to the Secondary Genre
                        -   `{array item object}`
                            
                            -   `id`(string) - The ID of the classification's level
                            -   `name`(string) - The Name of the classification's level
                            -   `locale`(string) - Locale in which the content is returned
                    -   `id`(string) - The ID of the classification's level
                    -   `name`(string) - The Name of the classification's level
                    -   `locale`(string) - Locale in which the content is returned
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
        -   `genre`(object) - Secondary Genre to further describe an entity (Rock, Classical, Animation, etc)
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
        -   `subGenre`(object) - Tertiary Genre for additional detail when describring an entity (Alternative Rock, Ambient Pop, etc)
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
        -   `type`(object) - A Type represents a kind or group of people. (Donation, Group, Individual, Merchandise, Event Style, etc)
            -   `subTypes`(array) - List of Sub Types linked to the Type
                -   `{array item object}`
                    
                    -   `id`(string) - The ID of the classification's level
                    -   `name`(string) - The Name of the classification's level
                    -   `locale`(string) - Locale in which the content is returned
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
        -   `subType`(object) - Secondary Type to further categorize an entity (Band, Choir, Chorus, etc)
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
        -   `family`(boolean) - True if this is a family classification
-   `place`(object) - Place has the information on where the event happens. It can be set if there is no venue
    
    -   `area`(object) - Area of the place
        -   `name`(string) - Name of the entity
    -   `address`(object) - Address of the place
        -   `line1`(string) - Address first line
        -   `line2`(string) - Address second line
        -   `line3`(string) - Address third line
    -   `city`(object) - City of the Place
        -   `name`(string) - Name of the entity
    -   `state`(object) - State / Province of the place
        -   `stateCode`(string) - State code
        -   `name`(string) - Name of the entity
    -   `country`(object) - Country of the place
        -   `countryCode`(string) - Country code (ISO 3166)
        -   `name`(string) - Name of the entity
    -   `postalCode`(string) - Postal code / zipcode of the place
    -   `location`(object) - Location of the place
        -   `longitude`(number) - Longitude
        -   `latitude`(number) - Latitude
    -   `name`(string) - Name of the entity
-   `externalLinks`(object) - List of external links
-   `test`(boolean) - Indicate if this is a test entity, by default test entities won't appear in discovery API
-   `aliases`(array) - List of aliases for entity
    -   `[ "string" ]`
        
-   `localizedAliases`(object) - List of localized aliases for entity

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/events/G5diZfkn0B-bh.json?apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \
--include 'https://app.ticketmaster.com/discovery/v2/events/G5diZfkn0B-bh.json?apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/events/G5diZfkn0B-bh.json?apikey={apikey} HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: https://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Rate-Limit-Over: 0
Content-Length: 5555
Rate-Limit-Available: 4722
Set-Cookie: CMPS=cE7N5yujrQNGYWvJF3bAH6iRNHwAv0FDp5i1VDetaW6+WW5OZTOBve6ZQCpN9qCv; path=/
Access-Control-Max-Age: 3628800
Access-Control-Allow-Methods: GET, PUT, POST, DELETE
Connection: keep-alive
Server: Apache-Coyote/1.1
Rate-Limit-Reset: 1457417554290
Access-Control-Allow-Headers: origin, x-requested-with, accept
Date: Mon, 07 Mar 2016 10:12:45 GMT
Access-Control-Allow-Origin: *
X-Application-Context: application:local,default,jphx1:8080
Content-Type: application/json;charset=utf-8
X-Unknown-Params: apikey
X-Unknown-Params: api-key
Rate-Limit: 5000

{
  "_embedded": {
    "venues": [
      {
        "name": "Madison Square Garden",
        "type": "venue",
        "id": "KovZpZA7AAEA",
        "test": false,
        "url": "http://ticketmaster.com/venue/483329",
        "locale": "en-us",
        "postalCode": "10001",
        "timezone": "America/New_York",
        "city": {
          "name": "New York"
        },
        "state": {
          "name": "New York",
          "stateCode": "NY"
        },
        "country": {
          "name": "United States Of America",
          "countryCode": "US"
        },
        "address": {
          "line1": "7th Ave & 32nd Street"
        },
        "location": {
          "longitude": "-73.99160060",
          "latitude": "40.74970620"
        },
        "markets": [
          {
            "id": "35"
          },
          {
            "id": "51"
          },
          {
            "id": "55"
          },
          {
            "id": "124"
          }
        ],
        "dmas": [
          {
            "id": 200
          },
          {
            "id": 296
          },
          {
            "id": 345
          },
          {
            "id": 422
          }
        ],
        "_links": {
          "self": {
            "href": "/discovery/v2/venues/KovZpZA7AAEA?locale=en-us"
          }
        }
      }
    ],
    "attractions": [
      {
        "name": "Radiohead",
        "type": "attraction",
        "id": "K8vZ91713wV",
        "test": false,
        "url": "http://ticketmaster.com/artist/763468",
        "locale": "en-us",
        "images": [
          {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_EVENT_DETAIL_PAGE_16_9.jpg",
            "width": 205,
            "height": 115,
            "fallback": false
          },
          {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_RETINA_LANDSCAPE_16_9.jpg",
            "width": 1136,
            "height": 639,
            "fallback": false
          },
          {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_RETINA_PORTRAIT_16_9.jpg",
            "width": 640,
            "height": 360,
            "fallback": false
          },
          {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_RECOMENDATION_16_9.jpg",
            "width": 100,
            "height": 56,
            "fallback": false
          },
          {
            "ratio": "3_2",
            "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_RETINA_PORTRAIT_3_2.jpg",
            "width": 640,
            "height": 427,
            "fallback": false
          },
          {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_TABLET_LANDSCAPE_16_9.jpg",
            "width": 1024,
            "height": 576,
            "fallback": false
          },
          {
            "ratio": "3_2",
            "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_ARTIST_PAGE_3_2.jpg",
            "width": 305,
            "height": 203,
            "fallback": false
          },
          {
            "ratio": "16_9",
            "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_TABLET_LANDSCAPE_LARGE_16_9.jpg",
            "width": 2048,
            "height": 1152,
            "fallback": false
          },
          {
            "ratio": "3_2",
            "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_TABLET_LANDSCAPE_3_2.jpg",
            "width": 1024,
            "height": 683,
            "fallback": false
          },
          {
            "ratio": "4_3",
            "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_CUSTOM.jpg",
            "width": 305,
            "height": 225,
            "fallback": false
          }
        ],
        "classifications": [
          {
            "primary": true,
            "segment": {
              "id": "KZFzniwnSyZfZ7v7nJ",
              "name": "Music"
            },
            "genre": {
              "id": "KnvZfZ7vAeA",
              "name": "Rock"
            },
            "subGenre": {
              "id": "KZazBEonSMnZfZ7v6dt",
              "name": "Alternative Rock"
            }
          }
        ],
        "_links": {
          "self": {
            "href": "/discovery/v2/attractions/K8vZ91713wV?locale=en-us"
          }
        }
      }
    ]
  },
  "_links": {
    "self": {
      "href": "/discovery/v2/events/G5diZfkn0B-bh?locale=en-us"
    },
    "attractions": [
      {
        "href": "/discovery/v2/attractions/K8vZ91713wV?locale=en-us"
      }
    ],
    "venues": [
      {
        "href": "/discovery/v2/venues/KovZpZA7AAEA?locale=en-us"
      }
    ]
  },
  "classifications": [
    {
      "primary": true,
      "segment": {
        "id": "KZFzniwnSyZfZ7v7nJ",
        "name": "Music"
      },
      "genre": {
        "id": "KnvZfZ7vAeA",
        "name": "Rock"
      },
      "subGenre": {
        "id": "KZazBEonSMnZfZ7v6dt",
        "name": "Alternative Rock"
      }
    }
  ],
  "dates": {
    "start": {
      "localDate": "2016-07-27",
      "localTime": "19:30:00",
      "dateTime": "2016-07-27T23:30:00Z",
      "dateTBD": false,
      "dateTBA": false,
      "timeTBA": false,
      "noSpecificTime": false
    },
    "timezone": "America/New_York",
    "status": {
      "code": "onsale"
    }
  },
  "id": "G5diZfkn0B-bh",
  "images": [
    {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_EVENT_DETAIL_PAGE_16_9.jpg",
      "width": 205,
      "height": 115,
      "fallback": false
    },
    {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_RETINA_LANDSCAPE_16_9.jpg",
      "width": 1136,
      "height": 639,
      "fallback": false
    },
    {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_RETINA_PORTRAIT_16_9.jpg",
      "width": 640,
      "height": 360,
      "fallback": false
    },
    {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_RECOMENDATION_16_9.jpg",
      "width": 100,
      "height": 56,
      "fallback": false
    },
    {
      "ratio": "3_2",
      "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_RETINA_PORTRAIT_3_2.jpg",
      "width": 640,
      "height": 427,
      "fallback": false
    },
    {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_TABLET_LANDSCAPE_16_9.jpg",
      "width": 1024,
      "height": 576,
      "fallback": false
    },
    {
      "ratio": "3_2",
      "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_ARTIST_PAGE_3_2.jpg",
      "width": 305,
      "height": 203,
      "fallback": false
    },
    {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_TABLET_LANDSCAPE_LARGE_16_9.jpg",
      "width": 2048,
      "height": 1152,
      "fallback": false
    },
    {
      "ratio": "3_2",
      "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_TABLET_LANDSCAPE_3_2.jpg",
      "width": 1024,
      "height": 683,
      "fallback": false
    },
    {
      "ratio": "4_3",
      "url": "http://s1.ticketm.net/dam/a/c4c/e751ab33-b9cd-4d24-ad4a-5ef79faa7c4c_72681_CUSTOM.jpg",
      "width": 305,
      "height": 225,
      "fallback": false
    }
  ],
  "locale": "en-us",
  "name": "Radiohead",
  "pleaseNote": "No tickets will be delivered prior to April 18th. Tickets are not available at the box office on the first day of the public on sale. ARRIVE EARLY: Please arrive one-hour prior to showtime. All packages, including briefcases and pocketbooks, will be inspected prior to entry.",
  "priceRanges": [
    {
      "type": "standard",
      "currency": "USD",
      "min": 80,
      "max": 80
    }
  ],
  "promoter": {
    "id": "494"
  },
  "sales": {
    "public": {
      "startDateTime": "2016-03-18T14:00:00Z",
      "startTBD": false,
      "endDateTime": "2016-07-27T21:30:00Z"
    }
  },
  "test": false,
  "type": "event",
  "url": "http://ticketmaster.com/event/3B00506AA4EA161B"
}
```

## Get Event Images

**Method:** GET

**Summary:** Get Event Images

**Description:** Get images for a specific event using the unique identifier for the event.

/discovery/v2/events/{id}/images

### URL parameters:

Parameter

Description

Type

Default Value

Required

`id`

ID of the event

String

Yes

### Query parameters:

Parameter

Description

Type

Default Value

Required

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

\*

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `_links`(object) - links to data sets
    -   `self`(object) - link to this data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
-   `type`(string) - Type of the entity
-   `id`(string) - Unique id of the entity in the discovery API
-   `images`(array) - Images of the entity
    -   `{ array item object }`
        -   `url`(string) - Public URL of the image
        -   `ratio`(string: enum) - Aspect ratio of the image
            -   16\_9
            -   3\_2
            -   4\_3
        -   `width`(integer) - Width of the image
        -   `height`(integer) - Height of the image
        -   `fallback`(boolean) - true if the image is not the event's image but a fallbak image
        -   `attribution`(string) - Attribution of the image

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/events/k7vGFKzleBdwS/images.json?apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \
--include 'https://app.ticketmaster.com/discovery/v2/events/k7vGFKzleBdwS/images.json?apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/events/0B004F0401BD55E5/images.json?apikey={apikey} HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: https://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Rate-Limit-Over: 0
Content-Length: 1791
Rate-Limit-Available: 4721
Set-Cookie: CMPS=JZE+KB6vdvAgtu5+7+q5LjU8d3RbODYo2jv3r5+vwk0BcMxjtg3kAFdo3D2gFulS; path=/
Access-Control-Max-Age: 3628800
Access-Control-Allow-Methods: GET, PUT, POST, DELETE
Connection: keep-alive
Server: Apache-Coyote/1.1
Rate-Limit-Reset: 1457417554290
Access-Control-Allow-Headers: origin, x-requested-with, accept
Date: Mon, 07 Mar 2016 10:15:18 GMT
Access-Control-Allow-Origin: *
X-Application-Context: application:local,default,jphx1:8080
Content-Type: application/json;charset=utf-8
X-Unknown-Params: apikey
X-Unknown-Params: api-key
Rate-Limit: 5000

{
  "type": "event",
  "id": "k7vGFKzleBdwS",
  "images":  [
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/063/1689bfea-ae98-4c7e-a31d-bbca2dd14063_54361_RECOMENDATION_16_9.jpg",
      "width": 100,
      "height": 56,
      "fallback": false
    },
     {
      "ratio": "3_2",
      "url": "http://s1.ticketm.net/dam/a/063/1689bfea-ae98-4c7e-a31d-bbca2dd14063_54361_ARTIST_PAGE_3_2.jpg",
      "width": 305,
      "height": 203,
      "fallback": false
    },
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/063/1689bfea-ae98-4c7e-a31d-bbca2dd14063_54361_TABLET_LANDSCAPE_LARGE_16_9.jpg",
      "width": 2048,
      "height": 1152,
      "fallback": false
    },
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/063/1689bfea-ae98-4c7e-a31d-bbca2dd14063_54361_RETINA_LANDSCAPE_16_9.jpg",
      "width": 1136,
      "height": 639,
      "fallback": false
    },
     {
      "ratio": "3_2",
      "url": "http://s1.ticketm.net/dam/a/063/1689bfea-ae98-4c7e-a31d-bbca2dd14063_54361_TABLET_LANDSCAPE_3_2.jpg",
      "width": 1024,
      "height": 683,
      "fallback": false
    },
     {
      "ratio": "4_3",
      "url": "http://s1.ticketm.net/dam/a/063/1689bfea-ae98-4c7e-a31d-bbca2dd14063_54361_CUSTOM.jpg",
      "width": 305,
      "height": 225,
      "fallback": false
    },
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/063/1689bfea-ae98-4c7e-a31d-bbca2dd14063_54361_EVENT_DETAIL_PAGE_16_9.jpg",
      "width": 205,
      "height": 115,
      "fallback": false
    },
     {
      "ratio": "3_2",
      "url": "http://s1.ticketm.net/dam/a/063/1689bfea-ae98-4c7e-a31d-bbca2dd14063_54361_RETINA_PORTRAIT_3_2.jpg",
      "width": 640,
      "height": 427,
      "fallback": false
    },
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/063/1689bfea-ae98-4c7e-a31d-bbca2dd14063_54361_RETINA_PORTRAIT_16_9.jpg",
      "width": 640,
      "height": 360,
      "fallback": false
    },
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/063/1689bfea-ae98-4c7e-a31d-bbca2dd14063_54361_TABLET_LANDSCAPE_16_9.jpg",
      "width": 1024,
      "height": 576,
      "fallback": false
    }
  ],
  "_links":  {
    "self":  {
      "href": "/discovery/v2/events/k7vGFKzleBdwS/images?locale=en-us"
    }
  }
}
```

## Attraction Search

**Method:** GET

**Summary:** Attraction Search

**Description:** Find attractions (artists, sports, packages, plays and so on) and filter your search by name, and much more.

/discovery/v2/attractions

### Query parameters:

Parameter

Description

Type

Default Value

Required

`id`

Filter entities by its id

String

No

`keyword`

Keyword to search on

String

No

`source`

Filter entities by its primary source name OR publishing source name

String enum:\["ticketmaster", " universe", " frontgate", " tmr"\]

No

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

en

No

`includeTest`

Yes if you want to have entities flag as test in the response. Only, if you only wanted test entities

String enum:\["yes", " no", " only"\]

no

No

`size`

Page size of the response

String

20

No

`page`

Page number

String

0

No

`sort`

Sorting order of the search result. Allowable Values : 'name,asc', 'name,desc', 'relevance,asc', 'relevance,desc', 'random'

String

relevance,desc

No

`classificationName`

Filter attractions by classification name: name of any segment, genre, sub-genre, type, sub-type

Array

No

`classificationId`

Filter attractions by classification id: id of any segment, genre, sub-genre, type, sub-type

Array

No

`includeFamily`

Filter by classification that are family-friendly

String enum:\["yes", " no", " only"\]

yes

No

`segmentId`

Filter attractions by segmentId

Array

No

`genreId`

Filter attractions by genreId

Array

No

`subGenreId`

Filter attractions by subGenreId

Array

No

`typeId`

Filter attractions by typeId

Array

No

`subTypeId`

Filter attractions by subTypeId

Array

No

`preferredCountry`

Popularity boost by country, default is us.

String enum:\["us", " ca"\]

us

No

`includeSpellcheck`

yes, to include spell check suggestions in the response.

String enum:\["yes", " no"\]

no

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `_links`(object) - links to data sets
    -   `self`(object) - link to this data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
    -   `next`(object) - link to the next data set
        -   `href`(string) - reference.
        -   `templated`(boolean) - ability to be templated
    -   `prev`(object) - link to the previous data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
-   `_embedded`(object) - container
    -   `attractions`(array)
        -   `{array item object}`
            -   `_links`(object) - links to data sets
                -   `self`(object) - link to this data set
                    -   `href`(string) - reference
                    -   `templated`(boolean) - ability to be templated
            -   `type`(string) - Type of the entity
            -   `id`(string) - Unique id of the entity in the discovery API
            -   `locale`(string) - Locale in which the content is returned
            -   `name`(string) - Name of the entity
            -   `description`(string) - Description's of the entity
            -   `additionalInfo`(string) - Additional information of the entity
            -   `url`(string) - URL of a web site detail page of the entity
            -   `images`(array) - Images of the entity
                -   `{ array item object }`
                    -   `url`(string) - Public URL of the image
                    -   `ratio`(string: enum) - Aspect ratio of the image
                        -   16\_9
                        -   3\_2
                        -   4\_3
                    -   `width`(integer) - Width of the image
                    -   `height`(integer) - Height of the image
                    -   `fallback`(boolean) - true if the image is not the event's image but a fallbak image
                    -   `attribution`(string) - Attribution of the image
            -   `classifications`(array) - Attraction's classifications
                -   `{ array item object }`
                    -   `primary`(boolean)
                    -   `segment`(object) - A Segment is a primary genre for an entity (Music, Sports, Arts, etc)
                        -   `genres`(array) - List of Genre linked to the Segment
                            -   `{array item object}`
                                
                                -   `subGenres`(array) - List of Tertiary Genre linked to the Secondary Genre
                                    -   `{array item object}`
                                        
                                        -   `id`(string) - The ID of the classification's level
                                        -   `name`(string) - The Name of the classification's level
                                        -   `locale`(string) - Locale in which the content is returned
                                -   `id`(string) - The ID of the classification's level
                                -   `name`(string) - The Name of the classification's level
                                -   `locale`(string) - Locale in which the content is returned
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `genre`(object) - Secondary Genre to further describe an entity (Rock, Classical, Animation, etc)
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `subGenre`(object) - Tertiary Genre for additional detail when describring an entity (Alternative Rock, Ambient Pop, etc)
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `type`(object) - A Type represents a kind or group of people. (Donation, Group, Individual, Merchandise, Event Style, etc)
                        -   `subTypes`(array) - List of Sub Types linked to the Type
                            -   `{array item object}`
                                
                                -   `id`(string) - The ID of the classification's level
                                -   `name`(string) - The Name of the classification's level
                                -   `locale`(string) - Locale in which the content is returned
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `subType`(object) - Secondary Type to further categorize an entity (Band, Choir, Chorus, etc)
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                    -   `family`(boolean) - True if this is a family classification
            -   `externalLinks`(object) - List of external links
            -   `test`(boolean) - Indicate if this is a test entity, by default test entities won't appear in discovery API
            -   `aliases`(array) - List of aliases for entity
                -   `[ "string" ]`
                    
            -   `localizedAliases`(object) - List of localized aliases for entity
            -   `upcomingEvents`(object) - number of upcoming events
-   `page`(object) - information about current page in data source
    -   `size`(number) - size of page.
    -   `totalElements`(number) - total number of available elements in server
    -   `totalPages`(number) - total number of available pages in server
    -   `number`(number) - current page number counted from 0

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/attractions.json?apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \
--include 'https://app.ticketmaster.com/discovery/v2/attractions.json?apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/attractions.json?size=1&apikey={apikey} HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: https://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Rate-Limit-Over: 0
Content-Length: 2306
Rate-Limit-Available: 4719
Set-Cookie: CMPS=twsJFiJCd9puX3QeIpWdz1Co+AFBb0GGb2S5IpJoKGFAy6VVeUUgAfUsgfrWYV89; path=/
Access-Control-Max-Age: 3628800
Access-Control-Allow-Methods: GET, PUT, POST, DELETE
Connection: keep-alive
Server: Apache-Coyote/1.1
Rate-Limit-Reset: 1457417554290
Access-Control-Allow-Headers: origin, x-requested-with, accept
Date: Mon, 07 Mar 2016 10:17:30 GMT
Access-Control-Allow-Origin: *
X-Application-Context: application:local,default,jphx1:8080
Content-Type: application/json;charset=utf-8
X-Unknown-Params: apikey
X-Unknown-Params: api-key
Rate-Limit: 5000

{
  "_links":  {},
  "_embedded":  {
    "attractions":  [
       {
        "name": "!!!",
        "type": "attraction",
        "id": "K8vZ9175BhV",
        "test": false,
        "locale": "en-us",
        "images":  [],
        "classifications":  [
           {
            "primary": true,
            "segment":  {
              "id": "KZFzniwnSyZfZ7v7nJ",
              "name": "Music"
            },
            "genre":  {
              "id": "KnvZfZ7vAeA",
              "name": "Rock"
            },
            "subGenre":  {
              "id": "KZazBEonSMnZfZ7v6F1",
              "name": "Pop"
            }
          }
        ],
        "_links":  {
          "self":  {
            "href": "/discovery/v2/attractions/K8vZ9175BhV?locale=en-us"
          }
        }
      }
    ]
  },
  "page":  {
    "size": 1,
    "totalElements": 162165,
    "totalPages": 162165,
    "number": 0
  }
}
```

## Get Attraction Details

**Method:** GET

**Summary:** Get Attraction Details

**Description:** Get details for a specific attraction using the unique identifier for the attraction.

/discovery/v2/attractions/{id}

### URL parameters:

Parameter

Description

Type

Default Value

Required

`id`

ID of the attraction

String

Yes

### Query parameters:

Parameter

Description

Type

Default Value

Required

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

\*

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `_links`(object) - links to data sets
    -   `self`(object) - link to this data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
-   `type`(string) - Type of the entity
-   `id`(string) - Unique id of the entity in the discovery API
-   `locale`(string) - Locale in which the content is returned
-   `name`(string) - Name of the entity
-   `description`(string) - Description's of the entity
-   `additionalInfo`(string) - Additional information of the entity
-   `url`(string) - URL of a web site detail page of the entity
-   `images`(array) - Images of the entity
    -   `{ array item object }`
        -   `url`(string) - Public URL of the image
        -   `ratio`(string: enum) - Aspect ratio of the image
            -   16\_9
            -   3\_2
            -   4\_3
        -   `width`(integer) - Width of the image
        -   `height`(integer) - Height of the image
        -   `fallback`(boolean) - true if the image is not the event's image but a fallbak image
        -   `attribution`(string) - Attribution of the image
-   `classifications`(array) - Attraction's classifications
    -   `{ array item object }`
        -   `primary`(boolean)
        -   `segment`(object) - A Segment is a primary genre for an entity (Music, Sports, Arts, etc)
            -   `genres`(array) - List of Genre linked to the Segment
                -   `{array item object}`
                    
                    -   `subGenres`(array) - List of Tertiary Genre linked to the Secondary Genre
                        -   `{array item object}`
                            
                            -   `id`(string) - The ID of the classification's level
                            -   `name`(string) - The Name of the classification's level
                            -   `locale`(string) - Locale in which the content is returned
                    -   `id`(string) - The ID of the classification's level
                    -   `name`(string) - The Name of the classification's level
                    -   `locale`(string) - Locale in which the content is returned
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
        -   `genre`(object) - Secondary Genre to further describe an entity (Rock, Classical, Animation, etc)
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
        -   `subGenre`(object) - Tertiary Genre for additional detail when describring an entity (Alternative Rock, Ambient Pop, etc)
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
        -   `type`(object) - A Type represents a kind or group of people. (Donation, Group, Individual, Merchandise, Event Style, etc)
            -   `subTypes`(array) - List of Sub Types linked to the Type
                -   `{array item object}`
                    
                    -   `id`(string) - The ID of the classification's level
                    -   `name`(string) - The Name of the classification's level
                    -   `locale`(string) - Locale in which the content is returned
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
        -   `subType`(object) - Secondary Type to further categorize an entity (Band, Choir, Chorus, etc)
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
        -   `family`(boolean) - True if this is a family classification
-   `externalLinks`(object) - List of external links
-   `test`(boolean) - Indicate if this is a test entity, by default test entities won't appear in discovery API
-   `aliases`(array) - List of aliases for entity
    -   `[ "string" ]`
        
-   `localizedAliases`(object) - List of localized aliases for entity
-   `upcomingEvents`(object) - number of upcoming events

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/attractions/K8vZ9175BhV.json?apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \
--include 'https://app.ticketmaster.com/discovery/v2/attractions/K8vZ9175BhV.json?apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/attractions/K8vZ9175BhV.json?apikey={apikey} HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: https://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Rate-Limit-Over: 0
Content-Length: 2019
Rate-Limit-Available: 4715
Set-Cookie: CMPS=5vv+AGPecM7pv5Q4MmLBniGH0DBXyfh68w9nYydgerFjBhsCrQs1DbTINMWnUgrDL0UGMYDHSDc=; path=/
Access-Control-Max-Age: 3628800
Access-Control-Allow-Methods: GET, PUT, POST, DELETE
Connection: keep-alive
Server: Apache-Coyote/1.1
Rate-Limit-Reset: 1457417554290
Access-Control-Allow-Headers: origin, x-requested-with, accept
Date: Mon, 07 Mar 2016 10:21:02 GMT
Access-Control-Allow-Origin: *
X-Application-Context: application:local,default,jash1:8080
Content-Type: application/json;charset=utf-8
X-Unknown-Params: apikey
X-Unknown-Params: api-key
Rate-Limit: 5000

{
  "name": "!!!",
  "type": "attraction",
  "id": "K8vZ9175BhV",
  "test": false,
  "locale": "en-us",
  "images":  [
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/418/aa73b994-9912-4535-ba21-4865ae93a418_41291_RECOMENDATION_16_9.jpg",
      "width": 100,
      "height": 56,
      "fallback": false
    },
     {
      "ratio": "4_3",
      "url": "http://s1.ticketm.net/dam/a/418/aa73b994-9912-4535-ba21-4865ae93a418_41291_CUSTOM.jpg",
      "width": 305,
      "height": 225,
      "fallback": false
    },
     {
      "ratio": "3_2",
      "url": "http://s1.ticketm.net/dam/a/418/aa73b994-9912-4535-ba21-4865ae93a418_41291_ARTIST_PAGE_3_2.jpg",
      "width": 305,
      "height": 203,
      "fallback": false
    },
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/418/aa73b994-9912-4535-ba21-4865ae93a418_41291_RETINA_PORTRAIT_16_9.jpg",
      "width": 640,
      "height": 360,
      "fallback": false
    },
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/418/aa73b994-9912-4535-ba21-4865ae93a418_41291_TABLET_LANDSCAPE_LARGE_16_9.jpg",
      "width": 2048,
      "height": 1152,
      "fallback": false
    },
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/418/aa73b994-9912-4535-ba21-4865ae93a418_41291_TABLET_LANDSCAPE_16_9.jpg",
      "width": 1024,
      "height": 576,
      "fallback": false
    },
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/418/aa73b994-9912-4535-ba21-4865ae93a418_41291_RETINA_LANDSCAPE_16_9.jpg",
      "width": 1136,
      "height": 639,
      "fallback": false
    },
     {
      "ratio": "3_2",
      "url": "http://s1.ticketm.net/dam/a/418/aa73b994-9912-4535-ba21-4865ae93a418_41291_RETINA_PORTRAIT_3_2.jpg",
      "width": 640,
      "height": 427,
      "fallback": false
    },
     {
      "ratio": "16_9",
      "url": "http://s1.ticketm.net/dam/a/418/aa73b994-9912-4535-ba21-4865ae93a418_41291_EVENT_DETAIL_PAGE_16_9.jpg",
      "width": 205,
      "height": 115,
      "fallback": false
    },
     {
      "ratio": "3_2",
      "url": "http://s1.ticketm.net/dam/a/418/aa73b994-9912-4535-ba21-4865ae93a418_41291_TABLET_LANDSCAPE_3_2.jpg",
      "width": 1024,
      "height": 683,
      "fallback": false
    }
  ],
  "classifications":  [
     {
      "primary": true,
      "segment":  {
        "id": "KZFzniwnSyZfZ7v7nJ",
        "name": "Music"
      },
      "genre":  {
        "id": "KnvZfZ7vAeA",
        "name": "Rock"
      },
      "subGenre":  {
        "id": "KZazBEonSMnZfZ7v6F1",
        "name": "Pop"
      }
    }
  ],
  "_links":  {
    "self":  {
      "href": "/discovery/v2/attractions/K8vZ9175BhV?locale=en-us"
    }
  }
}
```

## Classification Search

**Method:** GET

**Summary:** Classification Search

**Description:** Find classifications and filter your search by name, and much more. Classifications help define the nature of attractions and events.

/discovery/v2/classifications

### Query parameters:

Parameter

Description

Type

Default Value

Required

`id`

Filter entities by its id

String

No

`keyword`

Keyword to search on

String

No

`source`

Filter entities by its primary source name OR publishing source name

String enum:\["ticketmaster", " universe", " frontgate", " tmr"\]

No

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

en

No

`includeTest`

Yes if you want to have entities flag as test in the response. Only, if you only wanted test entities

String enum:\["yes", " no", " only"\]

no

No

`size`

Page size of the response

String

20

No

`page`

Page number

String

0

No

`sort`

Sorting order of the search result

String

name,asc

No

`preferredCountry`

Popularity boost by country, default is us.

String enum:\["us", " ca"\]

us

No

`includeSpellcheck`

yes, to include spell check suggestions in the response.

String enum:\["yes", " no"\]

no

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `_links`(object) - links to data sets
    -   `self`(object) - link to this data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
    -   `next`(object) - link to the next data set
        -   `href`(string) - reference.
        -   `templated`(boolean) - ability to be templated
    -   `prev`(object) - link to the previous data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
-   `_embedded`(object) - container
    -   `classifications`(array)
        -   `{array item object}`
            -   `_links`(object) - links to data sets
                -   `self`(object) - link to this data set
                    -   `href`(string) - reference
                    -   `templated`(boolean) - ability to be templated
            -   `segment`(object) - A Segment is a primary genre for an entity (Music, Sports, Arts, etc)
                
                -   `_embedded`(object) - container for genres.
                    -   `genres`(object)
                        -   `{array item object}`
                            
                            -   `_embedded`(object) - container for subgenres.
                                
                                -   `subgenres`(object) - Tertiary Genre for additional detail when describring an entity (Alternative Rock, Ambient Pop, etc)
                                    -   `{array item object}`
                                        -   `_links`(object) - links to data sets
                                            -   `self`(object) - link to this data set
                                                -   `href`(string) - reference
                                                -   `templated`(boolean) - ability to be templated
                                        -   `id`(string) - The ID of the classification's level
                                        -   `name`(string) - The Name of the classification's level
                                        -   `locale`(string) - Locale in which the content is returned
                                
                            
                            -   `_links`(object) - links to data sets
                                -   `self`(object) - link to this data set
                                    -   `href`(string) - reference
                                    -   `templated`(boolean) - ability to be templated
                            -   `id`(string) - The ID of the classification's level
                            -   `name`(string) - The Name of the classification's level
                            -   `locale`(string) - Locale in which the content is returned
                    
                    -   `_links`(object) - links to data sets
                        -   `self`(object) - link to this data set
                            -   `href`(string) - reference
                            -   `templated`(boolean) - ability to be templated
                    -   `id`(string) - The ID of the classification's level
                    -   `name`(string) - The Name of the classification's level
                    -   `locale`(string) - Locale in which the content is returned
                    
                
                -   `_links`(object) - links to data sets
                    -   `self`(object) - link to this data set
                        -   `href`(string) - reference
                        -   `templated`(boolean) - ability to be templated
                -   `id`(string) - The ID of the classification's level
                -   `name`(string) - The Name of the classification's level
                -   `locale`(string) - Locale in which the content is returned
                
                -   `_links`(object) - links to data sets
                    -   `self`(object) - link to this data set
                        -   `href`(string) - reference
                        -   `templated`(boolean) - ability to be templated
                -   `id`(string) - The ID of the classification's level
                -   `name`(string) - The Name of the classification's level
                -   `locale`(string) - Locale in which the content is returned
            -   `primary`(boolean) - No description specified
            -   `type`(object) - A Type represents a kind or group of people. (Donation, Group, Individual, Merchandise, Event Style, etc)
                
                -   `subTypes`(array) - List of Sub Types linked to the Type
                    -   `{array item object}`
                        
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                -   `id`(string) - The ID of the classification's level
                -   `name`(string) - The Name of the classification's level
                -   `locale`(string) - Locale in which the content is returned
            -   `subType`(object) - Secondary Type to further categorize an entity (Band, Choir, Chorus, etc)
                
                -   `_links`(object) - links to data sets
                    -   `self`(object) - link to this data set
                        -   `href`(string) - reference
                        -   `templated`(boolean) - ability to be templated
                -   `id`(string) - The ID of the classification's level
                -   `name`(string) - The Name of the classification's level
                -   `locale`(string) - Locale in which the content is returned
            -   `family`(boolean) - True if this is a family classification
-   `page`(object) - information about current page in data source
    -   `size`(number) - size of page.
    -   `totalElements`(number) - total number of available elements in server
    -   `totalPages`(number) - total number of available pages in server
    -   `number`(number) - current page number counted from 0

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/classifications.json?apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \
--include 'https://app.ticketmaster.com/discovery/v2/classifications.json?apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/classifications.json?apikey={apikey} HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: https://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Rate-Limit-Over: 0
Content-Length: 1093
Rate-Limit-Available: 4714
Set-Cookie: CMPS=X+EBiEvRM0syS+stL/cX/gsj/b+Ekp+ax1Y1UXwHF4W4DqB22Y2rXsf00GJCnetC; path=/
Access-Control-Max-Age: 3628800
Access-Control-Allow-Methods: GET, PUT, POST, DELETE
Connection: keep-alive
Server: Apache-Coyote/1.1
Rate-Limit-Reset: 1457417554290
Access-Control-Allow-Headers: origin, x-requested-with, accept
Date: Mon, 07 Mar 2016 10:23:47 GMT
Access-Control-Allow-Origin: *
X-Application-Context: application:local,default,jphx1:8080
Content-Type: application/json;charset=utf-8
X-Unknown-Params: apikey
X-Unknown-Params: api-key
Rate-Limit: 5000

{  
   "_links":{  
      "self":{  
         "href":"/discovery/v2/classifications.json?view=null&size=2{&page,sort}",
         "templated":true
      },
      "next":{  
         "href":"/discovery/v2/classifications.json?view=null&page=1&size=2{&sort}",
         "templated":true
      }
   },
   "_embedded":{  
      "classifications":[  
         {  
            "_links":{  
               "self":{  
                  "href":"/discovery/v2/classifications/KZFzniwnSyZfZ7v7na?locale=en-us"
               }
            },
            "segment":{  
               "id":"KZFzniwnSyZfZ7v7na",
               "name":"Arts & Theatre",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/segments/KZFzniwnSyZfZ7v7na?locale=en-us"
                  }
               },
               "_embedded":{  
                  "genres":[
                    {  
                        "id":"KnvZfZ7v7lv",
                        "name":"Magic & Illusion",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7v7lv?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7v7l7",
                                 "name":"Magic",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7v7l7?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     }
                  ]
               }
            }
         },
         {  
            "_links":{  
               "self":{  
                  "href":"/discovery/v2/classifications/KZFzniwnSyZfZ7v7nn?locale=en-us"
               }
            },
            "segment":{  
               "id":"KZFzniwnSyZfZ7v7nn",
               "name":"Film",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/segments/KZFzniwnSyZfZ7v7nn?locale=en-us"
                  }
               },
               "_embedded":{  
                  "genres":[  
                     {  
                        "id":"KnvZfZ7vAka",
                        "name":"Miscellaneous",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7vAka?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7vFll",
                                 "name":"Classic/Reissue",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFll?locale=en-us"
                                    }
                                 }
                              },
                              {  
                                 "id":"KZazBEonSMnZfZ7vFln",
                                 "name":"Miscellaneous",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFln?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     },
                     {  
                        "id":"KnvZfZ7vAkF",
                        "name":"Family",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7vAkF?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7vFlt",
                                 "name":"Miscellaneous",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFlt?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     },
                     {  
                        "id":"KnvZfZ7vAk1",
                        "name":"Foreign",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7vAk1?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7vavv",
                                 "name":"Foreign",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vavv?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     },
                     {  
                        "id":"KnvZfZ7vAkd",
                        "name":"Animation",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7vAkd?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7vFla",
                                 "name":"Animation",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFla?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     },
                     {  
                        "id":"KnvZfZ7vAkE",
                        "name":"Urban",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7vAkE?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7vavd",
                                 "name":"Urban",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vavd?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     },
                     {  
                        "id":"KnvZfZ7vAke",
                        "name":"Action/Adventure",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7vAke?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7vFlF",
                                 "name":"Action/Adventure",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFlF?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     },
                     {  
                        "id":"KnvZfZ7vAkJ",
                        "name":"Music",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7vAkJ?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7vave",
                                 "name":"Music",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vave?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     },
                     {  
                        "id":"KnvZfZ7vAkA",
                        "name":"Comedy",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7vAkA?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7vFlJ",
                                 "name":"Comedy",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFlJ?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     },
                     {  
                        "id":"KnvZfZ7vAk7",
                        "name":"Arthouse",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7vAk7?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7vFl1",
                                 "name":"Arthouse",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFl1?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     },
                     {  
                        "id":"KnvZfZ7vAk6",
                        "name":"Drama",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7vAk6?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7vFlI",
                                 "name":"Drama",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFlI?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     },
                     {  
                        "id":"KnvZfZ7vAkk",
                        "name":"Documentary",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/genres/KnvZfZ7vAkk?locale=en-us"
                           }
                        },
                        "_embedded":{  
                           "subgenres":[  
                              {  
                                 "id":"KZazBEonSMnZfZ7vFlE",
                                 "name":"Documentary",
                                 "_links":{  
                                    "self":{  
                                       "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFlE?locale=en-us"
                                    }
                                 }
                              }
                           ]
                        }
                     }
                  ]
               }
            }
         }
      ]
   },
   "page":{  
      "size":2,
      "totalElements":6,
      "totalPages":3,
      "number":0
   }
}
```

## Get Classification Details

**Method:** GET

**Summary:** Get Classification Details

**Description:** Get details for a specific segment, genre, or sub-genre using its unique identifier.

/discovery/v2/classifications/{id}

### URL parameters:

Parameter

Description

Type

Default Value

Required

`id`

ID of the segment, genre, or sub-genre

String

Yes

### Query parameters:

Parameter

Description

Type

Default Value

Required

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

\*

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `_links`(object) - links to data sets
    -   `self`(object) - link to this data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
-   `segment`(object) - A Segment is a primary genre for an entity (Music, Sports, Arts, etc)
    
    -   `_embedded`(object) - container for genres.
        -   `genres`(object) - Classification
            -   `{array item object}`
                
                -   `_embedded`(object) - container for subgenres.
                    
                    -   `subgenres`(object) - Tertiary Genre for additional detail when describring an entity (Alternative Rock, Ambient Pop, etc)
                        -   `{array item object}`
                            -   `_links`(object) - links to data sets
                                -   `self`(object) - link to this data set
                                    -   `href`(string) - reference
                                    -   `templated`(boolean) - ability to be templated
                            -   `id`(string) - The ID of the classification's level
                            -   `name`(string) - The Name of the classification's level
                            -   `locale`(string) - Locale in which the content is returned
                    
                
                -   `_links`(object) - links to data sets
                    -   `self`(object) - link to this data set
                        -   `href`(string) - reference
                        -   `templated`(boolean) - ability to be templated
                -   `id`(string) - The ID of the classification's level
                -   `name`(string) - The Name of the classification's level
                -   `locale`(string) - Locale in which the content is returned
        
        -   `_links`(object) - links to data sets
            -   `self`(object) - link to this data set
                -   `href`(string) - reference
                -   `templated`(boolean) - ability to be templated
        -   `id`(string) - The ID of the classification's level
        -   `name`(string) - The Name of the classification's level
        -   `locale`(string) - Locale in which the content is returned
        
    
    -   `_links`(object) - links to data sets
        -   `self`(object) - link to this data set
            -   `href`(string) - reference
            -   `templated`(boolean) - ability to be templated
    -   `id`(string) - The ID of the classification's level
    -   `name`(string) - The Name of the classification's level
    -   `locale`(string) - Locale in which the content is returned
    
    -   `_links`(object) - links to data sets
        -   `self`(object) - link to this data set
            -   `href`(string) - reference
            -   `templated`(boolean) - ability to be templated
    -   `id`(string) - The ID of the classification's level
    -   `name`(string) - The Name of the classification's level
    -   `locale`(string) - Locale in which the content is returned
-   `primary`(boolean) - No description specified
-   `type`(object) - A Type represents a kind or group of people. (Donation, Group, Individual, Merchandise, Event Style, etc)
    
    -   `subTypes`(array) - List of Sub Types linked to the Type
        -   `{array item object}`
            
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
    -   `id`(string) - The ID of the classification's level
    -   `name`(string) - The Name of the classification's level
    -   `locale`(string) - Locale in which the content is returned
-   `subType`(object) - Secondary Type to further categorize an entity (Band, Choir, Chorus, etc)
    
    -   `_links`(object) - links to data sets
        -   `self`(object) - link to this data set
            -   `href`(string) - reference
            -   `templated`(boolean) - ability to be templated
    -   `id`(string) - The ID of the classification's level
    -   `name`(string) - The Name of the classification's level
    -   `locale`(string) - Locale in which the content is returned
-   `family`(boolean) - True if this is a family classification

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/classifications/KZFzniwnSyZfZ7v7nE.json?apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \
--include 'https://app.ticketmaster.com/discovery/v2/classifications/KZFzniwnSyZfZ7v7nE.json?apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/classifications/KZFzniwnSyZfZ7v7nE?apikey={apikey} HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: https://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Rate-Limit-Over: 0
Content-Length: 146
Rate-Limit-Available: 4704
Set-Cookie: CMPS=knuzxOVcqdhMvUWKhD1HJcR5XXSZVELJc0IG2tQ2a6fPIMvDGc3jQaIZwf8n3jmw; path=/
Access-Control-Max-Age: 3628800
Access-Control-Allow-Methods: GET, PUT, POST, DELETE
Connection: keep-alive
Server: Apache-Coyote/1.1
Rate-Limit-Reset: 1457417554290
Access-Control-Allow-Headers: origin, x-requested-with, accept
Date: Mon, 07 Mar 2016 10:33:15 GMT
Access-Control-Allow-Origin: *
X-Application-Context: application:local,default,jphx1:8080
Content-Type: application/json;charset=utf-8
X-Unknown-Params: apikey
X-Unknown-Params: api-key
Rate-Limit: 5000

{  
   "_links":{  
      "self":{  
         "href":"/discovery/v2/classifications/KZFzniwnSyZfZ7v7nE?locale=en-us"
      }
   },
   "segment":{  
      "id":"KZFzniwnSyZfZ7v7nE",
      "name":"Sports",
      "_links":{  
         "self":{  
            "href":"/discovery/v2/classifications/segments/KZFzniwnSyZfZ7v7nE?locale=en-us"
         }
      },
      "_embedded":{  
         "genres":[  
            {  
               "id":"KnvZfZ7vA76",
               "name":"Netball",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA76?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFtA",
                        "name":"Netball",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtA?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7k",
               "name":"Motorsports/Racing",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7k?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFt7",
                        "name":"Motorsports/Racing",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFt7?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7a",
               "name":"Roller Hockey",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7a?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFtF",
                        "name":"Roller Hockey",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtF?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAea",
               "name":"Rodeo",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAea?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFtk",
                        "name":"Bullriding",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtk?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vF1d",
                        "name":"Rodeo",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vF1d?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA71",
               "name":"Rugby",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA71?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFta",
                        "name":"Rugby",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFta?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFt1",
                        "name":"Rugby League",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFt1?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFtJ",
                        "name":"Rugby Union",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtJ?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7v",
               "name":"Ice Skating",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7v?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFIF",
                        "name":"Ice Skating",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFIF?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7d",
               "name":"Martial Arts",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7d?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFIJ",
                        "name":"Kickboxing",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFIJ?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFI1",
                        "name":"Karate",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFI1?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFIE",
                        "name":"Mixed Martial Arts",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFIE?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7e",
               "name":"Indoor Soccer",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7e?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFIa",
                        "name":"Indoor Soccer",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFIa?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7A",
               "name":"Miscellaneous",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7A?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFtv",
                        "name":"High School",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtv?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFIl",
                        "name":"GAA",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFIl?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFIt",
                        "name":"Miscellaneous",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFIt?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFte",
                        "name":"College",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFte?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFtd",
                        "name":"Minor League",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtd?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA77",
               "name":"Lacrosse",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA77?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFII",
                        "name":"Lacrosse",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFII?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAet",
               "name":"Athletic Races",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAet?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vF11",
                        "name":"Athletic Races",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vF11?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7l",
               "name":"Table Tennis",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7l?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFne",
                        "name":"Table Tennis",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFne?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAeI",
               "name":"Aquatics",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAeI?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vF1a",
                        "name":"Aquatics",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vF1a?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7n",
               "name":"Swimming",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7n?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFnv",
                        "name":"Swimming",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFnv?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAel",
               "name":"Bandy",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAel?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vF1E",
                        "name":"Bandy",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vF1E?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAen",
               "name":"Badminton",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAen?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vF1J",
                        "name":"Badminton",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vF1J?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7E",
               "name":"Soccer",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7E?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFtI",
                        "name":"MLS",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtI?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFtt",
                        "name":"Soccer",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtt?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7J",
               "name":"Ski Jumping",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7J?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFtE",
                        "name":"Ski Jumping",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtE?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7t",
               "name":"Surfing",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7t?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFtl",
                        "name":"Surfing",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtl?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vA7I",
               "name":"Squash",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vA7I?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFtn",
                        "name":"Squash",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtn?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAdk",
               "name":"Cricket",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAdk?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFJE",
                        "name":"Cricket",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJE?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAdA",
               "name":"Boxing",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAdA?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFJJ",
                        "name":"Boxing",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJJ?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAdF",
               "name":"Curling",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAdF?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFJl",
                        "name":"Curling",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJl?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAd6",
               "name":"Skiing",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAd6?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFJI",
                        "name":"Cross Country",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJI?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFJt",
                        "name":"Nordic Combined",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJt?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFJn",
                        "name":"Skiing",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJn?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAd1",
               "name":"Equestrian",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAd1?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFEe",
                        "name":"Dressage",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEe?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFEd",
                        "name":"Equestrian",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEd?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFE7",
                        "name":"Horse Racing",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFE7?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAda",
               "name":"Cycling",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAda?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFEv",
                        "name":"Cycling",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEv?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAAe",
               "name":"Toros",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAAe?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFn7",
                        "name":"Toros",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFn7?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAAv",
               "name":"Tennis",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAAv?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFnd",
                        "name":"Tennis",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFnd?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAde",
               "name":"Basketball",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAde?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFJ6",
                        "name":"Men Professional",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJ6?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFJ7",
                        "name":"NBDL",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJ7?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFJd",
                        "name":"Minor League",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJd?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFJA",
                        "name":"NBA",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJA?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFJF",
                        "name":"WNBA",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJF?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFJv",
                        "name":"College",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJv?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFJe",
                        "name":"High School",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJe?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFJk",
                        "name":"NBL",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJk?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFn1",
                        "name":"NBA D League",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFn1?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFnJ",
                        "name":"Women Professional",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFnJ?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAA7",
               "name":"Volleyball",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAA7?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFnk",
                        "name":"Minor League",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFnk?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFn6",
                        "name":"Volleyball",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFn6?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAdv",
               "name":"Baseball",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAdv?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vF1t",
                        "name":"Minor League",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vF1t?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vF1l",
                        "name":"Professional",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vF1l?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vF1I",
                        "name":"College",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vF1I?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vF1n",
                        "name":"MLB",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vF1n?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAAd",
               "name":"Track & Field",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAAd?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFnA",
                        "name":"Track & Field",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFnA?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAd7",
               "name":"Body Building",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAd7?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFJ1",
                        "name":"Body Building",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJ1?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAAk",
               "name":"Wrestling",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAAk?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFna",
                        "name":"Wrestling",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFna?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAdd",
               "name":"Biathlon",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAdd?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFJa",
                        "name":"Biathlon",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFJa?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAAA",
               "name":"Waterpolo",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAAA?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFnF",
                        "name":"Waterpolo",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFnF?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAdn",
               "name":"Gymnastics",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAdn?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFIk",
                        "name":"Gymnastics",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFIk?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAdt",
               "name":"Golf",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAdt?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFI7",
                        "name":"PGA Tour",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFI7?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFId",
                        "name":"PGA B-Tour",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFId?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFIv",
                        "name":"Golf",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFIv?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFIe",
                        "name":"LPGA",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFIe?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFIA",
                        "name":"PGA Senior Tour",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFIA?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAdl",
               "name":"Handball",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAdl?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFI6",
                        "name":"Handball",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFI6?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAdJ",
               "name":"Extreme",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAdJ?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFEA",
                        "name":"Extreme",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEA?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAdI",
               "name":"Hockey",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAdI?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFEl",
                        "name":"Ice Hockey",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEl?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFEE",
                        "name":"NHL",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEE?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFEI",
                        "name":"College",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEI?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFEt",
                        "name":"Minor League",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEt?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFEn",
                        "name":"Professional",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEn?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            },
            {  
               "id":"KnvZfZ7vAdE",
               "name":"Football",
               "_links":{  
                  "self":{  
                     "href":"/discovery/v2/classifications/genres/KnvZfZ7vAdE?locale=en-us"
                  }
               },
               "_embedded":{  
                  "subgenres":[  
                     {  
                        "id":"KZazBEonSMnZfZ7vFEk",
                        "name":"AFL",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEk?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFE1",
                        "name":"NFL",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFE1?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFEa",
                        "name":"High School",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEa?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFE6",
                        "name":"College",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFE6?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFEJ",
                        "name":"Professional",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEJ?locale=en-us"
                           }
                        }
                     },
                     {  
                        "id":"KZazBEonSMnZfZ7vFEF",
                        "name":"International Rules",
                        "_links":{  
                           "self":{  
                              "href":"/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFEF?locale=en-us"
                           }
                        }
                     }
                  ]
               }
            }
         ]
      }
   }
}
```

## Get Genre Details

**Method:** GET

**Summary:** Get Genre Details

**Description:** Get details for a specific genre using its unique identifier.

/discovery/v2/classifications/genres/{id}

### URL parameters:

Parameter

Description

Type

Default Value

Required

`id`

ID of the genre

String

Yes

### Query parameters:

Parameter

Description

Type

Default Value

Required

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

\*

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `_links`(object) - links to data sets
    -   `self`(object) - link to this data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
-   `_embedded`(object) - container
    -   `subgenres`(array) - related
        -   `{array item object}`
            -   `_links`(object) - links to data sets
                -   `self`(object) - link to this data set
                    -   `href`(string) - reference
                    -   `templated`(boolean) - ability to be templated
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
-   `subGenres`(array) - List of Tertiary Genre linked to the Secondary Genre
    -   `{ array item object }`
        -   `id`(string) - The ID of the classification's level
        -   `name`(string) - The Name of the classification's level
        -   `locale`(string) - Locale in which the content is returned
-   `id`(string) - The ID of the classification's level
-   `name`(string) - The Name of the classification's level
-   `locale`(string) - Locale in which the content is returned

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/classifications/genres/KnvZfZ7vA71.json?apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \ 
--include 'https://app.ticketmaster.com/discovery/v2/classifications/genres/KnvZfZ7vA71.json?apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/classifications/genres/KnvZfZ7vA71.json?apikey={apikey} HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: https://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Access-Control-Allow-Headers:origin, x-requested-with, accept
Access-Control-Allow-Methods:GET, PUT, POST, DELETE
Access-Control-Allow-Origin:*
Access-Control-Max-Age:3628800
Connection:keep-alive
Content-Length:605
Content-Type:application/hal+json;charset=utf-8
Date:Tue, 20 Sep 2016 10:39:15 GMT
Rate-Limit:5000
Rate-Limit-Available:1654
Rate-Limit-Over:0
Rate-Limit-Reset:1474384903781
Server:Apache-Coyote/1.1
X-Application-Context:application:local,default,jphx1:8080
X-TM-GTM-Origin:uapi-us-phx2

{
  "_embedded": {
    "subgenres": [
      {
        "id": "KZazBEonSMnZfZ7vFta",
        "name": "Rugby",
        "_links": {
          "self": {
            "href": "/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFta?locale=en-us"
          }
        }
      },
      {
        "id": "KZazBEonSMnZfZ7vFt1",
        "name": "Rugby League",
        "_links": {
          "self": {
            "href": "/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFt1?locale=en-us"
          }
        }
      },
      {
        "id": "KZazBEonSMnZfZ7vFtJ",
        "name": "Rugby Union",
        "_links": {
          "self": {
            "href": "/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFtJ?locale=en-us"
          }
        }
      }
    ]
  },
  "_links": {
    "self": {
      "href": "/discovery/v2/classifications/genres/KnvZfZ7vA71?locale=en-us"
    }
  },
  "id": "KnvZfZ7vA71",
  "name": "Rugby"
}
```

## Get Segment Details

**Method:** GET

**Summary:** Get Segment Details

**Description:** Get details for a specific segment using its unique identifier.

/discovery/v2/classifications/segments/{id}

### URL parameters:

Parameter

Description

Type

Default Value

Required

`id`

ID of the segment

String

Yes

### Query parameters:

Parameter

Description

Type

Default Value

Required

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

\*

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `_links`(object) - links to data sets
    -   `self`(object) - link to this data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
-   `_embedded`(object) - container for genres.
    -   `genres`(object) - Segment
        -   `{array item object}`
            
            -   `_embedded`(object) - container for subgenres.
                
                -   `subgenres`(object) - Tertiary Genre for additional detail when describring an entity (Alternative Rock, Ambient Pop, etc)
                    -   `{array item object}`
                        -   `_links`(object) - links to data sets
                            -   `self`(object) - link to this data set
                                -   `href`(string) - reference
                                -   `templated`(boolean) - ability to be templated
                        -   `id`(string) - The ID of the classification's level
                        -   `name`(string) - The Name of the classification's level
                        -   `locale`(string) - Locale in which the content is returned
                
            
            -   `_links`(object) - links to data sets
                -   `self`(object) - link to this data set
                    -   `href`(string) - reference
                    -   `templated`(boolean) - ability to be templated
            -   `id`(string) - The ID of the classification's level
            -   `name`(string) - The Name of the classification's level
            -   `locale`(string) - Locale in which the content is returned
    
    -   `_links`(object) - links to data sets
        -   `self`(object) - link to this data set
            -   `href`(string) - reference
            -   `templated`(boolean) - ability to be templated
    -   `id`(string) - The ID of the classification's level
    -   `name`(string) - The Name of the classification's level
    -   `locale`(string) - Locale in which the content is returned
    
-   `id`(string) - The ID of the classification's level
-   `name`(string) - The Name of the classification's level
-   `locale`(string) - Locale in which the content is returned

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/classifications/segments/KZazBEonSMnZfZ7vFta.json?apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \ 
--include 'https://app.ticketmaster.com/discovery/v2/classifications/segments/KZazBEonSMnZfZ7vFta.json?apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/classifications/segments/KZazBEonSMnZfZ7vFta.json?apikey={apikey} HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: https://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Access-Control-Allow-Headers:origin, x-requested-with, accept
Access-Control-Allow-Methods:GET, PUT, POST, DELETE
Access-Control-Allow-Origin:*
Access-Control-Max-Age:3628800
Connection:keep-alive
Content-Length:469
Content-Type:application/json;charset=utf-8
Date:Tue, 20 Sep 2016 11:21:54 GMT
Rate-Limit:5000
Rate-Limit-Available:1514
Rate-Limit-Over:0
Rate-Limit-Reset:1474384903781
Server:Apache-Coyote/1.1
Set-Cookie:CMPS=bpb3Bk6rqZf1d8yLiJWyOiPc1IunRq4KdVq9OqS25+BMCRNts5X3I7p9SbevsS6OttgINJhrlh4=; path=/
X-Application-Context:application:local,default,jphx1:8080

{
  "id": "KZFzniwnSyZfZ7v7nE",
  "name": "Sports",
  "_links": {
    "self": {
      "href": "/discovery/v2/classifications/segments/KZFzniwnSyZfZ7v7nE?locale=en-us"
    }
  },
  "_embedded": {
    "genres": [
      {
        "id": "KnvZfZ7vA71",
        "name": "Rugby",
        "_links": {
          "self": {
            "href": "/discovery/v2/classifications/genres/KnvZfZ7vA71?locale=en-us"
          }
        },
        "_embedded": {
          "subgenres": [
            {
              "id": "KZazBEonSMnZfZ7vFta",
              "name": "Rugby",
              "_links": {
                "self": {
                  "href": "/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFta?locale=en-us"
                }
              }
            }
          ]
        }
      }
    ]
  }
}
```

## Get Sub-Genre Details

**Method:** GET

**Summary:** Get Sub-Genre Details

**Description:** Get details for a specific sub-genre using its unique identifier.

/discovery/v2/classifications/subgenres/{id}

### URL parameters:

Parameter

Description

Type

Default Value

Required

`id`

ID of the subgenre

String

Yes

### Query parameters:

Parameter

Description

Type

Default Value

Required

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

\*

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `id`(string) - The ID of the classification's level
-   `name`(string) - The Name of the classification's level
-   `locale`(string) - Locale in which the content is returned

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFta.json?apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \ 
--include 'https://app.ticketmaster.com/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFta.json?apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFta.json?apikey={apikey} HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: https://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Access-Control-Allow-Headers:origin, x-requested-with, accept
Access-Control-Allow-Methods:GET, PUT, POST, DELETE
Access-Control-Allow-Origin:*
Access-Control-Max-Age:3628800
Connection:keep-alive
Content-Length:145
Content-Type:application/json;charset=utf-8
Date:Tue, 20 Sep 2016 11:27:24 GMT
Rate-Limit:5000
Rate-Limit-Available:1498
Rate-Limit-Over:0
Rate-Limit-Reset:1474384903781
Server:Apache-Coyote/1.1
X-Application-Context:application:local,default,jphx1:8080
X-TM-GTM-Origin:uapi-us-phx2

{
  "_links": {
    "self": {
      "href": "/discovery/v2/classifications/subgenres/KZazBEonSMnZfZ7vFta?locale=en-us"
    }
  },
  "id": "KZazBEonSMnZfZ7vFta",
  "name": "Rugby"
}
```

## Venue Search

**Method:** GET

**Summary:** Venue Search

**Description:** Find venues and filter your search by name, and much more.

/discovery/v2/venues

### Query parameters:

Parameter

Description

Type

Default Value

Required

`id`

Filter entities by its id

String

No

`keyword`

Keyword to search on

String

No

`latlong`

Filter events by latitude and longitude, this filter is deprecated and maybe removed in a future release, please use geoPoint instead

String

No

`radius`

Radius of the area in which we want to search for events.

String

No

`unit`

Unit of the radius

String enum:\["miles", "km"\]

miles

No

`source`

Filter entities by its primary source name OR publishing source name

String enum:\["ticketmaster", " universe", " frontgate", " tmr"\]

No

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

en

No

`includeTest`

Yes if you want to have entities flag as test in the response. Only, if you only wanted test entities

String enum:\["yes", " no", " only"\]

no

No

`size`

Page size of the response

String

20

No

`page`

Page number

String

0

No

`sort`

Sorting order of the search result. Allowable Values: 'name,asc', 'name,desc', 'relevance,asc', 'relevance,desc', 'distance,asc', 'distance,desc', 'random'

String

relevance,desc

No

`countryCode`

Filter venues by country code

String

No

`stateCode`

Filter venues by state / province code

String

No

`geoPoint`

filter events by geoHash

String

No

`preferredCountry`

Popularity boost by country, default is us.

String enum:\["us", " ca"\]

us

No

`includeSpellcheck`

yes, to include spell check suggestions in the response.

String enum:\["yes", " no"\]

no

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `_links`(object) - links to data sets
    -   `self`(object) - link to this data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
    -   `next`(object) - link to the next data set
        -   `href`(string) - reference.
        -   `templated`(boolean) - ability to be templated
    -   `prev`(object) - link to the previous data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
-   `_embedded`(object) - container
    -   `venues`(array)
        -   `{array item object}`
            -   `_links`(object) - links to data sets
                -   `self`(object) - link to this data set
                    -   `href`(string) - reference
                    -   `templated`(boolean) - ability to be templated
            -   `type`(string) - Type of the entity
            -   `distance`(number) - double
            -   `units`(string) - No description specified
            -   `id`(string) - Unique id of the entity in the discovery API
            -   `locale`(string) - Locale in which the content is returned
            -   `name`(string) - Name of the entity
            -   `description`(string) - Description's of the entity
            -   `address`(object) - Address of the venue
                
                -   `line1`(string) - Address first line
                -   `line2`(string) - Address second line
                -   `line3`(string) - Address third line
            -   `city`(object) - City of the venue
                
                -   `name`(string) - Name of the entity
            -   `additionalInfo`(string) - Additional information of the entity
            -   `state`(object) - State / Province of the venue
                
                -   `stateCode`(string) - State code
                -   `name`(string) - Name of the entity
            -   `country`(object) - Country of the venue
                
                -   `countryCode`(string) - Country code (ISO 3166)
                -   `name`(string) - Name of the entity
            -   `url`(string) - URL of a web site detail page of the entity
            -   `postalCode`(string) - Postal code / zipcode of the venue
            -   `location`(object) - Location of the venue
                
                -   `longitude`(number) - Longitude
                -   `latitude`(number) - Latitude
            -   `timezone`(string) - Timezone of the venue
            -   `currency`(string) - Default currency of ticket prices for events in this venue
            -   `markets`(array) - Markets of the venue
                -   `{ array item object }`
                    -   `id`(string) - Market's id
                    -   `name`(string) - Name of the entity
            -   `images`(array) - Images of the entity
                -   `{ array item object }`
                    -   `url`(string) - Public URL of the image
                    -   `ratio`(string: enum) - Aspect ratio of the image
                        -   16\_9
                        -   3\_2
                        -   4\_3
                    -   `width`(integer) - Width of the image
                    -   `height`(integer) - Height of the image
                    -   `fallback`(boolean) - true if the image is not the event's image but a fallbak image
                    -   `attribution`(string) - Attribution of the image
            -   `dma`(array) - The list of associated DMAs (Designated Market Areas) of the venue
                -   `{ array item object }`
                    -   `id`(integer) - DMS's id
            -   `social`(object) - Social networks data
                
                -   `twitter`(object) - Twitter data
                    -   `handle`(string: enum) - Twitter handle
                        -   @a Twitter handle
                    -   `hashtags`(array) - Twitter hashtags
                        -   `[ "string" ]` - No description specified
            -   `boxOfficeInfo`(object) - Box office informations for the venue
                
                -   `phoneNumberDetail`(string) - Venue box office phone number
                -   `openHoursDetail`(string) - Venue box office opening hours
                -   `acceptedPaymentDetail`(string) - Venue box office accepted payment details
                -   `willCallDetail`(string) - Venue box office will call details
            -   `parkingDetail`(string) - Venue parking info
            -   `accessibleSeatingDetail`(string) - Venue accessible seating detail
            -   `generalInfo`(object) - General informations on the venue
                
                -   `generalRule`(string) - Venue general rules
                -   `childRule`(string) - Venue children rule
            -   `externalLinks`(object) - List of external links
            -   `test`(boolean) - Indicate if this is a test entity, by default test entities won't appear in discovery API
            -   `aliases`(array) - List of aliases for entity
                -   `[ "string" ]`
                    
            -   `localizedAliases`(object) - List of localized aliases for entity
            -   `upcomingEvents`(object) - number of upcoming events
            -   `ada`(object) - ADA information
                
                -   `adaPhones`(string)
                -   `adaCustomCopy`(string)
                -   `adaHours`(string)
-   `page`(object) - information about current page in data source
    -   `size`(number) - size of page.
    -   `totalElements`(number) - total number of available elements in server
    -   `totalPages`(number) - total number of available pages in server
    -   `number`(number) - current page number counted from 0

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/venues.json?keyword=UCV&apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \
--include 'https://app.ticketmaster.com/discovery/v2/venues.json?keyword=UCV&apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/venues.json?apikey={apikey}&keyword=UCV HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: http://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Rate-Limit-Over: 0
Content-Length: 1241
Rate-Limit-Available: 4646
Set-Cookie: CMPS=5Gk5wt8nfXBXXBiKtbkBiPSHgfZp9zC9Gv5MPAEcFsr5g6kwuwchDenPMUm/k7jxtKdE9AU0WRI=; path=/
Access-Control-Max-Age: 3628800
Access-Control-Allow-Methods: GET, PUT, POST, DELETE
Connection: keep-alive
Server: Apache-Coyote/1.1
Rate-Limit-Reset: 1457417554290
Access-Control-Allow-Headers: origin, x-requested-with, accept
Date: Mon, 07 Mar 2016 12:07:21 GMT
Access-Control-Allow-Origin: *
X-Application-Context: application:local,default,jash1:8080
Content-Type: application/json;charset=utf-8
X-Unknown-Params: apikey
X-Unknown-Params: api-key
Rate-Limit: 5000

{
  "_embedded": {
    "venues": [
      {
        "_links": {
          "self": {
            "href": "/discovery/v2/venues/KovZpZAFnIEA?locale=en-us"
          }
        },
        "address": {
          "line1": "Crysler Park Marina, 13480 County Rd 2"
        },
        "city": {
          "name": "Morrisburg"
        },
        "country": {
          "name": "Canada",
          "countryCode": "CA"
        },
        "dmas": [
          {
            "id": 519
          }
        ],
        "id": "KovZpZAFnIEA",
        "locale": "en-us",
        "location": {
          "longitude": "-75.18702730",
          "latitude": "44.94535340"
        },
        "markets": [
          {
            "id": "103"
          }
        ],
        "name": "#1 Please do not use, left over from UCV initial acct set up",
        "postalCode": "K0C1X0",
        "state": {
          "name": "Ontario",
          "stateCode": "ON"
        },
        "test": false,
        "timezone": "America/Toronto",
        "type": "venue",
        "url": "http://ticketmaster.ca/venue/341396"
      },
      {
        "name": "#2 Please do not use, left over from UCV initial acct set up",
        "type": "venue",
        "id": "KovZpZAFnIJA",
        "test": false,
        "url": "http://ticketmaster.ca/venue/341395",
        "locale": "en-us",
        "postalCode": "K0C1X0",
        "timezone": "America/Toronto",
        "city": {
          "name": "Morrisburg"
        },
        "state": {
          "name": "Ontario",
          "stateCode": "ON"
        },
        "country": {
          "name": "Canada",
          "countryCode": "CA"
        },
        "address": {
          "line1": "13740 County Road 2"
        },
        "location": {
          "longitude": "-75.18635300",
          "latitude": "44.89937100"
        },
        "markets": [
          {
            "id": "103"
          }
        ],
        "dmas": [
          {
            "id": 519
          }
        ],
        "_links": {
          "self": {
            "href": "/discovery/v2/venues/KovZpZAFnIJA?locale=en-us"
          }
        }
      }
    ]
  },
  "_links": {
    "self": {
      "href": "/discovery/v2/venues.json?view=null&keyword=UCV{&page,size,sort}",
      "templated": true
    }
  },
  "page": {
    "size": 20,
    "totalElements": 2,
    "totalPages": 1,
    "number": 0
  }
}
```

## Get Venue Details

**Method:** GET

**Summary:** Get Venue Details

**Description:** Get details for a specific venue using the unique identifier for the venue.

/discovery/v2/venues/{id}

### URL parameters:

Parameter

Description

Type

Default Value

Required

`id`

ID of the venue

String

Yes

### Query parameters:

Parameter

Description

Type

Default Value

Required

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

\*

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `_links`(object) - links to data sets
    -   `self`(object) - link to this data set
        -   `href`(string) - reference
        -   `templated`(boolean) - ability to be templated
-   `type`(string) - Type of the entity
-   `distance`(number) - double
-   `units`(string) - No description specified
-   `id`(string) - Unique id of the entity in the discovery API
-   `locale`(string) - Locale in which the content is returned
-   `name`(string) - Name of the entity
-   `description`(string) - Description's of the entity
-   `address`(object) - Address of the venue
    
    -   `line1`(string) - Address first line
    -   `line2`(string) - Address second line
    -   `line3`(string) - Address third line
-   `city`(object) - City of the venue
    
    -   `name`(string) - Name of the entity
-   `additionalInfo`(string) - Additional information of the entity
-   `state`(object) - State / Province of the venue
    
    -   `stateCode`(string) - State code
    -   `name`(string) - Name of the entity
-   `country`(object) - Country of the venue
    
    -   `countryCode`(string) - Country code (ISO 3166)
    -   `name`(string) - Name of the entity
-   `url`(string) - URL of a web site detail page of the entity
-   `postalCode`(string) - Postal code / zipcode of the venue
-   `location`(object) - Location of the venue
    
    -   `longitude`(number) - Longitude
    -   `latitude`(number) - Latitude
-   `timezone`(string) - Timezone of the venue
-   `currency`(string) - Default currency of ticket prices for events in this venue
-   `markets`(array) - Markets of the venue
    -   `{ array item object }`
        -   `id`(string) - Market's id
        -   `name`(string) - Name of the entity
-   `images`(array) - Images of the entity
    -   `{ array item object }`
        -   `url`(string) - Public URL of the image
        -   `ratio`(string: enum) - Aspect ratio of the image
            -   16\_9
            -   3\_2
            -   4\_3
        -   `width`(integer) - Width of the image
        -   `height`(integer) - Height of the image
        -   `fallback`(boolean) - true if the image is not the event's image but a fallbak image
        -   `attribution`(string) - Attribution of the image
-   `dma`(array) - The list of associated DMAs (Designated Market Areas) of the venue
    -   `{ array item object }`
        -   `id`(integer) - DMS's id
-   `social`(object) - Social networks data
    
    -   `twitter`(object) - Twitter data
        -   `handle`(string: enum) - Twitter handle
            -   @a Twitter handle
        -   `hashtags`(array) - Twitter hashtags
            -   `[ "string" ]` - No description specified
-   `boxOfficeInfo`(object) - Box office informations for the venue
    
    -   `phoneNumberDetail`(string) - Venue box office phone number
    -   `openHoursDetail`(string) - Venue box office opening hours
    -   `acceptedPaymentDetail`(string) - Venue box office accepted payment details
    -   `willCallDetail`(string) - Venue box office will call details
-   `parkingDetail`(string) - Venue parking info
-   `accessibleSeatingDetail`(string) - Venue accessible seating detail
-   `generalInfo`(object) - General informations on the venue
    
    -   `generalRule`(string) - Venue general rules
    -   `childRule`(string) - Venue children rule
-   `externalLinks`(object) - List of external links
-   `test`(boolean) - Indicate if this is a test entity, by default test entities won't appear in discovery API
-   `aliases`(array) - List of aliases for entity
    -   `[ "string" ]`
        
-   `localizedAliases`(object) - List of localized aliases for entity
-   `upcomingEvents`(object) - number of upcoming events
-   `ada`(object) - ADA information
    
    -   `adaPhones`(string)
    -   `adaCustomCopy`(string)
    -   `adaHours`(string)

> [JavaScript](#js) [cURL](#curl)

```
$.ajax({
  type:"GET",
  url:"https://app.ticketmaster.com/discovery/v2/venues/KovZpZAFnIEA.json?apikey={apikey}",
  async:true,
  dataType: "json",
  success: function(json) {
              console.log(json);
              // Parse the response.
              // Do other things.
           },
  error: function(xhr, status, err) {
              // This time, we do not end up here!
           }
});
```

```
curl \
--include 'https://app.ticketmaster.com/discovery/v2/venues/KovZpZAFnIEA.json?apikey={apikey}'
```

### Examples:

> [Request](#req) [Response](#res)

```
GET /discovery/v2/venues/KovZpZAFnIEA.json?apikey={apikey} HTTP/1.1
Host: app.ticketmaster.com
X-Target-URI: https://app.ticketmaster.com
Connection: Keep-Alive
```

```
HTTP/1.1 200 OK
Rate-Limit-Over: 0
Content-Length: 534
Rate-Limit-Available: 4641
Set-Cookie: CMPS=EVPIT1pv8wWL7BwB100rEn1yhwBpj7YSqibbjeotIHcpnB/odVGK9VdPBPm3dTrr; path=/
Access-Control-Max-Age: 3628800
Access-Control-Allow-Methods: GET, PUT, POST, DELETE
Connection: keep-alive
Server: Apache-Coyote/1.1
Rate-Limit-Reset: 1457417554290
Access-Control-Allow-Headers: origin, x-requested-with, accept
Date: Mon, 07 Mar 2016 12:10:53 GMT
Access-Control-Allow-Origin: *
X-Application-Context: application:local,default,jphx1:8080
Content-Type: application/json;charset=utf-8
X-Unknown-Params: apikey
X-Unknown-Params: api-key
Rate-Limit: 5000

{
  "name": "#1 Please do not use, left over from UCV initial acct set up",
  "type": "venue",
  "id": "KovZpZAFnIEA",
  "test": false,
  "locale": "en-us",
  "postalCode": "K0C1X0",
  "timezone": "America/Toronto",
  "city":  {
    "name": "Morrisburg"
  },
  "state":  {
    "name": "Ontario",
    "stateCode": "ON"
  },
  "country":  {
    "name": "Canada",
    "countryCode": "CA"
  },
  "address":  {
    "line1": "Crysler Park Marina, 13480 County Rd 2"
  },
  "location":  {
    "longitude": "-75.18702730",
    "latitude": "44.94535340"
  },
  "markets":  [
     {
      "id": "103"
    }
  ],
  "_links":  {
    "self":  {
      "href": "/discovery/v2/venues/KovZpZAFnIEA?locale=en-us"
    }
  }
}
```

## Find Suggest

**Method:** GET

**Summary:** Find Suggest

**Description:** Find search suggestions and filter your suggestions by location, source, etc.

/discovery/v2/suggest

### Query parameters:

Parameter

Description

Type

Default Value

Required

`keyword`

Keyword to search on

String

No

`latlong`

Filter events by latitude and longitude, this filter is deprecated and maybe removed in a future release, please use geoPoint instead

String

No

`radius`

Radius of the area in which we want to search for events.

String

100

No

`unit`

Unit of the radius

String enum:\["miles", "km"\]

miles

No

`source`

Filter entities by its primary source name OR publishing source name

String enum:\["ticketmaster", " universe", " frontgate", " tmr"\]

No

`locale`

The locale in ISO code format. Multiple comma-separated values can be provided. When omitting the country part of the code (e.g. only 'en' or 'fr') then the first matching locale is used. When using a '\*' it matches all locales. '\*' can only be used at the end (e.g. 'en-us,en,\*')

String

en

No

`includeTBA`

True, to include events with date to be announce (TBA)

String enum:\["yes", " no", " only"\]

no if date parameter sent, yes otherwise

No

`includeTBD`

True, to include event with a date to be defined (TBD)

String enum:\["yes", " no", " only"\]

no if date parameter sent, yes otherwise

No

`includeTest`

Yes if you want to have entities flag as test in the response. Only, if you only wanted test entities

String enum:\["yes", " no", " only"\]

no

No

`size`

Size of every entity returned in the response

String

5

No

`countryCode`

Filter suggestions by country code

String

No

`segmentId`

Filter suggestions by segment id

Array

No

`geoPoint`

filter events by geoHash

String

No

`resource`

which resources to include in the suggest response, defaults to all resources

Array

attractions,events,venues,products

No

`preferredCountry`

Popularity boost by country, default is us.

String enum:\["us", " ca"\]

us

No

`startEndDateTime`

Filter event where event start and end date overlap this range

Array

No

`localStartEndDateTime`

Filter event where event local start and end date overlap this range

Array

No

`includeSpellcheck`

yes, to include spell check suggestions in the response.

String enum:\["yes", " no"\]

no

No

`domain`

Filter entities based on domains they are available on

Array

No

### Response structure:

200 successful operation

-   `schema` (string) - A simple string response
    

## Supported Country Codes

This the [ISO Alpha-2 Code](https://en.wikipedia.org/wiki/ISO_3166-1) country values:

CountryCode

US (United States Of America)

AD (Andorra)

AI (Anguilla)

AR (Argentina)

AU (Australia)

AT (Austria)

AZ (Azerbaijan)

BS (Bahamas)

BH (Bahrain)

BB (Barbados)

BE (Belgium)

BM (Bermuda)

BR (Brazil)

BG (Bulgaria)

CA (Canada)

CL (Chile)

CN (China)

CO (Colombia)

CR (Costa Rica)

HR (Croatia)

CY (Cyprus)

CZ (Czech Republic)

DK (Denmark)

DO (Dominican Republic)

EC (Ecuador)

EE (Estonia)

FO (Faroe Islands)

FI (Finland)

FR (France)

GE (Georgia)

DE (Germany)

GH (Ghana)

GI (Gibraltar)

GB (Great Britain)

GR (Greece)

HK (Hong Kong)

HU (Hungary)

IS (Iceland)

IN (India)

IE (Ireland)

IL (Israel)

IT (Italy)

JM (Jamaica)

JP (Japan)

KR (Korea, Republic of)

LV (Latvia)

LB (Lebanon)

LT (Lithuania)

LU (Luxembourg)

MY (Malaysia)

MT (Malta)

MX (Mexico)

MC (Monaco)

ME (Montenegro)

MA (Morocco)

NL (Netherlands)

AN (Netherlands Antilles)

NZ (New Zealand)

ND (Northern Ireland)

NO (Norway)

PE (Peru)

PL (Poland)

PT (Portugal)

RO (Romania)

RU (Russian Federation)

LC (Saint Lucia)

SA (Saudi Arabia)

RS (Serbia)

SG (Singapore)

SK (Slovakia)

SI (Slovenia)

ZA (South Africa)

ES (Spain)

SE (Sweden)

CH (Switzerland)

TW (Taiwan)

TH (Thailand)

TT (Trinidad and Tobago)

TR (Turkey)

UA (Ukraine)

AE (United Arab Emirates)

UY (Uruguay)

VE (Venezuela)

## Supported Markets

Markets can be used to filter events by larger regional demographic groupings. Each market is typically comprised of several DMAs.

#### USA

ID

Market

1

Birmingham & More

2

Charlotte

3

Chicagoland & Northern IL

4

Cincinnati & Dayton

5

Dallas - Fort Worth & More

6

Denver & More

7

Detroit, Toledo & More

8

El Paso & New Mexico

9

Grand Rapids & More

10

Greater Atlanta Area

11

Greater Boston Area

12

Cleveland, Youngstown & More

13

Greater Columbus Area

14

Greater Las Vegas Area

15

Greater Miami Area

16

Minneapolis/St. Paul & More

17

Greater Orlando Area

18

Greater Philadelphia Area

19

Greater Pittsburgh Area

20

Greater San Diego Area

21

Greater Tampa Area

22

Houston & More

23

Indianapolis & More

24

Iowa

25

Jacksonville & More

26

Kansas City & More

27

Greater Los Angeles Area

28

Louisville & Lexington

29

Memphis, Little Rock & More

30

Milwaukee & WI

31

Nashville, Knoxville & More

33

New England

34

New Orleans & More

35

New York/Tri-State Area

36

Phoenix & Tucson

37

Portland & More

38

Raleigh & Durham

39

Saint Louis & More

40

San Antonio & Austin

41

N. California/N. Nevada

42

Greater Seattle Area

43

North & South Dakota

44

Upstate New York

45

Utah & Montana

46

Virginia

47

Washington, DC and Maryland

48

West Virginia

49

Hawaii

50

Alaska

52

Nebraska

53

Springfield

54

Central Illinois

55

Northern New Jersey

121

South Carolina

122

South Texas

123

Beaumont

124

Connecticut

125

Oklahoma

#### Canada

ID

Market

102

Toronto, Hamilton & Area

103

Ottawa & Eastern Ontario

106

Manitoba

107

Edmonton & Northern Alberta

108

Calgary & Southern Alberta

110

B.C. Interior

111

Vancouver & Area

112

Saskatchewan

120

Montréal & Area

#### Europe

ID

Market

202

London (UK)

203

South (UK)

204

Midlands and Central (UK)

205

Wales and North West (UK)

206

North and North East (UK)

207

Scotland

208

Ireland

209

Northern Ireland

210

Germany

211

Netherlands

500

Sweden

501

Spain

502

Barcelona (Spain)

503

Madrid (Spain)

600

Turkey

#### Australia and New Zealand

ID

Market

302

New South Wales/Australian Capital Territory

303

Queensland

304

Western Australi

305

Victoria/Tasmania

306

Western Australia

351

North Island

352

South Island

#### Mexico

ID

Market

402

Mexico City and Metropolitan Area

403

Monterrey

404

Guadalajara

## Supported Sources

Source

ticketmaster

tmr (ticketmaster resale platform)

universe

frontgate

## Supported Locales

We support all languages, without any fallback.

## Supported Designated Market Area (DMA)

Designated Market Area (DMA) can be used to segment and target events to a specific audience. Each DMA groups several zipcodes into a specific market segmentation based on population demographics.

DMA ID

DMA name

200

All of US

212

Abilene - Sweetwater

213

Albany - Schenectady - Troy

214

Albany, GA

215

Albuquerque - Santa Fe

216

Alexandria, LA

217

Alpena

218

Amarillo

219

Anchorage

220

Atlanta

221

Augusta

222

Austin

223

Bakersfield

224

Baltimore

225

Bangor

226

Baton Rouge

227

Beaumont - Port Arthur

228

Bend, OR

229

Billings

230

Biloxi - Gulfport

231

Binghamton

232

Birmingham (Anniston and Tuscaloosa)

233

Bluefield - Beckley - Oak Hill

234

Boise

235

Boston (Manchester)

236

Bowling Green

237

Buffalo

238

Burlington - Plattsburgh

239

Butte - Bozeman

240

Casper - Riverton

241

Cedar Rapids - Waterloo & Dubuque

242

Champaign & Springfield - Decatur

243

Charleston, SC

244

Charleston-Huntington

245

Charlotte

246

Charlottesville

247

Chattanooga

248

Cheyenne - Scottsbluff

249

Chicago

250

Chico - Redding

251

Cincinnati

252

Clarksburg - Weston

253

Cleveland

254

Colorado Springs - Pueblo

255

Columbia - Jefferson City

256

Columbia, SC

257

Columbus - Tupelo - West Point

258

Columbus, GA

259

Columbus, OH

260

Corpus Christi

261

Dallas - Fort Worth

262

Davenport - Rock Island - Moline

263

Dayton

264

Denver

265

Des Moines - Ames

266

Detroit

267

Dothan

268

Duluth - Superior

269

El Paso

270

Elmira

271

Erie

272

Eugene

273

Eureka

274

Evansville

275

Fairbanks

276

Fargo - Valley City

277

Flint - Saginaw - Bay City

278

Florence - Myrtle Beach

279

Fort Myers - Naples

280

Fort Smith - Fayetteville - Springdale - Rogers

281

Fort Wayne

282

Fresno - Visalia

283

Gainesville

284

Glendive

285

Grand Junction - Montrose

286

Grand Rapids - Kalamazoo - Battle Creek

287

Great Falls

288

Green Bay - Appleton

289

Greensboro - High Point - Winston-Salem

290

Greenville - New Bern - Washington

291

Greenville - Spartansburg - Asheville - Anderson

292

Greenwood - Greenville

293

Harlingen - Weslaco - Brownsville - McAllen

294

Harrisburg - Lancaster - Lebanon - York

295

Harrisonburg

296

Hartford & New Haven

297

Hattiesburg - Laurel

298

Helena

299

Honolulu

300

Houston

301

Huntsville - Decatur (Florence)

302

Idaho Falls - Pocatello

303

Indianapolis

304

Jackson, MS

305

Jackson, TN

306

Jacksonville

307

Johnstown - Altoona

308

Jonesboro

309

Joplin - Pittsburg

310

Juneau

311

Kansas City

312

Knoxville

313

La Crosse - Eau Claire

314

Lafayette, IN

315

Lafayette, LA

316

Lake Charles

317

Lansing

318

Laredo

319

Las Vegas

320

Lexington

321

Lima

322

Lincoln & Hastings - Kearney

323

Little Rock - Pine Bluff

324

Los Angeles

325

Louisville

326

Lubbock

327

Macon

328

Madison

329

Mankato

330

Marquette

331

Medford - Klamath Falls

332

Memphis

333

Meridian

334

Miami - Fort Lauderdale

335

Milwaukee

336

Minneapolis - Saint Paul

337

Minot - Bismarck - Dickinson

338

Missoula

339

Mobile - Pensacola (Fort Walton Beach)

340

Monroe - El Dorado

341

Monterey - Salinas

342

Montgomery (Selma)

343

Nashville

344

New Orleans

345

New York

346

Norfolk - Portsmouth - Newport News

347

North Platte

348

Odessa - Midland

349

Oklahoma City

350

Omaha

351

Orlando - Daytona Beach - Melbourne

352

Ottumwa - Kirksville

353

Paducah - Cape Girardeau - Harrisburg - Mt Vernon

354

Palm Springs

355

Panama City

356

Parkersburg

357

Peoria - Bloomington

358

Philadelphia

359

Phoenix

360

Pittsburgh

361

Portland - Auburn

362

Portland, OR

363

Presque Isle

364

Providence - New Bedford

365

Quincy - Hannibal - Keokuk

366

Raleigh - Durham (Fayetteville)

367

Rapid City

368

Reno

369

Richmond - Petersburg

370

Roanoke - Lynchburg

371

Rochester - Mason City - Austin

372

Rochester, NY

373

Rockford

374

Sacramento - Stockton - Modesto

375

Saint Joseph

376

Saint Louis

377

Salisbury

378

Salt Lake City

379

San Angelo

380

San Antonio

381

San Diego

382

San Francisco - Oakland - San Jose

383

Santa Barbara - Santa Maria - San Luis Obispo

384

Savannah

385

Seattle - Tacoma

386

Sherman - Ada

387

Shreveport

388

Sioux City

389

Sioux Falls (Mitchell)

390

South Bend - Elkhart

391

Spokane

392

Springfield - Holyoke

393

Springfield, MO

394

Syracuse

395

Tallahassee - Thomasville

396

Tampa - Saint Petersburg (Sarasota)

397

Terre Haute

398

Toledo

399

Topeka

400

Traverse City - Cadillac

401

Tri-Cities, TN-VA

402

Tucson (Sierra Vista)

403

Tulsa

404

Twin Falls

405

Tyler - Longview (Lufkin & Nacogdoches)

406

Utica

407

Victoria

408

Waco - Temple - Bryan

409

Washington DC (Hagerstown)

410

Watertown

411

Wausau - Rhinelander

412

West Palm Beach - Fort Pierce

413

Wheeling - Steubenville

414

Wichita - Hutchinson

415

Wichita Falls & Lawton

416

Wilkes Barre - Scranton

417

Wilmington

418

Yakima - Pasco - Richland - Kennewick

419

Youngstown

420

Yuma - El Centro

421

Zanesville

422

Northern New Jersey

500

All of Canada

501

Barrie-Orillia

502

Belleville-Peterborough

503

Owen Sound

504

Burnaby-New Westminster-Surrey

505

Calgary-Banff

506

Edmonton

507

Fraser Valley

508

Hamilton-Niagara

509

Kitchener-Waterloo

510

London-Sarnia

511

Mississauga-Oakville

512

Newfoundland

513

NWT

514

New Brunswick

515

Northern Ontario

516

Nova Scotia

517

Nunavit

518

Okanagan-Kootenays

519

Ottawa-Gatineau

520

PEI

521

Prince George-North

522

Montreal and Surrounding Area

523

Red Deer

524

Saskatchewan

527

Toronto

528

Vancouver

529

Sunshine Coast-Islands

530

Winnipeg-Brandon

531

Yukon

601

All of United Kingdom

602

London

603

South

604

Midlands and Central

605

Wales and North West

606

North and North East

607

Scotland

608

All of Ireland

609

Northern Ireland

610

Germany

611

Netherlands

612

Sweden

613

Turkey

701

All of Australia

702

New South Wales/Australian Capital Territory

703

Queensland

704

Western Australia

705

Victoria/Tasmania

750

All of New Zealand

751

North Island

752

South Island

801

All of Mexico

802

Mexico City and Metropolitan Area

803

Monterrey

804

Guadalajara

901

All of Spain

902

Barcelona

903

Madrid