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
	foreach ( datamachine_agent_package_artifact_type_definitions() as $type => $args ) {
		wp_register_agent_package_artifact_type( $type, $args );
	}
}

/**
 * Data Machine package artifact type metadata.
 *
 * @return array<string,array<string,mixed>>
 */
function datamachine_agent_package_artifact_type_definitions(): array {
	$import_callback = __NAMESPACE__ . '\\import_datamachine_agent_package_artifact';

	$definitions = array(
		'datamachine/pipeline'    => array(
			'label'           => 'Data Machine pipeline',
			'description'     => 'A portable Data Machine pipeline document.',
			'import_callback' => $import_callback,
		),
		'datamachine/flow'        => array(
			'label'           => 'Data Machine flow',
			'description'     => 'A portable Data Machine flow document.',
			'import_callback' => $import_callback,
		),
		'datamachine/prompt'      => array(
			'label'           => 'Data Machine prompt',
			'description'     => 'A reusable prompt artifact owned by Data Machine materialization.',
			'import_callback' => $import_callback,
		),
		'datamachine/rubric'      => array(
			'label'           => 'Data Machine rubric',
			'description'     => 'A reusable rubric artifact owned by Data Machine materialization.',
			'import_callback' => $import_callback,
		),
		'datamachine/tool-policy' => array(
			'label'           => 'Data Machine tool policy',
			'description'     => 'A portable tool policy artifact for Data Machine package installs.',
			'import_callback' => $import_callback,
		),
		'datamachine/auth-ref'    => array(
			'label'           => 'Data Machine auth reference',
			'description'     => 'A portable auth reference artifact resolved by Data Machine during install.',
			'import_callback' => $import_callback,
		),
		'datamachine/queue-seed'  => array(
			'label'           => 'Data Machine queue seed',
			'description'     => 'A portable queue seed artifact for Data Machine flow setup.',
			'import_callback' => $import_callback,
		),
	);

	return AgentBundleArtifactExtensions::package_artifact_type_definitions( $definitions );
}

/**
 * Apply a package artifact through Data Machine bundle materializers.
 *
 * @param \WP_Agent_Package_Artifact $artifact Package artifact declaration.
 * @param array<string,mixed>        $context Adoption context.
 * @return mixed
 */
function import_datamachine_agent_package_artifact( \WP_Agent_Package_Artifact $artifact, array $context ) {
	$target          = is_array( $context['target'] ?? null ) ? $context['target'] : array();
	$bundle_type     = AgentBundleUpgradePlanner::bundle_artifact_type( $artifact->get_type() );
	$bundle_artifact = array(
		'artifact_type' => $bundle_type,
		'artifact_id'   => (string) ( $target['artifact_id'] ?? $artifact->get_slug() ),
		'source_path'   => (string) ( $target['source'] ?? $artifact->get_source() ),
		'payload'       => $target['payload'] ?? null,
	);

	if ( isset( $target['hash'] ) ) {
		$bundle_artifact['hash'] = (string) $target['hash'];
	}

	$agent  = is_array( $context['agent'] ?? null ) ? $context['agent'] : array();
	$result = AgentBundleArtifactExtensions::apply_artifact( $bundle_artifact, $agent, $context );

	/**
	 * Back-compat apply seam for existing bundle artifact materializers.
	 *
	 * @param mixed               $result Current result.
	 * @param array<string,mixed> $bundle_artifact Bundle artifact envelope.
	 * @param array<string,mixed> $context Full adoption context.
	 */
	$result = apply_filters( 'datamachine_bundle_upgrade_apply_artifact', $result, $bundle_artifact, $context );

	return ( null === $result || is_wp_error( $result ) ) ? false : $result;
}
