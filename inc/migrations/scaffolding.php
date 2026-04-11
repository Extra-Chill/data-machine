<?php
/**
 * Data Machine — Scaffolding helpers.
 *
 * Scaffold defaults, context files, generator registration, and content
 * builders for agent memory files (SOUL.md, USER.md, MEMORY.md, RULES.md,
 * daily memory).
 *
 * @package DataMachine
 * @since 0.60.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build scaffold defaults for agent memory files using WordPress site data.
 *
 * Gathers site metadata, admin info, active plugins, content types, and
 * environment details to populate agent files with useful context instead
 * of empty placeholder comments.
 *
 * @since 0.32.0
 * @since 0.51.0 Accepts optional $agent_name for identity-aware SOUL.md scaffolding.
 *
 * @param string $agent_name Optional agent display name to include in SOUL.md identity.
 * @return array<string, string> Filename => content map for SOUL.md, USER.md, MEMORY.md.
 */
function datamachine_get_scaffold_defaults( string $agent_name = '' ): array {
	// --- Site metadata ---
	$site_name    = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'WordPress Site';
	$site_tagline = get_bloginfo( 'description' );
	$site_url     = home_url();
	$timezone     = wp_timezone_string();

	// --- Active theme ---
	$theme      = wp_get_theme();
	$theme_name = $theme->get( 'Name' ) ? $theme->get( 'Name' ) : 'Unknown';

	// --- Active plugins (exclude Data Machine itself) ---
	$active_plugins = get_option( 'active_plugins', array() );

	// On multisite, include network-activated plugins too.
	if ( is_multisite() ) {
		$network_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
		$active_plugins  = array_unique( array_merge( $active_plugins, $network_plugins ) );
	}

	$plugin_names = array();

	foreach ( $active_plugins as $plugin_file ) {
		if ( 0 === strpos( $plugin_file, 'data-machine/' ) ) {
			continue;
		}

		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( function_exists( 'get_plugin_data' ) && file_exists( $plugin_path ) ) {
			$plugin_data    = get_plugin_data( $plugin_path, false, false );
			$plugin_names[] = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : dirname( $plugin_file );
		} else {
			$dir            = dirname( $plugin_file );
			$plugin_names[] = '.' === $dir ? str_replace( '.php', '', basename( $plugin_file ) ) : $dir;
		}
	}

	// --- Content types with counts ---
	$content_lines = array();
	$post_types    = get_post_types( array( 'public' => true ), 'objects' );

	foreach ( $post_types as $pt ) {
		$count     = wp_count_posts( $pt->name );
		$published = isset( $count->publish ) ? (int) $count->publish : 0;

		if ( $published > 0 || in_array( $pt->name, array( 'post', 'page' ), true ) ) {
			$content_lines[] = sprintf( '%s: %d published', $pt->label, $published );
		}
	}

	// --- Multisite ---
	$multisite_line = '';
	if ( is_multisite() ) {
		$site_count     = get_blog_count();
		$multisite_line = sprintf(
			"\n- **Network:** WordPress Multisite with %d site%s",
			$site_count,
			1 === $site_count ? '' : 's'
		);
	}

	// --- Admin user ---
	$admin_email = get_option( 'admin_email', '' );
	$admin_user  = $admin_email ? get_user_by( 'email', $admin_email ) : null;
	$admin_name  = $admin_user ? $admin_user->display_name : '';

	// --- Versions ---
	$wp_version  = get_bloginfo( 'version' );
	$php_version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
	$dm_version  = defined( 'DATAMACHINE_VERSION' ) ? DATAMACHINE_VERSION : 'unknown';
	$created     = wp_date( 'Y-m-d' );

	// --- Build SOUL.md context lines ---
	$context_items   = array();
	$context_items[] = sprintf( '- **Site:** %s', $site_name );

	if ( $site_tagline ) {
		$context_items[] = sprintf( '- **Tagline:** %s', $site_tagline );
	}

	$context_items[] = sprintf( '- **URL:** %s', $site_url );
	$context_items[] = sprintf( '- **Theme:** %s', $theme_name );

	if ( $plugin_names ) {
		$context_items[] = sprintf( '- **Plugins:** %s', implode( ', ', $plugin_names ) );
	}

	if ( $content_lines ) {
		$context_items[] = sprintf( '- **Content:** %s', implode( ' · ', $content_lines ) );
	}

	$context_items[] = sprintf( '- **Timezone:** %s', $timezone );

	$soul_context = implode( "\n", $context_items ) . $multisite_line;

	// --- SOUL.md ---
	$identity_line = ! empty( $agent_name )
		? "You are **{$agent_name}**, an AI assistant managing {$site_name}."
		: "You are an AI assistant managing {$site_name}.";

	$identity_meta = '';
	if ( ! empty( $agent_name ) ) {
		$identity_meta = "\n- **Name:** {$agent_name}";
	}

	$soul = <<<MD
# Agent Soul — {$site_name}

## Identity
{$identity_line}
{$identity_meta}

## Voice & Tone
Write in a clear, helpful tone.

## Rules
- Follow the site's content guidelines
- Ask for clarification when instructions are ambiguous

## Context
{$soul_context}

## Continuity
SOUL.md (this file) defines who you are. USER.md profiles your human. MEMORY.md tracks persistent knowledge. Daily memory files (daily/YYYY/MM/DD.md) capture session activity — the system generates daily summaries automatically. Keep MEMORY.md lean: persistent facts only, not session logs.
MD;

	// --- USER.md ---
	$user_lines = array();
	if ( $admin_name ) {
		$user_lines[] = sprintf( '- **Name:** %s', $admin_name );
	}
	if ( $admin_email ) {
		$user_lines[] = sprintf( '- **Email:** %s', $admin_email );
	}
	$user_lines[] = '- **Role:** Site Administrator';
	$user_about   = implode( "\n", $user_lines );

	$user = <<<MD
# User Profile

## About
{$user_about}

## Preferences
<!-- Communication style, formatting preferences, things to remember -->

## Goals
<!-- What you're working toward with this site or project -->
MD;

	// --- MEMORY.md ---
	$memory = <<<MD
# Agent Memory

## State
- Data Machine v{$dm_version} activated on {$created}
- WordPress {$wp_version}, PHP {$php_version}

## Lessons Learned
<!-- What worked, what didn't, patterns to remember -->

## Context
<!-- Accumulated knowledge about the site, audience, domain -->
MD;

	return array(
		'SOUL.md'   => $soul,
		'USER.md'   => $user,
		'MEMORY.md' => $memory,
	);
}

