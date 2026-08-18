<?php
/**
 * Explicit PHPUnit fixture for the legacy Jobs namespace reference.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Jobs;

if ( ! class_exists( __NAMESPACE__ . '\\EngineData', false ) ) {
	class_alias( \DataMachine\Core\EngineData::class, __NAMESPACE__ . '\\EngineData' );
}
