<?php
/**
 * Run-scoped runtime tool source.
 *
 * Adapts client/transport-declared runtime tool definitions into the normal
 * tool source pipeline. These tools are visible to model requests only after
 * the existing ToolPolicyResolver/Agents API policy pass allows them; execution
 * remains outside PHP because declarations are marked with a client executor.
 *
 * @package DataMachine\Engine\AI\Tools\Sources
 */

namespace DataMachine\Engine\AI\Tools\Sources;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration;

defined( 'ABSPATH' ) || exit;

final class RuntimeToolSource {

	/**
	 * Gather normalized runtime tool declarations from resolver context.
	 *
	 * @param array $modes Agent mode slugs.
	 * @param array $args  Full resolution arguments.
	 * @return array Tools keyed by tool name.
	 */
	public function __invoke( array $modes, array $args = array() ): array {
		$tools = array();

		foreach ( $this->declarationsFromContext( $args ) as $entry ) {
			$declaration = $entry['declaration'] ?? null;
			if ( ! is_array( $declaration ) ) {
				$this->emitInvalidDeclarationDiagnostic( $entry['key'] ?? '', 'runtime_tool_declaration_must_be_an_object' );
				continue;
			}

			try {
				$normalized = WP_Agent_Tool_Declaration::normalize( $declaration );
			} catch ( \InvalidArgumentException $e ) {
				$this->emitInvalidDeclarationDiagnostic( $declaration['name'] ?? ( $entry['key'] ?? '' ), $e->getMessage() );
				continue;
			}

			$name           = (string) $normalized['name'];
			$tools[ $name ] = array_merge(
				$normalized,
				array(
					'modes'             => $modes,
					'access_level'      => 'public',
					'runtime_tool'      => true,
					'external_executor' => true,
					'requires_opt_in'   => true,
				)
			);
		}

		return $tools;
	}

	/**
	 * Extract declarations from explicit resolver args.
	 *
	 * Runtime adapters can add transport-specific declaration sets through the
	 * `datamachine_runtime_tool_declaration_sets` filter. Data Machine core only
	 * reads explicit resolver arguments and the namespaced `client_context.runtime_tools`
	 * envelope.
	 *
	 * @param array $args Full resolution arguments.
	 * @return array<int,array{key:string, declaration:mixed}> Runtime declarations.
	 */
	private function declarationsFromContext( array $args ): array {
		$sets = array_filter(
			array(
				$args['runtime_tool_declarations'] ?? null,
				$args['runtime_tools'] ?? null,
			),
			'is_array'
		);

		$client_context = is_array( $args['client_context'] ?? null ) ? $args['client_context'] : array();
		$runtime_tools  = is_array( $client_context['runtime_tools'] ?? null ) ? $client_context['runtime_tools'] : array();
		if ( is_array( $runtime_tools['declarations'] ?? null ) ) {
			$sets[] = $runtime_tools['declarations'];
		} elseif ( ! empty( $runtime_tools ) && $this->looksLikeDeclarationSet( $runtime_tools ) ) {
			$sets[] = $runtime_tools;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$sets = apply_filters( 'datamachine_runtime_tool_declaration_sets', $sets, $args, $client_context );
		}

		$declarations = array();
		foreach ( $sets as $set ) {
			if ( ! is_array( $set ) ) {
				continue;
			}

			foreach ( $set as $name => $declaration ) {
				if ( is_array( $declaration ) && is_string( $name ) && '' !== $name && empty( $declaration['name'] ) ) {
					$declaration['name'] = $name;
				}

				$declarations[] = array(
					'key'         => is_string( $name ) ? $name : (string) $name,
					'declaration' => $declaration,
				);
			}
		}

		return $declarations;
	}

	/**
	 * Heuristically detect the legacy compact map shape under runtime_tools.
	 *
	 * @param array<mixed> $set Candidate declaration set.
	 * @return bool Whether the set appears to contain declarations.
	 */
	private function looksLikeDeclarationSet( array $set ): bool {
		foreach ( $set as $entry ) {
			if ( is_array( $entry ) && ( isset( $entry['description'] ) || isset( $entry['parameters'] ) || isset( $entry['input_schema'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Emit a bounded diagnostic without including the raw runtime declaration.
	 *
	 * @param mixed  $name_or_key Declaration name/key supplied by the caller.
	 * @param string $reason      Validation failure reason.
	 * @return void
	 */
	private function emitInvalidDeclarationDiagnostic( $name_or_key, string $reason ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action(
			'datamachine_log',
			'warning',
			'Invalid runtime tool declaration skipped',
			array(
				'code'  => 'invalid_runtime_tool_declaration',
				'tool'  => $this->boundedDiagnosticString( is_scalar( $name_or_key ) ? (string) $name_or_key : 'unknown' ),
				'error' => $this->boundedDiagnosticString( $reason ),
			)
		);
	}

	/**
	 * Bound diagnostic strings so logs identify the failure without carrying payloads.
	 *
	 * @param string $value Raw diagnostic value.
	 * @return string Bounded diagnostic value.
	 */
	private function boundedDiagnosticString( string $value ): string {
		$value = trim( preg_replace( '/\s+/', ' ', $value ) ?? '' );
		if ( '' === $value ) {
			return 'unknown';
		}

		if ( strlen( $value ) <= 160 ) {
			return $value;
		}

		return substr( $value, 0, 120 ) . '...' . substr( hash( 'sha256', $value ), 0, 12 );
	}
}