/**
 * Create a default agent and scaffold its memory files.
 *
 * Ensures a first-class agent record exists for the default admin user,
 * then scaffolds agent-layer (SOUL.md, MEMORY.md) and user-layer (USER.md)
 * files. Also creates default context files (contexts/{context}.md).
 *
 * Called on activation (directly or via deferred transient) and lazily on
 * any request that reads agent files. Existing files are never overwritten —
 * only missing files are recreated from scaffold defaults.
 *
 * Returns false when the Abilities API is unavailable (e.g. during plugin
 * activation where init callbacks haven't fired), so the caller can defer.
 * The agent record is still created in this case — only the file scaffold
 * is deferred.
 *
 * @since 0.30.0
 * @since 0.65.0 Creates default agent record before scaffolding files.
 *
 * @return bool True if scaffold ran, false if abilities were unavailable.
 */
function datamachine_ensure_default_memory_files(): bool {
	$default_user_id = \DataMachine\Core\FilesRepository\DirectoryManager::get_default_agent_user_id();

	// Create a default agent record before scaffolding any files.
	// Without an agent, files would be written to a directory derived from
	// the user_login fallback — not tied to any real agent identity.
	$agent_id = datamachine_resolve_or_create_agent_id( $default_user_id );

	// Resolve agent slug for proper directory resolution.
	$agent_slug = null;
	if ( $agent_id > 0 ) {
		$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
		$agent       = $agents_repo->get_by_owner_id( $default_user_id );
		$agent_slug  = ! empty( $agent['agent_slug'] ) ? $agent['agent_slug'] : null;
	}

	$scaffold_context = array(
		'user_id'    => $default_user_id,
		'agent_slug' => $agent_slug,
		'agent_id'   => $agent_id,
	);

	$ability = \DataMachine\Abilities\File\ScaffoldAbilities::get_ability();
	if ( ! $ability ) {
		return false;
	}

	$ability->execute( array( 'layer' => 'shared' ) );
	$ability->execute( array_merge( $scaffold_context, array( 'layer' => 'agent' ) ) );
	$ability->execute( array_merge( $scaffold_context, array( 'layer' => 'user' ) ) );

	// Scaffold default context memory files (contexts/{context}.md).
	datamachine_ensure_default_context_files( $default_user_id );

	return true;
}

/**
 * Scaffold default context memory files (contexts/{context}.md).
 *
 * Creates the contexts/ directory and writes default context files
 * for each core execution context. Existing files are never overwritten.
 *
 * @since 0.58.0
 *
 * @param int $user_id Default agent user ID.
 */
