<?php
/**
 * No-op AI loop event sink.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Default sink used when a caller does not request event emission.
 */
class NullLoopEventSink implements LoopEventSinkInterface {

	/**
	 * Ignore emitted loop events.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Structured event payload.
	 */
	public function emit( string $event, array $payload = array() ): void {
		// Intentionally empty.
	}
}
