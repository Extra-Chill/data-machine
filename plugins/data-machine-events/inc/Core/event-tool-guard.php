<?php
/**
 * Event AI tool guard.
 *
 * Events pipelines are shaped `event_import → ai → upsert` where the upsert
 * handler is `upsert_event` (creates `data_machine_events` posts). The AI step
 * is the only place the model can decide to publish — and it is supposed to
 * reach ONLY the events upsert tool plus the fetch disposition tools
 * (`reject_source` / `defer_item`) and read/research tools.
 *
 * Data Machine core, however, exposes generic content-publishing ability tools
 * (`upsert_post`, `insert_content`) to EVERY pipeline AI step by default: they
 * declare `modes` including `pipeline`, and `ToolPolicyResolver` only narrows
 * the set when a step carries an explicit `enabled_tools` allow-list. Events
 * flows were seeded with `enabled_tools: []` (no allow-list), so the events AI
 * agent could reach past `upsert_event` and publish plain `post`-type junk
 * ("rejection reports", "admin logs"). On events.extrachill.com this leaked
 * 224 junk `post` items across ~75 flows (see issue #412).
 *
 * Events pipelines are hand-built in the DM UI and stored as flow_config data;
 * this plugin does not seed them programmatically, so a code default for new
 * flows could not retroactively protect the existing flow rows. Instead this
 * guard hooks DM core's `datamachine_resolved_tools` filter and strips the
 * generic content-writing tools whenever the AI step being resolved is
 * adjacent to an `upsert_event` upsert step. Detection keys off the events
 * handler shape (`upsert_event` in an adjacent step's `handler_slugs`), never
 * off hardcoded flow IDs, so it covers every existing events flow at resolution
 * time with no data migration.
 *
 * The durable fix — making generic publish tools opt-in in DM core — is tracked
 * separately in Extra-Chill/data-machine#2852. This is the events-layer
 * mitigation: it is generic within this layer and becomes a harmless
 * belt-and-suspenders once the core fix lands.
 *
 * @package DataMachineEvents
 * @subpackage Core
 * @since 0.46.0
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The events upsert handler slug this guard keys off.
 */
const EVENT_UPSERT_HANDLER_SLUG = 'upsert_event';

/**
 * Register the resolved-tools guard.
 *
 * Hooks `datamachine_resolved_tools`, the single filter DM core fires after
 * assembling the tool set for any execution context (see ToolPolicyResolver).
 */
function register_event_tool_guard(): void {
	add_filter( 'datamachine_resolved_tools', __NAMESPACE__ . '\\apply_event_tool_guard', 10, 3 );
}

/**
 * Generic content-writing tool names stripped from events AI steps.
 *
 * These are the DM core ability/handler tools that can mint an arbitrary
 * WordPress post outside the `upsert_event` handler (and thus bypass
 * EventUpsert's junk-title guard and PostIdentityIndex). None of them is
 * something an events pipeline ever needs: the only legitimate publish path is
 * the adjacent `upsert_event` handler tool, which is preserved.
 *
 * - `upsert_post`      — UpsertPostAbility (modes: chat/pipeline/system).
 * - `insert_content`   — InsertContentAbility (modes: chat/pipeline/system/editor).
 * - `wordpress_publish`— generic publish-step handler tool (only present when a
 *                        publish step is adjacent; stripped defensively).
 *
 * @return string[] Lowercase tool names to strip.
 */
function event_ai_blocked_tools(): array {
	$tools = array(
		'upsert_post',
		'insert_content',
		'wordpress_publish',
	);

	/**
	 * Filter the generic content-writing tools stripped from events AI steps.
	 *
	 * Site operators can tighten or loosen the strip list without a code change.
	 * Tools listed here are removed from the resolved set ONLY when the AI step
	 * is adjacent to an `upsert_event` upsert; they are untouched for every
	 * other pipeline and for chat/system mode.
	 *
	 * @param string[] $tools Lowercase tool names to strip.
	 */
	$tools = apply_filters( 'data_machine_events_event_ai_blocked_tools', $tools );

	return array_values( array_unique( array_filter( array_map( 'strval', (array) $tools ) ) ) );
}

