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
	 * @return array Request array with messages, tools, and directive metadata
	 */
	public function build( string $mode, string $provider, array $payload = array() ): array {
		$detailed = $this->buildDetailed( $mode, $provider, $payload );

		return array(
			'messages'           => $detailed['messages'],
			'tools'              => $detailed['tools'],
			'applied_directives' => $detailed['applied_directives'],
			'directive_metadata' => $detailed['directive_metadata'],
		);
	}

	/**
	 * Build the final AI request and include directive-level inspection metadata.
	 *
	 * @param string $mode     Agent mode ('pipeline', 'chat', etc.).
	 * @param string $provider AI provider name.
	 * @param array  $payload  Request payload.
	 * @return array Request array with messages, tools, applied_directives, directive_metadata, and directive_breakdown.
	 */
	public function buildDetailed( string $mode, string $provider, array $payload = array() ): array {
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
		$directive_outputs     = array();
		$applied_directives    = array();
		$directive_metadata    = array();
		$directive_breakdown   = array();
		$validation_context    = array_filter(
			array(
				'job_id'       => $payload['job_id'] ?? null,
				'flow_step_id' => $payload['flow_step_id'] ?? null,
			),
			fn( $v ) => null !== $v
		);

		foreach ( $this->directives as $directiveConfig ) {
			$directive = $directiveConfig['directive'];
			$modes     = $directiveConfig['modes'];
			$priority  = (int) ( $directiveConfig['priority'] ?? 10 );

			if ( ! in_array( 'all', $modes, true ) && ! in_array( $mode, $modes, true ) ) {
				continue;
			}

			$stepId          = $payload['step_id'] ?? null;
			$directive_class = is_string( $directive ) ? $directive : ( is_object( $directive ) ? get_class( $directive ) : '' );
			if ( '' === $directive_class ) {
				continue;
			}
			$namespace_pos  = strrpos( $directive_class, '\\' );
			$directive_name = false === $namespace_pos ? $directive_class : substr( $directive_class, $namespace_pos + 1 );

			$outputs = null;

			if ( is_string( $directive ) && class_exists( $directive ) && is_subclass_of( $directive, DirectiveInterface::class ) ) {
				$outputs = $directive::get_outputs( $provider, $this->tools, $stepId, $payload );
			} elseif ( is_object( $directive ) && $directive instanceof DirectiveInterface ) {
				$outputs = $directive->get_outputs( $provider, $this->tools, $stepId, $payload );
			}

			if ( null === $outputs ) {
				continue;
			}

			if ( ! empty( $outputs ) ) {
				$directive_outputs = array_merge( $directive_outputs, $outputs );
			}

			$validated_outputs     = DirectiveOutputValidator::validateOutputs( $outputs, $validation_context );
			$rendered_messages     = DirectiveRenderer::renderMessages( $validated_outputs );
			$applied_directives[]  = $directive_name;
			$directive_metadata[]  = self::describeDirectiveOutputs( $directive_name, $priority, $outputs );
			$directive_breakdown[] = array(
				'class'                  => $directive_class,
				'name'                   => $directive_name,
				'priority'               => $priority,
				'output_count'           => count( $outputs ),
				'validated_output_count' => count( $validated_outputs ),
				'rendered_message_count' => count( $rendered_messages ),
				'content_bytes'          => self::sumMessageContentBytes( $rendered_messages ),
				'json_bytes'             => self::jsonBytes( $rendered_messages ),
			);
		}

		$validated_outputs  = DirectiveOutputValidator::validateOutputs( $directive_outputs, $validation_context );
		$directive_messages = DirectiveRenderer::renderMessages( $validated_outputs );

		return array(
			'messages'            => AgentMessageEnvelope::normalize_many( array_merge( $directive_messages, $conversation_messages ) ),
			'tools'               => $this->tools,
			'applied_directives'  => $applied_directives,
			'directive_metadata'  => $directive_metadata,
			'directive_breakdown' => $directive_breakdown,
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

	private static function jsonBytes( array $value ): int {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? strlen( $json ) : 0;
	}

	private static function sumMessageContentBytes( array $messages ): int {
		$total = 0;
		foreach ( $messages as $message ) {
			$content = $message['content'] ?? '';
			if ( is_string( $content ) ) {
				$total += strlen( $content );
			} elseif ( is_array( $content ) ) {
				$total += self::jsonBytes( $content );
			}
		}
		return $total;
	}
}