function datamachine_ensure_default_context_files( int $user_id ): void {
	$dm          = new \DataMachine\Core\FilesRepository\DirectoryManager();
	$contexts_dir = $dm->get_contexts_directory( array( 'user_id' => $user_id ) );

	if ( ! $dm->ensure_directory_exists( $contexts_dir ) ) {
		return;
	}

	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		\WP_Filesystem();
	}

	$defaults = datamachine_get_default_context_files();

	foreach ( $defaults as $slug => $content ) {
		$filepath = trailingslashit( $contexts_dir ) . $slug . '.md';
		if ( file_exists( $filepath ) ) {
			continue;
		}
		$wp_filesystem->put_contents( $filepath, $content, FS_CHMOD_FILE );
	}
}

/**
 * Get default context file contents.
 *
 * Each key is the context slug (filename without .md extension).
 * These replace the former hardcoded ChatContextDirective,
 * PipelineContextDirective, and SystemContextDirective PHP classes.
 *
 * @since 0.58.0
 * @return array<string, string> Context slug => markdown content.
 */
function datamachine_get_default_context_files(): array {
	$defaults = array(
		'chat'     => datamachine_default_chat_context(),
		'pipeline' => datamachine_default_pipeline_context(),
		'system'   => datamachine_default_system_context(),
	);

	/**
	 * Filter the default context file contents.
	 *
	 * Extensions can add their own context defaults (e.g. 'editor')
	 * or modify the core defaults before scaffolding.
	 *
	 * @since 0.58.0
	 *
	 * @param array<string, string> $defaults Context slug => markdown content.
	 */
	return apply_filters( 'datamachine_default_context_files', $defaults );
}

/**
 * Default chat context (replaces ChatContextDirective).
 *
 * @since 0.58.0
 */
function datamachine_default_chat_context(): string {
	return <<<'MD'
# Chat Session Context

This is a live chat session with a user in the Data Machine admin UI. You have tools to configure and manage workflows. Your identity, voice, and knowledge come from your memory files above.

## Data Machine Architecture

HANDLERS are the core intelligence. Fetch handlers extract and structure source data. Update/publish handlers apply changes with schema defaults for unconfigured fields. Each handler has a settings schema — only use documented fields.

PIPELINES define workflow structure: step types in sequence (e.g., event_import → ai → upsert). The pipeline system_prompt defines AI behavior shared by all flows.

FLOWS are configured pipeline instances. Each step needs a handler_slug and handler_config. When creating flows, match handler configurations from existing flows on the same pipeline.

AI STEPS process data that handlers cannot automatically handle. Flow user_message is rarely needed; only for minimal source-specific overrides.

## Discovery

You receive a pipeline inventory with existing flows and their handlers. Use `api_query` for detailed configuration. Query existing flows before creating new ones to learn established patterns.

## Configuration Rules

- Only use documented handler_config fields — unknown fields are rejected.
- Use pipeline_step_id from the inventory to target steps.
- Unconfigured handler fields use schema defaults automatically.
- Act first — if the user gives executable instructions, execute them.

## Scheduling

- Scheduling uses intervals only (daily, hourly, etc.), not specific times of day.
- Valid intervals are provided in the tool definitions. Use update_flow to change schedules.

## Execution Protocol

- Only confirm task completion after a successful tool result. Never claim success on error.
- Check error_type on failure: not_found/permission → report, validation → fix and retry, system → retry once.
- If a tool rejects unknown fields, retry with only the valid fields listed in the error.
- Act decisively — execute tools directly for routine configuration.
- If uncertain about a value, use sensible defaults and note the assumption.
MD;
}

/**
 * Default pipeline context (replaces PipelineContextDirective).
 *
 * @since 0.58.0
 */
function datamachine_default_pipeline_context(): string {
	return <<<'MD'
# Pipeline Execution Context

This is an automated pipeline step — not a chat session. You're processing data through a multi-step workflow. Your identity and knowledge come from your memory files above. Apply that context to the content you process.

## How Pipelines Work

- Each pipeline step has a specific purpose within the overall workflow
- Handler tools produce final results — execute once per workflow objective
- Analyze available data and context before taking action

## Data Packet Structure

You receive content as JSON data packets with these guaranteed fields:
- type: The step type that created this packet
- timestamp: When the packet was created

Additional fields may include data, metadata, content, and handler-specific information.
MD;
}

/**
 * Default system context (replaces SystemContextDirective).
 *
 * @since 0.58.0
 */
