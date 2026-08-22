<?php
/**
 * Canonical outbound message ability registration.
 *
 * Registers `agents/dispatch-message` as the stable handoff from workflows,
 * agents, routines, and product plugins to an outbound channel transport.
 * Agents API owns the contract and dispatch hook; consumers own the actual
 * delivery runtime by filtering `wp_agent_dispatch_message_handler`.
 *
 * @package AgentsAPI
 * @since   0.107.0
 */

namespace AgentsAPI\AI\Channels;

defined( 'ABSPATH' ) || exit;

const AGENTS_DISPATCH_MESSAGE_ABILITY = 'agents/dispatch-message';

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		if ( wp_has_ability_category( 'agents-api' ) ) {
			return;
		}

		wp_register_ability_category(
			'agents-api',
			array(
				'label'       => 'Agents API',
				'description' => 'Cross-cutting abilities provided by the Agents API substrate (channel dispatch, canonical chat contract, and workflow dispatch).',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( wp_has_ability( AGENTS_DISPATCH_MESSAGE_ABILITY ) ) {
			return;
		}

		wp_register_ability(
			AGENTS_DISPATCH_MESSAGE_ABILITY,
			array(
				'label'               => 'Dispatch Message',
				'description'         => 'Canonical entry point for sending one outbound message through a channel transport. Dispatches to whichever runtime is registered via the wp_agent_dispatch_message_handler filter.',
				'category'            => 'agents-api',
				'input_schema'        => agents_dispatch_message_input_schema(),
				'output_schema'       => agents_dispatch_message_output_schema(),
				'execute_callback'    => __NAMESPACE__ . '\\agents_dispatch_message_dispatch',
				'permission_callback' => __NAMESPACE__ . '\\agents_dispatch_message_permission',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
	}
);

/**
 * Dispatch an outbound message to the registered runtime.
 *
 * @since 0.107.0
 *
 * @param array<mixed> $input Canonical dispatch-message input.
 * @return array<mixed>|\WP_Error Canonical output, or WP_Error if no runtime is registered.
 */
function agents_dispatch_message_dispatch( array $input ) {
	/**
	 * Filter the outbound message runtime handler.
	 *
	 * The first hook to return a callable wins. Handlers receive the canonical
	 * input map and must return either the canonical output shape or WP_Error.
	 *
	 * @since 0.107.0
	 *
	 * @param callable|null $handler Currently registered handler.
	 * @param array<mixed>         $input   Canonical dispatch-message input.
	 */
	$handler = apply_filters( 'wp_agent_dispatch_message_handler', null, $input );

	if ( ! is_callable( $handler ) ) {
		do_action( 'agents_dispatch_message_failed', 'no_handler', $input );

		return new \WP_Error(
			'agents_dispatch_message_no_handler',
			'No agents/dispatch-message handler is registered. Install a channel runtime, or add a callable to the wp_agent_dispatch_message_handler filter.'
		);
	}

	$result = call_user_func( $handler, $input );

	if ( is_wp_error( $result ) ) {
		do_action( 'agents_dispatch_message_failed', $result->get_error_code(), $input );
		return $result;
	}

	if ( ! is_array( $result ) ) {
		do_action( 'agents_dispatch_message_failed', 'invalid_result', $input );
		return new \WP_Error(
			'agents_dispatch_message_invalid_result',
			'agents/dispatch-message handler returned an unexpected result type. Handlers must return an array matching the canonical output shape or a WP_Error.'
		);
	}

	return $result;
}

/**
 * Permission gate for `agents/dispatch-message`.
 *
 * @since 0.107.0
 *
 * @param array<mixed> $input Canonical dispatch-message input.
 */
function agents_dispatch_message_permission( array $input ): bool {
	return (bool) apply_filters(
		'agents_dispatch_message_permission',
		current_user_can( 'manage_options' ),
		$input
	);
}

/**
 * Canonical input schema.
 *
 * @since 0.107.0
 * @return array<string, mixed>
 */
function agents_dispatch_message_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'channel', 'recipient', 'message' ),
		'properties' => array(
			'channel'         => array(
				'type'        => 'string',
				'description' => 'Outbound channel/transport identifier — an opaque, product-defined id.',
			),
			'recipient'       => array(
				'type'        => 'string',
				'description' => 'Transport-specific destination id, as defined by the channel implementation (e.g. an account id, address, or handle).',
			),
			'message'         => array(
				'type'        => 'string',
				'description' => 'Text body to send.',
			),
			'conversation_id' => array(
				'type'        => array( 'string', 'null' ),
				'description' => 'Optional transport conversation/thread id.',
			),
			'attachments'     => array(
				'type'        => 'array',
				'default'     => array(),
				'items'       => array( 'type' => 'object' ),
				'description' => 'Optional transport-defined attachments.',
			),
			'client_context'  => array(
				'type'        => 'object',
				'description' => 'Optional caller/runtime context.',
			),
			'metadata'        => array(
				'type'        => 'object',
				'description' => 'Opaque caller metadata for transport runtimes and audit logs.',
			),
		),
	);
}

/**
 * Canonical output schema.
 *
 * @since 0.107.0
 * @return array<string, mixed>
 */
function agents_dispatch_message_output_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'sent', 'channel', 'recipient' ),
		'properties' => array(
			'sent'       => array( 'type' => 'boolean' ),
			'channel'    => array( 'type' => 'string' ),
			'recipient'  => array( 'type' => 'string' ),
			'message_id' => array( 'type' => array( 'string', 'null' ) ),
			'metadata'   => array( 'type' => 'object' ),
		),
	);
}

/**
 * Convenience helper for consumers.
 *
 * @since 0.107.0
 *
 * @param callable $handler Receives canonical input, returns canonical output or WP_Error.
 * @param int      $priority Filter priority.
 */
function register_dispatch_message_handler( callable $handler, int $priority = 10 ): void {
	add_filter(
		'wp_agent_dispatch_message_handler',
		static function ( $existing, array $input ) use ( $handler ) {
			unset( $input );
			if ( null !== $existing ) {
				return $existing;
			}
			return $handler;
		},
		$priority,
		2
	);
}
