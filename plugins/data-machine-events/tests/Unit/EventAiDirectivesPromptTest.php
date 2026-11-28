<?php
/**
 * Events AI directives reach a real Data Machine prompt (issue #3959).
 *
 * The directive unit tests call get_outputs() directly, which passed while
 * PromptBuilder silently skipped both classes for not implementing
 * DirectiveInterface. These tests go through PromptBuilder itself.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Engine\AI\Directives\DirectiveInterface;
use DataMachine\Engine\AI\PromptBuilder;
use DataMachineEvents\Core\EventDateContextDirective;
use DataMachineEvents\Core\PinnedVenueContextDirective;
use WP_UnitTestCase;

class EventAiDirectivesPromptTest extends WP_UnitTestCase {

	public function test_directives_implement_data_machine_interface(): void {
		$this->assertTrue( is_subclass_of( EventDateContextDirective::class, DirectiveInterface::class ) );
		$this->assertTrue( is_subclass_of( PinnedVenueContextDirective::class, DirectiveInterface::class ) );
	}

	public function test_registered_directives_render_into_pipeline_prompt(): void {
		$builder = new PromptBuilder();
		$builder->setMessages( array() )->setTools(
			array(
				'upsert_event' => array(
					'name'    => 'upsert_event',
					'handler' => 'upsert_event',
				),
			)
		);

		foreach ( apply_filters( 'datamachine_directives', array() ) as $directive ) {
			if ( in_array( $directive['class'] ?? '', array( EventDateContextDirective::class, PinnedVenueContextDirective::class ), true ) ) {
				$builder->addDirective( $directive['class'], (int) $directive['priority'], $directive['modes'] );
			}
		}

		$payload = array(
			'engine_data' => array(
				'flow_config' => array(
					'1_step_0_9' => array(
						'handler_configs' => array(
							'universal_web_scraper' => array(
								'venue_name'  => 'Stumble Inn Bar & Grill',
								'venue_city'  => 'Londonderry',
								'venue_state' => 'NH',
							),
						),
					),
					'1_step_2_9' => array( 'handler_configs' => array( 'upsert_event' => array( 'taxonomy_venue_selection' => '117854' ) ) ),
				),
			),
		);

		$request = $builder->buildDetailed( array( 'pipeline' ), 'openai', $payload );

		$this->assertContains( 'EventDateContextDirective', $request['applied_directives'] );
		$this->assertContains( 'PinnedVenueContextDirective', $request['applied_directives'] );

		$text = wp_json_encode( $request['messages'] );
		$this->assertStringContainsString( 'Event date context', $text );
		$this->assertStringContainsString( 'Stumble Inn Bar & Grill (Londonderry, NH)', $text );
	}
}
