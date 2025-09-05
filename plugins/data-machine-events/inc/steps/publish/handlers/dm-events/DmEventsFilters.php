<?php
/**
 * Data Machine Events Publish Handler Registration
 * 
 * Registers the Data Machine Events publish handler and AI tools with Data Machine.
 *
 * @package DmEvents\Steps\Publish\Handlers\DmEvents
 * @since 1.0.0
 */

namespace DmEvents\Steps\Publish\Handlers\DmEvents;

use DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Data Machine Events publish handler with Data Machine
 * 
 * Adds the create_event handler to Data Machine's handler registry.
 */
add_filter('dm_handlers', function($handlers) {
    $handlers['create_event'] = [
        'type' => 'publish',
        'class' => 'DmEvents\\Steps\\Publish\\Handlers\\DmEvents\\DmEventsPublisher',
        'label' => __('Publish to Events Calendar', 'dm-events'),
        'description' => __('Create event posts in WordPress with Event Details blocks', 'dm-events')
    ];
    
    return $handlers;
});

/**
 * Register settings for Data Machine Events publish handler
 */
add_filter('dm_handler_settings', function($all_settings) {
    $all_settings['create_event'] = new DmEventsSettings();
    return $all_settings;
});

/**
 * Register AI tools for event creation
 * 
 * Registers the create_event AI tool when create_event handler is used.
 * Dynamically includes taxonomy parameters based on settings.
 */
add_filter('ai_tools', function($tools, $handler_slug = null, $handler_config = []) {
    // Only register tool when create_event handler is the target
    if ($handler_slug === 'create_event') {
        $tools['create_event'] = ce_get_dynamic_event_tool($handler_config);
    }
    
    return $tools;
}, 10, 3);

/**
 * Register handler directive for AI guidance
 * 
 * Provides clear instructions to AI about mandatory tool usage for event creation.
 */
add_filter('dm_handler_directives', function($directives) {
    $directives['create_event'] = 'You MUST create an event post using the create_event tool. Generate an engaging description based on the provided event data. Process ALL event information into a WordPress post - this action is required and not optional.';
    return $directives;
});

/**
 * Get base event tool definition
 * 
 * @return array Base event tool configuration
 */
function ce_get_event_base_tool(): array {
    return [
        'class' => 'DmEvents\\Steps\\Publish\\Handlers\\DmEvents\\DmEventsPublisher',
        'method' => 'handle_tool_call',
        'handler' => 'create_event',
        'description' => 'REQUIRED: Process event data into WordPress post with Event Details block. You must use this tool to create the event.',
        'parameters' => [
            'title' => [
                'type' => 'string',
                'description' => 'Event title should be direct and descriptive (event name, venue, performer) but exclude dates',
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
                'description' => 'Venue name (extract if available in event data)',
                'required' => false
            ],
            'artist' => [
                'type' => 'string',
                'description' => 'Artist or performer name (extract if available in event data)',
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
                'description' => 'Generate an engaging, informative description for this event based on the provided details (venue, artist, dates, etc.). Make it compelling and informative for potential attendees.',
                'required' => true
            ]
        ]
    ];
}

/**
 * Generate dynamic event tool based on taxonomy settings
 * 
 * @param array $handler_config Handler configuration containing taxonomy selections
 * @return array Dynamic tool configuration with taxonomy parameters
 */
function ce_get_dynamic_event_tool(array $handler_config): array {
    // Extract Data Machine Events-specific config
    $ce_config = $handler_config['create_event'] ?? $handler_config;
    
    // Apply global defaults from settings if available
    if (function_exists('apply_filters')) {
        $ce_config = apply_filters('dm_apply_global_defaults', $ce_config, 'create_event', 'publish');
    }
    
    // Start with base tool
    $tool = ce_get_event_base_tool();
    
    // Add dynamic parameter requirement logic based on available data
    ce_apply_dynamic_parameter_requirements($tool, $ce_config);
    
    // Add dynamic schema parameters based on data completeness
    $schema_params = ce_get_dynamic_schema_parameters($ce_config);
    $tool['parameters'] = array_merge($tool['parameters'], $schema_params);
    
    // Store resolved configuration for execution
    $tool['handler_config'] = $ce_config;
    
    if (!is_array($handler_config) || empty($ce_config)) {
        return $tool;
    }
    
    // Get taxonomies that support 'dm_events' post type
    $taxonomies = get_object_taxonomies('dm_events', 'objects');
    
    foreach ($taxonomies as $taxonomy) {
        if (!$taxonomy->public) {
            continue;
        }
        
        $field_key = "taxonomy_{$taxonomy->name}_selection";
        $selection = $ce_config[$field_key] ?? 'skip';
        
        // Only include taxonomies set to 'ai_decides' as AI tool parameters
        if ($selection === 'ai_decides') {
            $parameter_name = $taxonomy->name;
            
            // Add existing terms as context for AI decision
            $terms = DmEventsSettings::get_taxonomy_terms_for_ai($taxonomy->name);
            $description = "Choose appropriate {$taxonomy->label} for this event";
            
            if (!empty($terms)) {
                $term_names = array_column($terms, 'name');
                $description .= '. Available options: ' . implode(', ', array_slice($term_names, 0, 10));
                if (count($term_names) > 10) {
                    $description .= ' and ' . (count($term_names) - 10) . ' more';
                }
            }
            
            if ($taxonomy->hierarchical) {
                $tool['parameters'][$parameter_name] = [
                    'type' => 'string',
                    'required' => false,
                    'description' => $description
                ];
            } else {
                $tool['parameters'][$parameter_name] = [
                    'type' => 'array',
                    'required' => false,
                    'description' => $description . ' (can be multiple)'
                ];
            }
        }
    }
    
    return $tool;
}

