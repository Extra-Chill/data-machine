=== Agents API ===
Contributors: automattic
Tags: ai, agents, automation, workflows, tools
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shared WordPress runtime substrate for registering AI agents, mediating tools, running workflows, and building product-specific agent experiences.

== Description ==

Agents API is a shared foundation for building AI agents in WordPress.

New to the project? Start with the Introduction, which breaks down the core concepts and vocabulary in plain language: https://github.com/Automattic/agents-api/blob/main/docs/introduction.md

It provides the reusable substrate that product plugins need when they add agent-backed features: agent registration, runtime value objects, tool mediation contracts, transcript and memory interfaces, approval primitives, messaging-channel adapters, and lightweight workflow scaffolding.

Agents API is intentionally not a full agent product. It does not provide admin screens, provider-specific model execution, product workflows, concrete storage implementations, or an end-user chat application. Those pieces belong to consumer plugins.

= What Agents API Provides =

* Agent registration and lookup helpers.
* Messaging channel contracts for external transports.
* Runtime message, request, result, completion, and iteration-budget value objects.
* Tool declaration, parameter normalization, tool-call mediation, and tool-result contracts.
* Conversation transcript, memory, context, consent, approval, and authorization contracts.
* Workflow spec, validation, runner, registry, and optional Action Scheduler bridge primitives.
* Canonical abilities for agent chat, workflow execution, pending actions, and access checks.

= What Agents API Does Not Provide =

* Provider-specific model request code.
* Product UI, onboarding, dashboards, or settings screens.
* Concrete product workflows or product-specific tools.
* Durable production storage implementations for every host.
* Product-specific prompt policy, escalation policy, or consent UX.

= Core-Candidate API Naming =

Agents API intentionally exposes WordPress-shaped public APIs such as `wp_register_agent()`, `wp_get_agent()`, `wp_agents_api_init`, and `wp_agent_*` hooks.

These names are not accidental plugin globals. They are the public API surface being evaluated for possible WordPress Core alignment, following existing Core naming conventions for substrate APIs. Plugin Check may report prefix warnings for these symbols; those warnings are expected for this Core-candidate package and should be reviewed in that context.

== Installation ==

Install and activate Agents API like any other WordPress plugin.

Consumer plugins should register agents after the `wp_agents_api_init` action and should feature-detect public APIs when Agents API is an optional dependency.

Example:

`
add_action(
	'wp_agents_api_init',
	static function () {
		if ( function_exists( 'wp_register_agent' ) ) {
			wp_register_agent(
				'example-agent',
				array(
					'label' => 'Example Agent',
				)
			);
		}
	}
);
`

== Frequently Asked Questions ==

= Is this a complete AI chatbot plugin? =

No. Agents API is the substrate for other plugins. It provides shared contracts and primitives so product plugins do not need to invent their own agent runtime plumbing.

= Does Agents API send prompts to an AI provider? =

No. Provider-specific prompt execution belongs to `wp-ai-client` or a host plugin adapter. Agents API defines the neutral runtime contracts around agents, tools, transcripts, memory, and workflows.

= Why does this plugin expose `wp_*` functions? =

Agents API is designed as a Core-candidate substrate. Its public API intentionally follows WordPress Core naming conventions so consumer code can target the intended Core-shaped surface.

== Changelog ==

= 0.1.0 =

* Initial public substrate release for agent registration, runtime contracts, tool mediation, approvals, authorization, channels, routines, and workflows.
