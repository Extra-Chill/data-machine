<?php
/**
 * Agent runtime profile resolver.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\Core\Identity\WP_Agent_Materialized_Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves an auditable provider/model binding for an agent.
 */
final class WP_Agent_Runtime_Profile_Resolver {

	/**
	 * Resolve an agent runtime profile.
	 *
	 * Precedence, per field: explicit context override > runtime_overrides > host
	 * providers > config arrays. Provider and model are resolved independently so
	 * a higher-priority source can provide one field while another source provides
	 * the other. Empty strings are valid resolved field values.
	 *
	 * @param \WP_Agent           $agent   Registered agent definition.
	 * @param array<string,mixed> $context Runtime resolution context.
	 * @return WP_Agent_Runtime_Profile|null Resolved profile, or null when no binding exists.
	 */
	public function resolve( \WP_Agent $agent, array $context = array() ): ?WP_Agent_Runtime_Profile {
		$provider_id = null;
		$model_id    = null;
		$provenance  = array(
			'config_sources' => array(),
		);
		$identity    = $this->identity_payload( $context['identity'] ?? null );

		$this->apply_context_overrides( $context, $provider_id, $model_id, $provenance );
		$this->apply_runtime_overrides( $agent, $context, $provider_id, $model_id, $provenance );
		$this->apply_host_providers( $agent, $context, $provider_id, $model_id, $identity, $provenance );
		$this->apply_config_sources( $agent, $context, $provider_id, $model_id, $provenance );

		if ( null === $provider_id && null === $model_id && null === $identity ) {
			return $this->apply_final_filter( null, $agent, $context );
		}

		$profile = new WP_Agent_Runtime_Profile(
			$agent->get_slug(),
			(string) ( $provider_id ?? '' ),
			(string) ( $model_id ?? '' ),
			$identity,
			$provenance
		);

		return $this->apply_final_filter( $profile, $agent, $context );
	}

	/**
	 * @param array<string,mixed>      $context    Runtime context.
	 * @param string|null             $provider_id Resolved provider id.
	 * @param string|null             $model_id    Resolved model id.
	 * @param array<string,mixed>     $provenance  Field provenance.
	 */
	private function apply_context_overrides( array $context, ?string &$provider_id, ?string &$model_id, array &$provenance ): void {
		$model = is_array( $context['model'] ?? null ) ? $context['model'] : array();

		if ( null === $provider_id && array_key_exists( 'provider_id', $context ) && is_scalar( $context['provider_id'] ) ) {
			$provider_id               = (string) $context['provider_id'];
			$provenance['provider_id'] = array(
				'source' => 'context',
				'path'   => 'provider_id',
			);
		} elseif ( null === $provider_id && array_key_exists( 'provider_id', $model ) && is_scalar( $model['provider_id'] ) ) {
			$provider_id               = (string) $model['provider_id'];
			$provenance['provider_id'] = array(
				'source' => 'context',
				'path'   => 'model.provider_id',
			);
		}

		if ( null === $model_id && array_key_exists( 'model_id', $context ) && is_scalar( $context['model_id'] ) ) {
			$model_id               = (string) $context['model_id'];
			$provenance['model_id'] = array(
				'source' => 'context',
				'path'   => 'model_id',
			);
		} elseif ( null === $model_id && array_key_exists( 'model_id', $model ) && is_scalar( $model['model_id'] ) ) {
			$model_id               = (string) $model['model_id'];
			$provenance['model_id'] = array(
				'source' => 'context',
				'path'   => 'model.model_id',
			);
		}
	}

	/**
	 * @param \WP_Agent               $agent       Agent definition.
	 * @param array<string,mixed>     $context     Runtime context.
	 * @param string|null             $provider_id Resolved provider id.
	 * @param string|null             $model_id    Resolved model id.
	 * @param array<string,mixed>     $provenance  Field provenance.
	 */
	private function apply_runtime_overrides( \WP_Agent $agent, array $context, ?string &$provider_id, ?string &$model_id, array &$provenance ): void {
		$overrides = $context['runtime_overrides'] ?? null;
		if ( ! $overrides instanceof \WP_Agent_Runtime_Overrides ) {
			$overrides = $agent->runtime_overrides();
		}

		if ( null === $provider_id && null !== $overrides->provider_id() ) {
			$provider_id               = $overrides->provider_id();
			$provenance['provider_id'] = array(
				'source' => 'runtime_overrides',
				'path'   => 'provider_id',
			);
		}

		if ( null === $model_id && null !== $overrides->model_id() ) {
			$model_id               = $overrides->model_id();
			$provenance['model_id'] = array(
				'source' => 'runtime_overrides',
				'path'   => 'model_id',
			);
		}
	}

