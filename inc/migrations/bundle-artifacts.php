<?php
/**
 * Data Machine — installed bundle artifact tracking table migration.
 *
 * @package DataMachine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ensure the installed bundle artifact tracking table exists on upgraded installs.
 *
 * Fresh installs create the table during activation. Deploy-in-place upgrades do
 * not fire activation hooks, so the runtime migration chain also enters the same
 * idempotent dbDelta path once per version gate.
 *
 * @return void
 */
function datamachine_migrate_bundle_artifacts_table(): void {
	\DataMachine\Core\Database\BundleArtifacts\InstalledBundleArtifacts::create_table();
}
