<?php
/**
 * Agents API dual-load version-skew guardrail.
 *
 * The agents-api substrate guards its entire class-loading block with a single
 * coarse `AGENTS_API_LOADED` constant:
 *
 *     if ( defined( 'AGENTS_API_LOADED' ) ) { return; }
 *     define( 'AGENTS_API_LOADED', true );
 *     // ... require_once every class file ...
 *
 * That guard is correct for preventing a double-define, but it is *all-or-nothing
 * per-constant*: the first copy of the substrate to define the constant freezes the
 * class set for the request. When two different versions of the substrate coexist on
 * one network — data-machine's bundled (vendored composer) copy AND a separately
 * activated standalone `agents-api` plugin — and the OLDER copy wins the load race, the
 * newer bundled copy early-returns before requiring its newer class files. Any code
 * path that touches a class only present in the newer bundled set then fatals with a
 * bare "Class ... not found" (e.g. `WP_Agent_Package_Artifact_Hasher` during
 * `agent export`).
 *
 * The durable fix belongs upstream in Automattic/agents-api (idempotent per-class
 * loading independent of the bootstrap constant). This file is data-machine's
 * guardrail: when the skew is detected, top up the missing classes from data-machine's
 * OWN bundled copy where it is safe, and otherwise fail LOUD and CLEAR instead of
 * degrading into a cryptic fatal.
 *
 * @package DataMachine
 */

defined( 'WPINC' ) || exit;

/**
 * A representative class from the newer bundled agents-api copy used as the canary for
 * the version-skew collision. If `AGENTS_API_LOADED` is defined but this class is
 * missing, an older copy of the substrate won the load race.
 */
if ( ! defined( 'DATAMACHINE_AGENTS_API_CANARY_CLASS' ) ) {
	define( 'DATAMACHINE_AGENTS_API_CANARY_CLASS', 'WP_Agent_Package_Artifact_Hasher' );
}

/**
 * Detect the agents-api version-skew collision.
 *
 * The collision is present when the bootstrap constant is defined (some copy of the
 * substrate loaded) but the canary class from the newer bundled copy is absent (an
 * older copy froze the class set).
 *
 * @return bool True when the version-skew collision is present.
 */
function datamachine_agents_api_skew_detected(): bool {
	return defined( 'AGENTS_API_LOADED' ) && ! class_exists( DATAMACHINE_AGENTS_API_CANARY_CLASS );
}

/**
 * Extract the class-file require targets from a bundled agents-api bootstrap file.
 *
 * The bundled bootstrap requires each class file as
 * `require_once AGENTS_API_PATH . 'src/.../file.php';`. We parse those literal relative
 * paths rather than hardcoding a list so the guardrail self-updates whenever the bundled
 * copy changes. Only `src/` paths are considered (the class/registration files); the
 * side-effect `add_action` registrations at the foot of the bootstrap are deliberately
 * not re-run here — the older copy already registered its hooks, and the top-up only
 * needs the missing class declarations.
 *
 * @param string $bootstrap_path Absolute path to the bundled agents-api.php bootstrap.
 * @return string[] Relative paths (e.g. `src/Packages/class-...php`) in bootstrap order.
 */
function datamachine_agents_api_bundled_require_targets( string $bootstrap_path ): array {
	if ( ! is_readable( $bootstrap_path ) ) {
		return array();
	}

	$source = file_get_contents( $bootstrap_path );
	if ( ! is_string( $source ) || '' === $source ) {
		return array();
	}

	// Match: require_once AGENTS_API_PATH . 'src/.../file.php';
	if ( ! preg_match_all(
		"/require_once\s+AGENTS_API_PATH\s*\.\s*'(src\/[^']+\.php)'/",
		$source,
		$matches
	) ) {
		return array();
	}

	return array_values( array_unique( $matches[1] ) );
}

/**
 * Attempt to self-heal the agents-api version skew from data-machine's bundled copy.
 *
 * Re-runs each class-file require target from the bundled bootstrap against the bundled
 * agents-api tree. Every include uses `require_once`, so files already loaded by the
 * older copy are skipped and only the missing newer classes are topped up. This makes
 * data-machine's newer surface (export, packages, etc.) work even when an older
 * standalone copy won the load race.
 *
 * @param string $bundled_dir Absolute path to data-machine's bundled agents-api dir
 *                            (trailing slash optional).
 * @return bool True when the canary class is available after the top-up.
 */
function datamachine_agents_api_self_heal( string $bundled_dir ): bool {
	$bundled_dir   = rtrim( $bundled_dir, '/\\' ) . '/';
	$bootstrap_path = $bundled_dir . 'agents-api.php';

	$targets = datamachine_agents_api_bundled_require_targets( $bootstrap_path );
	if ( empty( $targets ) ) {
		return class_exists( DATAMACHINE_AGENTS_API_CANARY_CLASS );
	}

	foreach ( $targets as $relative_path ) {
		$file = $bundled_dir . $relative_path;
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	return class_exists( DATAMACHINE_AGENTS_API_CANARY_CLASS );
}

/**
 * Build the operator-facing message describing the unresolved version skew.
 *
 * @return string Plain-text guidance message.
 */
function datamachine_agents_api_skew_message(): string {
	$bundled_version = defined( 'DATAMACHINE_VERSION' ) ? (string) constant( 'DATAMACHINE_VERSION' ) : 'unknown';

	return sprintf(
		/* translators: %s: Data Machine plugin version. */
		'Data Machine: agents-api version skew detected. An older standalone "agents-api" plugin loaded before Data Machine\'s bundled copy (Data Machine v%s), so newer substrate classes such as "%s" are missing and exports/packages will fatal. Deactivate or remove the standalone agents-api plugin, or update it to a version >= the copy bundled with Data Machine.',
		$bundled_version,
		DATAMACHINE_AGENTS_API_CANARY_CLASS
	);
}

/**
 * Run the agents-api dual-load guardrail.
 *
 * Call after Composer's autoloader and the agents-api require block have run. When the
 * version skew is detected, attempt a safe self-heal from the bundled copy; if that
 * still leaves the substrate incomplete, surface a clear admin notice and (under WP-CLI)
 * a hard CLI error so the failure mode is loud and actionable rather than a bare fatal.
 *
 * @param string $bundled_dir Absolute path to data-machine's bundled agents-api dir.
 * @return void
 */
function datamachine_agents_api_run_guardrail( string $bundled_dir ): void {
	if ( ! datamachine_agents_api_skew_detected() ) {
		return;
	}

	if ( datamachine_agents_api_self_heal( $bundled_dir ) ) {
		return;
	}

	$message = datamachine_agents_api_skew_message();

	add_action(
		'admin_notices',
		static function () use ( $message ): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	);

	if ( defined( 'WP_CLI' ) && (bool) constant( 'WP_CLI' ) && class_exists( '\WP_CLI' ) ) {
		\WP_CLI::error( $message );
	}
}
