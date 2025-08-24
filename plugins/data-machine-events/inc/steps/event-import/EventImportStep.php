<?php
/**
 * Event Import Step for Data Machine
 * 
 * Custom step type that imports events from multiple sources using handler discovery.
 * Follows Data Machine's step architecture pattern.
 *
 * @package ChillEvents\Steps\EventImport
 * @since 1.0.0
 */

namespace ChillEvents\Steps\EventImport;

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
     * Execute event import data collection
     * 
     * @param string $job_id The job ID for context tracking
     * @param string $flow_step_id The flow step ID to process
     * @param array $data The cumulative data packet array for this job  
     * @param array $flow_step_config Flow step configuration including handler settings
     * @return array Updated data packet array with event data added
     */
    public function execute($job_id, $flow_step_id, array $data = [], array $flow_step_config = []): array {
        try {
            do_action('dm_log', 'debug', 'Event Import Step: Starting event collection', [
                'flow_step_id' => $flow_step_id,
                'existing_items' => count($data)
            ]);
            
            if (empty($flow_step_config)) {
                do_action('dm_log', 'error', 'Event Import Step: No step configuration provided', ['flow_step_id' => $flow_step_id]);
                return [];
            }

            $handler_data = $flow_step_config['handler'] ?? null;
            
            if (!$handler_data || empty($handler_data['handler_slug'])) {
                do_action('dm_log', 'error', 'Event Import Step: Event import step requires handler configuration', [
                    'flow_step_id' => $flow_step_id,
                    'available_flow_step_config' => array_keys($flow_step_config),
                    'handler_data' => $handler_data
                ]);
                return [];
            }
            
            $handler = $handler_data['handler_slug'];
            $handler_settings = $handler_data['settings'] ?? [];
            
            // Add flow_step_id to handler settings for proper context
            $handler_settings['flow_step_id'] = $flow_step_config['flow_step_id'] ?? null;

            // Execute single handler - one step, one handler, per flow
            $import_entry = $this->execute_handler($handler, $flow_step_config, $handler_settings, $job_id);

            if (!$import_entry || empty($import_entry['content']['title']) && empty($import_entry['content']['body'])) {
                do_action('dm_log', 'error', 'Event import handler returned no content', ['flow_step_id' => $flow_step_id]);
                return $data; // Return unchanged array
            }

            // Add import entry to front of data packet array (newest first)
            array_unshift($data, $import_entry);

            do_action('dm_log', 'debug', 'Event Import Step: Event collection completed', [
                'flow_step_id' => $flow_step_id,
                'handler' => $handler,
                'content_length' => strlen($import_entry['content']['body'] ?? '') + strlen($import_entry['content']['title'] ?? ''),
                'source_type' => $import_entry['metadata']['source_type'] ?? '',
                'total_items' => count($data)
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

    /**
     * Execute event import handler using filter-based discovery
     * 
     * @param string $handler_name Event import handler name
     * @param array $flow_step_config Flow step configuration including pipeline/flow IDs
     * @param array $handler_settings Handler settings
     * @param string $job_id Job ID for processed items tracking
     * @return array|null Event import entry array or null on failure
     */
    private function execute_handler(string $handler_name, array $flow_step_config, array $handler_settings, string $job_id): ?array {
        // Get handler object directly from handler system
        $handler = $this->get_handler_object($handler_name);
        if (!$handler) {
            do_action('dm_log', 'error', 'Event Import Step: Handler not found or invalid', [
                'handler' => $handler_name,
                'flow_step_config' => array_keys($flow_step_config)
            ]);
            return null;
        }

        try {
            // Get pipeline and flow IDs from flow_step_config (provided by orchestrator)
            $pipeline_id = $flow_step_config['pipeline_id'] ?? null;
            $flow_id = $flow_step_config['flow_id'] ?? null;
            
            if (!$pipeline_id) {
                do_action('dm_log', 'error', 'Event Import Step: Pipeline ID not found in step config', [
                    'flow_step_config_keys' => array_keys($flow_step_config)
                ]);
                return null;
            }

            // Execute handler - pass job_id for processed items tracking
            $result = $handler->get_event_data($pipeline_id, $handler_settings, $job_id);

            // Convert handler output to data entry for the data packet array
            $context = [
                'pipeline_id' => $pipeline_id,
                'flow_id' => $flow_id
            ];
            
            return $this->create_data_entry($result, $handler_name, $context);

        } catch (\Exception $e) {
            do_action('dm_log', 'error', 'Event Import Step: Handler execution failed', [
                'handler' => $handler_name,
                'pipeline_id' => $pipeline_id ?? 'unknown',
                'flow_id' => $flow_id ?? 'unknown',
                'exception' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create data entry from handler result
     * 
     * @param array $result Handler result
     * @param string $handler_name Handler name
     * @param array $context Pipeline context
     * @return array|null Data entry or null on failure
     */
    private function create_data_entry($result, $handler_name, $context): ?array {
        try {
            if (!is_array($result)) {
                throw new \InvalidArgumentException('Handler output must be an array');
            }
            
            // Event Import handlers return processed_items with event data
            if (isset($result['processed_items']) && is_array($result['processed_items']) && !empty($result['processed_items'])) {
                // Take first event for this data entry (handlers can return multiple events)
                $event_data = $result['processed_items'][0];
                
                // Extract event content from data
                $event_content = $event_data['data'] ?? [];
                $title = $event_content['title'] ?? '';
                
                // Create content string with event data for pipeline processing
                $body = json_encode($event_content, JSON_PRETTY_PRINT);
            } else {
                // Direct event data structure
                $title = $result['title'] ?? '';
                $body = json_encode($result, JSON_PRETTY_PRINT);
            }
            
            // Create event import data entry for the data packet array
            $import_entry = [
                'type' => 'event_import',
                'handler' => $handler_name,
                'content' => [
                    'title' => $title,
                    'body' => $body
                ],
                'metadata' => array_merge([
                    'source_type' => $handler_name,
                    'pipeline_id' => $context['pipeline_id'],
                    'flow_id' => $context['flow_id']
                ], $result['metadata'] ?? []),
                'attachments' => $result['attachments'] ?? [],
                'timestamp' => time()
            ];
            
            return $import_entry;
            
        } catch (\Exception $e) {
            do_action('dm_log', 'error', 'Event Import Step: Failed to create data entry from handler output', [
                'handler' => $handler_name,
                'pipeline_id' => $context['pipeline_id'],
                'flow_id' => $context['flow_id'],
                'result_type' => gettype($result),
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get handler object directly from the handler system.
     * 
     * Uses the object-based handler registration to get
     * instantiated handler objects directly, eliminating class discovery.
     * 
     * @param string $handler_name Handler name/key
     * @return object|null Handler object or null if not found
     */
    private function get_handler_object(string $handler_name): ?object {
        // Direct handler discovery - no redundant filtering needed
        $all_handlers = apply_filters('dm_handlers', []);
        $handler_info = $all_handlers[$handler_name] ?? null;
        
        if (!$handler_info || !isset($handler_info['class'])) {
            return null;
        }
        
        // Verify handler type is event_import
        if (($handler_info['type'] ?? '') !== 'event_import') {
            return null;
        }
        
        $class_name = $handler_info['class'];
        return class_exists($class_name) ? new $class_name() : null;
    }
}