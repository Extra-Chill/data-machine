<?php
/**
 * Chill Events Publish Handler Registration
 * 
 * Registers the Chill Events publish handler and AI tools with Data Machine.
 *
 * @package ChillEvents\Steps\Publish\Handlers\ChillEvents
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Chill Events publish handler with Data Machine
 * 
 * Adds the chill_events_publish handler to Data Machine's handler registry.
 */
add_filter('dm_handlers', function($handlers) {
    $handlers['chill_events_publish'] = [
        'type' => 'publish',
        'class' => 'ChillEvents\\Steps\\Publish\\Handlers\\ChillEvents\\ChillEventsPublisher',
        'label' => __('Publish to Events Calendar', 'chill-events'),
        'description' => __('Create event posts in WordPress with Event Details blocks', 'chill-events')
    ];
    
    return $handlers;
});

/**
 * Register AI tools for event creation
 * 
 * Registers the create_event AI tool when chill_events_publish handler is used.
 * Follows Data Machine's conditional registration pattern.
 */
add_filter('ai_tools', function($tools, $handler_slug = null, $handler_config = []) {
    // Only register tool when chill_events_publish handler is the target
    if ($handler_slug === 'chill_events_publish') {
        $tools['create_event'] = [
            'class' => 'ChillEvents\\Steps\\Publish\\Handlers\\ChillEvents\\ChillEventsPublisher',
            'method' => 'handle_tool_call',
            'handler' => 'chill_events_publish',
            'description' => 'Create an event post with Event Details block in WordPress',
            'handler_config' => $handler_config,
            'parameters' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Event title',
                    'required' => true
                ],
                'startDate' => [
                    'type' => 'string',
                    'description' => 'Event start date (YYYY-MM-DD format)',
                    'required' => false
                ],
                'endDate' => [
                    'type' => 'string', 
                    'description' => 'Event end date (YYYY-MM-DD format)',
                    'required' => false
                ],
                'startTime' => [
                    'type' => 'string',
                    'description' => 'Event start time (HH:MM format)',
                    'required' => false
                ],
                'endTime' => [
                    'type' => 'string',
                    'description' => 'Event end time (HH:MM format)', 
                    'required' => false
                ],
                'venue' => [
                    'type' => 'string',
                    'description' => 'Venue name',
                    'required' => false
                ],
                'address' => [
                    'type' => 'string',
                    'description' => 'Venue address',
                    'required' => false
                ],
                'artist' => [
                    'type' => 'string',
                    'description' => 'Artist or performer name',
                    'required' => false
                ],
                'price' => [
                    'type' => 'string',
                    'description' => 'Ticket price information',
                    'required' => false
                ],
                'ticketUrl' => [
                    'type' => 'string',
                    'description' => 'URL to purchase tickets',
                    'required' => false
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Event description',
                    'required' => false
                ]
            ]
        ];
    }
    
    return $tools;
}, 10, 3);