<?php
/**
 * Context Registry — Deprecated Shim
 *
 * This file exists solely for backward compatibility. The canonical
 * implementation is now AgentModeRegistry. All method calls are
 * forwarded transparently via class_alias.
 *
 * @package DataMachine\Engine\AI
 * @since   0.63.0
 * @deprecated 0.68.0 Use AgentModeRegistry instead.
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

// Alias the old class name to the new one — existing code referencing
// ContextRegistry will resolve to AgentModeRegistry seamlessly.
class_alias( AgentModeRegistry::class, __NAMESPACE__ . '\\ContextRegistry' );

// Fire the deprecated action when the new action fires, so old extensions
// that hook into datamachine_contexts still work during the transition.
add_action( 'datamachine_agent_modes', function ( $modes ) {
	/**
	 * @deprecated 0.68.0 Use `datamachine_agent_modes` instead.
	 */
	do_action_deprecated(
		'datamachine_contexts',
		array( $modes ),
		'0.68.0',
		'datamachine_agent_modes'
	);
} );
