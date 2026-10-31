<?php
/**
 * Generic Server-Sent Events response helpers.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Open an SSE response: send headers and disable output buffering/compression.
 *
 * @return void
 */
function agents_api_open_sse_response(): void {
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );
	}

	while ( ob_get_level() > 0 ) {
		ob_end_flush();
	}
}

/**
 * Write one JSON SSE `data:` frame and flush it to the client.
 *
 * @param array<string,mixed> $frame JSON-serializable frame.
 * @return void
 */
function agents_api_emit_sse_json_frame( array $frame ): void {
	echo 'data: ' . wp_json_encode( $frame ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE payload is JSON, not HTML.
	flush();
}
