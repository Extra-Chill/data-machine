<?php
/**
 * CLI User Resolver
 *
 * Resolves a --user flag value to a WordPress user ID.
 * Accepts user ID, login, or email. Returns 0 when omitted
 * (legacy/shared agent directory).
 *
 * @package DataMachine\Cli
 * @since 0.37.0
 */

namespace DataMachine\Cli;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

class UserResolver {

	/**
	 * Resolve a --user flag to a WordPress user ID.
	 *
	 * Returns 0 when no --user flag is provided, which means "no user filter"
	 * (show all data regardless of owner). This is the correct default for CLI
	 * commands where admins expect to see all pipelines, flows, and jobs —
	 * including pre-multi-agent data with user_id=0.
	 *
	 * @param array $assoc_args Command arguments (checks for 'user' key).
	 * @return int WordPress user ID, or 0 if not specified.
	 */
	public static function resolve( array $assoc_args ): int {
		$user_value = $assoc_args['user'] ?? null;

		// No --user flag: return 0 (unscoped). CLI callers pass this as null
		// to ability queries, which then return all data regardless of owner.
		if ( null === $user_value || '' === $user_value ) {
			return 0;
		}

		// Numeric: treat as user ID.
		if ( is_numeric( $user_value ) ) {
			$user = get_user_by( 'id', (int) $user_value );
			if ( ! $user ) {
				WP_CLI::error( sprintf( 'User ID %d not found.', (int) $user_value ) );
			}
			return $user->ID;
		}

		// Email.
		if ( is_email( $user_value ) ) {
			$user = get_user_by( 'email', $user_value );
			if ( ! $user ) {
				WP_CLI::error( sprintf( 'User with email "%s" not found.', $user_value ) );
			}
			return $user->ID;
		}

		// Login.
		$user = get_user_by( 'login', $user_value );
		if ( ! $user ) {
			WP_CLI::error( sprintf( 'User with login "%s" not found.', $user_value ) );
		}
		return $user->ID;
	}

	/**
	 * Resolve the user a command is acting for, honoring WP-CLI's global --user.
	 *
	 * WP-CLI consumes `--user=<id|login|email>` as a global parameter and sets
	 * the current user before the command runs, so the flag never reaches
	 * `$assoc_args`. Commands that address per-user state (USER.md, principal
	 * memory) must therefore fall back to `get_current_user_id()`. An explicit
	 * `user` key in `$assoc_args` still wins.
	 *
	 * @since next
	 * @param array $assoc_args Command arguments.
	 * @return int WordPress user ID, or 0 when no user was requested.
	 */
	public static function resolveActingUser( array $assoc_args ): int {
		$explicit = self::resolve( $assoc_args );
		if ( $explicit > 0 ) {
			return $explicit;
		}

		if ( function_exists( 'get_current_user_id' ) ) {
			return max( 0, (int) get_current_user_id() );
		}

		return 0;
	}
}
