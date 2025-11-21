<?php
/**
 * Data Machine Events Handler Registration
 *
 * @package DataMachineEvents\Steps\Publish\Events
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\Publish\Events;

use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineEvents\Core\Venue_Taxonomy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register all DM Events publish handler component filters
 *
 * Complete self-registration pattern following Data Machine "plugins within plugins" architecture.
 * Engine discovers DM Events handler capabilities purely through filter-based discovery.
 */
class Filters {
    use HandlerRegistrationTrait;

    public static function register(): void {
        self::registerHandler(
            'create_event',
            'publish',
            Publisher::class,
            __('Publish to Events Calendar', 'datamachine-events'),
            __('Create event posts in WordPress with Event Details blocks', 'datamachine-events'),
            false,
            null,
            Settings::class,
            [self::class, 'registerAITools']
        );

        add_filter('datamachine_customize_handler_display', [self::class, 'customizeHandlerDisplay'], 10, 5);
    }

    /**
     * Register AI tools for event creation
     */
    public static function registerAITools($tools, $handler_slug = null, $handler_config = []) {
        // Only register tool when create_event handler is the target
        if ($handler_slug === 'create_event') {
            $tools['create_event'] = self::getDynamicEventTool($handler_config);
        }
        
        return $tools;
    }

    /**
     * Customize handler display for venue and author fields
     */
    public static function customizeHandlerDisplay($settings_display, $handler_slug, $current_settings, $fields, $flow_step_id) {
        // Check if this is a DM Events handler
        if ($handler_slug !== 'universal_web_scraper' && $handler_slug !== 'create_event') {
            return $settings_display;
        }

        // Process each setting for venue and author customization
        $customized_display = [];
        foreach ($settings_display as $setting) {
            $setting_key = $setting['key'] ?? '';
            $setting_value = $setting['value'] ?? '';

            // Handle venue term ID conversion to full metadata display
            if ($setting_key === 'venue' && is_numeric($setting_value) && class_exists(Venue_Taxonomy::class)) {
                $venue_data = Venue_Taxonomy::get_venue_data($setting_value);

                if (!empty($venue_data)) {
                    // Add venue name as primary display
                    $customized_display[] = [
                        'key' => 'venue',
                        'label' => 'Venue',
                        'value' => $setting_value,
                        'display_value' => $venue_data['name']
                    ];

                    // Add all venue metadata fields
                    $venue_meta_fields = [
                        'address' => 'Address',
                        'city' => 'City',
                        'state' => 'State',
                        'zip' => 'Zip',
                        'country' => 'Country',
                        'phone' => 'Phone',
                        'website' => 'Website',
                        'capacity' => 'Capacity',
                        'coordinates' => 'Coordinates'
                    ];

                    foreach ($venue_meta_fields as $field_key => $field_label) {
                        $customized_display[] = [
                            'key' => "venue_{$field_key}",
                            'label' => $field_label,
                            'value' => $venue_data[$field_key] ?? '',
                            'display_value' => $venue_data[$field_key] ?? ''
                        ];
                    }

                    continue; // Skip adding the original venue setting
                }
            }

            // Handle author user ID conversion to display name using centralized filter
            if (($setting_key === 'post_author' || $setting_key === 'author') && is_numeric($setting_value)) {
                $display_name = apply_filters('datamachine_wordpress_user_display_name', null, $setting_value);
                if ($display_name) {
                    $customized_display[] = [
                        'key' => $setting_key,
                        'label' => $setting['label'] ?? 'Post Author',
                        'value' => $setting_value,
                        'display_value' => $display_name
                    ];
                    continue; // Skip adding the original author setting
                }
            }

            // Keep all other settings as-is
            $customized_display[] = $setting;
        }

        return $customized_display;
    }