/**
 * Apply dynamic parameter requirements based on available import data
 * 
 * Makes venue and artist parameters required only when data is available,
 * following the pattern of other successful Data Machine handlers.
 * 
 * @param array &$tool Tool configuration to modify (passed by reference)
 * @param array $ce_config Configuration data including import context
 */
function ce_apply_dynamic_parameter_requirements(array &$tool, array $ce_config): void {
    // If no data context available, keep base requirements
    if (empty($ce_config)) {
        // Make venue and artist optional when no data context
        if (isset($tool['parameters']['venue'])) {
            $tool['parameters']['venue']['required'] = false;
            $tool['parameters']['venue']['description'] = 'Venue name (extract if available in event data)';
        }
        if (isset($tool['parameters']['artist'])) {
            $tool['parameters']['artist']['required'] = false;  
            $tool['parameters']['artist']['description'] = 'Artist or performer name (extract if available in event data)';
        }
        return;
    }
    
    // Check if venue data is available in import context
    $has_venue_data = !empty($ce_config['venue']) || 
                     !empty($ce_config['venue_name']) ||
                     !empty($ce_config['location']);
    
    // Check if artist data is available in import context  
    $has_artist_data = !empty($ce_config['artist']) ||
                      !empty($ce_config['performer']) ||
                      !empty($ce_config['artist_name']);
                      
    // Apply dynamic requirements based on data availability - remove system-provided parameters
    if (isset($tool['parameters']['venue'])) {
        if ($has_venue_data) {
            // System has venue data - remove parameter, let system provide it
            unset($tool['parameters']['venue']);
        } else {
            // System lacks venue data - let AI decide/generate  
            $tool['parameters']['venue']['required'] = false;
            $tool['parameters']['venue']['description'] = 'Generate appropriate venue name if not provided in event data';
        }
    }
    
    if (isset($tool['parameters']['artist'])) {
        if ($has_artist_data) {
            // System has artist data - remove parameter, let system provide it
            unset($tool['parameters']['artist']);
        } else {
            // System lacks artist data - let AI decide/generate
            $tool['parameters']['artist']['required'] = false;
            $tool['parameters']['artist']['description'] = 'Generate appropriate artist/performer name if not provided in event data';
        }
    }
}

/**
 * Generate dynamic schema parameters based on data completeness
 * 
 * Analyzes available import data and only includes AI parameters for fields
 * that cannot be reliably derived from system data.
 * 
 * @param array $ce_config Configuration data including import context
 * @return array Additional AI tool parameters for schema completion
 */
function ce_get_dynamic_schema_parameters(array $ce_config): array {
    $params = [];
    
    // Always include performer type inference when artist data exists
    $params['performerType'] = [
        'type' => 'string',
        'required' => false,
        'description' => 'Determine performer type based on artist name: Person for solo artists, PerformingGroup for bands/groups, MusicGroup for musical ensembles',
        'enum' => ['Person', 'PerformingGroup', 'MusicGroup']
    ];
    
    // Include organizer inference for better schema completeness
    $params['organizerName'] = [
        'type' => 'string',
        'required' => false,
        'description' => 'Determine event organizer name from context (venue management, artist label, or promotion company). Leave empty if unclear.'
    ];
    
    $params['organizerType'] = [
        'type' => 'string',
        'required' => false,
        'description' => 'Determine if organizer is a Person or Organization',
        'enum' => ['Person', 'Organization']
    ];
    
    // Currency inference for pricing schema
    $params['priceCurrency'] = [
        'type' => 'string',
        'required' => false,
        'description' => 'Infer ISO 4217 currency code from price format and venue location context (USD, EUR, GBP, etc.)'
    ];
    
    // Event status inference for enhanced schema
    $params['eventStatus'] = [
        'type' => 'string',
        'required' => false,
        'description' => 'Determine event status - use EventScheduled for normal events, EventPostponed/EventCancelled only if explicitly mentioned',
        'enum' => ['EventScheduled', 'EventPostponed', 'EventCancelled', 'EventRescheduled']
    ];
    
    // Offer availability inference
    $params['offerAvailability'] = [
        'type' => 'string', 
        'required' => false,
        'description' => 'Determine ticket availability: InStock for current events, SoldOut if mentioned, PreOrder for future sales',
        'enum' => ['InStock', 'SoldOut', 'PreOrder']
    ];
    
    return $params;
}