<?php
/**
 * Internal Link Audit AI Tool
 *
 * Exposes internal link audit capabilities to AI agents.
 * Delegates to InternalLinkingAbilities for execution.
 *
 * Available actions:
 * - audit:     Scan content and build link graph (cached 24hr).
 * - orphans:   Get orphaned posts from cached graph.
 * - backlinks: Get all posts linking to a given post.
 * - broken:    HTTP HEAD checks for broken links (internal, external, or all).
 *
 * @package DataMachine\Engine\AI\Tools\Global
 * @since 0.32.0
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\Tools\BaseTool;

class InternalLinkAudit extends BaseTool {

	public function __construct() {
		if ( ! function_exists( '\datamachine_register_ability_tool' ) ) {
			return;
		}

		\datamachine_register_ability_tool(
			'internal_link_audit',
			array_merge(
				$this->getToolDefinition(),
				array(
					'ability'                    => 'datamachine/audit-internal-links',
					'ability_map'                => array(
						'audit'     => 'datamachine/audit-internal-links',
						'orphans'   => 'datamachine/get-orphaned-posts',
						'backlinks' => 'datamachine/get-backlinks',
						'broken'    => 'datamachine/check-broken-links',
					),
					'modes'                      => array( 'chat', 'pipeline' ),
					'strip_action_parameter'     => true,
					'strip_internal_result_keys' => true,
				)
			)
		);
	}

	public function getToolDefinition(): array {
		return array(
			'description'     => 'Audit links on this WordPress site. Four actions: "audit" scans post content to build a link graph (cached 24hr), "orphans" lists posts with zero inbound links, "backlinks" gets all posts linking to a given post_id, "broken" performs HTTP HEAD checks for broken URLs (expensive, supports internal/external/all scope). Always run "audit" first, then use other actions for specific checks.',
			'requires_config' => false,
			'parameters'      => array(
				'type'       => 'object',
				'properties' => array(
					'action'    => array(
					'type'        => 'string',
					'description' => 'Action to perform: "audit" (scan + cache link graph), "orphans" (list orphaned posts), "backlinks" (get posts linking to a given post_id), or "broken" (HTTP check for broken links).',
					'enum'        => array( 'audit', 'orphans', 'backlinks', 'broken' ),
				),
					'post_id'   => array(
					'type'        => 'integer',
					'description' => 'Post ID to get backlinks for (backlinks action only).',
				),
					'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type to audit (default: "post").',
				),
					'category'  => array(
					'type'        => 'string',
					'description' => 'Category slug to limit audit scope (audit action only).',
				),
					'force'     => array(
					'type'        => 'boolean',
					'description' => 'Force rebuild even if cached graph exists (audit action only).',
				),
					'scope'     => array(
					'type'        => 'string',
					'description' => 'Link scope for broken action: "internal" (default), "external", or "all".',
					'enum'        => array( 'internal', 'external', 'all' ),
				),
					'limit'     => array(
					'type'        => 'integer',
					'description' => 'Maximum results to return. For orphans: max posts (default 50). For broken: max URLs to check (default 200).',
				),
					'types'     => array(
					'type'        => 'array',
					'description' => 'Optional edge types to include (e.g. ["html_anchor"], ["wikilink"]). Omit for all registered types.',
					'items'       => array( 'type' => 'string' ),
				),
				),
				'required'   => array( 'action' ),
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
