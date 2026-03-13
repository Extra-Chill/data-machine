<?php
/**
 * WP-CLI Step Types Command
 *
 * Wraps StepTypeAbilities for step type discovery and validation.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.41.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\StepTypeAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Step type discovery and validation.
 *
 * @since 0.41.0
 */
class StepTypesCommand extends BaseCommand {

	/**
	 * List all registered step types.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine step-types list
	 *     wp datamachine step-types list --format=json
	 *
	 * @subcommand list
	 */
	public function list_step_types( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		$ability = new StepTypeAbilities();
		$result  = $ability->executeGetStepTypes( array() );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to get step types.' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( empty( $result['step_types'] ) ) {
			WP_CLI::warning( 'No step types registered.' );
			return;
		}

		$items = array();
		foreach ( $result['step_types'] as $slug => $step_type ) {
			$items[] = array(
				'slug'         => $slug,
				'label'        => $step_type['label'] ?? '',
				'uses_handler' => ! empty( $step_type['uses_handler'] ) ? 'yes' : 'no',
			);
		}

		$fields = array( 'slug', 'label', 'uses_handler' );
		$this->format_items( $items, $fields, $assoc_args, 'slug' );

		WP_CLI::log( sprintf( 'Total: %d step type(s).', $result['count'] ) );
	}

	/**
	 * Get a specific step type by slug.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Step type slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine step-types get fetch
	 *     wp datamachine step-types get publish --format=json
	 *
	 * @subcommand get
	 */
	public function get( array $args, array $assoc_args ): void {
		$slug   = $args[0] ?? '';
		$format = $assoc_args['format'] ?? 'json';

		if ( empty( $slug ) ) {
			WP_CLI::error( 'Step type slug is required.' );
			return;
		}

		$ability = new StepTypeAbilities();
		$result  = $ability->executeGetStepTypes(
			array( 'step_type_slug' => $slug )
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to get step type.' );
			return;
		}

		if ( empty( $result['step_types'] ) ) {
			WP_CLI::error( sprintf( 'Step type "%s" not found.', $slug ) );
			return;
		}

		$step_type = $result['step_types'][ $slug ] ?? reset( $result['step_types'] );

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $step_type, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::log( sprintf( 'Slug:         %s', $slug ) );
		WP_CLI::log( sprintf( 'Label:        %s', $step_type['label'] ?? '' ) );
		WP_CLI::log( sprintf( 'Uses handler: %s', ! empty( $step_type['uses_handler'] ) ? 'yes' : 'no' ) );

		if ( ! empty( $step_type['description'] ) ) {
			WP_CLI::log( sprintf( 'Description:  %s', $step_type['description'] ) );
		}
	}

	/**
	 * Validate a step type slug.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Step type slug to validate.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine step-types validate fetch
	 *     wp datamachine step-types validate nonexistent
	 *
	 * @subcommand validate
	 */
	public function validate( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';

		if ( empty( $slug ) ) {
			WP_CLI::error( 'Step type slug is required.' );
			return;
		}

		$ability = new StepTypeAbilities();
		$result  = $ability->executeValidateStepType(
			array( 'step_type' => $slug )
		);

		if ( $result['valid'] ) {
			WP_CLI::success( sprintf( 'Step type "%s" is valid.', $slug ) );
		} else {
			WP_CLI::error( $result['error'] ?? sprintf( 'Step type "%s" is not valid.', $slug ) );
		}
	}
}
