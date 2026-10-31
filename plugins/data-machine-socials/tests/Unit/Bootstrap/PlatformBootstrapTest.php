<?php
/**
 * Platform bootstrap isolation tests.
 *
 * @package DataMachineSocials\Tests\Unit\Bootstrap
 */

use DataMachineSocials\Bootstrap\PlatformBootstrap;
use DataMachineSocials\Bootstrap\PlatformProvider;

final class PlatformBootstrapTest extends WP_UnitTestCase {

	public function test_bootstrap_matrix_isolates_provider_availability_and_failures(): void {
		$order = array();

		$present = $this->provider( 'present', $order );
		$absent  = $this->provider( 'absent', $order, array( 'DataMachineSocials\\Tests\\MissingPlatformDependency' ) );
		$throwing = $this->provider(
			'throwing',
			$order,
			array(),
			static function () use ( &$order ): void {
				$order[] = 'throwing';
				throw new RuntimeException( 'Platform constructor failed.' );
			}
		);
		$unrelated = $this->provider( 'unrelated', $order );
		$bootstrap = new PlatformBootstrap( array( $present, $absent, $throwing, $unrelated ) );

		$bootstrap->register();

		$this->assertSame( array( 'present', 'throwing', 'unrelated' ), $order );
		$this->assertSame( 'registered', $present->availability()['state'] );
		$this->assertSame( 'unavailable', $absent->availability()['state'] );
		$this->assertSame( 'missing_prerequisites', $absent->availability()['reason'] );
		$this->assertSame( 'failed', $throwing->availability()['state'] );
		$this->assertSame( 'registered', $unrelated->availability()['state'] );
	}

	public function test_misconfigured_optional_component_does_not_suppress_another_platform(): void {
		$order = array();
		$broken_tools = $this->provider(
			'broken-tools',
			$order,
			array(),
			null,
			static function (): void {
				throw new InvalidArgumentException( 'Invalid tool configuration.' );
			}
		);
		$healthy = $this->provider( 'healthy', $order, array(), null, static function () use ( &$order ): void {
			$order[] = 'healthy-tools';
		} );
		$bootstrap = new PlatformBootstrap( array( $broken_tools, $healthy ) );

		$bootstrap->register();
		$bootstrap->register_tools();

		$this->assertSame( 'tools_registration_failed', $broken_tools->availability()['reason'] );
		$this->assertSame( 'registered', $healthy->availability()['components']['tools'] );
		$this->assertContains( 'healthy-tools', $order );
	}

	public function test_misconfigured_provider_is_unavailable_without_running_registration(): void {
		$registered = false;
		$provider   = new PlatformProvider( '', array(), array(), static function () use ( &$registered ): void {
			$registered = true;
		} );

		( new PlatformBootstrap( array( $provider ) ) )->register();

		$this->assertFalse( $registered );
		$this->assertSame( 'unavailable', $provider->availability()['state'] );
		$this->assertSame( 'invalid_configuration', $provider->availability()['reason'] );
	}

	public function test_registration_is_idempotent_ordered_and_rejects_duplicates(): void {
		$order     = array();
		$first     = $this->provider( 'first', $order );
		$duplicate = $this->provider( 'first', $order );
		$last      = $this->provider( 'last', $order );
		$bootstrap = new PlatformBootstrap( array( $first, $duplicate, $last ) );

		$bootstrap->register();
		$bootstrap->register();

		$this->assertSame( array( 'first', 'last' ), $order );
		$this->assertSame( 'duplicate', $duplicate->availability()['state'] );
		$this->assertSame( array( 'first' ), $bootstrap->duplicates() );
		$this->assertSame( array( 'first', 'last' ), array_keys( $bootstrap->availability() ) );
	}

	public function test_availability_exposes_declared_capabilities(): void {
		$order        = array();
		$capabilities = array(
			'abilities' => array( 'datamachine/example-publish' ),
			'handler'   => 'example_publish',
			'tools'     => array( 'PublishExample' ),
			'cli'       => 'datamachine-socials example',
		);
		$provider     = new PlatformProvider( 'example', array(), $capabilities, static function () use ( &$order ): void {
			$order[] = 'example';
		} );
		$bootstrap    = new PlatformBootstrap( array( $provider ) );

		$bootstrap->register();

		$this->assertSame( $capabilities, $bootstrap->availability()['example']['capabilities'] );
		$this->assertTrue( $bootstrap->availability()['example']['available'] );
	}

	public function test_absent_optional_prerequisite_is_discoverable(): void {
		$order     = array();
		$provider  = $this->provider( 'optional-absent', $order, array(), null, static function (): bool {
			return false;
		} );
		$bootstrap = new PlatformBootstrap( array( $provider ) );

		$bootstrap->register();
		$bootstrap->register_tools();

		$this->assertTrue( $provider->availability()['available'] );
		$this->assertSame( 'unavailable', $provider->availability()['components']['tools'] );
	}

	/**
	 * @param array<int,string>                 $order         Registration log.
	 * @param array<int,class-string>           $prerequisites Required classes.
	 * @param callable|null                     $core           Core callback override.
	 * @param callable|null                     $tools          Tools callback.
	 */
	private function provider( string $id, array &$order, array $prerequisites = array(), ?callable $core = null, ?callable $tools = null ): PlatformProvider {
		$core = $core ?? static function () use ( $id, &$order ): void {
			$order[] = $id;
		};

		return new PlatformProvider(
			$id,
			$prerequisites,
			array( 'abilities' => array( 'datamachine/' . $id ) ),
			$core,
			$tools
		);
	}
}
