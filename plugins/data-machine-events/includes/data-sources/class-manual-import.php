<?php
/**
 * Manual Import Data Source for Chill Events
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents;

if (!defined('ABSPATH')) {
    exit;
}

class ManualImport extends BaseDataSource {
    /**
     * Get info about this data source
     *
     * @return array
     */
    public function get_info() {
        return array(
            'name' => 'Manual Import',
            'type' => 'manual',
            'description' => 'Manually import events via CSV upload or direct input',
            'settings_fields' => array(
                'import_type' => array(
                    'type' => 'select',
                    'label' => 'Import Type',
                    'options' => array(
                        'csv' => 'CSV File Upload',
                        'json' => 'JSON Data Input',
                        'direct' => 'Direct Entry'
                    ),
                    'default' => 'csv',
                    'description' => 'Choose how you want to import events manually.'
                ),
                'batch_size' => array(
                    'type' => 'number',
                    'label' => 'Batch Size',
                    'default' => 25,
                    'description' => 'Number of events to process at once.'
                )
            )
        );
    }

    /**
     * Get events from this data source
     *
     * @param array $settings Data source settings
     * @return array Array of standardized event data
     */
    public function get_events($settings = array()) {
        // Manual import would typically be triggered by user action
        // This is just a placeholder that returns empty array
        return array();
    }
} 