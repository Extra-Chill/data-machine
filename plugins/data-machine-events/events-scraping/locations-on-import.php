<?php

/**
 * Assigns the 'location' taxonomy to imported events based on the source URL or organizer name.
 *
 * @param array  $event An array of the event data that was sent and saved.
 * @param array  $item  An array containing the raw version of the event data that was sent to the site.
 * @param object $data  An object representing the Importer record.
 */
function assign_location_taxonomy_based_on_source( $event, $item, $data ) {
    // Log function trigger
    error_log( "Custom TEC Imports: Function triggered for event ID {$event['ID']}." );

    // Attempt to get 'source' from $data->meta['source']
    if ( isset( $data->meta['source'] ) ) {
        $source_url = esc_url_raw( $data->meta['source'] );
        // Normalize URL by removing fragment and trailing slash
        $source_url = strtok( $source_url, '#' );
        $source_url = rtrim( $source_url, '/' );
        error_log( "Custom TEC Imports: Source URL from data->meta['source']: {$source_url}" );

        // Define source URL to location slug mapping
        $sources_and_locations = [
            'https://www.eventbrite.com/o/charleston-pour-house-17947350144' => 'charleston',
            'https://www.eventbrite.com/o/lo-fi-brewing-14959647606'         => 'charleston',
            // Add more mappings as needed
        ];

        // Normalize the mapping URLs
        $normalized_sources_and_locations = [];
        foreach ( $sources_and_locations as $url => $location ) {
            $normalized_url = strtok( $url, '#' );
            $normalized_url = rtrim( $normalized_url, '/' );
            $normalized_sources_and_locations[ $normalized_url ] = $location;
        }

        // Check if the source URL exists in the mapping array
        if ( array_key_exists( $source_url, $normalized_sources_and_locations ) ) {
            $location = $normalized_sources_and_locations[ $source_url ];
            error_log( "Custom TEC Imports: Assigning location '{$location}' to event ID {$event['ID']}." );

            // Assign the location taxonomy term to the event
            wp_set_object_terms( $event['ID'], $location, 'location', false );
        } else {
            error_log( "Custom TEC Imports: No mapping found for source URL '{$source_url}'." );
        }
    } else {
        // Fallback to organizer's name if 'source' meta is not available
        if ( isset( $event['Organizer'][0]->organizer ) ) {
            $organizer_name = sanitize_text_field( $event['Organizer'][0]->organizer );
            error_log( "Custom TEC Imports: Organizer name: '{$organizer_name}' for event ID {$event['ID']}." );

            // Define organizer name to location slug mapping
            $organizers_and_locations = [
                'Charleston Pour House' => 'charleston',
                'LO-Fi Brewing'         => 'charleston',
                // Add more mappings as needed
            ];

            if ( array_key_exists( $organizer_name, $organizers_and_locations ) ) {
                $location = $organizers_and_locations[ $organizer_name ];
                error_log( "Custom TEC Imports: Assigning location '{$location}' to event ID {$event['ID']}." );

                // Assign the location taxonomy term to the event
                wp_set_object_terms( $event['ID'], $location, 'location', false );
            } else {
                error_log( "Custom TEC Imports: No mapping found for organizer name '{$organizer_name}'." );
            }
        } else {
            error_log( "Custom TEC Imports: Neither 'source' meta nor organizer name found for event ID {$event['ID']}." );
        }
    }
}
add_action( 'tribe_aggregator_after_insert_post', 'assign_location_taxonomy_based_on_source', 10, 3 );
