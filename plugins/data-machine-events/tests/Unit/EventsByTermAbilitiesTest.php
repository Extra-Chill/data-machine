<?php
/**
 * Events By Term Abilities Tests
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EventsByTermAbilities;
use WP_UnitTestCase;

class EventsByTermAbilitiesTest extends WP_UnitTestCase {

	public function test_events_blog_defaults_to_current_site(): void {
		$abilities = new EventsByTermAbilities();
		$method    = new \ReflectionMethod( $abilities, 'resolveEventsBlogId' );
		$method->setAccessible( true );

		$this->assertSame( get_current_blog_id(), $method->invoke( $abilities ) );
	}

	public function test_events_blog_can_be_configured_by_consumer(): void {
		$callback = static function ( int $blog_id ): int {
			return $blog_id + 1;
		};
		add_filter( 'data_machine_events_events_blog_id', $callback );

		$abilities = new EventsByTermAbilities();
		$method    = new \ReflectionMethod( $abilities, 'resolveEventsBlogId' );
		$method->setAccessible( true );

		$this->assertSame( get_current_blog_id() + 1, $method->invoke( $abilities ) );
		remove_filter( 'data_machine_events_events_blog_id', $callback );
	}
}
