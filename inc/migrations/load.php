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

require_once __DIR__ . '/activation.php';
require_once __DIR__ . '/handler-keys.php';
require_once __DIR__ . '/layered-architecture.php';
require_once __DIR__ . '/agent-ping.php';
require_once __DIR__ . '/scaffolding.php';
require_once __DIR__ . '/site-md.php';
require_once __DIR__ . '/backfill.php';
require_once __DIR__ . '/network-scope.php';
require_once __DIR__ . '/flows.php';
require_once __DIR__ . '/post-pipeline-meta.php';
require_once __DIR__ . '/update-to-upsert.php';
require_once __DIR__ . '/strip-pipeline-step-provider-model.php';
require_once __DIR__ . '/ai-enabled-tools.php';
