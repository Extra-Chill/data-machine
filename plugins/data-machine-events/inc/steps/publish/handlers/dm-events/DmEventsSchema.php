<?php
/**
 * Event Schema JSON-LD Generator
 *
 * Generates Google Event Schema from Event Details block attributes and venue taxonomy data.
 *
 * @package DmEvents\Steps\Publish\Handlers\DmEvents
 */

namespace DmEvents\Steps\Publish\Handlers\DmEvents;

if (!defined('ABSPATH')) {
    exit;
}

class DmEventsSchema {

    /**
     * Route parameters between engine (system data) and tool (AI inference)
     *
     * Engine parameters have priority over import data for venue information.
     *
     * @param array $event_data Basic event data
     * @param array $import_data Import handler data
     * @param array $engine_parameters Engine parameters with venue data
     * @return array Array with 'engine' and 'tool' parameter routing
     */
    public static function engine_or_tool($event_data, $import_data, $engine_parameters = []) {
        $engine_params = [];
        $tool_params = [];
        
        // Schema parameters available for routing
        $possible_fields = [
            'startDate', 'endDate', 'startTime', 'endTime',
            'performer', 'performerType', 
            'organizer', 'organizerType', 'organizerUrl',
            'eventStatus', 'previousStartDate',
            'price', 'priceCurrency', 'ticketUrl', 'offerAvailability',
            'description' // Always goes to tool for AI generation
        ];
        
        foreach ($possible_fields as $field) {
            if ($field === 'description') {
                $tool_params[] = $field;
                continue;
            }
            
            // Engine parameters take precedence over import data
            if (!empty($engine_parameters[$field])) {
                $engine_params[$field] = $engine_parameters[$field];
            } elseif (!empty($import_data[$field])) {
                $engine_params[$field] = $import_data[$field];
            } else {
                $tool_params[] = $field;
            }
        }
        
        // Handle venue fields with engine parameter priority
        $venue_fields = ['venue', 'venueAddress', 'venueCity', 'venueState', 'venueZip', 
                        'venueCountry', 'venuePhone', 'venueWebsite', 'venueCoordinates'];
        foreach ($venue_fields as $field) {
            // Engine parameters take precedence over import data for venue information
            if (!empty($engine_parameters[$field])) {
                $engine_params[$field] = $engine_parameters[$field];
            } elseif (!empty($import_data[$field])) {
                $engine_params[$field] = $import_data[$field];
            }
            // Note: venue fields don't go to tool_params if missing - they're optional
        }
        
        return [
            'engine' => $engine_params,
            'tool' => $tool_params
        ];
    }

    /**
     * Generate Google Event Schema JSON-LD from block attributes with engine parameter priority
     * 
     * Priority chain: 1) Engine Parameters, 2) Venue Taxonomy Meta, 3) Block Attributes
     * This ensures imported venue data takes precedence over user-entered block data.
     *
     * @param array $attributes Event Details block attributes
     * @param array|null $venue_data Venue taxonomy meta data
     * @param int $post_id WordPress post ID
     * @param array $engine_parameters Optional engine parameters (highest priority)
     * @return array Event schema array ready for JSON-LD output
     */
    public static function generate_event_schema(array $attributes, ?array $venue_data, int $post_id, array $engine_parameters = []): array {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => get_the_title($post_id),
        ];
        
        if (!empty($attributes['startDate'])) {
            $start_time = !empty($attributes['startTime']) ? 'T' . $attributes['startTime'] : '';
            $schema['startDate'] = $attributes['startDate'] . $start_time;
        }
        
        if (!empty($attributes['endDate'])) {
            $end_time = !empty($attributes['endTime']) ? 'T' . $attributes['endTime'] : '';
            $schema['endDate'] = $attributes['endDate'] . $end_time;
        }
        
        // Generate location schema with priority: engine parameters > venue taxonomy > attributes
        $venue_name = $engine_parameters['venue'] ?? $venue_data['name'] ?? $attributes['venue'] ?? '';
        
