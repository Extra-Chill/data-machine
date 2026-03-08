<?php
/**
 * All Abilities Registered Test
 *
 * Validates all abilities are registered during plugin boot.
 * This test does NOT instantiate ability classes - it only checks
 * that they were already registered during WordPress bootstrap.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use WP_UnitTestCase;

class AllAbilitiesRegisteredTest extends WP_UnitTestCase {

	/**
	 * Test all Data Machine abilities are registered at boot.
	 *
	 * If an ability is missing from the boot sequence in data-machine.php,
	 * this test will fail. This prevents regression where abilities are
	 * registered late (after hooks fire), causing _doing_it_wrong() warnings.
	 */
	public function test_all_data_machine_abilities_registered(): void {
		$expected = array(
			// FlowAbilities (5)
			'datamachine/get-flows',
			'datamachine/create-flow',
			'datamachine/delete-flow',
			'datamachine/update-flow',
			'datamachine/duplicate-flow',
			// AuthAbilities (3)
			'datamachine/get-auth-status',
			'datamachine/disconnect-auth',
			'datamachine/save-auth-config',
			// AgentFileAbilities (5)
			'datamachine/list-agent-files',
			'datamachine/get-agent-file',
			'datamachine/write-agent-file',
			'datamachine/delete-agent-file',
			'datamachine/upload-agent-file',
			// FlowFileAbilities (5)
			'datamachine/list-flow-files',
			'datamachine/get-flow-file',
			'datamachine/delete-flow-file',
			'datamachine/cleanup-flow-files',
			'datamachine/upload-flow-file',
			// FlowStepAbilities (3)
			'datamachine/get-flow-steps',
			'datamachine/update-flow-step',
			'datamachine/configure-flow-steps',
			// JobAbilities (5)
			'datamachine/get-jobs',
			'datamachine/delete-jobs',
			'datamachine/run-flow',
			'datamachine/get-flow-health',
			'datamachine/get-problem-flows',
			// LogAbilities (6)
			'datamachine/write-to-log',
			'datamachine/clear-logs',
			'datamachine/read-logs',
			'datamachine/get-log-metadata',
			'datamachine/get-log-level',
			'datamachine/set-log-level',
			// PipelineAbilities (7)
			'datamachine/get-pipelines',
			'datamachine/create-pipeline',
			'datamachine/update-pipeline',
			'datamachine/delete-pipeline',
			'datamachine/duplicate-pipeline',
			'datamachine/import-pipelines',
			'datamachine/export-pipelines',
			// PipelineStepAbilities (5)
			'datamachine/get-pipeline-steps',
			'datamachine/add-pipeline-step',
			'datamachine/update-pipeline-step',
			'datamachine/delete-pipeline-step',
			'datamachine/reorder-pipeline-steps',
			// ProcessedItemsAbilities (3)
			'datamachine/clear-processed-items',
			'datamachine/check-processed-item',
			'datamachine/has-processed-history',
			// SettingsAbilities (7)
			'datamachine/get-settings',
			'datamachine/update-settings',
			'datamachine/get-scheduling-intervals',
			'datamachine/get-tool-config',
			'datamachine/save-tool-config',
			'datamachine/get-handler-defaults',
			'datamachine/update-handler-defaults',
			// HandlerAbilities (5)
			'datamachine/get-handlers',
			'datamachine/validate-handler',
			'datamachine/get-handler-config-fields',
			'datamachine/apply-handler-defaults',
			'datamachine/get-handler-site-defaults',
			// StepTypeAbilities (2)
			'datamachine/get-step-types',
			'datamachine/validate-step-type',
			// PostQueryAbilities (1)
			'datamachine/query-posts',
			// LocalSearchAbilities (1)
			'datamachine/local-search',
			// DuplicateCheckAbility (2)
			'datamachine/check-duplicate',
			'datamachine/titles-match',
		);

		$missing = array();
		foreach ( $expected as $ability_id ) {
			if ( wp_get_ability( $ability_id ) === null ) {
				$missing[] = $ability_id;
			}
		}

		$this->assertEmpty(
			$missing,
			'Abilities not registered during plugin boot: ' . implode( ', ', $missing )
		);
	}

	/**
	 * Test datamachine category is registered at boot.
	 */
	public function test_datamachine_category_registered(): void {
		$categories = wp_get_ability_categories();
		$this->assertArrayHasKey(
			'datamachine',
			$categories,
			'datamachine category should be registered during plugin boot'
		);
	}
}