	/**
	 * @param \WP_Agent                       $agent       Agent definition.
	 * @param array<string,mixed>             $context     Runtime context.
	 * @param string|null                     $provider_id Resolved provider id.
	 * @param string|null                     $model_id    Resolved model id.
	 * @param array<string,mixed>|null        $identity    Identity payload.
	 * @param array<string,mixed>             $provenance  Field provenance.
	 */
	private function apply_host_providers( \WP_Agent $agent, array $context, ?string &$provider_id, ?string &$model_id, ?array &$identity, array &$provenance ): void {
		foreach ( $this->get_profile_providers( $agent, $context ) as $index => $provider ) {
			$profile = $provider->resolve_agent_runtime_profile( $agent, $context );
			if ( ! $profile instanceof WP_Agent_Runtime_Profile ) {
				continue;
			}

			$host_provenance = $profile->provenance();
			if ( null === $provider_id ) {
				$provider_id               = $profile->provider_id();
				$provenance['provider_id'] = is_array( $host_provenance['provider_id'] ?? null ) ? $host_provenance['provider_id'] : array(
					'source' => 'runtime_profile_provider',
					'path'   => 'runtime_profile_providers.' . $index . '.provider_id',
				);
			}

			if ( null === $model_id ) {
				$model_id               = $profile->model_id();
				$provenance['model_id'] = is_array( $host_provenance['model_id'] ?? null ) ? $host_provenance['model_id'] : array(
					'source' => 'runtime_profile_provider',
					'path'   => 'runtime_profile_providers.' . $index . '.model_id',
				);
			}

			if ( null === $identity && null !== $profile->identity() ) {
				$identity = $profile->identity();
			}

			$this->append_config_source( $provenance, 'runtime_profile_provider', 'runtime_profile_providers.' . $index );
			break;
		}
	}

	/**
	 * @param \WP_Agent               $agent       Agent definition.
	 * @param array<string,mixed>     $context     Runtime context.
	 * @param string|null             $provider_id Resolved provider id.
	 * @param string|null             $model_id    Resolved model id.
	 * @param array<string,mixed>     $provenance  Field provenance.
	 */
	private function apply_config_sources( \WP_Agent $agent, array $context, ?string &$provider_id, ?string &$model_id, array &$provenance ): void {
		$mode    = isset( $context['mode'] ) && is_scalar( $context['mode'] ) ? trim( (string) $context['mode'] ) : '';
		$sources = array();

		if ( is_array( $context['agent_config'] ?? null ) ) {
			$sources[] = array( 'source' => 'context.agent_config', 'config' => $this->string_keyed_array( $context['agent_config'] ) );
		}

		$identity = $context['identity'] ?? null;
		if ( $identity instanceof WP_Agent_Materialized_Identity ) {
			$sources[] = array( 'source' => 'identity.config', 'config' => $this->string_keyed_array( $identity->config ) );
		}

		$sources[] = array( 'source' => 'agent.default_config', 'config' => $this->string_keyed_array( $agent->get_default_config() ) );

		foreach ( $sources as $source ) {
			$config = $source['config'];
			if ( array() === $config ) {
				continue;
			}

			$this->append_config_source( $provenance, $source['source'], '' );

			$mode_models = is_array( $config['mode_models'] ?? null ) ? $config['mode_models'] : array();
			if ( '' !== $mode && is_array( $mode_models[ $mode ] ?? null ) ) {
				$mode_config = $this->string_keyed_array( $mode_models[ $mode ] );
				$this->apply_config_field( $mode_config, 'provider_id', 'provider', 'mode_models.' . $mode, (string) $source['source'], $provider_id, $provenance );
				$this->apply_config_field( $mode_config, 'model_id', 'model', 'mode_models.' . $mode, (string) $source['source'], $model_id, $provenance );
			}

			$this->apply_config_field( $config, 'default_provider_id', 'default_provider', '', (string) $source['source'], $provider_id, $provenance );
			$this->apply_config_field( $config, 'default_model_id', 'default_model', '', (string) $source['source'], $model_id, $provenance );

			if ( null !== $provider_id && null !== $model_id ) {
				break;
			}
		}
	}