function datamachine_default_system_context(): string {
	return <<<'MD'
# System Task Context

This is a background system task — not a chat session. You are the internal agent responsible for automated housekeeping: generating session titles, summarizing content, and other system-level operations.

Your identity and knowledge are already loaded from your memory files above. Use that context.

## Task Behavior

- Execute the task described in the user message below.
- Return exactly what the task asks for — no extra commentary, no meta-discussion.
- Apply your knowledge of this site, its voice, and its conventions from your memory files.

## Session Title Generation

When asked to generate a chat session title: create a concise, descriptive title (3-6 words) capturing the discussion essence. Return ONLY the title text, under 100 characters.
MD;
}

/**
 * Resolve agent display name from scaffolding context.
 *
 * Looks up the agent record from the provided context identifiers
 * (agent_slug, agent_id, or user_id) and returns the display name.
 * Returns empty string when no agent can be resolved.
 *
 * @since 0.51.0
 *
 * @param array $context Scaffolding context with agent_slug, agent_id, or user_id.
 * @return string Agent display name, or empty string.
 */
function datamachine_resolve_agent_name_from_context( array $context ): string {
	if ( ! class_exists( '\\DataMachine\\Core\\Database\\Agents\\Agents' ) ) {
		return '';
	}

	$agents_repo = new \DataMachine\Core\Database\Agents\Agents();

	// 1) Explicit agent_slug.
	if ( ! empty( $context['agent_slug'] ) ) {
		$agent = $agents_repo->get_by_slug( sanitize_title( (string) $context['agent_slug'] ) );
		if ( ! empty( $agent['agent_name'] ) ) {
			return (string) $agent['agent_name'];
		}
	}

	// 2) Agent ID.
	$agent_id = (int) ( $context['agent_id'] ?? 0 );
	if ( $agent_id > 0 ) {
		$agent = $agents_repo->get_agent( $agent_id );
		if ( ! empty( $agent['agent_name'] ) ) {
			return (string) $agent['agent_name'];
		}
	}

	// 3) User ID → owner lookup.
	$user_id = (int) ( $context['user_id'] ?? 0 );
	if ( $user_id > 0 ) {
		$agent = $agents_repo->get_by_owner_id( $user_id );
		if ( ! empty( $agent['agent_name'] ) ) {
			return (string) $agent['agent_name'];
		}
	}

	return '';
}

/**
 * Register default content generators for datamachine/scaffold-memory-file.
 *
 * Each generator handles one filename and builds content from the
 * context array (user_id, agent_slug, etc.). Generators are composable
 * via the `datamachine_scaffold_content` filter — plugins can override
 * or extend any file's default content.
 *
 * @since 0.50.0
 */
function datamachine_register_scaffold_generators(): void {
	add_filter( 'datamachine_scaffold_content', 'datamachine_scaffold_user_content', 10, 3 );
	add_filter( 'datamachine_scaffold_content', 'datamachine_scaffold_soul_content', 10, 3 );
	add_filter( 'datamachine_scaffold_content', 'datamachine_scaffold_memory_content', 10, 3 );
	add_filter( 'datamachine_scaffold_content', 'datamachine_scaffold_daily_content', 10, 3 );
	add_filter( 'datamachine_scaffold_content', 'datamachine_scaffold_rules_content', 10, 3 );
}
add_action( 'plugins_loaded', 'datamachine_register_scaffold_generators', 5 );

/**
 * Generate USER.md content from WordPress user profile data.
 *
 * @since 0.50.0
 *
 * @param string $content  Current content (empty if no prior generator).
 * @param string $filename Filename being scaffolded.
 * @param array  $context  Scaffolding context with user_id.
 * @return string
 */
function datamachine_scaffold_user_content( string $content, string $filename, array $context ): string {
	if ( 'USER.md' !== $filename || '' !== $content ) {
		return $content;
	}

	$user_id = (int) ( $context['user_id'] ?? 0 );
	if ( $user_id <= 0 ) {
		return $content;
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return $content;
	}

	$about_lines   = array();
	$about_lines[] = sprintf( '- **Name:** %s', $user->display_name );
	$about_lines[] = sprintf( '- **Username:** %s', $user->user_login );

	$roles = $user->roles;
	if ( ! empty( $roles ) ) {
		$role_name     = ucfirst( reset( $roles ) );
		$about_lines[] = sprintf( '- **Role:** %s', $role_name );
	}

	if ( ! empty( $user->user_registered ) ) {
		$registered    = wp_date( 'F Y', strtotime( $user->user_registered ) );
		$about_lines[] = sprintf( '- **Member since:** %s', $registered );
	}

	$post_count = count_user_posts( $user_id, 'post', true );
	if ( $post_count > 0 ) {
		$about_lines[] = sprintf( '- **Published posts:** %d', $post_count );
	}

	$description = get_user_meta( $user_id, 'description', true );
	if ( ! empty( $description ) ) {
		$clean_bio     = wp_strip_all_tags( $description );
		$about_lines[] = sprintf( "\n%s", $clean_bio );
	}

	$about = implode( "\n", $about_lines );

	return <<<MD
# User Profile

## About
{$about}

## Preferences
<!-- Communication style, topics of interest, working hours, things to remember -->

## Goals
<!-- What are you working toward? Projects, content themes, skills to develop -->
MD;
}

