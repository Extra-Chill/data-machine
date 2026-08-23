<?php
/**
 * Registered step type metadata queries.
 *
 * @package DataMachine\Core\Steps
 */

namespace DataMachine\Core\Steps;

defined( 'ABSPATH' ) || exit;

class StepTypeMetadata {

	/**
	 * Determine whether a step owns source-ingestion lifecycle behavior.
	 *
	 * @param string $step_type Step type slug.
	 * @return bool
	 */
	public static function isSourceIngestion( string $step_type ): bool {
		$step_types = apply_filters( 'datamachine_step_types', array() );

		return true === ( $step_types[ $step_type ]['source_ingestion'] ?? false );
	}

	/**
	 * Determine whether empty output completes successfully for a step type.
	 *
	 * @param string $step_type Step type slug.
	 * @return bool
	 */
	public static function allowsEmptyOutput( string $step_type ): bool {
		$step_types = apply_filters( 'datamachine_step_types', array() );

		return true === ( $step_types[ $step_type ]['allows_empty_output'] ?? false );
	}

	/**
	 * Determine whether a step supports item-disposition tools.
	 *
	 * @param string $step_type Step type slug.
	 * @return bool
	 */
	public static function supportsItemDisposition( string $step_type ): bool {
		$step_types = apply_filters( 'datamachine_step_types', array() );

		return true === ( $step_types[ $step_type ]['supports_item_disposition'] ?? false );
	}

	/**
	 * Determine whether a step type belongs to a handler category.
	 *
	 * @param string $step_type Step type slug.
	 * @param string $category Handler category.
	 * @return bool
	 */
	public static function hasHandlerCategory( string $step_type, string $category ): bool {
		$step_types = apply_filters( 'datamachine_step_types', array() );

		return $category === ( $step_types[ $step_type ]['handler_category'] ?? null );
	}
}
