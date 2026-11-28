<?php
/**
 * Agent Mode Directive - Priority 22
 *
 * Injects execution-mode guidance (pipeline, system, etc.) into AI
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
	 * Default pipeline mode guidance.
	 *
	 * @since 0.68.0
	 */
	private const PIPELINE_MODE = <<<'MD'
# Pipeline Execution Context

This is an automated pipeline step. You're processing data through a multi-step workflow. Your identity and knowledge come from your memory files above. Apply that context to the content you process.

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

This is a background system task. You are the internal agent responsible for automated housekeeping: summarizing content, generating metadata, and other system-level operations.

Your identity and knowledge are already loaded from your memory files above. Use that context.

## Task Behavior

- Execute the task described in the user message below.
- Return exactly what the task asks for — no extra commentary, no meta-discussion.
- Apply your knowledge of this site, its voice, and its conventions from your memory files.
MD;

	/**
	 * Get directive outputs for the current agent mode.
	 *
	 * Reads mode slugs from $payload['agent_modes'], applies built-in defaults,
	 * then filters through datamachine_agent_mode_{slug} for extension composition.
	 *
	 * @param string      $provider_name AI provider name.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID.
	 * @param array       $payload       Additional payload data.
	 * @return array Directive outputs.
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$modes = self::normalizeModes( $payload['agent_modes'] ?? array() );

		if ( empty( $modes ) ) {
			return array();
		}

		$contents = array();
		foreach ( $modes as $mode ) {
			$default = self::get_default_for_mode( $mode );

			/**
			 * Filter agent-mode guidance for a given mode slug.
			 *
			 * Lets extensions append or modify mode-specific guidance
			 * (e.g. the editor plugin appends diff workflow instructions
			 * when the 'editor' mode is active).
			 *
			 * Fires as datamachine_agent_mode_{mode}, e.g.
			 * datamachine_agent_mode_pipeline.
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
			if ( '' !== $content ) {
				$contents[] = $content;
			}
		}

		$content = trim( implode( "\n\n", $contents ) );

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
			'pipeline' => self::PIPELINE_MODE,
			'system'   => self::SYSTEM_MODE,
			default    => '',
		};
	}

	/** @return array<int,string> */
	private static function normalizeModes( mixed $modes ): array {
		if ( is_string( $modes ) ) {
			$modes = array( $modes );
		}
		if ( ! is_array( $modes ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $modes as $mode ) {
			if ( ! is_scalar( $mode ) ) {
				continue;
			}
			$mode = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $mode ) : strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $mode ) ?? '' );
			if ( '' !== $mode ) {
				$normalized[] = $mode;
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}

// Self-register in the directive system (Priority 22 = agent mode guidance for all AI calls).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => AgentModeDirective::class,
			'priority' => 22,
			'modes'    => array( 'all' ),
		);
		return $directives;
	}
);
