<?php
/**
 * Provider request assembler.
 *
 * Builds the provider request payload without Data Machine dispatch, logging, or
 * directive discovery policy. Callers pass the directive list they want applied.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

use AgentsAPI\AI\AgentMessageEnvelope;

defined( 'ABSPATH' ) || exit;

class ProviderRequestAssembler {

	/**
	 * Assemble a provider request without dispatching it.
	 *
	 * @param array  $messages   Initial canonical message envelopes.
	 * @param string $provider   AI provider name.
	 * @param string $model      Model identifier.
	 * @param array  $tools      Raw tools array from filters or runtime declarations.
	 * @param string $mode       Execution mode.
	 * @param array  $payload    Request payload.
	 * @param array  $directives Directive configs already selected by the caller.
	 * @return array Assembled request and inspection metadata.
	 */
	public function assemble(
		array $messages,
		string $provider,
		string $model,
		array $tools,
		string $mode,
		array $payload = array(),
		array $directives = array()
	): array {
		$structured_tools = self::restructureTools( $tools );

		$prompt_builder = new PromptBuilder();
		$prompt_builder->setMessages( $messages )->setTools( $structured_tools );

		foreach ( $directives as $directive ) {
			$prompt_builder->addDirective(
				$directive['class'],
				$directive['priority'],
				$directive['modes'] ?? array( 'all' )
			);
		}

		$request             = $prompt_builder->buildDetailed( $mode, $provider, $payload );
		$request['messages'] = AgentMessageEnvelope::normalize_many( $request['messages'] ?? array() );
		$applied_directives  = $request['applied_directives'] ?? array();
		$directive_metadata  = $request['directive_metadata'] ?? array();
		$directive_breakdown = $request['directive_breakdown'] ?? array();
		unset( $request['applied_directives'], $request['directive_metadata'], $request['directive_breakdown'] );
		$request['model'] = $model;

		return array(
			'request'             => $request,
			'structured_tools'    => $structured_tools,
			'applied_directives'  => $applied_directives,
			'directive_metadata'  => $directive_metadata,
			'directive_breakdown' => $directive_breakdown,
		);
	}

	/**
	 * Convert assembled request messages into provider-facing message arrays.
	 *
	 * @param array $request Assembled request.
	 * @return array Provider request.
	 */
	public static function toProviderRequest( array $request ): array {
		$provider_request             = $request;
		$provider_request['messages'] = AgentMessageEnvelope::to_provider_messages( $request['messages'] ?? array() );
		return $provider_request;
	}

	/**
	 * Normalize raw tool definitions to the provider request shape.
	 *
	 * @param array $raw_tools Raw tools keyed by name.
	 * @return array Structured tools with explicit fields.
	 */
	public static function restructureTools( array $raw_tools ): array {
		$structured = array();

		foreach ( $raw_tools as $tool_name => $tool_config ) {
			$structured[ $tool_name ] = array(
				'name'           => $tool_name,
				'description'    => $tool_config['description'] ?? '',
				'parameters'     => $tool_config['parameters'] ?? array(),
				'handler'        => $tool_config['handler'] ?? null,
				'handler_config' => $tool_config['handler_config'] ?? array(),
			);
		}

		return $structured;
	}
}