/**
 * Determine whether a resolved-tools request targets an events AI step.
 *
 * True when the resolution runs in pipeline mode AND one of the steps adjacent
 * to the AI step is an `upsert` step whose handler list contains
 * `upsert_event`. This mirrors the shape used by DM core's own
 * `FlowStepConfig::getAdjacentRequiredHandlerSlugsForAi` (which already trusts
 * the adjacent step configs for required-handler detection), so the signal is
 * exactly as reliable as the mechanism that wires the `upsert_event` tool
 * itself.
 *
 * Adjacent step configs are populated by `AIStep` only for pipeline AI steps,
 * so the predicate is inherently scoped to that context; the explicit
 * pipeline-mode check is defensive.
 *
 * @param array $args Resolved-tools filter args (modes + adjacent step configs).
 * @return bool True when this is an events AI step adjacent to upsert_event.
 */
function is_event_upsert_ai_step( array $args ): bool {
	$modes = is_array( $args['modes'] ?? null ) ? $args['modes'] : array( $args['mode'] ?? '' );
	if ( ! is_array( $modes ) ) {
		$modes = array( $modes );
	}
	$modes = array_map( 'strval', $modes );

	if ( ! in_array( 'pipeline', $modes, true ) ) {
		return false;
	}

	foreach ( array( $args['previous_step_config'] ?? null, $args['next_step_config'] ?? null ) as $step_config ) {
		if ( ! is_array( $step_config ) ) {
			continue;
		}

		$handler_slugs = is_array( $step_config['handler_slugs'] ?? null )
			? $step_config['handler_slugs']
			: array();

		if ( in_array( EVENT_UPSERT_HANDLER_SLUG, $handler_slugs, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Strip generic content-writing tools from an events AI step.
 *
 * Pure transform over the resolved tools array. Exposed (not just used as the
 * filter callback) so it can be unit-tested without firing the real filter.
 *
 * Preserves every other tool — `upsert_event`, `reject_source`, `defer_item`,
 * web/post read tools, duplicate-detection tools — untouched.
 *
 * @param array $tools Resolved tools keyed by tool name.
 * @param array $args  Resolved-tools filter args.
 * @return array Filtered tools, unchanged when this is not an events AI step.
 */
function strip_event_ai_content_tools( array $tools, array $args ): array {
	if ( ! is_event_upsert_ai_step( $args ) ) {
		return $tools;
	}

	$blocked = event_ai_blocked_tools();
	if ( array() === $blocked ) {
		return $tools;
	}

	$blocked_lookup = array_flip( $blocked );

	$removed = array();
	foreach ( $tools as $name => $tool ) {
		if ( isset( $blocked_lookup[ $name ] ) ) {
			unset( $tools[ $name ] );
			$removed[] = $name;
		}
	}

	if ( array() !== $removed && function_exists( 'do_action' ) ) {
		do_action(
			'datamachine_log',
			'debug',
			'Event Tool Guard: stripped generic content-writing tools from events AI step',
			array(
				'context'         => 'Event Tool Guard',
				'removed_tools'   => $removed,
				'retained_sample' => array_slice( array_keys( $tools ), 0, 20 ),
			)
		);
	}

	return $tools;
}

/**
 * `datamachine_resolved_tools` filter callback.
 *
 * @param array               $tools Resolved tools keyed by tool name.
 * @param string|array|string $mode  Active mode(s) — unused; gating is args-driven.
 * @param array               $args  Full resolution arguments.
 * @return array Filtered tools.
 */
function apply_event_tool_guard( array $tools, $mode, array $args ): array {
	unset( $mode );
	return strip_event_ai_content_tools( $tools, $args );
}
