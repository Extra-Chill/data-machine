<?php
/**
 * Failure-isolated bootstrap for Data Machine Business providers.
 *
 * @package DataMachineBusiness
 */

namespace DataMachineBusiness\Bootstrap;

defined( 'ABSPATH' ) || exit;

final class ProviderBootstrap {

	/** @var array<int,mixed> */
	private array $modules;

	/** @var array<string,array<string,mixed>> */
	private array $availability = array();

	private bool $registered = false;

	private static ?self $current = null;

	private static bool $discovery_registered = false;

	/** @param array<int,mixed> $modules Provider modules in deterministic registration order. */
	public function __construct( array $modules ) {
		$this->modules = $modules;
	}

	/** @param ProviderModule[] $modules Provider modules in deterministic registration order. */
	public static function boot( array $modules ): self {
		if ( null === self::$current ) {
			self::$current = new self( $modules );
		}

		self::$current->register();
		return self::$current;
	}

	public static function register_discovery(): void {
		if ( self::$discovery_registered ) {
			return;
		}

		add_filter( 'datamachine_business_provider_availability', array( self::class, 'filter_provider_availability' ) );
		add_filter( 'datamachine_business_capability_availability', array( self::class, 'filter_capability_availability' ) );
		self::$discovery_registered = true;
	}

	/** @param array<string,array<string,mixed>> $availability Existing provider availability. */
	public static function filter_provider_availability( array $availability ): array {
		return array_merge( $availability, self::$current ? self::$current->availability() : array() );
	}

	/** @param array<string,array<string,mixed>> $availability Existing capability availability. */
	public static function filter_capability_availability( array $availability ): array {
		if ( null === self::$current ) {
			return $availability;
		}

		foreach ( self::$current->availability() as $provider => $status ) {
			foreach ( $status['capabilities'] as $capability ) {
				$availability[ $capability ] = array(
					'provider'  => $provider,
					'available' => $status['available'],
					'reason'    => $status['reason'],
				);
			}
		}

		return $availability;
	}

	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;
		foreach ( $this->modules as $index => $module ) {
			if ( ! $module instanceof ProviderModule ) {
				$this->availability[ 'invalid-module-' . $index ] = array(
					'available'             => false,
					'reason'                => 'invalid_module',
					'missing_prerequisites' => array(),
					'capabilities'          => array(),
				);
				continue;
			}

			$id = $module->id();
			if ( isset( $this->availability[ $id ] ) ) {
				continue;
			}

			try {
				$missing = $module->missing_prerequisites();
				if ( ! empty( $missing ) ) {
					$this->availability[ $id ] = $this->status( $module, false, 'missing_prerequisites', $missing );
					continue;
				}

				$module->register();
				$this->availability[ $id ] = $this->status( $module, true, 'available' );
			} catch ( \Throwable ) {
				$this->availability[ $id ] = $this->status( $module, false, 'initialization_failed' );
			}
		}
	}

	/** @return array<string,array<string,mixed>> */
	public function availability(): array {
		return $this->availability;
	}

	/**
	 * @param string[] $missing Missing prerequisite names.
	 * @return array<string,mixed>
	 */
	private function status( ProviderModule $module, bool $available, string $reason, array $missing = array() ): array {
		return array(
			'available'             => $available,
			'reason'                => $reason,
			'missing_prerequisites' => $missing,
			'capabilities'          => $module->capabilities(),
		);
	}
}
