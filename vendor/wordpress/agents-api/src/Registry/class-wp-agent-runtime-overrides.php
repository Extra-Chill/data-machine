<?php
/**
 * WP_Agent runtime override value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Runtime_Overrides' ) ) {
	/**
	 * Nullable per-agent runtime overrides for caller-owned runners.
	 */
	final class WP_Agent_Runtime_Overrides {

		/** @var string|null */
		private ?string $system_prompt;

		/** @var string|null */
		private ?string $provider_id;

		/** @var string|null */
		private ?string $model_id;

		/** @var float|null */
		private ?float $temperature;

		/** @var int|null */
		private ?int $max_iterations;

		/** @var string[] */
		private array $tier_1_tools;

		/** @var string|null */
		private ?string $greeting;

		/**
		 * @param array<string, mixed> $overrides Raw override map.
		 */
		public function __construct( array $overrides = array() ) {
			$this->system_prompt  = self::optional_string( $overrides['system_prompt'] ?? null );
			$this->provider_id    = self::optional_string( $overrides['provider_id'] ?? null );
			$this->model_id       = self::optional_string( $overrides['model_id'] ?? null );
			$this->temperature    = self::optional_float( $overrides['temperature'] ?? null );
			$this->max_iterations = self::optional_positive_int( $overrides['max_iterations'] ?? null );
			$this->tier_1_tools   = self::string_list( $overrides['tier_1_tools'] ?? array() );
			$this->greeting       = self::optional_string( $overrides['greeting'] ?? null );
		}

		/** @return string|null System prompt override. */
		public function system_prompt(): ?string {
			return $this->system_prompt;
		}

		/** @return string|null Provider ID override. */
		public function provider_id(): ?string {
			return $this->provider_id;
		}

		/** @return string|null Model ID override. */
		public function model_id(): ?string {
			return $this->model_id;
		}

		/** @return float|null Temperature override. */
		public function temperature(): ?float {
			return $this->temperature;
		}

		/** @return int|null Maximum iteration override. */
		public function max_iterations(): ?int {
			return $this->max_iterations;
		}

		/** @return string[] Tier-1 tool names. */
		public function tier_1_tools(): array {
			return $this->tier_1_tools;
		}

		/** @return string|null Greeting override. */
		public function greeting(): ?string {
			return $this->greeting;
		}

		/**
		 * Export nullable overrides for persistence or diagnostics.
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			return array(
				'system_prompt'  => $this->system_prompt,
				'provider_id'    => $this->provider_id,
				'model_id'       => $this->model_id,
				'temperature'    => $this->temperature,
				'max_iterations' => $this->max_iterations,
				'tier_1_tools'   => $this->tier_1_tools,
				'greeting'       => $this->greeting,
			);
		}

		/**
		 * @param mixed $value Raw string-ish value.
		 * @return string|null Normalized non-empty string or null.
		 */
		private static function optional_string( $value ): ?string {
			if ( null === $value ) {
				return null;
			}

			if ( ! is_scalar( $value ) ) {
				return null;
			}

			$value = trim( (string) $value );
			return '' === $value ? null : $value;
		}

		/**
		 * @param mixed $value Raw float-ish value.
		 * @return float|null Normalized float or null.
		 */
		private static function optional_float( $value ): ?float {
			return is_scalar( $value ) && '' !== $value ? (float) $value : null;
		}

		/**
		 * @param mixed $value Raw int-ish value.
		 * @return int|null Positive int or null.
		 */
		private static function optional_positive_int( $value ): ?int {
			if ( ! is_scalar( $value ) ) {
				return null;
			}

			$value = (int) $value;
			return $value > 0 ? $value : null;
		}

		/**
		 * @param mixed $values Raw list.
		 * @return string[] Non-empty unique strings.
		 */
		private static function string_list( $values ): array {
			$values = is_array( $values ) ? $values : array( $values );
			$values = array_filter(
				array_map(
					static fn( $value ): string => is_scalar( $value ) ? trim( (string) $value ) : '',
					$values
				),
				static fn( string $value ): bool => '' !== $value
			);

			return array_values( array_unique( $values ) );
		}
	}
}
