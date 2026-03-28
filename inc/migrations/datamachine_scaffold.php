//! datamachine_scaffold — extracted from migrations.php.


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
