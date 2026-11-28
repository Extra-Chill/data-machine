<?php
/**
 * Agent bundle validation exception.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Raised when a bundle document does not match the supported schema.
 */
class BundleValidationException extends \InvalidArgumentException {}
