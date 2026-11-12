<?php
/**
 * Template-string binding resolver for workflow steps.
 *
 * A workflow step's `args` (or `message`, or any other field a runner asks
 * the resolver to expand) may contain `${...}` references that pull values
 * from the workflow's static inputs, previous steps' outputs, or scoped
 * runtime variables:
 *
 *     ${inputs.<dot.path>}            // workflow input
 *     ${steps.<step_id>.output.<dot.path>}  // earlier step output
 *     ${vars.<name>.<dot.path>}       // loop/runtime variable
 *
 * Resolution is deliberately conservative: the helper substitutes whole
 * template expressions atomically (so binding a non-string value into a
 * non-string slot replaces the slot, not just a substring of it), and a
 * binding that targets a missing path returns `null` rather than the
 * literal `${...}` string. Callers that need stricter behavior can check
 * for `null` after expansion.
 *
 * The token vocabulary is intentionally minimal — `inputs.*`,
 * `steps.<id>.output.*`, and `vars.*`. Anything else (env vars,
 * user-context, secrets) needs to be introduced via a dedicated runtime hook
 * so the contract stays auditable.
 *
 * @package AgentsAPI
 * @since   0.103.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helper. No state, no construction — call the static methods.
 *
 * @since 0.103.0
 */
final class WP_Agent_Workflow_Bindings {

	/**
	 * Pattern for a single `${...}` binding. Matches lazily so multiple
	 * templates on one line resolve independently.
	 *
	 * @since 0.103.0
	 *
	 * @var string
	 */
	private const TEMPLATE_PATTERN = '/\$\{([^}]+)\}/';

	/**
	 * Recursively expand bindings inside `$value`. Strings get their
	 * `${...}` tokens substituted; arrays are walked depth-first; scalars
	 * other than strings pass through unchanged.
	 *
	 * @since 0.103.0
	 *
	 * @param mixed $value   Raw spec fragment (string, array, scalar).
	 * @param array<mixed> $context Resolution context. Required keys:
	 *                       - `inputs` (array): workflow input values.
	 *                       - `steps`  (array<string,array>): each entry
	 *                         shaped `[ 'output' => mixed ]`, keyed by step id.
	 * @return mixed Expanded value. Strings whose entire content was a single
	 *               template expression are returned as the underlying value
	 *               (preserving its type).
	 */
	public static function expand( $value, array $context ) {
		if ( is_array( $value ) ) {
			$expanded = array();
			foreach ( $value as $key => $inner ) {
				$expanded[ $key ] = self::expand( $inner, $context );
			}
			return $expanded;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		// Whole-string atomic substitution: if the value is exactly one
		// template expression, return the resolved value with its native
		// type — caller asked for an array / int / null and that's what
		// they get, not a stringified version.
		if ( preg_match( '/^\$\{([^}]+)\}$/', $value, $matches ) ) {
			return self::resolve_path( trim( $matches[1] ), $context );
		}

		// Mixed content: substitute every template, coercing each
		// resolved value to string. Missing paths → empty string.
		return (string) preg_replace_callback(
			self::TEMPLATE_PATTERN,
			static function ( array $m ) use ( $context ): string {
				$resolved = self::resolve_path( trim( $m[1] ), $context );
				if ( null === $resolved || is_array( $resolved ) || is_object( $resolved ) ) {
					return '';
				}
				if ( is_bool( $resolved ) ) {
					return $resolved ? 'true' : 'false';
				}
				return is_scalar( $resolved ) ? (string) $resolved : '';
			},
			$value
		);
	}

	/**
	 * Resolve a single `inputs.<path>` or `steps.<id>.output.<path>`
	 * reference against the resolution context. Returns null on miss
	 * (rather than the original token) so callers can distinguish
	 * "missing" from "intentionally empty string".
	 *
	 * @since 0.103.0
	 *
	 * @param string $expression Path expression without the surrounding `${}`.
	 * @param array<mixed>  $context    Resolution context (see {@see expand()}).
	 * @return mixed|null
	 */
	public static function resolve_path( string $expression, array $context ) {
		$segments = array_values(
			array_filter(
				explode( '.', $expression ),
				static fn ( string $segment ): bool => '' !== $segment
			)
		);
		if ( count( $segments ) < 2 ) {
			return null;
		}

		$root = array_shift( $segments );

		if ( 'inputs' === $root ) {
			$source = $context['inputs'] ?? array();
			return self::walk( $source, self::string_list( $segments ) );
		}

		if ( 'steps' === $root ) {
			$step_id = array_shift( $segments );
			if ( null === $step_id ) {
				return null;
			}
			$steps = $context['steps'] ?? array();
			if ( ! is_array( $steps ) ) {
				return null;
			}
			$step = $steps[ $step_id ] ?? null;
			if ( ! is_array( $step ) ) {
				return null;
			}
			// `steps.<id>.output.<path>` is the only supported shape today.
			$next = array_shift( $segments );
			if ( 'output' !== $next ) {
				return null;
			}
			return self::walk( $step['output'] ?? null, self::string_list( $segments ) );
		}

		if ( 'vars' === $root ) {
			$source = $context['vars'] ?? array();
			return self::walk( $source, self::string_list( $segments ) );
		}

		return null;
	}

	/**
	 * Walk a dot-separated path into a nested array. Supports numeric
	 * indices for arrays. Returns the located value or null on miss.
	 *
	 * @since 0.103.0
	 *
	 * @param mixed         $value
	 * @param array<string> $segments
	 * @return mixed|null
	 */
	private static function walk( $value, array $segments ) {
		foreach ( $segments as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
				continue;
			}
			if ( is_array( $value ) && ctype_digit( (string) $segment ) && array_key_exists( (int) $segment, $value ) ) {
				$value = $value[ (int) $segment ];
				continue;
			}
			return null;
		}
		return $value;
	}

	/**
	 * @param array<mixed> $segments Raw path segments.
	 * @return array<int,string>
	 */
	private static function string_list( array $segments ): array {
		$prepared = array();
		foreach ( $segments as $segment ) {
			if ( is_string( $segment ) && '' !== $segment ) {
				$prepared[] = $segment;
			}
		}

		return $prepared;
	}
}
