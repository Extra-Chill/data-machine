<?php
/**
 * WP_Agent_Autonomous_Capability_Policy derivation helper.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Autonomous_Capability_Policy' ) ) {
	/**
	 * Derives a safe default capability ceiling for autonomous executions.
	 *
	 * An autonomous execution is one where no human is directly authorizing
	 * actions in real time. Agents API detects autonomy from the execution
	 * principal's own fields (authentication source) rather than from any
	 * consumer orchestration vocabulary, and applies a substrate-defined,
	 * filterable default ceiling that excludes content-mutating WordPress
	 * capabilities unless the host made an explicit grant.
	 */
	final class WP_Agent_Autonomous_Capability_Policy {

		/**
		 * Default content-mutating capabilities excluded from autonomous
		 * executions when no explicit host grant is present.
		 *
		 * The set is intentionally conservative: it covers post and page
		 * authoring at every lifecycle stage, taxonomy and options
		 * administration, media uploads, unfiltered markup, and plugin, theme,
		 * and user management. Hosts and Core can adjust it through the
		 * `agents_api_autonomous_denied_capabilities` filter.
		 *
		 * @var string[]
		 */
		public const DEFAULT_DENIED_CAPABILITIES = array(
			'edit_posts',
			'edit_others_posts',
			'edit_published_posts',
			'publish_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_published_posts',
			'edit_pages',
			'edit_others_pages',
			'edit_published_pages',
			'publish_pages',
			'delete_pages',
			'delete_others_pages',
			'delete_published_pages',
			'manage_categories',
			'manage_options',
			'upload_files',
			'unfiltered_html',
			'edit_themes',
			'update_themes',
			'edit_plugins',
			'update_plugins',
			'install_plugins',
			'activate_plugins',
			'create_users',
			'delete_users',
			'promote_users',
		);

		/**
		 * Default authentication sources treated as autonomous.
		 *
		 * @var string[]
		 */
		public const DEFAULT_AUTONOMOUS_AUTH_SOURCES = array(
			AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_SYSTEM,
			AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_RUNTIME,
			AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_AGENT_TOKEN,
		);

		/**
		 * Authentication sources that Agents API treats as autonomous by default.
		 *
		 * Hosts and Core can adjust this set through the
		 * `agents_api_autonomous_auth_sources` filter.
		 *
		 * @return string[]
		 */
		public static function autonomous_auth_sources(): array {
			$sources = self::DEFAULT_AUTONOMOUS_AUTH_SOURCES;

			if ( function_exists( 'apply_filters' ) ) {
				$sources = apply_filters( 'agents_api_autonomous_auth_sources', $sources );
			}

			return self::string_list( $sources );
		}

		/**
		 * Whether an execution principal represents an autonomous execution.
		 *
		 * Autonomous means the run is driven by automation rather than a live
		 * human authorizing each action in real time. The default signal is the
		 * principal's authentication source; hosts can override the per-principal
		 * decision through the `agents_api_principal_is_autonomous` filter.
		 *
		 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal Execution principal.
		 * @return bool
		 */
		public static function is_autonomous( AgentsAPI\AI\WP_Agent_Execution_Principal $principal ): bool {
			$sources    = self::autonomous_auth_sources();
			$autonomous = in_array( $principal->auth_source, $sources, true );

			if ( function_exists( 'apply_filters' ) ) {
				$autonomous = (bool) apply_filters( 'agents_api_principal_is_autonomous', $autonomous, $principal );
			}

			return $autonomous;
		}

		/**
		 * Capabilities excluded from autonomous executions by default.
		 *
		 * Hosts and Core can adjust the set through the
		 * `agents_api_autonomous_denied_capabilities` filter.
		 *
		 * @return string[]
		 */
		public static function denied_capabilities(): array {
			$denied = self::DEFAULT_DENIED_CAPABILITIES;

			if ( function_exists( 'apply_filters' ) ) {
				$denied = apply_filters( 'agents_api_autonomous_denied_capabilities', $denied );
			}

			return self::string_list( $denied );
		}

		/**
		 * Build the safe default ceiling for an autonomous execution.
		 *
		 * The ceiling leaves the allow-list unrestricted (null) and records the
		 * content-mutating capabilities on the deny-list, so the ceiling blocks
		 * those capabilities while permitting everything else the acting
		 * identity can otherwise use.
		 *
		 * @param int $user_id WordPress user ID bound to the ceiling. 0 for non-user principals.
		 * @return WP_Agent_Capability_Ceiling
		 */
		public static function safe_default_ceiling( int $user_id ): WP_Agent_Capability_Ceiling {
			return new WP_Agent_Capability_Ceiling(
				$user_id,
				null,
				array(
					'autonomous_safe_default' => true,
					'source'                  => 'wp_agent_autonomous_capability_policy',
				),
				self::denied_capabilities()
			);
		}

		/**
		 * Resolve the effective capability ceiling for a principal.
		 *
		 * Composition rules:
		 *  - An explicit host grant (a ceiling carrying any capability shaping,
		 *    whether an allow-list or a deny-list) is always respected and
		 *    returned unchanged. The safe default never widens, narrows, or
		 *    overrides a deliberate host decision.
		 *  - An autonomous principal without an explicit grant receives the
		 *    safe default ceiling, which excludes content-mutating capabilities.
		 *  - Any other principal is returned with its ceiling untouched (null or
		 *    unrestricted as the host supplied).
		 *
		 * @param AgentsAPI\AI\WP_Agent_Execution_Principal $principal Execution principal.
		 * @return WP_Agent_Capability_Ceiling|null
		 */
		public static function resolve_ceiling( AgentsAPI\AI\WP_Agent_Execution_Principal $principal ): ?WP_Agent_Capability_Ceiling {
			$existing = $principal->capability_ceiling;

			if ( null !== $existing && ( $existing->has_capability_restrictions() || $existing->has_denied_capabilities() ) ) {
				return $existing;
			}

			if ( ! self::is_autonomous( $principal ) ) {
				return $existing;
			}

			return self::safe_default_ceiling( $principal->acting_user_id );
		}

		/**
		 * Normalize a raw list into unique, trimmed, non-empty capability strings.
		 *
		 * @param mixed $value Raw capability list.
		 * @return string[]
		 */
		private static function string_list( $value ): array {
			if ( ! is_array( $value ) ) {
				return array();
			}

			$strings = array();
			foreach ( $value as $item ) {
				if ( ! is_scalar( $item ) ) {
					continue;
				}

				$trimmed = trim( (string) $item );
				if ( '' === $trimmed ) {
					continue;
				}

				$strings[] = $trimmed;
			}

			return array_values( array_unique( $strings ) );
		}
	}
}
