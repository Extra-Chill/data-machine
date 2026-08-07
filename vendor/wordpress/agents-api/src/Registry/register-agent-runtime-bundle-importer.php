<?php
/**
 * Runtime agent bundle importer.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_agent_import_runtime_bundles' ) ) {
	/**
	 * Import one or more runtime agent bundle specs through the generic importer seam.
	 *
	 * Specs may include an inline `bundle` object or a `source` path to a JSON file.
	 * Concrete storage stays with the active registry/materialization adapter; this
	 * helper only normalizes transport and produces stable per-item result envelopes.
	 *
	 * @param array<int,mixed>    $bundle_specs Runtime bundle specs.
	 * @param array<string,mixed> $input        Shared import input.
	 * @return list<array<string,mixed>> Per-spec import results.
	 */
	function wp_agent_import_runtime_bundles( array $bundle_specs, array $input = array() ): array {
		$results = array();
		foreach ( $bundle_specs as $index => $spec ) {
			if ( ! is_array( $spec ) ) {
				$results[] = wp_agent_runtime_bundle_import_result( array(
					'success' => false,
					'index'   => $index,
					'error'   => array(
						'code'    => 'wp_agent_runtime_bundle_spec_invalid',
						'message' => 'Runtime agent bundle spec must be an object.',
					),
				) );
				continue;
			}

			$result = apply_filters( 'wp_agent_runtime_import_bundle', null, $spec, $input, $index );
			if ( is_wp_error( $result ) ) {
				$results[] = wp_agent_runtime_bundle_import_result( array(
					'success' => false,
					'index'   => $index,
					'error'   => array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
						'data'    => $result->get_error_data(),
					),
				) );
				continue;
			}

			if ( null === $result ) {
				$results[] = wp_agent_runtime_bundle_import_result( array(
					'success' => false,
					'index'   => $index,
					'error'   => array(
						'code'    => 'wp_agent_runtime_bundle_unclaimed',
						'message' => 'No runtime bundle importer accepted this spec.',
					),
				) );
				continue;
			}

			$normalized          = is_array( $result ) ? $result : array( 'result' => $result );
			$normalized['index'] = $index;
			if ( ! array_key_exists( 'success', $normalized ) ) {
				$normalized['success'] = true;
			}
			$results[] = wp_agent_runtime_bundle_import_result( $normalized );
		}

		return $results;
	}
}

if ( ! function_exists( 'wp_agent_runtime_bundle_import_result' ) ) {
	/**
	 * Normalize one runtime bundle import result to a string-keyed envelope.
	 *
	 * @param array<mixed> $result Raw import result.
	 * @return array<string,mixed>
	 */
	function wp_agent_runtime_bundle_import_result( array $result ): array {
		$normalized = array();
		foreach ( $result as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}
		return $normalized;
	}
}

