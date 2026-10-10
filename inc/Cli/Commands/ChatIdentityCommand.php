<?php
/**
 * WP-CLI Chat Identity Command
 *
 * Thin adapter over the chat identity abilities: link, unlink and list
 * platform-scoped chat actor IDs stored on WordPress users.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\AbilityRunner;
use DataMachine\Cli\BaseCommand;
use DataMachine\Cli\UserResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage chat identity links on WordPress users.
 *
 * ## EXAMPLES
 *
 *     # Link a chat actor on a platform to a user
 *     wp datamachine chat-identity link <platform> <actor_id> --user=<user>
 *
 *     # Remove a user's link for a platform
 *     wp datamachine chat-identity unlink <platform> --user=<user>
 *
 *     # List links
 *     wp datamachine chat-identity list [--user=<user>] [--platform=<platform>]
 */
class ChatIdentityCommand extends BaseCommand {

	/**
	 * Link a platform actor ID to a WordPress user.
	 *
	 * ## OPTIONS
	 *
	 * <platform>
	 * : Platform name (data; any slug).
	 *
	 * <actor_id>
	 * : External actor ID on that platform.
	 *
	 * --user=<user>
	 * : WordPress user ID, login or email.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function link( array $args, array $assoc_args ): void {
		$user_id = UserResolver::resolve( $assoc_args );
		if ( $user_id <= 0 || empty( $args[0] ) || ! isset( $args[1] ) ) {
			WP_CLI::error( 'Usage: wp datamachine chat-identity link <platform> <actor_id> --user=<user>' );
			return;
		}

		$result = AbilityRunner::execute(
			'datamachine/link-chat-identity',
			array(
				'user_id'  => $user_id,
				'platform' => (string) $args[0],
				'actor_id' => (string) $args[1],
			)
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to link chat identity.' );
			return;
		}

		WP_CLI::success( sprintf( 'Linked %s actor %s to user %d.', $result['platform'], $result['actor_id'], $result['user_id'] ) );
	}

	/**
	 * Remove a user's chat identity link for a platform.
	 *
	 * ## OPTIONS
	 *
	 * <platform>
	 * : Platform name.
	 *
	 * --user=<user>
	 * : WordPress user ID, login or email.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function unlink( array $args, array $assoc_args ): void {
		$user_id = UserResolver::resolve( $assoc_args );
		if ( $user_id <= 0 || empty( $args[0] ) ) {
			WP_CLI::error( 'Usage: wp datamachine chat-identity unlink <platform> --user=<user>' );
			return;
		}

		$result = AbilityRunner::execute(
			'datamachine/unlink-chat-identity',
			array(
				'user_id'  => $user_id,
				'platform' => (string) $args[0],
			)
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to unlink chat identity.' );
			return;
		}

		if ( empty( $result['removed'] ) ) {
			WP_CLI::warning( 'No link existed for that user and platform.' );
			return;
		}

		WP_CLI::success( sprintf( 'Unlinked %s from user %d.', (string) $args[0], $user_id ) );
	}

	/**
	 * List chat identity links.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<user>]
	 * : Only links for this WordPress user (ID, login or email).
	 *
	 * [--platform=<platform>]
	 * : Only links for this platform.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$result = AbilityRunner::execute(
			'datamachine/list-chat-identities',
			array(
				'user_id'  => UserResolver::resolve( $assoc_args ),
				'platform' => (string) ( $assoc_args['platform'] ?? '' ),
			)
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to list chat identities.' );
			return;
		}

		if ( empty( $result['identities'] ) ) {
			WP_CLI::log( 'No chat identities linked.' );
			return;
		}

		$this->format_items( $result['identities'], array( 'user_id', 'platform', 'actor_id' ), $assoc_args );
	}
}
