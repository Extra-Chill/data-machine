<?php
/**
 * Agent Mode Directive - Priority 22
 *
 * Injects execution-mode guidance (chat, pipeline, system, etc.) into AI
 * calls as a runtime directive rather than per-agent disk files.
 *
 * Replaces the former contexts/{mode}.md per-agent file system. Mode
 * guidance is platform knowledge, not agent-specific state — shipping it
 * as a directive removes per-agent disk clutter, enables hook-based
 * composition, and more accurately reflects what the content is.
 *
 * Priority Order in Directive System:
 * 1. Priority 10 - Plugin Core Directive (agent identity)
 * 2. Priority 20 - Core Memory Files (memory files)
 * 3. Priority 22 - Agent Mode Directive (THIS CLASS)
 * 4. Priority 40 - Pipeline Memory Files (per-pipeline selectable)
 * 5. Priority 50 - Pipeline System Prompt (pipeline instructions)
 *
 * @package DataMachine\Engine\AI\Directives
 * @since   0.68.0
 */

namespace DataMachine\Engine\AI\Directives;

defined( 'ABSPATH' ) || exit;

class AgentModeDirective implements DirectiveInterface {

	/**
	 * Default chat mode guidance.
	 *
	 * @since 0.68.0
	 */
	private const CHAT_MODE = <<<'MD'
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

	/**
	 * Default pipeline mode guidance.
	 *
	 * @since 0.68.0
	 */
	private const PIPELINE_MODE = <<<'MD'
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

	/**
	 * Default system mode guidance.
	 *
	 * @since 0.68.0
	 */
	private const SYSTEM_MODE = <<<'MD'
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

	/**
	 * Get directive outputs for the current agent mode.
	 *
	 * Reads the mode slug from $payload['agent_mode'], applies the built-in
	 * default, then filters through datamachine_agent_mode_{slug} for
	 * extension composition.
	 *
	 * @param string      $provider_name AI provider name.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID.
	 * @param array       $payload       Additional payload data.
	 * @return array Directive outputs.
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$mode = $payload['agent_mode'] ?? '';

		if ( empty( $mode ) ) {
			return array();
		}

		$default = self::get_default_for_mode( $mode );

		/**
		 * Filter agent-mode guidance for a given mode slug.
		 *
		 * Lets extensions append or modify mode-specific guidance
		 * (e.g. the editor plugin appends diff workflow instructions
		 * when the 'editor' mode is active).
		 *
		 * Fires as datamachine_agent_mode_{mode}, e.g.
		 * datamachine_agent_mode_chat.
		 *
		 * @since 0.68.0
		 *
		 * @param string $content Current guidance text.
		 * @param array  $payload Full request payload (agent_id, user_id, etc.).
		 */
		$content = apply_filters(
			"datamachine_agent_mode_{$mode}",
			$default,
			$payload
		);

		$content = trim( (string) $content );

		if ( '' === $content ) {
			return array();
		}

		return array(
			array(
				'type'    => 'system_text',
				'content' => $content,
			),
		);
	}

	/**
	 * Get built-in default guidance for a mode.
	 *
	 * @since 0.68.0
	 *
	 * @param string $mode Mode slug.
	 * @return string Default guidance, or empty string for unknown modes.
	 */
	private static function get_default_for_mode( string $mode ): string {
		return match ( $mode ) {
			'chat'     => self::CHAT_MODE,
			'pipeline' => self::PIPELINE_MODE,
			'system'   => self::SYSTEM_MODE,
			default     => '',
		};
	}
}

// Self-register in the directive system (Priority 22 = agent mode guidance for all AI calls).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => AgentModeDirective::class,
			'priority' => 22,
			'contexts' => array( 'all' ),
		);
		return $directives;
	}
);
