<?php
/**
 * Base class for event import handlers
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers
 */

namespace DataMachineEvents\Steps\EventImport\Handlers;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;

if (!defined('ABSPATH')) {
    exit;
}

abstract class EventImportHandler extends FetchHandler {
    
    public function __construct(string $handler_type) {
        parent::__construct($handler_type);
    }
    
    // Add any event-specific helper methods here if needed
}