        if (!empty($venue_name)) {
            $schema['location'] = [
                '@type' => 'Place',
                'name' => $venue_name
            ];
            
            // Use priority chain for address components
            $address_data = [
                'streetAddress' => $engine_parameters['venueAddress'] ?? $venue_data['address'] ?? $attributes['address'] ?? '',
                'addressLocality' => $engine_parameters['venueCity'] ?? $venue_data['city'] ?? $attributes['venueCity'] ?? '',
                'addressRegion' => $engine_parameters['venueState'] ?? $venue_data['state'] ?? $attributes['venueState'] ?? '',
                'postalCode' => $engine_parameters['venueZip'] ?? $venue_data['zip'] ?? $attributes['venueZip'] ?? '',
                'addressCountry' => $engine_parameters['venueCountry'] ?? $venue_data['country'] ?? $attributes['venueCountry'] ?? 'US'
            ];
            
            // Only add address if we have at least street address or city
            if (!empty($address_data['streetAddress']) || !empty($address_data['addressLocality'])) {
                $schema['location']['address'] = ['@type' => 'PostalAddress'];
                
                foreach ($address_data as $key => $value) {
                    if (!empty($value)) {
                        $schema['location']['address'][$key] = $value;
                    }
                }
            }
            
            // Add venue phone from engine parameters (highest priority)
            $venue_phone = $engine_parameters['venuePhone'] ?? $venue_data['phone'] ?? $attributes['venuePhone'] ?? '';
            if (!empty($venue_phone)) {
                $schema['location']['telephone'] = $venue_phone;
            }
            
            // Add venue website from engine parameters (highest priority)
            $venue_website = $engine_parameters['venueWebsite'] ?? $venue_data['website'] ?? $attributes['venueWebsite'] ?? '';
            if (!empty($venue_website)) {
                $schema['location']['url'] = $venue_website;
            }
            
            // Add venue coordinates for geo schema
            $venue_coordinates = $engine_parameters['venueCoordinates'] ?? $venue_data['coordinates'] ?? $attributes['venueCoordinates'] ?? '';
            if (!empty($venue_coordinates)) {
                $coords = explode(',', $venue_coordinates);
                if (count($coords) === 2) {
                    $schema['location']['geo'] = [
                        '@type' => 'GeoCoordinates',
                        'latitude' => trim($coords[0]),
                        'longitude' => trim($coords[1])
                    ];
                }
            }
        }
        
        if (!empty($attributes['description'])) {
            $schema['description'] = $attributes['description'];
        }
        
        if (!empty($attributes['performer'])) {
            $performer_type = !empty($attributes['performerType']) ? $attributes['performerType'] : 'PerformingGroup';
            $schema['performer'] = [
                '@type' => $performer_type,
                'name' => $attributes['performer']
            ];
        }
        
        if (!empty($attributes['organizer'])) {
            $organizer_type = !empty($attributes['organizerType']) ? $attributes['organizerType'] : 'Organization';
            $schema['organizer'] = [
                '@type' => $organizer_type,
                'name' => $attributes['organizer']
            ];
            
            if (!empty($attributes['organizerUrl'])) {
                $schema['organizer']['url'] = $attributes['organizerUrl'];
            }
        }
        
        $featured_image_url = get_post_thumbnail_url($post_id, 'large');
        if ($featured_image_url) {
            $schema['image'] = $featured_image_url;
        }
        
        if (!empty($attributes['ticketUrl']) || !empty($attributes['price'])) {
            $schema['offers'] = [
                '@type' => 'Offer'
            ];
            
            if (!empty($attributes['ticketUrl'])) {
                $schema['offers']['url'] = $attributes['ticketUrl'];
            }
            
            if (!empty($attributes['offerAvailability'])) {
                $schema['offers']['availability'] = 'https://schema.org/' . $attributes['offerAvailability'];
            } else {
                $schema['offers']['availability'] = 'https://schema.org/InStock';
            }
            
            if (!empty($attributes['price'])) {
                $numeric_price = preg_replace('/[^0-9.]/', '', $attributes['price']);
                if ($numeric_price) {
                    $schema['offers']['price'] = floatval($numeric_price);
                    $schema['offers']['priceCurrency'] = !empty($attributes['priceCurrency']) ? $attributes['priceCurrency'] : 'USD';
                }
            }
        }
        
        if (!empty($attributes['eventStatus'])) {
            $schema['eventStatus'] = 'https://schema.org/' . $attributes['eventStatus'];
        } else {
            $schema['eventStatus'] = 'https://schema.org/EventScheduled';
        }
        
        if (!empty($attributes['previousStartDate']) && $attributes['eventStatus'] === 'EventRescheduled') {
            $schema['previousStartDate'] = $attributes['previousStartDate'];
        }
        
        return $schema;
    }
}