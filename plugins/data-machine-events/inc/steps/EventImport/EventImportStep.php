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
     * Execute event import step.
     *
     * @param array $payload Unified step payload
     * @return array Updated data packet array with event data added
     */
    public function execute(array $payload): array {
        $job_id = $payload['job_id'] ?? 0;
        $flow_step_id = $payload['flow_step_id'] ?? '';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $flow_step_config = $payload['flow_step_config'] ?? [];
        
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
            
            $handler_payload = $payload;
            $handler_payload['data'] = $data;
            $data = $handler->execute($handler_payload);

            if (!is_array($data)) {
                do_action('datamachine_log', 'error', 'Event Import Step: Handler returned invalid payload', [
                    'flow_step_id' => $flow_step_id,
                    'handler_slug' => $handler_slug,
                    'result_type' => gettype($data)
                ]);
                return is_array($payload['data'] ?? null) ? $payload['data'] : [];
            }

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