if ( ! function_exists( 'wp_agent_runtime_bundle_from_source' ) ) {
	/**
	 * Load and decode a runtime bundle JSON file.
	 *
	 * @param string $source Source path.
	 * @param int    $index  Spec index for error metadata.
	 * @return array<string,mixed>|WP_Error Bundle array or error.
	 */
	function wp_agent_runtime_bundle_from_source( string $source, int $index ) {
		$source = trim( $source );
		if ( '' === $source || ! is_readable( $source ) ) {
			return new WP_Error(
				'wp_agent_runtime_bundle_source_unreadable',
				'Runtime agent bundle source is not readable.',
				array( 'index' => $index )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Runtime bundle sources are local JSON files, not remote URLs.
		$contents = file_get_contents( $source );
		if ( false === $contents ) {
			return new WP_Error(
				'wp_agent_runtime_bundle_source_read_failed',
				'Runtime agent bundle source could not be read.',
				array( 'index' => $index )
			);
		}

		try {
			$bundle = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			return new WP_Error(
				'wp_agent_runtime_bundle_source_invalid_json',
				'Runtime agent bundle source must contain valid JSON.',
				array(
					'index'  => $index,
					'reason' => $error->getMessage(),
				)
			);
		}

		if ( ! is_array( $bundle ) ) {
			return new WP_Error(
				'wp_agent_runtime_bundle_source_invalid_shape',
				'Runtime agent bundle source must decode to an object.',
				array( 'index' => $index )
			);
		}

		$normalized = array();
		foreach ( $bundle as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}
}

add_filter(
	'wp_agent_runtime_import_bundle',
	static function ( $result, array $spec, array $input = array(), int $index = 0 ) {
		if ( null !== $result ) {
			return $result;
		}

		$string_value = static function ( mixed ...$values ): string {
			foreach ( $values as $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return trim( (string) $value );
				}
			}

			return '';
		};

		$bundle = is_array( $spec['bundle'] ?? null ) ? $spec['bundle'] : array();
		if ( empty( $bundle ) && is_string( $spec['source'] ?? null ) ) {
			$source_bundle = wp_agent_runtime_bundle_from_source( $spec['source'], $index );
			if ( is_wp_error( $source_bundle ) ) {
				return $source_bundle;
			}
			$bundle = $source_bundle;
		}
		$agent = is_array( $bundle['agent'] ?? null ) ? $bundle['agent'] : array();
		if ( empty( $agent ) ) {
			return null;
		}

		$slug = sanitize_title( $string_value( $input['slug'] ?? null, $spec['slug'] ?? null, $agent['agent_slug'] ?? null, $bundle['package_slug'] ?? null, $bundle['bundle_slug'] ?? null ) );
		if ( '' === $slug ) {
			return new WP_Error(
				'wp_agent_runtime_bundle_missing_agent_slug',
				'Runtime agent bundle imports require an agent slug.',
				array( 'index' => $index )
			);
		}

		$registry = WP_Agents_Registry::get_instance();
		if ( ! $registry instanceof WP_Agents_Registry ) {
			return new WP_Error(
				'wp_agent_runtime_bundle_registry_unavailable',
				'The Agents API registry is unavailable for runtime bundle import.',
				array( 'index' => $index )
			);
		}

		$on_conflict = $string_value( $input['on_conflict'] ?? null, $spec['on_conflict'] ?? null, 'upgrade' );
		if ( ! in_array( $on_conflict, array( 'error', 'skip', 'upgrade' ), true ) ) {
			$on_conflict = 'upgrade';
		}

		if ( $registry->is_registered( $slug ) ) {
			if ( 'skip' === $on_conflict ) {
				return array(
					'success'    => true,
					'status'     => 'skipped',
					'agent_slug' => $slug,
				);
			}

			if ( 'error' === $on_conflict ) {
				return new WP_Error(
					'wp_agent_runtime_bundle_agent_exists',
					'Runtime agent bundle import would replace an existing agent.',
					array(
						'index'      => $index,
						'agent_slug' => $slug,
					)
				);
			}

			$registry->unregister( $slug );
		}

		$config         = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();
		$meta           = is_array( $agent['meta'] ?? null ) ? $agent['meta'] : array();
		$source_type    = $string_value( $bundle['source_type'] ?? null, $spec['source_type'] ?? null, 'runtime-agent-package' );
		$source_package = $string_value( $bundle['source_package'] ?? null, $spec['source_package'] ?? null, $bundle['package_slug'] ?? null, $spec['package_slug'] ?? null, $bundle['bundle_slug'] ?? null );
		$source_version = $string_value( $bundle['source_version'] ?? null, $spec['source_version'] ?? null, $bundle['package_version'] ?? null, $spec['package_version'] ?? null, $bundle['bundle_version'] ?? null );
		if ( '' !== $source_package || '' !== $source_version ) {
			$meta = array_merge(
				array(
					'source_type'    => $source_type,
					'source_package' => $source_package,
					'source_version' => $source_version,
				),
				$meta
			);
		}

		$registered = $registry->register(
			$slug,
			array(
				'label'          => $string_value( $agent['agent_name'] ?? null, $agent['label'] ?? null, $slug ),
				'description'    => $string_value( $agent['description'] ?? null ),
				'default_config' => $config,
				'meta'           => $meta,
			)
		);

		if ( ! $registered instanceof WP_Agent ) {
			return new WP_Error(
				'wp_agent_runtime_bundle_register_failed',
				'Runtime agent bundle import failed to register the agent.',
				array(
					'index'      => $index,
					'agent_slug' => $slug,
				)
			);
		}

		return array(
			'success'    => true,
			'status'     => 'registered',
			'agent_slug' => $slug,
		);
	},
	10,
	4
);
