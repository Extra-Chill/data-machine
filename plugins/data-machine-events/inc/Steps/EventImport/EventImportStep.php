<?php
/**
 * Event import step for Data Machine pipeline with handler discovery
 *
 * @package DataMachineEvents\Steps\EventImport
 */

namespace DataMachineEvents\Steps\EventImport;

use DataMachine\Core\Steps\Step;
use DataMachine\Core\Steps\StepTypeRegistrationTrait;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Event import step for Data Machine pipeline with handler discovery
 */
class EventImportStep extends Step {

    use StepTypeRegistrationTrait;

    public function __construct() {
        parent::__construct('event_import');

        self::registerStepType(
            slug: 'event_import',
            label: 'Event Import',
            description: 'Import events from venues and ticketing platforms',
            class: self::class,
            position: 25,
            usesHandler: true,
            hasPipelineConfig: false
        );
    }

    /**
     * Execute event import step.
     *
     * @return array Updated data packet array with event data added
     */
    protected function executeStep(): array {
        $handler_slug = $this->getHandlerSlug();
        
        // Get handler object from registry
        $all_handlers = apply_filters('datamachine_handlers', [], 'event_import');
        $handler_info = $all_handlers[$handler_slug] ?? null;
        
        if (!$handler_info || empty($handler_info['class'])) {
            $this->logConfigurationError('Handler not found in registry', [
                'handler_slug' => $handler_slug
            ]);
            return $this->dataPackets;
        }
        
        // Instantiate handler
        $class_name = $handler_info['class'];
        if (!class_exists($class_name)) {
            $this->logConfigurationError('Handler class not found', [
                'handler_slug' => $handler_slug,
                'class_name' => $class_name
            ]);
            return $this->dataPackets;
        }
        
        $handler = new $class_name();
        
        // Check if handler extends FetchHandler (New Architecture)
        if ($handler instanceof FetchHandler) {
            $pipeline_id = (int) ($this->flow_step_config['pipeline_id'] ?? 0);
            
            // Prepare config with required IDs
            $handler_config = array_merge(
                $this->getHandlerConfig(),
                [
                    'flow_step_id' => $this->flow_step_id,
                    'flow_id' => $this->flow_step_config['flow_id'] ?? 0,
                    'pipeline_id' => $pipeline_id
                ]
            );
            
            $this->log('debug', 'Event Import Step: Executing FetchHandler', [
                'handler_class' => $class_name,
                'pipeline_id' => $pipeline_id
            ]);
            
            $result = $handler->get_fetch_data($pipeline_id, $handler_config, (string)$this->job_id);
            
            // Handle both processed_items format and direct DataPacket arrays
            if (isset($result['processed_items']) && is_array($result['processed_items'])) {
                // Process items from processed_items format
                foreach ($result['processed_items'] as $item) {
                    if ($item instanceof \DataMachine\Core\DataPacket) {
                        $this->dataPackets = $item->addTo($this->dataPackets);
                    }
                }
                return $this->dataPackets;
            } elseif (is_array($result)) {
                // Process direct DataPacket arrays (new standardized format)
                foreach ($result as $item) {
                    if ($item instanceof \DataMachine\Core\DataPacket) {
                        $this->dataPackets = $item->addTo($this->dataPackets);
                    }
                }
                return $this->dataPackets;
            }

            return $this->dataPackets;
        }
        
        // Legacy Handler Support (execute method)
        if (method_exists($handler, 'execute')) {
            $this->log('debug', 'Event Import Step: Executing legacy handler', [
                'handler_class' => $class_name
            ]);
            
            // Reconstruct legacy payload
            $legacy_payload = [
                'job_id' => $this->job_id,
                'flow_step_id' => $this->flow_step_id,
                'data' => $this->dataPackets,
                'flow_step_config' => $this->flow_step_config,
                'engine' => $this->engine
            ];
            
            $result = $handler->execute($legacy_payload);
            
            return is_array($result) ? $result : $this->dataPackets;
        }
        
        $this->logConfigurationError('Handler does not implement required interface', [
            'handler_class' => $class_name
        ]);
        
        return $this->dataPackets;
    }
}
