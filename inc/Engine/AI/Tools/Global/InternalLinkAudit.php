<?php
/**
 * Internal Link Audit AI Tool
 *
 * Exposes internal link audit capabilities to AI agents.
 * Delegates to InternalLinkingAbilities for execution.
 *
 * Available actions:
 * - audit:   Scan content and build link graph (cached 24hr).
 * - orphans: Get orphaned posts from cached graph.
 * - broken:  HTTP HEAD checks for broken internal links.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 * @since 0.32.0
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\Tools\BaseTool;

class InternalLinkAudit extends BaseTool {

	public function __construct() {
		$this->registerGlobalTool( 'internal_link_audit', array( $this, 'getToolDefinition' ) );
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? 'audit';

		$ability_map = array(
			'audit'   => 'datamachine/audit-internal-links',
			'orphans' => 'datamachine/get-orphaned-posts',
			'broken'  => 'datamachine/check-broken-links',
		);

		if ( ! isset( $ability_map[ $action ] ) ) {
			return $this->buildErrorResponse(
				sprintf( 'Invalid action "%s". Valid: audit, orphans, broken.', $action ),
				'internal_link_audit'
			);
		}

		$ability_slug = $ability_map[ $action ];
		$ability      = wp_get_ability( $ability_slug );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				sprintf( 'Ability "%s" not registered. Ensure WordPress 6.9+ and InternalLinkingAbilities is loaded.', $ability_slug ),
				'internal_link_audit'
			);
		}

		// Build input from parameters (strip action).
		$input = array_diff_key( $parameters, array( 'action' => true ) );

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'internal_link_audit'
			);
		}

		if ( isset( $result['error'] ) ) {
			return $this->buildErrorResponse(
				$result['error'],
				'internal_link_audit'
			);
		}

		// Strip internal keys (prefixed with _) from AI response.
		$clean = array_filter(
			$result,
			fn( $key ) => 0 !== strpos( $key, '_' ),
			ARRAY_FILTER_USE_KEY
		);

		return array(
			'success'   => true,
			'data'      => $clean,
			'tool_name' => 'internal_link_audit',
		);
	}

	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Audit internal links on this WordPress site. Three actions: "audit" scans post content to build a link graph (cached 24hr), "orphans" lists posts with zero inbound links from the cached graph, "broken" performs HTTP HEAD checks on cached links to find broken URLs (expensive). Always run "audit" first, then use "orphans" or "broken" for specific checks.',
			'requires_config' => false,
			'parameters'      => array(
				'action'    => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Action to perform: "audit" (scan + cache link graph), "orphans" (list orphaned posts), or "broken" (HTTP check for broken links).',
					'enum'        => array( 'audit', 'orphans', 'broken' ),
				),
				'post_type' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Post type to audit (default: "post").',
				),
				'category'  => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Category slug to limit audit scope (audit action only).',
				),
				'force'     => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Force rebuild even if cached graph exists (audit action only).',
				),
				'limit'     => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum results to return. For orphans: max posts (default 50). For broken: max URLs to check (default 200).',
				),
			),
		);
	}

	public static function is_configured(): bool {
		return true;
	}

	public function check_configuration( $configured, $tool_id ) {
		if ( 'internal_link_audit' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}
}
