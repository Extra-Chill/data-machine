<?php
/**
 * End-to-end pipeline execution contract coverage with a deterministic AI stub.
 *
 * @package DataMachine\Tests\Unit\Abilities\Engine
 */

namespace DataMachine\Tests\Unit\Abilities\Engine;

use DataMachine\Abilities\Engine\ExecuteStepAbility;
use DataMachine\Abilities\Engine\RunFlowAbility;
use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Core\JobStatus;
use DataMachine\Core\PluginSettings;
use DataMachine\Core\Steps\FlowStepConfigFactory;
use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/WpAiClientTestDoubles.php';

class PipelineExecutionContractTest extends WP_UnitTestCase
{
    private $handler_filter;
    private $tools_filter;
    private $schedule_capture;
    private $log_capture;
    private array $scheduled_steps = array();
    private array $captured_ai_requests = array();
    private array $captured_ai_tools = array();
    private array $captured_logs = array();
    private array $original_settings = array();

    public function set_up(): void
    {
        parent::set_up();

        datamachine_register_capabilities();

        $user_id = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($user_id);

        $this->original_settings = get_option('datamachine_settings', array());
        update_option(
            'datamachine_settings',
            array_merge(
                $this->original_settings,
                array(
                    'mode_models' => array(
                        'pipeline' => array(
                            'provider' => 'fake_provider',
                            'model'    => 'fake-model',
                        ),
                    ),
                )
            )
        );
        PluginSettings::clearCache();

        $this->scheduled_steps      = array();
        $this->captured_ai_requests = array();
        $this->captured_ai_tools    = array();
        $this->captured_logs        = array();

        $this->handler_filter = function (array $handlers, ?string $step_type = null): array {
            if (null === $step_type || 'fetch' === $step_type) {
                $handlers['fake_fetch'] = array(
                    'label' => 'Fake Fetch',
                    'type'  => 'fetch',
                    'class' => FakePipelineFetchHandler::class,
                );
            }

            if (null === $step_type || 'publish' === $step_type) {
                $handlers['fake_publish'] = array(
                    'label' => 'Fake Publish',
                    'type'  => 'publish',
                    'class' => FakePipelinePublishHandler::class,
                );
            }

            return $handlers;
        };
        add_filter('datamachine_handlers', $this->handler_filter, 10, 2);

        $this->tools_filter = function (array $tools): array {
            $tools['__handler_tools_fake_publish'] = array(
                '_handler_callable' => static function (string $slug, array $config): array {
                    return array(
                        'fake_publish_tool' => array(
                            'description'    => 'Publish synthetic content for the pipeline contract test.',
                            'class'          => FakePipelinePublishTool::class,
                            'method'         => 'handle',
                            'handler'        => $slug,
                            'handler_config' => $config,
                            'parameters'     => array(
                                'title'   => array(
                                    'type'     => 'string',
                                    'required' => true,
                                ),
                                'content' => array(
                                    'type'     => 'string',
                                    'required' => true,
                                ),
                            ),
                        ),
                    );
                },
                'handler'           => 'fake_publish',
                'modes'             => array('pipeline'),
                'access_level'      => 'admin',
            );

            return $tools;
        };
        add_filter('datamachine_tools', $this->tools_filter);

        WpAiClientTestDouble::reset();
        WpAiClientTestDouble::set_response_callback(function (array $request, string $provider): array {
            $this->captured_ai_requests[] = array(
                'provider' => $provider,
                'request'  => $request,
            );
            $this->captured_ai_tools[]    = $request['tools'] ?? array();

            return array(
                'success' => true,
                'data'    => array(
                    'content'    => '',
                    'tool_calls' => array(
                        array(
                            'name'       => 'fake_publish_tool',
                            'parameters' => array(
                                'title'   => 'Synthetic AI Published Title',
                                'content' => '<p>Synthetic AI body.</p>',
                            ),
                        ),
                    ),
                    'usage'      => array(
                        'prompt_tokens'     => 10,
                        'completion_tokens' => 5,
                        'total_tokens'      => 15,
                    ),
                ),
            );
        });

        $this->schedule_capture = function ($job_id, $flow_step_id, $data_packets = array()): void {
            $this->scheduled_steps[] = array(
                'job_id'       => (int) $job_id,
                'flow_step_id' => (string) $flow_step_id,
                'data_packets' => is_array($data_packets) ? $data_packets : array(),
            );
        };
        add_action('datamachine_schedule_next_step', $this->schedule_capture, 1, 3);

        $this->log_capture = function (string $level, string $message, array $context = array()): void {
            $this->captured_logs[] = array(
                'level'   => $level,
                'message' => $message,
                'context' => $context,
            );
        };
        add_action('datamachine_log', $this->log_capture, 10, 3);

        HandlerAbilities::clearCache();
        ToolManager::clearCache();
    }

