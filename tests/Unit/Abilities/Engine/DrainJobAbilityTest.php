<?php
/**
 * Synchronous drain-job ability coverage.
 *
 * @package DataMachine\Tests\Unit\Abilities\Engine
 */

namespace DataMachine\Tests\Unit\Abilities\Engine;

use DataMachine\Abilities\Engine\DrainJobAbility;
use DataMachine\Abilities\Engine\RunFlowAbility;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\JobStatus;
use DataMachine\Core\Steps\FlowStepConfigFactory;
use WP_UnitTestCase;

class DrainJobAbilityTest extends WP_UnitTestCase
{
    private $handler_filter;

    public function set_up(): void
    {
        parent::set_up();

        datamachine_register_capabilities();

        $user_id = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($user_id);

        $this->handler_filter = function (array $handlers, ?string $step_type = null): array {
            if (null === $step_type || 'fetch' === $step_type) {
                $handlers['drain_job_empty_fetch'] = array(
                    'label' => 'Drain Job Empty Fetch',
                    'type'  => 'fetch',
                    'class' => DrainJobEmptyFetchHandler::class,
                );
            }

            return $handlers;
        };
        add_filter('datamachine_handlers', $this->handler_filter, 10, 2);
    }

    public function tear_down(): void
    {
        remove_filter('datamachine_handlers', $this->handler_filter, 10);
        wp_set_current_user(0);

        parent::tear_down();
    }

    public function test_drain_job_runs_scheduled_flow_to_terminal_state(): void
    {
        $this->assertTrue(class_exists('\ActionScheduler'), 'Action Scheduler must be loaded for synchronous draining.');
        $this->assertNotFalse(
            has_action('datamachine_execute_step'),
            'Execution engine execute-step bridge should be registered.'
        );

        $pipeline_id = (new Pipelines())->create_pipeline(
            array(
                'pipeline_name'   => 'Drain Job Ability Pipeline',
                'pipeline_config' => array(),
                'user_id'         => get_current_user_id(),
            )
        );
        $this->assertIsInt($pipeline_id);

        $flow_id = (new Flows())->create_flow(
            array(
                'pipeline_id'       => $pipeline_id,
                'flow_name'         => 'Drain Job Ability Flow',
                'flow_config'       => array(),
                'scheduling_config' => array('enabled' => true),
                'user_id'           => get_current_user_id(),
            )
        );
        $this->assertIsInt($flow_id);

        $flow_config = array(
            'flow_fetch' => FlowStepConfigFactory::build(
                array(
                    'flow_step_id'     => 'flow_fetch',
                    'pipeline_step_id' => 'pipeline_fetch',
                    'step_type'        => 'fetch',
                    'execution_order'  => 0,
                    'pipeline_id'      => $pipeline_id,
                    'flow_id'          => $flow_id,
                    'handler_slug'     => 'drain_job_empty_fetch',
                    'handler_config'   => array(),
                    'queue_mode'       => 'static',
                )
            ),
        );
        $this->assertTrue((new Flows())->update_flow($flow_id, array('flow_config' => $flow_config)));

        $run_result = (new RunFlowAbility())->execute(array('flow_id' => $flow_id));
        $this->assertTrue($run_result['success'] ?? false);

        $job_id = (int) $run_result['job_id'];
        $this->assertGreaterThan(0, $job_id);
        $this->assertSame(JobStatus::PROCESSING, (new Jobs())->get_job($job_id)['status'] ?? '');

        $drain_result = (new DrainJobAbility())->execute(
            array(
                'job_id'         => $job_id,
                'step_budget'    => 5,
                'time_budget_ms' => 10000,
            )
        );

        $this->assertTrue($drain_result['success'] ?? false);
        $this->assertSame(JobStatus::COMPLETED_NO_ITEMS, $drain_result['terminal_state'] ?? null);
        $this->assertSame(1, $drain_result['actions_drained'] ?? null);
        $this->assertSame(0, $drain_result['remaining_actions'] ?? null);
        $this->assertFalse($drain_result['budget_exhausted'] ?? true);
    }
}

class DrainJobEmptyFetchHandler
{
    /**
     * Return no data so the single-step flow reaches completed_no_items.
     *
     * @param int|string $pipeline_id Pipeline ID.
     * @param array      $handler_settings Handler settings.
     * @param string     $job_id Job ID.
     * @return array Empty packet list.
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    public function get_fetch_data($pipeline_id, array $handler_settings, string $job_id): array
    {
        $pipeline_id;
        $handler_settings;
        $job_id;

        return array();
    }
}
