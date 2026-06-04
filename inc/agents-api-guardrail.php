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
 * class set for the request. Any condition that leaves `AGENTS_API_LOADED` defined while
 * the per-class `require_once` list did NOT fully run for the request produces a bare
 * "Class ... not found" fatal the first time a `WP_Agent_*` global is referenced (e.g.
 * `WP_Agent_Package_Artifact_Hasher` during `agent export`). Known triggers:
 *
 *   - Version skew: two copies of the substrate coexist (data-machine's vendored copy AND
 *     a separately activated standalone `agents-api` plugin) and the OLDER copy wins the
 *     load race, early-returning the newer copy before it requires its newer class files.
 *   - Partial / aborted bootstrap: the constant is defined near the top of the bootstrap,
 *     so if the require block is interrupted the constant survives but the classes do not.
 *   - Any future load-order regression that references a `WP_Agent_*` global before the
 *     bootstrap require block has run to completion.
 *
 * The durable fix belongs upstream in Automattic/agents-api (idempotent per-class loading
 * independent of the bootstrap constant). This file is data-machine's guardrail: it gates
 * on a cheap canary `class_exists()` check (no file I/O on the healthy path), and when the
 * canary is missing it tops up the substrate class set from data-machine's OWN bundled
 * copy via idempotent `require_once`. This recovers from any cause above, not just the
 * skew sub-case. If a class is STILL missing after the top-up (e.g. a bundled file is
 * absent), it fails LOUD and CLEAR instead of degrading into a cryptic fatal.
 *
 * @package DataMachine
 */

defined( 'WPINC' ) || exit;

/**
 * A representative class from the bundled agents-api copy used as the canary for an
 * incomplete substrate load. If this class is missing after bootstrap, the substrate
 * class set did not fully load for the request and the top-up is required.
 */
if ( ! defined( 'DATAMACHINE_AGENTS_API_CANARY_CLASS' ) ) {
	define( 'DATAMACHINE_AGENTS_API_CANARY_CLASS', 'WP_Agent_Package_Artifact_Hasher' );
}

/**
 * Detect an incomplete agents-api substrate load.
 *
 * The substrate is incomplete when the bootstrap constant is defined (some copy of the
 * substrate started loading) but the canary class is absent — meaning the per-class
 * require block did not run to completion for this request (version skew, partial
 * bootstrap, or a load-order regression).
 *
 * @return bool True when the substrate class set is incomplete.
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

	$source = file_get_contents( $bootstrap_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled PHP file from disk at bootstrap, before the WordPress filesystem abstraction is available.
	if ( ! is_string( $source ) || '' === $source ) {
		return array();
	}

	// Match each require_once AGENTS_API_PATH . 'src/.../file.php' bootstrap line.
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
 * Ensure the bundled agents-api substrate class set is fully loaded.
 *
 * Re-runs each `require_once AGENTS_API_PATH . 'src/...php'` target parsed from the
 * bundled bootstrap against the bundled agents-api tree. The target list includes both
 * class files and the substrate's `register-*.php` files (which call `add_action()` at
 * file scope). Because every include uses `require_once`, any target already loaded by
 * the original bootstrap is skipped (a no-op) — so re-running this list does NOT
 * double-register hooks. Only targets that never loaded for this request are executed,
 * which restores both the missing classes AND any registrations the incomplete bootstrap
 * skipped. This makes data-machine's substrate-dependent surface (export, packages,
 * bundle hashing, etc.) work whenever the bootstrap constant is defined but the class set
 * is incomplete — regardless of cause (version skew, partial bootstrap, or load-order
 * regression).
 *
 * The trailing `add_action()` registrations in the bootstrap body (outside the parsed
 * `require_once src/...` lines, e.g. the `init` hooks) are not matched and not re-run.
 *
 * @param string $bundled_dir Absolute path to data-machine's bundled agents-api dir
 *                            (trailing slash optional).
 * @return bool True when the canary class is available after the top-up.
 */
function datamachine_agents_api_self_heal( string $bundled_dir ): bool {
	$bundled_dir    = rtrim( $bundled_dir, '/\\' ) . '/';
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
 * Build the operator-facing message describing the unrecoverable substrate-load failure.
 *
 * Reached only when the bundled top-up could not restore the canary class — typically a
 * missing or unreadable bundled file, or a separately activated older standalone
 * `agents-api` plugin whose copy lacks the class entirely.
 *
 * @return string Plain-text guidance message.
 */
function datamachine_agents_api_skew_message(): string {
	$bundled_version = defined( 'DATAMACHINE_VERSION' ) ? (string) constant( 'DATAMACHINE_VERSION' ) : 'unknown';

	return sprintf(
		/* translators: 1: Data Machine plugin version, 2: canary class name. */
		'Data Machine: the agents-api substrate failed to load completely (Data Machine v%1$s). Required substrate classes such as "%2$s" are missing even after a bundled top-up, so exports/packages will fatal. This usually means an older standalone "agents-api" plugin loaded first and lacks the class, or Data Machine\'s bundled agents-api files are missing. Deactivate or update any standalone agents-api plugin, or reinstall Data Machine so its bundled vendor copy is intact.',
		$bundled_version,
		DATAMACHINE_AGENTS_API_CANARY_CLASS
	);
}

/**
 * Run the agents-api substrate-load guardrail.
 *
 * Call after Composer's autoloader and the agents-api require block have run. The
 * substrate's bootstrap loads its class files only inside an `AGENTS_API_LOADED`-guarded
 * block that early-returns, so any condition that defines the constant without completing
 * the require block leaves `WP_Agent_*` classes missing and produces a bare "Class not
 * found" fatal the first time one is referenced (e.g. during `agent export`).
 *
 * The guardrail gates on a cheap canary `class_exists()` check: when the substrate loaded
 * completely the canary is present and the guardrail returns immediately, adding no file
 * I/O on the healthy path. When the canary is missing (the bootstrap constant is defined
 * but the class set is incomplete), it tops up the missing classes from data-machine's own
 * bundled copy via idempotent `require_once`. If the canary is STILL missing after the
 * top-up (e.g. a bundled file is absent), it surfaces a clear admin notice and (under
 * WP-CLI) a hard error so the failure is loud and actionable rather than a cryptic fatal.
 *
 * @param string $bundled_dir Absolute path to data-machine's bundled agents-api dir.
 * @return void
 */
function datamachine_agents_api_run_guardrail( string $bundled_dir ): void {
	// Common case: the substrate loaded completely, so the canary class is present.
	// This is a single class_exists() check — no file I/O — so the guardrail adds no
	// measurable per-request cost on the overwhelmingly common healthy path.
	if ( ! datamachine_agents_api_skew_detected() ) {
		return;
	}

	// Incomplete load detected. Idempotently top up the bundled class files: already-loaded
	// files are skipped via require_once, and the missing classes are restored. This closes
	// the "constant defined, classes missing" fatal class regardless of cause.
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