    /**
     * Get base event tool definition
     */
    private static function getBaseTool(): array {
        return [
            'class' => Publisher::class,
            'method' => 'handle_tool_call',
            'handler' => 'create_event',
            'description' => 'Create WordPress event post with Event Details block. This tool completes your pipeline task by publishing the event to WordPress.',
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
                ],
                'job_id' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Job ID for tracking workflow execution'
                ]
            ]
        ];
    }

    /**
     * Generate dynamic event tool based on taxonomy settings
     */
    private static function getDynamicEventTool(array $handler_config): array {
        // Extract Data Machine Events-specific config
        $ce_config = $handler_config['create_event'] ?? $handler_config;
        
        // Apply global defaults from settings if available
        if (function_exists('apply_filters')) {
            $ce_config = apply_filters('datamachine_apply_global_defaults', $ce_config, 'create_event', 'publish');
        }
        
        // Start with base tool
        $tool = self::getBaseTool();
        
        // Add dynamic parameter requirement logic based on available data
        self::applyDynamicParameterRequirements($tool, $ce_config);
        
        // Add dynamic schema parameters based on data completeness
        $schema_params = self::getDynamicSchemaParameters($ce_config);
        $tool['parameters'] = array_merge($tool['parameters'], $schema_params);
        
        // Store resolved configuration for execution
        $tool['handler_config'] = $ce_config;
        
        if (!is_array($handler_config) || empty($ce_config)) {
            return $tool;
        }
        
        // Get taxonomies that support 'datamachine_events' post type (EXCLUDING venue - handled by AI tool)
        $taxonomies = get_object_taxonomies('datamachine_events', 'objects');
        
        foreach ($taxonomies as $taxonomy) {
            if (!$taxonomy->public || $taxonomy->name === 'venue') {
                continue; // Skip venue - handled separately based on data availability
            }
            
            $field_key = "taxonomy_{$taxonomy->name}_selection";
            $selection = $ce_config[$field_key] ?? 'skip';
            
            // Only include taxonomies set to 'ai_decides' as AI tool parameters
            if ($selection === 'ai_decides') {
                $parameter_name = $taxonomy->name;
                
                // Add existing terms as context for AI decision
                $terms = Settings::get_taxonomy_terms_for_ai($taxonomy->name);
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
        
        // Handle venue parameters conditionally based on static venue data availability
        // Check if venue data will be available via engine data from import handlers
        $has_static_venue = self::checkStaticVenueAvailability($ce_config);
        
        if (!$has_static_venue) {
            // Add venue parameters to tool for AI to determine
            $tool['parameters']['venue'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Venue name where the event takes place'
            ];
            $tool['parameters']['venueAddress'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Street address of the venue'
            ];
            $tool['parameters']['venueCity'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'City where the venue is located'
            ];
            $tool['parameters']['venueState'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'State/province where the venue is located'
            ];
            $tool['parameters']['venueZip'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Postal/zip code of the venue'
            ];
            $tool['parameters']['venueCountry'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Country where the venue is located'
            ];
            $tool['parameters']['venuePhone'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Phone number of the venue'
            ];
            $tool['parameters']['venueWebsite'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'Website URL of the venue'
            ];
            $tool['parameters']['venueCoordinates'] = [
                'type' => 'string',
                'required' => false,
                'description' => 'GPS coordinates of the venue (latitude,longitude format)'
            ];
        }
        
        return $tool;
    }

    /**
     * Apply dynamic parameter requirements based on available import data
     */
    private static function applyDynamicParameterRequirements(array &$tool, array $ce_config): void {
        // If no data context available, keep base requirements
        if (empty($ce_config)) {
            // Make venue optional when no data context (though venue should be system parameter)
            if (isset($tool['parameters']['venue'])) {
                $tool['parameters']['venue']['required'] = false;
                $tool['parameters']['venue']['description'] = 'Venue name (extract if available in event data)';
            }
            return;
        }
        
        // Venue is ALWAYS provided by system from event data - remove from AI parameters
        if (isset($tool['parameters']['venue'])) {
            unset($tool['parameters']['venue']);
        }
        
        // Artist is handled via taxonomy system, not AI parameters - remove if present
        if (isset($tool['parameters']['artist'])) {
            unset($tool['parameters']['artist']);
        }
    }

    /**
     * Generate dynamic schema parameters based on data completeness
     */
    private static function getDynamicSchemaParameters(array $ce_config): array {
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

    /**
     * Check if static venue data will be available from import handlers
     */
    private static function checkStaticVenueAvailability(array $ce_config): bool {
        // Check for Web Scraper static venue configuration
        if (isset($ce_config['universal_web_scraper']['venue']) && !empty($ce_config['universal_web_scraper']['venue'])) {
            return true;
        }
        
        // Ticketmaster and Dice FM always provide venue data (no need to check - they're always static)
        // This function mainly determines if Web Scraper has static venue configured
        
        // Other import handlers that provide venue data would be checked here
        // For now, we assume if we don't have explicit static venue config, then AI should handle it

        return false;
    }
}

function datamachine_register_events_filters() {
    Filters::register();
}

datamachine_register_events_filters();