    public function tear_down(): void
    {
        remove_filter('datamachine_handlers', $this->handler_filter, 10);
        remove_filter('datamachine_tools', $this->tools_filter, 10);
        WpAiClientTestDouble::reset();
        remove_action('datamachine_schedule_next_step', $this->schedule_capture, 1);
        remove_action('datamachine_log', $this->log_capture, 10);

        update_option('datamachine_settings', $this->original_settings);
        PluginSettings::clearCache();
        HandlerAbilities::clearCache();
        ToolManager::clearCache();
        wp_set_current_user(0);

        parent::tear_down();
    }

    public function test_fetch_ai_publish_pipeline_executes_with_fake_provider_only(): void
    {
        $this->assertNotFalse(
            has_action('datamachine_schedule_next_step'),
            'Execution engine schedule bridge should be registered.'
        );

        $pipeline_id = $this->_createPipelineWithFlowConfig(array());
        $flow_id     = $this->_createFlowWithConfig($pipeline_id, array());

        $flow_config     = $this->_buildFlowConfig($pipeline_id, $flow_id);
        $pipeline_config = array(
            'pipeline_ai' => array(
                'system_prompt' => 'Use the adjacent publish handler tool exactly once.',
            ),
        );

        $this->assertTrue(
            (new Pipelines())->update_pipeline($pipeline_id, array('pipeline_config' => $pipeline_config))
        );
        $this->assertTrue((new Flows())->update_flow($flow_id, array('flow_config' => $flow_config)));

        $run_result = (new RunFlowAbility())->execute(array('flow_id' => $flow_id));

        $this->assertTrue($run_result['success'] ?? false);
        $this->assertSame('flow_fetch', $run_result['first_step'] ?? '');

        $job_id      = (int) $run_result['job_id'];
        $engine_data = datamachine_get_engine_data($job_id);

        $this->assertSame($job_id, $engine_data['job']['job_id'] ?? null);
        $this->assertSame($flow_id, $engine_data['job']['flow_id'] ?? null);
        $this->assertSame($pipeline_id, $engine_data['job']['pipeline_id'] ?? null);
        $this->assertArrayHasKey('user_id', $engine_data['job'] ?? array());
        $this->assertArrayHasKey('flow_config', $engine_data);
        $this->assertArrayHasKey('pipeline_config', $engine_data);
        $this->assertArrayHasKey('flow_fetch', $engine_data['flow_config']);
        $this->assertArrayHasKey('flow_ai', $engine_data['flow_config']);
        $this->assertArrayHasKey('flow_publish', $engine_data['flow_config']);
        $this->assertArrayHasKey('pipeline_ai', $engine_data['pipeline_config']);

        $executor     = new ExecuteStepAbility();
        $fetch_result = $executor->execute(
            array(
                'job_id'       => $job_id,
                'flow_step_id' => 'flow_fetch',
            )
        );

        $this->assertTrue($fetch_result['success'] ?? false);
        $this->assertSame('inline_continuation', $fetch_result['outcome'] ?? '');
        $this->assertSame('flow_ai', $this->_latestScheduledStep()['flow_step_id'] ?? '');

        $ai_result = $executor->execute(
            array(
                'job_id'       => $job_id,
                'flow_step_id' => 'flow_ai',
            )
        );

        $this->assertTrue($ai_result['success'] ?? false);
        $this->assertSame('inline_continuation', $ai_result['outcome'] ?? '');

        $this->assertCount(1, $this->captured_ai_requests, 'AI provider should be called exactly once.');
        $this->assertSame('fake_provider', $this->captured_ai_requests[0]['provider']);
        $this->assertStringContainsString(
            'Synthetic fetched source body for fake AI pipeline coverage.',
            wp_json_encode($this->captured_ai_requests[0]['request']['messages'] ?? array())
        );

        $this->assertArrayHasKey('fake_publish_tool', $this->captured_ai_tools[0] ?? array());

        $ai_scheduled = $this->_latestScheduledStep();
        $this->assertSame('flow_publish', $ai_scheduled['flow_step_id'] ?? '');
        $this->assertCount(
            1,
            $ai_scheduled['data_packets'] ?? array(),
            'AI step should schedule only the handler-complete packet.'
        );
        $this->assertSame('ai_handler_complete', $ai_scheduled['data_packets'][0]['type'] ?? '');
        $this->assertSame('fake_publish_tool', $ai_scheduled['data_packets'][0]['metadata']['tool_name'] ?? '');
        $this->assertSame('fake_publish', $ai_scheduled['data_packets'][0]['metadata']['handler_tool'] ?? '');
        foreach ($ai_scheduled['data_packets'] as $packet) {
            $this->assertNotSame(
                'fetch',
                $packet['type'] ?? '',
                'Input fetch packet must not be carried forward from AIStep.'
            );
        }

        $engine_after_ai = datamachine_get_engine_data($job_id);
        $this->assertSame(
            array(
                'prompt_tokens'     => 10,
                'completion_tokens' => 5,
                'total_tokens'      => 15,
            ),
            $engine_after_ai['token_usage'] ?? array()
        );
        $this->assertSame('preserved', $engine_after_ai['fake_handler_written_key'] ?? '');

        $publish_result = $executor->execute(
            array(
                'job_id'       => $job_id,
                'flow_step_id' => 'flow_publish',
            )
        );

        $this->assertTrue($publish_result['success'] ?? false);
        $this->assertSame('completed', $publish_result['outcome'] ?? '');
        $this->assertTrue(
            $this->_logWasCaptured('AI successfully used handler tool', 'fake_publish'),
            'PublishStep should consume the handler packet through ToolResultFinder.'
        );

        $job = (new Jobs())->get_job($job_id);
        $this->assertSame(JobStatus::COMPLETED, $job['status'] ?? '');
    }

