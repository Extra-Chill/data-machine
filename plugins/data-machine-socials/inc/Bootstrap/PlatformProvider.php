<?php
/**
 * Isolated social platform bootstrap module.
 *
 * @package DataMachineSocials\Bootstrap
 */

namespace DataMachineSocials\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Registers one platform without coupling its failure to another platform.
 */
final class PlatformProvider {

	private string $id;

	/** @var array<int, class-string> */
	private array $prerequisites;

	/** @var callable */
	private $register_core;

	/** @var callable|null */
	private $register_tools;

	/** @var callable|null */
	private $register_cli;

	/** @var array<string, mixed> */
	private array $availability;

	/**
	 * @param string                   $id             Stable platform ID.
	 * @param array<int, class-string> $prerequisites Classes required by the platform runtime.
	 * @param array<string, mixed>     $capabilities  Public capabilities exposed by the platform.
	 * @param callable                 $register_core Core ability and handler registration.
	 * @param callable|null            $register_tools Optional chat tool registration.
	 * @param callable|null            $register_cli  Optional CLI registration.
	 */
	public function __construct( string $id, array $prerequisites, array $capabilities, callable $register_core, ?callable $register_tools = null, ?callable $register_cli = null ) {
		$this->id             = $id;
		$this->prerequisites  = $prerequisites;
		$this->register_core  = $register_core;
		$this->register_tools = $register_tools;
		$this->register_cli   = $register_cli;
		$this->availability   = array(
			'id'                    => $id,
			'available'             => false,
			'state'                 => 'pending',
			'reason'                => null,
			'prerequisites'         => $prerequisites,
			'missing_prerequisites' => array(),
			'capabilities'          => $capabilities,
			'components'            => array(
				'core'  => 'pending',
				'tools' => null === $register_tools ? 'not_applicable' : 'pending',
				'cli'   => null === $register_cli ? 'not_applicable' : 'pending',
			),
		);
	}

	public function id(): string {
		return $this->id;
	}

	/**
	 * Register platform abilities and handler exactly once.
	 */
	public function register(): void {
		if ( 'pending' !== $this->availability['components']['core'] ) {
			return;
		}

		if ( '' === trim( $this->id ) ) {
			$this->availability['state']              = 'unavailable';
			$this->availability['reason']             = 'invalid_configuration';
			$this->availability['components']['core'] = 'unavailable';
			return;
		}

		$missing = array();
		foreach ( $this->prerequisites as $class ) {
			try {
				if ( ! class_exists( $class ) ) {
					$missing[] = $class;
				}
			} catch ( \Throwable $throwable ) {
				$this->fail( 'core', $throwable );
				return;
			}
		}

		if ( $missing ) {
			$this->availability['state']                 = 'unavailable';
			$this->availability['reason']                = 'missing_prerequisites';
			$this->availability['missing_prerequisites'] = $missing;
			$this->availability['components']['core']    = 'unavailable';
			return;
		}

		try {
			( $this->register_core )();
			$this->availability['available']          = true;
			$this->availability['state']              = 'registered';
			$this->availability['components']['core'] = 'registered';
		} catch ( \Throwable $throwable ) {
			$this->fail( 'core', $throwable );
		}
	}

	/**
	 * Register optional chat tools exactly once.
	 */
	public function register_tools(): void {
		$this->register_optional_component( 'tools', $this->register_tools );
	}

	/**
	 * Register the platform CLI command exactly once.
	 */
	public function register_cli(): void {
		$this->register_optional_component( 'cli', $this->register_cli );
	}

	public function mark_duplicate(): void {
		if ( 'pending' !== $this->availability['state'] ) {
			return;
		}

		$this->availability['state']              = 'duplicate';
		$this->availability['reason']             = 'duplicate_provider';
		$this->availability['components']['core'] = 'skipped';
	}

	/** @return array<string, mixed> */
	public function availability(): array {
		return $this->availability;
	}

	/**
	 * @param string        $component Component key.
	 * @param callable|null $callback  Registration callback.
	 */
	private function register_optional_component( string $component, ?callable $callback ): void {
		if ( 'pending' !== $this->availability['components'][ $component ] ) {
			return;
		}

		if ( 'registered' !== $this->availability['components']['core'] ) {
			$this->availability['components'][ $component ] = 'skipped';
			return;
		}

		if ( null === $callback ) {
			$this->availability['components'][ $component ] = 'not_applicable';
			return;
		}

		try {
			$result = $callback();
			$this->availability['components'][ $component ] = false === $result ? 'unavailable' : 'registered';
		} catch ( \Throwable $throwable ) {
			$this->fail( $component, $throwable );
		}
	}

	private function fail( string $component, \Throwable $throwable ): void {
		$this->availability['available']                = false;
		$this->availability['state']                    = 'failed';
		$this->availability['reason']                   = $component . '_registration_failed';
		$this->availability['components'][ $component ] = 'failed';

		do_action( 'datamachine_socials_platform_bootstrap_failed', $this->id, $component, $throwable );
	}
}
