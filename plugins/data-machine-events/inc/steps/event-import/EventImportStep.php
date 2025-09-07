<?php
/**
 * Event Import Step for Data Machine
 * 
 * Custom step type that imports events from multiple sources using handler discovery.
 * Follows Data Machine's step architecture pattern.
 *
 * @package DmEvents\Steps\EventImport
 * @since 1.0.0
 */

namespace DmEvents\Steps\EventImport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EventImportStep class
 * 
 * Executes event import handlers to gather events from various sources.
 * Returns standardized data packets for Data Machine pipeline processing.
 */
class EventImportStep {

    /**
     * Execute event import with direct handler execution
     * 
     * Modern flat parameter architecture - directly executes handlers with unified parameters.
     * Eliminates adapter layer for native Data Machine compatibility.
     * 
     * @param array $parameters Unified flat parameter array from Data Machine engine
     *   - job_id: Data Machine job ID for tracking
     *   - flow_step_id: Flow step ID for processed items tracking  
     *   - data: Data packet array (cumulative pipeline data)
     *   - flow_step_config: Step configuration including handler settings
     * @return array Updated data packet array with event data added
     */
    public function execute(array $parameters): array {
        // Extract from flat parameter structure (Data Machine standard)
        $job_id = $parameters['job_id'];
        $flow_step_id = $parameters['flow_step_id'];
        $data = $parameters['data'] ?? [];
        $flow_step_config = $parameters['flow_step_config'] ?? [];
        
        try {
            do_action('dm_log', 'debug', 'Event Import Step: Starting event collection', [
                'flow_step_id' => $flow_step_id,
                'existing_items' => count($data)
            ]);
            
            if (empty($flow_step_config)) {
                do_action('dm_log', 'error', 'Event Import Step: No step configuration provided', ['flow_step_id' => $flow_step_id]);
                return $data;
            }

            $handler_data = $flow_step_config['handler'] ?? null;
            
            if (!$handler_data || empty($handler_data['handler_slug'])) {
                do_action('dm_log', 'error', 'Event Import Step: Event import step requires handler configuration', [
                    'flow_step_id' => $flow_step_id,
                    'available_flow_step_config' => array_keys($flow_step_config),
                    'handler_data' => $handler_data
                ]);
                return $data;
            }
            
            $handler_slug = $handler_data['handler_slug'];
            
            // Get handler object directly from unified discovery
            $all_handlers = apply_filters('dm_handlers', []);
            $handler_info = $all_handlers[$handler_slug] ?? null;
            
            if (!$handler_info || empty($handler_info['class'])) {
                do_action('dm_log', 'error', 'Event Import Step: Handler not found in registry', [
                    'handler_slug' => $handler_slug,
                    'flow_step_id' => $flow_step_id
                ]);
                return $data;
            }
            
            // Verify handler type is event_import
            if (($handler_info['type'] ?? '') !== 'event_import') {
                do_action('dm_log', 'error', 'Event Import Step: Invalid handler type', [
                    'handler_slug' => $handler_slug,
                    'expected_type' => 'event_import',
                    'actual_type' => $handler_info['type'] ?? 'unknown'
                ]);
                return $data;
            }
            
            // Instantiate handler
            $class_name = $handler_info['class'];
            if (!class_exists($class_name)) {
                do_action('dm_log', 'error', 'Event Import Step: Handler class not found', [
                    'handler_slug' => $handler_slug,
                    'class_name' => $class_name
                ]);
                return $data;
            }
            
            $handler = new $class_name();
            
            // Execute handler directly with flat parameters (no adapter layer)
            $data = $handler->execute($parameters);

            do_action('dm_log', 'debug', 'Event Import Step: Direct handler execution completed', [
                'flow_step_id' => $flow_step_id,
                'handler_slug' => $handler_slug,
                'handler_class' => $class_name,
                'total_data_entries' => count($data)
            ]);

            return $data;

        } catch (\Exception $e) {
            do_action('dm_log', 'error', 'Event Import Step: Exception during event collection', [
                'flow_step_id' => $flow_step_id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Return unchanged data packet on failure
            return $data;
        }
    }



}