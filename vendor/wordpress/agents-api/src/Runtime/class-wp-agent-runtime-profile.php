<?php
/**
 * Agent runtime profile value object.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable provider/model binding resolved for an agent at runtime.
 */
final class WP_Agent_Runtime_Profile {

	/**
	 * @param array<string,mixed>|null $identity   Optional materialized identity payload.
	 * @param array<string,mixed>      $provenance Field-level provenance metadata.
	 */
	public function __construct(
		private readonly string $agent_slug,
		private readonly string $provider_id,
		private readonly string $model_id,
		private readonly ?array $identity = null,
		private readonly array $provenance = array()
	) {}

	/** @return string Agent slug. */
	public function agent_slug(): string {
		return $this->agent_slug;
	}

	/** @return string Provider identifier. */
	public function provider_id(): string {
		return $this->provider_id;
	}

	/** @return string Model identifier. */
	public function model_id(): string {
		return $this->model_id;
	}

	/** @return array<string,mixed>|null Optional materialized identity payload. */
	public function identity(): ?array {
		return $this->identity;
	}

	/** @return array<string,mixed> Field-level provenance metadata. */
	public function provenance(): array {
		return $this->provenance;
	}

	/**
	 * Return a stable array shape for diagnostics and handoff to callers.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'agent_slug'  => $this->agent_slug,
			'provider_id' => $this->provider_id,
			'model_id'    => $this->model_id,
			'identity'    => $this->identity,
			'provenance'  => $this->provenance,
		);
	}
}
