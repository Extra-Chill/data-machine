<?php
/**
 * Structured-output capability failure.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/** Raised when a selected adapter cannot preserve the requested output contract. */
class WP_Agent_Structured_Output_Capability_Exception extends \RuntimeException {}