    /**
     * Create a pipeline row for the contract test.
     */
    private function _createPipelineWithFlowConfig(array $pipeline_config): int
    {
        $pipeline_id = (new Pipelines())->create_pipeline(
            array(
                'pipeline_name'   => 'Fake AI Pipeline Contract',
                'pipeline_config' => $pipeline_config,
                'user_id'         => get_current_user_id(),
            )
        );

        $this->assertIsInt($pipeline_id);
        $this->assertGreaterThan(0, $pipeline_id);

        return $pipeline_id;
    }

    /**
     * Create a flow row for the contract test.
     */
    private function _createFlowWithConfig(int $pipeline_id, array $flow_config): int
    {
        $flow_id = (new Flows())->create_flow(
            array(
                'pipeline_id'       => $pipeline_id,
                'flow_name'         => 'Fake AI Pipeline Contract Flow',
                'flow_config'       => $flow_config,
                'scheduling_config' => array('enabled' => true),
                'user_id'           => get_current_user_id(),
            )
        );

        $this->assertIsInt($flow_id);
        $this->assertGreaterThan(0, $flow_id);

        return $flow_id;
    }

    /**
     * Build fetch -> ai -> publish flow config with real step classes.
     */
    private function _buildFlowConfig(int $pipeline_id, int $flow_id): array
    {
        return array(
            'flow_fetch'   => FlowStepConfigFactory::build(
                array(
                    'flow_step_id'     => 'flow_fetch',
                    'pipeline_step_id' => 'pipeline_fetch',
                    'step_type'        => 'fetch',
                    'execution_order'  => 0,
                    'pipeline_id'      => $pipeline_id,
                    'flow_id'          => $flow_id,
                    'handler_slug'     => 'fake_fetch',
                    'handler_config'   => array('source' => 'contract-test'),
                    'queue_mode'       => 'static',
                )
            ),
            'flow_ai'      => FlowStepConfigFactory::build(
                array(
                    'flow_step_id'     => 'flow_ai',
                    'pipeline_step_id' => 'pipeline_ai',
                    'step_type'        => 'ai',
                    'execution_order'  => 1,
                    'pipeline_id'      => $pipeline_id,
                    'flow_id'          => $flow_id,
                    'queue_mode'       => 'static',
                    'prompt_queue'     => array(
                        array(
                            'prompt'   => 'Transform the fetched packet and call fake_publish_tool.',
                            'added_at' => gmdate('c'),
                        ),
                    ),
                )
            ),
            'flow_publish' => FlowStepConfigFactory::build(
                array(
                    'flow_step_id'     => 'flow_publish',
                    'pipeline_step_id' => 'pipeline_publish',
                    'step_type'        => 'publish',
                    'execution_order'  => 2,
                    'pipeline_id'      => $pipeline_id,
                    'flow_id'          => $flow_id,
                    'handler_slug'     => 'fake_publish',
                    'handler_config'   => array('destination' => 'contract-test'),
                )
            ),
        );
    }

