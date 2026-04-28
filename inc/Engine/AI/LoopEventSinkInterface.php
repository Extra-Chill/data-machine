<?php
/**
 * Transport-neutral AI loop event sink contract.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Receives structured AI loop lifecycle events.
 */
interface LoopEventSinkInterface {

	/**
	 * Emit a loop event.
	 *
	 * Event names are transport-neutral. Renderers can map them to logs, SSE,
	 * WebSockets, CLI output, Discord updates, transcripts, or other consumers.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Structured event payload.
	 */
	public function emit( string $event, array $payload = array() ): void;
}