	/**
	 * @param array<string,mixed> $config      Config map.
	 * @param string              $primary_key Preferred key.
	 * @param string              $fallback_key Fallback key.
	 * @param string              $prefix      Provenance path prefix.
	 * @param string              $source      Provenance source.
	 * @param string|null         $target      Resolved target field.
	 * @param array<string,mixed> $provenance  Field provenance.
	 */
	private function apply_config_field( array $config, string $primary_key, string $fallback_key, string $prefix, string $source, ?string &$target, array &$provenance ): void {
		if ( null !== $target ) {
			return;
		}

		$key   = null;
		$value = null;
		if ( array_key_exists( $primary_key, $config ) && is_scalar( $config[ $primary_key ] ) ) {
			$key = $primary_key;
			$value = $config[ $primary_key ];
		} elseif ( array_key_exists( $fallback_key, $config ) && is_scalar( $config[ $fallback_key ] ) ) {
			$key   = $fallback_key;
			$value = $config[ $fallback_key ];
		}

		if ( null === $key ) {
			return;
		}

		$field  = str_contains( $primary_key, 'provider' ) ? 'provider_id' : 'model_id';
		$target = (string) $value;

		$provenance[ $field ] = array(
			'source' => $source,
			'path'   => '' === $prefix ? $key : $prefix . '.' . $key,
		);
	}

	/**
	 * @param \WP_Agent           $agent   Agent definition.
	 * @param array<string,mixed> $context Runtime context.
	 * @return WP_Agent_Runtime_Profile_Provider[] Providers.
	 */
	private function get_profile_providers( \WP_Agent $agent, array $context ): array {
		$providers = array();
		if ( is_array( $context['runtime_profile_providers'] ?? null ) ) {
			$providers = array_merge( $providers, $context['runtime_profile_providers'] );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$providers = apply_filters( 'agents_api_runtime_profile_providers', $providers, $agent, $context );
		}

		return array_values(
			array_filter(
				is_array( $providers ) ? $providers : array(),
				static fn( $provider ): bool => $provider instanceof WP_Agent_Runtime_Profile_Provider
			)
		);
	}

	/**
	 * @param mixed $identity Raw identity context.
	 * @return array<string,mixed>|null Identity payload.
	 */
	private function identity_payload( $identity ): ?array {
		if ( $identity instanceof WP_Agent_Materialized_Identity ) {
			return $identity->to_array();
		}

		return is_array( $identity ) ? $this->string_keyed_array( $identity ) : null;
	}

	/**
	 * @param array<string,mixed> $provenance Field provenance.
	 * @param string              $source     Source label.
	 * @param string              $path       Source path.
	 */
	private function append_config_source( array &$provenance, string $source, string $path ): void {
		if ( ! is_array( $provenance['config_sources'] ?? null ) ) {
			$provenance['config_sources'] = array();
		}

		$provenance['config_sources'][] = array(
			'source' => $source,
			'path'   => $path,
		);
	}

	/**
	 * @param array<mixed> $value Raw map.
	 * @return array<string,mixed>
	 */
	private function string_keyed_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}

		return $result;
	}

	/**
	 * @param WP_Agent_Runtime_Profile|null $profile Profile before final filter.
	 * @param \WP_Agent                     $agent   Agent definition.
	 * @param array<string,mixed>           $context Runtime context.
	 * @return WP_Agent_Runtime_Profile|null Filtered profile.
	 */
	private function apply_final_filter( ?WP_Agent_Runtime_Profile $profile, \WP_Agent $agent, array $context ): ?WP_Agent_Runtime_Profile {
		if ( function_exists( 'apply_filters' ) ) {
			$profile = apply_filters( 'agents_api_resolved_agent_runtime_profile', $profile, $agent, $context );
		}

		return $profile instanceof WP_Agent_Runtime_Profile ? $profile : null;
	}
}
