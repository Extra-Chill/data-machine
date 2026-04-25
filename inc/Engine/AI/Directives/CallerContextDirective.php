<?php
/**
 * Caller Context Directive.
 *
 * Renders server-authenticated caller identity (who made this cross-site
 * A2A request, which chain, which hop) as a system message so the receiving
 * agent knows it is being called by a peer agent rather than a human.
 *
 * Data source is {@see \DataMachine\Abilities\PermissionHelper::get_caller_context()},
 * populated by {@see \DataMachine\Core\Auth\AgentAuthMiddleware} after bearer-token
 * resolution from the four A2A headers (`X-Datamachine-Caller-Site`,
 * `-Caller-Agent`, `-Chain-Id`, `-Chain-Depth`). This data is authenticated —
 * it cannot be spoofed by the client because the headers are read from the
 * incoming HTTP request and validated by the middleware.
 *
 * Distinct from {@see ClientContextDirective} (priority 35) which renders
 * free-form, caller-controlled `client_context` payload. Caller context is
 * trusted server-side provenance; client context is untrusted frontend
 * state. Both are legitimate and complementary.
 *
 * Only emits output when the current request is a genuine cross-site A2A
 * call (`PermissionHelper::in_cross_site_context()`). Local and top-of-chain
 * requests produce no output.
 *
 * Priority 25: between AgentModeDirective (22) and ClientContextDirective
 * (35). Caller context is a property of the authenticated request, rendered
 * before client-reported state so the agent knows WHO is asking before it
 * sees WHAT the client is showing.
 *
 * @package DataMachine\Engine\AI\Directives
 * @since 0.72.0
 */

namespace DataMachine\Engine\AI\Directives;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class CallerContextDirective {

	/**
	 * Produce directive outputs from the authenticated caller context.
	 *
	 * @param string      $provider_name AI provider identifier.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID (null in chat).
	 * @param array       $payload       Request payload (unused — caller context
	 *                                   is read from PermissionHelper, not payload).
	 * @return array Directive outputs (one system_text entry, or empty array).
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		$caller = PermissionHelper::get_caller_context();

		// Only render for genuine cross-site A2A calls. Local calls (admin UI,
		// CLI, same-site) produce no output — the normal directive chain is
		// sufficient.
		if ( null === $caller || ! $caller->isCrossSite() ) {
			return array();
		}

		$caller_site  = $caller->callerSite();
		$caller_agent = $caller->callerAgent();
		$chain_id     = $caller->chainId();
		$chain_depth  = $caller->chainDepth();

		$lines   = array();
		$lines[] = '# Cross-Site Caller Context';
		$lines[] = '';
		$lines[] = 'This request arrived from another agent on a different site via the Data Machine agent-to-agent (A2A) chat API. The caller identity below is **authenticated** — it was validated by the auth middleware, not self-reported by the client.';
		$lines[] = '';

		if ( '' !== $caller_agent ) {
			$lines[] = sprintf( '- **Caller agent:** `%s`', $caller_agent );
		}

		if ( '' !== $caller_site ) {
			$lines[] = sprintf( '- **Caller site:** `%s`', $caller_site );
		}

		$lines[] = sprintf( '- **Chain depth:** %d', $chain_depth );
		$lines[] = sprintf( '- **Chain id:** `%s`', $chain_id );
		$lines[] = '';
		$lines[] = '## Response Protocol';
		$lines[] = '';
		$lines[] = 'You are responding to a peer agent, not a human. Adjust accordingly:';
		$lines[] = '';
		$lines[] = '- **Be terse.** Skip greetings, pleasantries, and meta-commentary about what you\'re about to do.';
		$lines[] = '- **Be structured.** Prefer bulleted lists, tables, or JSON-like shapes when returning data. The caller is another LLM and can parse structure.';
		$lines[] = '- **Skip context you don\'t have.** Don\'t ask clarifying questions unless absolutely necessary — the caller agent already decided what to ask. Give your best answer based on what you know.';
		$lines[] = '- **Don\'t explain your reasoning unless asked.** Return the answer, not the thought process.';
		$lines[] = '- **Mind the chain budget.** You are at hop %d. Each tool call or further A2A hop increments the depth — at the ceiling the chain is refused. Keep the response self-contained when possible.';

		$content = implode( "\n", $lines );
		$content = sprintf( $content, $chain_depth );

		return array(
			array(
				'type'    => 'system_text',
				'content' => $content,
			),
		);
	}
}

// Self-register in the directive system.
// Priority 25 = after AgentModeDirective (22), before ClientContextDirective (35).
// Caller context is a property of the authenticated request; it's rendered
// before client-reported state so the agent knows "who's talking" before it
// sees "what the client is showing".
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => CallerContextDirective::class,
			'priority' => 25,
			'modes'    => array( 'all' ),
		);
		return $directives;
	}
);
