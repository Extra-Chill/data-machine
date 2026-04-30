<?php
/**
 * Agents API bootstrap.
 *
 * In-repo WordPress-shaped agent substrate bundled by Data Machine while the
 * extraction boundary is still being proven in place.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'AGENTS_API_LOADED' ) ) {
	return;
}

define( 'AGENTS_API_LOADED', true );
define( 'AGENTS_API_PATH', __DIR__ . '/' );

require_once AGENTS_API_PATH . 'inc/class-wp-agent.php';
require_once AGENTS_API_PATH . 'inc/class-wp-agents-registry.php';
require_once AGENTS_API_PATH . 'inc/register-agents.php';
