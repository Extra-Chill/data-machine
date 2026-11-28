<?php
/**
 * One isolated Data Machine Business provider module.
 *
 * @package DataMachineBusiness
 */

namespace DataMachineBusiness\Bootstrap;

defined( 'ABSPATH' ) || exit;

final class ProviderModule {

	private string $id;

	/** @var array<string,callable> */
	private array $prerequisites;

	/** @var string[] */
	private array $capabilities;

	/** @var callable */
	private $initializer;

	/**
	 * @param array<string,callable> $prerequisites Named prerequisite checks.
	 * @param string[]               $capabilities  Public surfaces supplied by the module.
	 */
	public function __construct( string $id, array $prerequisites, array $capabilities, callable $initializer ) {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $id ) ) {
			throw new \InvalidArgumentException( 'Provider module IDs must be non-empty slugs.' );
		}

		foreach ( $prerequisites as $name => $check ) {
			if ( '' === $name ) {
				throw new \InvalidArgumentException( 'Provider module prerequisites must be named callables.' );
			}
		}

		foreach ( $capabilities as $capability ) {
			if ( '' === $capability ) {
				throw new \InvalidArgumentException( 'Provider module capabilities must be non-empty strings.' );
			}
		}

		$this->id            = $id;
		$this->prerequisites = $prerequisites;
		$this->capabilities  = array_values( array_unique( $capabilities ) );
		$this->initializer   = $initializer;
	}

	public function id(): string {
		return $this->id;
	}

	/** @return string[] */
	public function capabilities(): array {
		return $this->capabilities;
	}

	/** @return string[] */
	public function missing_prerequisites(): array {
		$missing = array();

		foreach ( $this->prerequisites as $name => $check ) {
			if ( ! $check() ) {
				$missing[] = $name;
			}
		}

		return $missing;
	}

	public function register(): void {
		( $this->initializer )();
	}
}
