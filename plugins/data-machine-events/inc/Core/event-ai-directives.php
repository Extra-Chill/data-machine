<?php
/**
 * Register the events AI directives with Data Machine.
 *
 * Data Machine's PromptBuilder only runs directive classes that implement
 * DirectiveInterface. This plugin loads before Data Machine, so the directive
 * classes live in PSR-4 files and are autoloaded when PromptBuilder resolves
 * them. Registration only names them, so loading this file never needs the
 * interface (#3959).
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Directive classes for pipeline AI steps, with their priorities.
 *
 * @return array<string, int>
 */
function event_ai_directive_classes(): array {
	return array(
		// Today's date and the shared year-inference rule (#3882).
		EventDateContextDirective::class   => 55,
		// A pinned venue's location is settled (#3956).
		PinnedVenueContextDirective::class => 56,
	);
}

/**
 * Add the events directives to Data Machine's directive registry.
 */
function register_event_ai_directives(): void {
	add_filter(
		'datamachine_directives',
		static function ( $directives ) {
			foreach ( event_ai_directive_classes() as $class => $priority ) {
				$directives[] = array(
					'class'    => $class,
					'priority' => $priority,
					'modes'    => array( 'pipeline' ),
				);
			}
			return $directives;
		}
	);
}
