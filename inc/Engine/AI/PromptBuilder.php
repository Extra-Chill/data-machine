<?php
/**
 * Prompt Builder - Unified Directive Management for AI Requests
 *
 * Centralizes directive injection for AI requests with priority-based ordering.
 * Replaces separate global/agent filter application with a structured builder pattern.
 *
 * @package DataMachine\Engine\AI
 * @since 0.2.5
 */

namespace DataMachine\Engine\AI;

use DataMachine\Engine\AI\Directives\DirectiveInterface;
use DataMachine\Engine\AI\Directives\DirectiveOutputValidator;
use DataMachine\Engine\AI\Directives\DirectiveRenderer;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt Builder Class
 *
 * Manages directive registration and application for building AI requests.
 * Ensures directives are applied in correct priority order for consistent prompt structure.
 */
class PromptBuilder {

	/**
	 * Registered directives
	 *
	 * @var array Array of directive configurations
	 */
	private array $directives = array();

	/**
	 * Initial messages
	 *
	 * @var array
	 */
	private array $messages = array();

	/**
	 * Available tools
	 *
	 * @var array
	 */
	private array $tools = array();

	/**
	 * Set initial messages
	 *
	 * @param array $messages Initial conversation messages
	 * @return self
	 */
	public function setMessages( array $messages ): self {
		$this->messages = $messages;
		return $this;
	}

	/**
	 * Set available tools
	 *
	 * @param array $tools Available tools array
	 * @return self
	 */
	public function setTools( array $tools ): self {
		$this->tools = $tools;
		return $this;
	}

	/**
	 * Add a directive to the builder
	 *
	 * @param string|object $directive Directive class name or instance
	 * @param int           $priority Priority for ordering (lower = applied first)
	 * @param array         $contexts Contexts this directive applies to ('all' for global)
	 * @return self
	 */
	public function addDirective( $directive, int $priority, array $contexts = array( 'all' ) ): self {
		$this->directives[] = array(
			'directive' => $directive,
			'priority'  => $priority,
			'contexts'  => $contexts,
		);
		return $this;
	}

	/**
	 * Build the final AI request with directives applied
	 *
	 * @param string $context Execution context ('pipeline', 'chat', etc.)
	 * @param string $provider AI provider name
	 * @param array  $payload Request payload
	 * @return array Request array with 'messages', 'tools', and 'applied_directives' metadata
	 */
	public function build( string $context, string $provider, array $payload = array() ): array {
		usort(
			$this->directives,
			function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		// Ensure directives can access the current execution context
		// for context-scoped memory file loading (contexts/{context}.md).
		if ( ! isset( $payload['context'] ) ) {
			$payload['context'] = $context;
		}

		$conversation_messages = $this->messages;
		$directive_outputs     = array();
		$applied_directives    = array();

		foreach ( $this->directives as $directiveConfig ) {
			$directive = $directiveConfig['directive'];
			$contexts  = $directiveConfig['contexts'];

			if ( ! in_array( 'all', $contexts, true ) && ! in_array( $context, $contexts, true ) ) {
				continue;
			}

			$stepId          = $payload['step_id'] ?? null;
			$directive_class = is_string( $directive ) ? $directive : get_class( $directive );
			$directive_name  = substr( $directive_class, strrpos( $directive_class, '\\' ) + 1 );

			if ( is_string( $directive ) && class_exists( $directive ) && is_subclass_of( $directive, DirectiveInterface::class ) ) {
				$outputs = $directive::get_outputs( $provider, $this->tools, $stepId, $payload );
				if ( is_array( $outputs ) && ! empty( $outputs ) ) {
					$directive_outputs = array_merge( $directive_outputs, $outputs );
				}
				$applied_directives[] = $directive_name;
				continue;
			}

			if ( is_object( $directive ) && $directive instanceof DirectiveInterface ) {
				$outputs = $directive->get_outputs( $provider, $this->tools, $stepId, $payload );
				if ( is_array( $outputs ) && ! empty( $outputs ) ) {
					$directive_outputs = array_merge( $directive_outputs, $outputs );
				}
				$applied_directives[] = $directive_name;
				continue;
			}
		}

		$validation_context = array_filter(
			array(
				'job_id'       => $payload['job_id'] ?? null,
				'flow_step_id' => $payload['flow_step_id'] ?? null,
			),
			fn( $v ) => null !== $v
		);
		$validated_outputs  = DirectiveOutputValidator::validateOutputs( $directive_outputs, $validation_context );
		$directive_messages = DirectiveRenderer::renderMessages( $validated_outputs );

		return array(
			'messages'           => array_merge( $directive_messages, $conversation_messages ),
			'tools'              => $this->tools,
			'applied_directives' => $applied_directives,
		);
	}
}
