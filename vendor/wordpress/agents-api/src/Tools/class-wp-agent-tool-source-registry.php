<?php
/**
 * Tool source registry.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Composes tool declarations from named sources before runtime policy filtering.
 */
class WP_Agent_Tool_Source_Registry {

	/**
	 * @var array<string, array{callback: callable, priority: int, index: int}>
	 */
	private array $sources = array();

	/**
	 * Monotonic registration index used to keep same-priority ordering stable.
	 *
	 * @var int
	 */
	private int $registration_index = 0;

	/**
	 * Register a source callback.
	 *
	 * Source callbacks receive `(array $context, WP_Agent_Tool_Source_Registry $registry)` and
	 * return tool declarations keyed by tool name.
	 *
	 * @param string   $source_slug Source slug.
	 * @param callable $source      Source callback.
	 * @param int      $priority    Source priority. Lower numbers run earlier.
	 * @return void
	 */
	public function registerSource( string $source_slug, callable $source, int $priority = 10 ): void {
		if ( '' === $source_slug ) {
			throw new \InvalidArgumentException( 'invalid_tool_source: source_slug must be a non-empty string' );
		}

		$this->sources[ $source_slug ] = array(
			'callback' => $source,
			'priority' => $priority,
			'index'    => $this->registration_index++,
		);
	}

	/**
	 * Remove a source callback.
	 *
	 * @param string $source_slug Source slug.
	 * @return void
	 */
	public function unregisterSource( string $source_slug ): void {
		unset( $this->sources[ $source_slug ] );
	}

	/**
	 * Return registered source callbacks.
	 *
	 * @param array<mixed> $context Runtime context.
	 * @return array<string, callable>
	 */
	public function getSources( array $context = array() ): array {
		$sources = $this->getRegisteredSources();
		if ( function_exists( 'apply_filters' ) ) {
			$sources = apply_filters( 'agents_api_tool_sources', $sources, $context, $this );
		}

		if ( ! is_array( $sources ) ) {
			return array();
		}

		$callbacks = array();
		foreach ( $sources as $source_slug => $source ) {
			if ( is_string( $source_slug ) && is_callable( $source ) ) {
				$callbacks[ $source_slug ] = $source;
			}
		}

		return $callbacks;
	}

	/**
	 * Gather tools from registered sources in source order.
	 *
	 * Earlier sources win when two sources return the same tool name.
	 *
	 * @param array<mixed> $context Runtime context.
	 * @return array<string, array<mixed>> Tool declarations keyed by tool name.
	 */
	public function gather( array $context = array() ): array {
		$tools   = array();
		$sources = $this->getSources( $context );
		$order   = $this->getSourceOrder( $sources, $context );

		foreach ( $order as $source_slug ) {
			if ( ! isset( $sources[ $source_slug ] ) ) {
				continue;
			}

			$source_tools = call_user_func( $sources[ $source_slug ], $context, $this );
			if ( function_exists( 'apply_filters' ) ) {
				$source_tools = apply_filters(
					'agents_api_tool_source_tools',
					$source_tools,
					$source_slug,
					$context,
					$this
				);
			}

			if ( ! is_array( $source_tools ) ) {
				continue;
			}

			foreach ( $source_tools as $tool_name => $tool_definition ) {
				if ( ! is_string( $tool_name ) || isset( $tools[ $tool_name ] ) || ! is_array( $tool_definition ) ) {
					continue;
				}

				$normalized = $this->normalizeGatheredTool( $tool_name, $source_slug, $tool_definition );
				if ( ! empty( $normalized ) ) {
					$tools[ $tool_name ] = $normalized;
				}
			}
		}

		return $tools;
	}

	/**
	 * Return directly registered sources sorted by priority and registration order.
	 *
	 * @return array<string, callable>
	 */
	private function getRegisteredSources(): array {
		$sources = $this->sources;
		uasort(
			$sources,
			static function ( array $a, array $b ): int {
				return ( $a['priority'] <=> $b['priority'] ) ?: ( $a['index'] <=> $b['index'] );
			}
		);

		$callbacks = array();
		foreach ( $sources as $source_slug => $source ) {
			$callbacks[ $source_slug ] = $source['callback'];
		}

		return $callbacks;
	}

	/**
	 * Return source slugs in final precedence order.
	 *
	 * @param array<string, callable> $sources Registered sources.
	 * @param array<mixed>                   $context Runtime context.
	 * @return array<int, string>
	 */
	private function getSourceOrder( array $sources, array $context ): array {
		$order = array_keys( $sources );

		if ( function_exists( 'apply_filters' ) ) {
			$order = apply_filters(
				'agents_api_tool_source_order',
				$order,
				$context,
				$this,
				$sources
			);
		}

		if ( ! is_array( $order ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$order,
				static fn( $source_slug ): bool => is_string( $source_slug ) && isset( $sources[ $source_slug ] )
			)
		);
	}

	/**
	 * Normalize source metadata on a gathered declaration.
	 *
	 * @param string $tool_name       Tool identifier.
	 * @param string $source_slug     Source slug.
	 * @param array<mixed>  $tool_definition Raw tool declaration.
	 * @return array<string, mixed>
	 */
	private function normalizeGatheredTool( string $tool_name, string $source_slug, array $tool_definition ): array {
		$tool_definition['name'] = is_string( $tool_definition['name'] ?? null ) && '' !== $tool_definition['name'] ? $tool_definition['name'] : $tool_name;

		if ( ! is_string( $tool_definition['description'] ?? null ) || '' === trim( $tool_definition['description'] ) ) {
			$tool_definition['description'] = $tool_definition['name'];
		}

		if ( ! is_string( $tool_definition['source'] ?? null ) || '' === $tool_definition['source'] ) {
			$tool_definition['source'] = 0 === strpos( $tool_definition['name'], 'client/' ) ? WP_Agent_Tool_Declaration::SOURCE_CLIENT : $source_slug;
		}

		try {
			$normalized = array();
			foreach ( WP_Agent_Tool_Declaration::normalizeForConversationRequest( $tool_definition ) as $key => $value ) {
				if ( is_string( $key ) ) {
					$normalized[ $key ] = $value;
				}
			}

			return $normalized;
		} catch ( \InvalidArgumentException $error ) {
			unset( $error );
			return array();
		}
	}
}