/**
 * Generate SOUL.md content from site and agent context.
 *
 * Uses scaffolding context (agent_slug, agent_id) to resolve the agent's
 * display name from the database and embed it in the identity section.
 * Falls back to the generic template when no agent context is available.
 *
 * @since 0.50.0
 * @since 0.51.0 Resolves agent_name from context for identity-aware scaffolding.
 *
 * @param string $content  Current content.
 * @param string $filename Filename being scaffolded.
 * @param array  $context  Scaffolding context with agent_slug, agent_id, or user_id.
 * @return string
 */
function datamachine_scaffold_soul_content( string $content, string $filename, array $context ): string {
	if ( 'SOUL.md' !== $filename || '' !== $content ) {
		return $content;
	}

	// Resolve agent identity from context.
	$agent_name = datamachine_resolve_agent_name_from_context( $context );

	$defaults = datamachine_get_scaffold_defaults( $agent_name );
	return $defaults['SOUL.md'] ?? '';
}

/**
 * Generate MEMORY.md content from site context.
 *
 * @since 0.50.0
 *
 * @param string $content  Current content.
 * @param string $filename Filename being scaffolded.
 * @param array  $context  Scaffolding context.
 * @return string
 */
function datamachine_scaffold_memory_content( string $content, string $filename, array $context ): string {
	if ( 'MEMORY.md' !== $filename || '' !== $content ) {
		return $content;
	}

	$defaults = datamachine_get_scaffold_defaults();
	return $defaults['MEMORY.md'] ?? '';
}

/**
 * Generate RULES.md scaffold content.
 *
 * Creates a starter template for site-wide behavioral constraints.
 * RULES.md is admin-editable and applies to every agent on the site.
 *
 * @since 0.50.0
 *
 * @param string $content  Current content.
 * @param string $filename Filename being scaffolded.
 * @param array  $context  Scaffolding context.
 * @return string
 */
function datamachine_scaffold_rules_content( string $content, string $filename, array $context ): string {
	if ( 'RULES.md' !== $filename || '' !== $content ) {
		return $content;
	}

	$site_name = get_bloginfo( 'name' ) ?: 'this site';

	return <<<MD
# Site Rules

Behavioral constraints that apply to every agent on {$site_name}.

## General
- Be helpful, accurate, and concise.
- Follow the site's voice and tone.
- Do not make up facts or hallucinate information.

## Safety
- Never expose private user data.
- Never run destructive operations without confirmation.
- When in doubt, ask before acting.

## Content
- Respect the site's content guidelines.
- Do not publish or modify content without authorization.
MD;
}

/**
 * Generate daily memory file content with a date header.
 *
 * Matches filenames like 'daily/2026/03/20.md'. The context must
 * include a 'date' key in YYYY-MM-DD format for the header.
 *
 * @since 0.50.0
 *
 * @param string $content  Current content.
 * @param string $filename Logical filename (e.g. 'daily/2026/03/20.md').
 * @param array  $context  Scaffolding context with 'date'.
 * @return string
 */
function datamachine_scaffold_daily_content( string $content, string $filename, array $context ): string {
	if ( '' !== $content ) {
		return $content;
	}

	// Match daily file pattern: daily/YYYY/MM/DD.md.
	if ( ! preg_match( '#^daily/\d{4}/\d{2}/\d{2}\.md$#', $filename ) ) {
		return $content;
	}

	$date = $context['date'] ?? '';
	if ( empty( $date ) ) {
		// Extract date from filename path.
		if ( preg_match( '#^daily/(\d{4})/(\d{2})/(\d{2})\.md$#', $filename, $m ) ) {
			$date = "{$m[1]}-{$m[2]}-{$m[3]}";
		}
	}

	if ( empty( $date ) ) {
		return $content;
	}

	return "# {$date}";
}
