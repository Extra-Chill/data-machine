<?php
/**
 * Data Machine — Migrations loader.
 *
 * Requires all migration, scaffolding, and activation helper files.
 * Drop-in replacement for the former inc/migrations.php monolith.
 *
 * @package DataMachine
 * @since 0.60.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/scaffolding.php';
require_once __DIR__ . '/site-md.php';
require_once __DIR__ . '/agents-md.php';
require_once __DIR__ . '/flows.php';
require_once __DIR__ . '/bundle-artifacts.php';
require_once __DIR__ . '/run-metadata.php';
require_once __DIR__ . '/processed-item-claims.php';
require_once __DIR__ . '/pending-actions.php';
require_once __DIR__ . '/chat-sessions-network.php';

// Current schema runtime — creates current deploy-time tables/columns without
// carrying pre-1.0 data-shape migrations forward indefinitely.
require_once __DIR__ . '/runtime.php';
