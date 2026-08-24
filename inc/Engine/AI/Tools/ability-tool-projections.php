<?php
/**
 * Ability tool projection helpers.
 *
 * @package DataMachine\Engine\AI\Tools
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'datamachine_register_ability_tool' ) ) {
	/**
	 * Register an ability as a model-facing tool projection.
	 *
	 * The declaration must include an `ability` slug or non-empty `ability_map`.
	 * AbilityToolSource uses the primary slug for projection metadata.
	 * Optional keys such as `modes`, `description`, `parameters`,
	 * `requires_opt_in`, `action_policy`, and `runtime` are passed through.
	 *
	 * @param string $tool_name   Model-facing tool name.
	 * @param array  $declaration Ability projection declaration.
	 * @return bool Whether the projection was registered.
	 */
	function datamachine_register_ability_tool( string $tool_name, array $declaration ): bool {
		if ( '' === $tool_name || ( ( empty( $declaration['ability'] ) || ! is_string( $declaration['ability'] ) ) && ( empty( $declaration['ability_map'] ) || ! is_array( $declaration['ability_map'] ) ) ) ) {
			return false;
		}

		add_filter(
			'datamachine_ability_tool_projections',
			static function ( array $tools ) use ( $tool_name, $declaration ): array {
				$tools[ $tool_name ] = $declaration;
				return $tools;
			}
		);

		return true;
	}
}

datamachine_register_ability_tool(
	'local_search',
	array(
		'ability'        => 'datamachine/local-search',
		'modes'          => array( 'chat', 'pipeline' ),
		'description'    => 'Search this WordPress site for posts by title or content. Returns up to 10 results with titles, excerpts, permalinks, and metadata. Automatically tries multiple search strategies (standard search, title matching, split queries) if initial search returns no results. For best results, search for ONE item at a time. Use title_only=true for precise title matching.',
		'requires_config' => false,
		'parameters'     => array(
			'type'       => 'object',
			'properties' => array(
				'query'      => array(
					'type'        => 'string',
					'description' => 'Search terms to find relevant posts. For best results, use simple queries for one item at a time rather than multiple comma-separated items.',
				),
				'post_types' => array(
					'type'        => 'array',
					'description' => 'Post types to search (default: ["post", "page"]). Use ["datamachine_events"] for events.',
					'items'       => array( 'type' => 'string' ),
				),
				'title_only' => array(
					'type'        => 'boolean',
					'description' => 'Search only post titles instead of full content (default: false). Use for precise title matching when you know the exact or partial title.',
				),
			),
			'required'   => array( 'query' ),
		),
	)
);

datamachine_register_ability_tool(
	'image_generation',
	array(
		'ability'         => 'datamachine/generate-image',
		'modes'           => array( 'chat', 'pipeline' ),
		'description'     => 'Generate images using wp-ai-client image models. Returns a pending image-generation job that will sideload the generated image and optionally set it as featured media. Use descriptive, detailed prompts for best results. Default aspect ratio is 3:4 (portrait, ideal for Pinterest and blog featured images).',
		'requires_config' => true,
		'parameters'      => array(
			'type'       => 'object',
			'properties' => array(
				'prompt'       => array(
					'type'        => 'string',
					'description' => 'Detailed image generation prompt describing the desired image. Be specific about style, composition, lighting, and subject.',
				),
				'model'        => array(
					'type'        => 'string',
					'description' => 'wp-ai-client model identifier. Defaults to the image generation tool configuration.',
				),
				'provider'     => array(
					'type'        => 'string',
					'description' => 'wp-ai-client provider identifier. Defaults to the image generation tool configuration.',
				),
				'aspect_ratio' => array(
					'type'        => 'string',
					'description' => 'Image aspect ratio. Options: 1:1, 3:4, 4:3, 9:16, 16:9. Default: 3:4 (portrait).',
				),
			),
			'required'   => array( 'prompt' ),
		),
	)
);

datamachine_register_ability_tool(
	'internal_link_audit',
	array(
		'ability_map'                => array(
			'audit'     => 'datamachine/audit-internal-links',
			'orphans'   => 'datamachine/get-orphaned-posts',
			'backlinks' => 'datamachine/get-backlinks',
			'broken'    => 'datamachine/check-broken-links',
		),
		'modes'                      => array( 'chat', 'pipeline' ),
		'strip_action_parameter'     => true,
		'strip_internal_result_keys' => true,
		'description'                => 'Audit links on this WordPress site. Four actions: "audit" scans post content to build a link graph (cached 24hr), "orphans" lists posts with zero inbound links, "backlinks" gets all posts linking to a given post_id, "broken" performs HTTP HEAD checks for broken URLs (expensive, supports internal/external/all scope). Always run "audit" first, then use other actions for specific checks.',
		'requires_config'            => false,
		'parameters'                 => array(
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
	)
);

datamachine_register_ability_tool(
	'wordpress_post_reader',
	array(
		'ability'         => 'datamachine/get-wordpress-post',
		'modes'           => array( 'chat', 'pipeline' ),
		'description'     => 'Read full content and metadata from a specific WordPress post by permalink URL. Use after Local Search when you need complete post content instead of excerpts. Accepts standard WordPress permalinks (e.g., /post-slug/) or shortlinks (?p=123). Does NOT accept REST API URLs (/wp-json/...). Essential for content analysis before WordPress Update operations.',
		'requires_config' => false,
		'parameters'      => array(
			'type'       => 'object',
			'properties' => array(
				'source_url'   => array(
					'type'        => 'string',
					'description' => 'WordPress permalink URL (e.g., https://site.com/post-slug/ or https://site.com/?p=123). Do not use REST API URLs.',
				),
				'include_meta' => array(
					'type'        => 'boolean',
					'description' => 'Include custom fields in response (default: false)',
				),
			),
			'required'   => array( 'source_url' ),
		),
	)
);
