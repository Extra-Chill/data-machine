<?php
/**
 * Data Machine agent package artifact type registrations.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

add_action( 'wp_agent_package_artifacts_init', __NAMESPACE__ . '\register_datamachine_agent_package_artifact_types' );

/**
 * Register Data Machine-owned agent package artifact types.
 *
 * @return void
 */
function register_datamachine_agent_package_artifact_types(): void {
	if ( ! function_exists( 'wp_register_agent_package_artifact_type' ) ) {
		return;
	}

	foreach ( datamachine_agent_package_artifact_type_definitions() as $type => $args ) {
		wp_register_agent_package_artifact_type( $type, $args );
	}
}

/**
 * Data Machine package artifact type metadata.
 *
 * @return array<string,array<string,string>>
 */
function datamachine_agent_package_artifact_type_definitions(): array {
	return array(
		'datamachine/pipeline'    => array(
			'label'       => 'Data Machine pipeline',
			'description' => 'A portable Data Machine pipeline document.',
		),
		'datamachine/flow'        => array(
			'label'       => 'Data Machine flow',
			'description' => 'A portable Data Machine flow document.',
		),
		'datamachine/prompt'      => array(
			'label'       => 'Data Machine prompt',
			'description' => 'A reusable prompt artifact owned by Data Machine materialization.',
		),
		'datamachine/rubric'      => array(
			'label'       => 'Data Machine rubric',
			'description' => 'A reusable rubric artifact owned by Data Machine materialization.',
		),
		'datamachine/tool-policy' => array(
			'label'       => 'Data Machine tool policy',
			'description' => 'A portable tool policy artifact for Data Machine package installs.',
		),
		'datamachine/auth-ref'    => array(
			'label'       => 'Data Machine auth reference',
			'description' => 'A portable auth reference artifact resolved by Data Machine during install.',
		),
		'datamachine/queue-seed'  => array(
			'label'       => 'Data Machine queue seed',
			'description' => 'A portable queue seed artifact for Data Machine flow setup.',
		),
	);
}
