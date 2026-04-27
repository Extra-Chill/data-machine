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
	 * @param array         $modes    Agent modes this directive applies to ('all' for global)
	 * @return self
	 */
	public function addDirective( $directive, int $priority, array $modes = array( 'all' ) ): self {
		$this->directives[] = array(
			'directive' => $directive,
			'priority'  => $priority,
			'modes'     => $modes,
		);
		return $this;
	}

	/**
	 * Build the final AI request with directives applied
	 *
	 * @param string $mode     Agent mode ('pipeline', 'chat', etc.)
	 * @param string $provider AI provider name
	 * @param array  $payload  Request payload
	 * @return array Request array with 'messages', 'tools', and 'applied_directives' metadata
	 */
	public function build( string $mode, string $provider, array $payload = array() ): array {
		usort(
			$this->directives,
			function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		// Ensure directives can access the current agent mode.
		if ( ! isset( $payload['agent_mode'] ) ) {
			$payload['agent_mode'] = $mode;
		}

		$conversation_messages = $this->messages;
		$directive_outputs      = array();
		$applied_directives     = array();
		$directive_metadata     = array();
		$validation_context     = array_filter(
			array(
				'job_id'       => $payload['job_id'] ?? null,
				'flow_step_id' => $payload['flow_step_id'] ?? null,
			),
			fn( $v ) => null !== $v
		);

		foreach ( $this->directives as $directiveConfig ) {
			$directive = $directiveConfig['directive'];
			$modes     = $directiveConfig['modes'];

			if ( ! in_array( 'all', $modes, true ) && ! in_array( $mode, $modes, true ) ) {
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
				$directive_metadata[] = self::describeDirectiveOutputs(
					$directive_name,
					(int) $directiveConfig['priority'],
					is_array( $outputs ) ? $outputs : array()
				);
				continue;
			}

			if ( is_object( $directive ) && $directive instanceof DirectiveInterface ) {
				$outputs = $directive->get_outputs( $provider, $this->tools, $stepId, $payload );
				if ( is_array( $outputs ) && ! empty( $outputs ) ) {
					$directive_outputs = array_merge( $directive_outputs, $outputs );
				}
				$applied_directives[] = $directive_name;
				$directive_metadata[] = self::describeDirectiveOutputs(
					$directive_name,
					(int) $directiveConfig['priority'],
					is_array( $outputs ) ? $outputs : array()
				);
				continue;
			}
		}

		$validated_outputs  = DirectiveOutputValidator::validateOutputs( $directive_outputs, $validation_context );
		$directive_messages = DirectiveRenderer::renderMessages( $validated_outputs );

		return array(
			'messages'           => array_merge( $directive_messages, $conversation_messages ),
			'tools'              => $this->tools,
			'applied_directives' => $applied_directives,
			'directive_metadata' => $directive_metadata,
		);
	}

	/**
	 * Describe a directive's output without storing full prompt text.
	 *
	 * @param string $name     Directive display name.
	 * @param int    $priority Directive priority.
	 * @param array  $outputs  Raw directive outputs.
	 * @return array<string,mixed>
	 */
	private static function describeDirectiveOutputs( string $name, int $priority, array $outputs ): array {
		$content_bytes = 0;
		$memory_files  = array();

		foreach ( $outputs as $output ) {
			$content = '';
			if ( is_array( $output ) && isset( $output['content'] ) && is_scalar( $output['content'] ) ) {
				$content = (string) $output['content'];
			} elseif ( is_array( $output ) && isset( $output['data'] ) ) {
				$content = (string) wp_json_encode( $output['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}

			$content_bytes += strlen( $content );
			$memory_file    = self::extractMemoryFilename( $content );
			if ( '' !== $memory_file ) {
				$memory_files[] = array(
					'filename' => $memory_file,
					'bytes'    => strlen( $content ),
					'injected' => true,
				);
			}
		}

		$json = wp_json_encode( $outputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return array(
			'name'          => $name,
			'priority'      => $priority,
			'messages'      => self::countRenderableOutputs( $outputs ),
			'outputs'       => count( $outputs ),
			'content_bytes' => $content_bytes,
			'json_bytes'    => strlen( (string) $json ),
			'memory_files'  => $memory_files,
		);
	}

	/**
	 * Count outputs that can render into request messages without logging validation warnings.
	 *
	 * @param array $outputs Raw directive outputs.
	 * @return int Renderable output count.
	 */
	private static function countRenderableOutputs( array $outputs ): int {
		$count = 0;
		foreach ( $outputs as $output ) {
			if ( ! is_array( $output ) ) {
				continue;
			}

			$type = $output['type'] ?? '';
			if ( 'system_text' === $type && isset( $output['content'] ) && is_string( $output['content'] ) && '' !== trim( $output['content'] ) ) {
				++$count;
			}
			if ( 'system_json' === $type && ! empty( $output['label'] ) && is_array( $output['data'] ?? null ) ) {
				++$count;
			}
			if ( 'system_file' === $type && ! empty( $output['file_path'] ) && ! empty( $output['mime_type'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Extract a memory filename from the standard MemoryFilesReader content prefix.
	 *
	 * @param string $content Directive content.
	 * @return string Filename when present, otherwise empty string.
	 */
	private static function extractMemoryFilename( string $content ): string {
		if ( preg_match( '/^## Memory File: ([^\n]+)/', $content, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}
}
