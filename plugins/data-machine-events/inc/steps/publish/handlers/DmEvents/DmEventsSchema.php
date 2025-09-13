<?php
/**
 * Event Schema JSON-LD Generator
 *
 * @package DmEvents\Steps\Publish\Handlers\DmEvents
 */

namespace DmEvents\Steps\Publish\Handlers\DmEvents;

if (!defined('ABSPATH')) {
    exit;
}

class DmEventsSchema {

    /**
     * Routes parameters between engine (system) and AI processing based on data availability
     */
    public static function engine_or_tool($event_data, $import_data, $engine_parameters = []) {
        $engine_params = [];
        $tool_params = [];
        
        $possible_fields = [
            'startDate', 'endDate', 'startTime', 'endTime',
            'performer', 'performerType', 
            'organizer', 'organizerType', 'organizerUrl',
            'eventStatus', 'previousStartDate',
            'price', 'priceCurrency', 'ticketUrl', 'offerAvailability',
            'description'
        ];
        
        foreach ($possible_fields as $field) {
            if ($field === 'description') {
                $tool_params[] = $field;
                continue;
            }
            
            if (!empty($engine_parameters[$field])) {
                $engine_params[$field] = $engine_parameters[$field];
            } elseif (!empty($import_data[$field])) {
                $engine_params[$field] = $import_data[$field];
            } else {
                $tool_params[] = $field;
            }
        }
        
        $venue_fields = ['venue', 'venueAddress', 'venueCity', 'venueState', 'venueZip', 
                        'venueCountry', 'venuePhone', 'venueWebsite', 'venueCoordinates'];
        foreach ($venue_fields as $field) {
            // Engine parameters take precedence over import data for venue information
            if (!empty($engine_parameters[$field])) {
                $engine_params[$field] = $engine_parameters[$field];
            } elseif (!empty($import_data[$field])) {
                $engine_params[$field] = $import_data[$field];
            }
        }
        
        return [
            'engine' => $engine_params,
            'tool' => $tool_params
        ];
    }

    /**
     * Generates comprehensive Google Event structured data combining block attributes with venue taxonomy meta
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
        
        $venue_name = $engine_parameters['venue'] ?? $venue_data['name'] ?? $attributes['venue'] ?? '';
        
        if (!empty($venue_name)) {
            $schema['location'] = [
                '@type' => 'Place',
                'name' => $venue_name
            ];
            
            $address_data = [
                'streetAddress' => $engine_parameters['venueAddress'] ?? $venue_data['address'] ?? $attributes['address'] ?? '',
                'addressLocality' => $engine_parameters['venueCity'] ?? $venue_data['city'] ?? $attributes['venueCity'] ?? '',
                'addressRegion' => $engine_parameters['venueState'] ?? $venue_data['state'] ?? $attributes['venueState'] ?? '',
                'postalCode' => $engine_parameters['venueZip'] ?? $venue_data['zip'] ?? $attributes['venueZip'] ?? '',
                'addressCountry' => $engine_parameters['venueCountry'] ?? $venue_data['country'] ?? $attributes['venueCountry'] ?? 'US'
            ];
            
            if (!empty($address_data['streetAddress']) || !empty($address_data['addressLocality'])) {
                $schema['location']['address'] = ['@type' => 'PostalAddress'];
                
                foreach ($address_data as $key => $value) {
                    if (!empty($value)) {
                        $schema['location']['address'][$key] = $value;
                    }
                }
            }
            
            $venue_phone = $engine_parameters['venuePhone'] ?? $venue_data['phone'] ?? $attributes['venuePhone'] ?? '';
            if (!empty($venue_phone)) {
                $schema['location']['telephone'] = $venue_phone;
            }
            
            $venue_website = $engine_parameters['venueWebsite'] ?? $venue_data['website'] ?? $attributes['venueWebsite'] ?? '';
            if (!empty($venue_website)) {
                $schema['location']['url'] = $venue_website;
            }
            
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
        
        $featured_image_id = get_post_thumbnail_id($post_id);
        if ($featured_image_id) {
            $featured_image_url = wp_get_attachment_image_url($featured_image_id, 'large');
            if ($featured_image_url) {
                $schema['image'] = $featured_image_url;
            }
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