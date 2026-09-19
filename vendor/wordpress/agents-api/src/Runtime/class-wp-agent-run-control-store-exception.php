<?php
/**
 * Temporary run-control storage failure.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies storage failures that callers may safely retry.
 */
class WP_Agent_Run_Control_Store_Exception extends \RuntimeException {}
