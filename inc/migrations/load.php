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
require_once __DIR__ . '/handler-slug-scalar.php';
require_once __DIR__ . '/split-queue-payload.php';
require_once __DIR__ . '/user-message-queue-mode.php';
require_once __DIR__ . '/webhook-auth-v2.php';
require_once __DIR__ . '/agent-config-model-shape.php';
require_once __DIR__ . '/settings-mode-models.php';
require_once __DIR__ . '/bundle-artifacts.php';

// Schema-migration runtime — defines `datamachine_run_schema_migrations()`
// and `datamachine_maybe_run_deferred_migrations()`. Hooked at
// plugins_loaded priority 5 so deploys that bump DATAMACHINE_VERSION past
// the persisted `datamachine_db_version` option auto-migrate without
// requiring an activation cycle (#1301).
require_once __DIR__ . '/runtime.php';