    /**
     * Return the latest scheduled step captured by the action hook.
     */
    private function _latestScheduledStep(): array
    {
        $this->assertNotEmpty($this->scheduled_steps, 'Expected at least one scheduled step event.');

        return $this->scheduled_steps[array_key_last($this->scheduled_steps)];
    }

    /**
     * Determine whether a log line with a handler context was captured.
     */
    private function _logWasCaptured(string $message, string $handler): bool
    {
        foreach ($this->captured_logs as $log) {
            if ($message !== ($log['message'] ?? '')) {
                continue;
            }

            if ($handler === ($log['context']['handler'] ?? '')) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Fake fetch handler that returns one real DataPacket.
 */
class FakePipelineFetchHandler
{
    /**
     * Return a synthetic fetched packet.
     *
     * @param int|string $pipeline_id Pipeline ID.
     * @param array      $handler_settings Handler settings.
     * @param string     $job_id Job ID.
     * @return DataPacket[]
     */
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    public function get_fetch_data($pipeline_id, array $handler_settings, string $job_id): array
    {
        $pipeline_id;
        $handler_settings;

        return array(
            new DataPacket(
                array(
                    'title' => 'Synthetic Fetch Source',
                    'body'  => 'Synthetic fetched source body for fake AI pipeline coverage.',
                ),
                array(
                    'source_type'     => 'fake_fetch',
                    'item_identifier' => 'fake-fetch-item-' . $job_id,
                ),
                'fetch'
            ),
        );
    }
}

/**
 * Fake publish handler metadata target.
 */
class FakePipelinePublishHandler
{
}

/**
 * Fake handler tool executed by the real ToolExecutor.
 */
class FakePipelinePublishTool
{
    /**
     * Handle the fake publish tool call.
     *
     * @param array $parameters Complete tool parameters.
     * @param array $tool_def Tool definition.
     * @return array Tool result.
     */
    public function handle(array $parameters, array $tool_def): array
    {
        $tool_def;

        $job_id = (int) ($parameters['job_id'] ?? 0);
        if ($job_id > 0) {
            datamachine_merge_engine_data($job_id, array('fake_handler_written_key' => 'preserved'));
        }

        return array(
            'success' => true,
            'data'    => array(
                'title'   => $parameters['title'] ?? '',
                'content' => $parameters['content'] ?? '',
                'job_id'   => $job_id,
            ),
        );
    }
}
