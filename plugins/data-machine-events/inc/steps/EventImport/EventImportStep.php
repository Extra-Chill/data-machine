<?php
/**
 * Event import step for Data Machine pipeline with handler discovery
 *
 * @package DataMachineEvents\Steps\EventImport
 */

namespace DataMachineEvents\Steps\EventImport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Event import step for Data Machine pipeline with handler discovery
 */
class EventImportStep {

    /**
     * @param array $parameters Flat parameter structure from Data Machine
     * @return array Updated data packet array with event data added
     */
    public function execute(array $parameters): array {
        $job_id = $parameters['job_id'];
        $flow_step_id = $parameters['flow_step_id'];
        $data = $parameters['data'] ?? [];
        $flow_step_config = $parameters['flow_step_config'] ?? [];
        
        try {
            do_action('datamachine_log', 'debug', 'Event Import Step: Starting event collection', [
                'flow_step_id' => $flow_step_id,
                'existing_items' => count($data)
            ]);
            
            if (empty($flow_step_config)) {
                do_action('datamachine_log', 'error', 'Event Import Step: No step configuration provided', ['flow_step_id' => $flow_step_id]);
                return $data;
            }

            $handler_data = $flow_step_config['handler'] ?? null;
            
            if (!$handler_data || empty($handler_data['handler_slug'])) {
                do_action('datamachine_log', 'error', 'Event Import Step: Event import step requires handler configuration', [
                    'flow_step_id' => $flow_step_id,
                    'available_flow_step_config' => array_keys($flow_step_config),
                    'handler_data' => $handler_data
                ]);
                return $data;
            }
            
            $handler_slug = $handler_data['handler_slug'];
            
            // Get handler object from registry
            $all_handlers = apply_filters('datamachine_handlers', []);
            $handler_info = $all_handlers[$handler_slug] ?? null;
            
            if (!$handler_info || empty($handler_info['class'])) {
                do_action('datamachine_log', 'error', 'Event Import Step: Handler not found in registry', [
                    'handler_slug' => $handler_slug,
                    'flow_step_id' => $flow_step_id
                ]);
                return $data;
            }
            
            // Verify handler type is event_import
            if (($handler_info['type'] ?? '') !== 'event_import') {
                do_action('datamachine_log', 'error', 'Event Import Step: Invalid handler type', [
                    'handler_slug' => $handler_slug,
                    'expected_type' => 'event_import',
                    'actual_type' => $handler_info['type'] ?? 'unknown'
                ]);
                return $data;
            }
            
            // Instantiate handler
            $class_name = $handler_info['class'];
            if (!class_exists($class_name)) {
                do_action('datamachine_log', 'error', 'Event Import Step: Handler class not found', [
                    'handler_slug' => $handler_slug,
                    'class_name' => $class_name
                ]);
                return $data;
            }
            
            $handler = new $class_name();
            
            // Execute handler with unified parameter structure
            $data = $handler->execute($parameters);

            do_action('datamachine_log', 'debug', 'Event Import Step: Direct handler execution completed', [
                'flow_step_id' => $flow_step_id,
                'handler_slug' => $handler_slug,
                'handler_class' => $class_name,
                'total_data_entries' => count($data)
            ]);

            return $data;

        } catch (\Exception $e) {
            do_action('datamachine_log', 'error', 'Event Import Step: Exception during event collection', [
                'flow_step_id' => $flow_step_id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Return unchanged data packet on failure
            return $data;
        }
    }



}