<?php
/**
 * Abstract base class for all Data Machine step types.
 *
 * Provides common functionality for payload handling, validation, logging,
 * and exception handling across all step implementations.
 *
 * @package DataMachine\Core\Steps
 * @since   0.2.1
 */

namespace DataMachine\Core\Steps;

use DataMachine\Core\EngineData;

if (! defined('ABSPATH') ) {
    exit;
}

abstract class Step
{

    /**
     * Step type identifier.
     *
     * @var string
     */
    protected string $step_type;

    /**
     * Job ID from payload.
     *
     * @var int
     */
    protected int $job_id;

    /**
     * Flow step ID from payload.
     *
     * @var string
     */
    protected string $flow_step_id;

    /**
     * Data packets array from payload.
     *
     * @var array
     */
    protected array $dataPackets;

    /**
     * Flow step configuration from payload.
     *
     * @var array
     */
    protected array $flow_step_config;

    /**
     * Engine data loaded from centralized storage.
     *
     * @var array
     */
    protected array $engine_data = array();

    /**
     * Engine snapshot helper.
     */
    protected EngineData $engine;

    /**
     * Initialize step with type identifier.
     *
     * @param string $step_type Step type identifier (fetch, ai, publish, update)
     */
    public function __construct( string $step_type )
    {
        $this->step_type = $step_type;
    }

    /**
     * Execute step with unified payload handling.
     *
     * @param  array $payload Unified step payload (job_id, flow_step_id, data, flow_step_config)
     * @return array Updated data packet array
     */
    public function execute( array $payload ): array
    {
        try {
            // Destructure payload to properties
            $this->destructurePayload($payload);

            // Validate common configuration
            if (! $this->validateCommonConfiguration() ) {
                return $this->dataPackets;
            }

            // Validate step-specific configuration
            if (! $this->validateStepConfiguration() ) {
                return $this->dataPackets;
            }

            // Execute step-specific logic
            return $this->executeStep();
        } catch ( \Exception $e ) {
            return $this->handleException($e);
        }
    }

    /**
     * Execute step-specific logic.
     * Called after payload destructuring and common validation.
     *
     * @return array Updated data packet array
     */
    abstract protected function executeStep(): array;

    /**
     * Validate step-specific configuration requirements.
     * Default implementation checks for handler_slug. Override for custom validation.
     *
     * @return bool True if configuration is valid, false otherwise
     */
    protected function validateStepConfiguration(): bool
    {
        $handler = $this->getHandlerSlug();

        if (empty($handler) ) {
            $this->logConfigurationError(
                'Step requires handler configuration',
                array(
                'available_flow_step_config' => array_keys($this->flow_step_config),
                )
            );
            return false;
        }

        return true;
    }

    /**
     * Extract and store payload fields to class properties.
     *
     * @param  array $payload Unified step payload
     * @return void
     */
    protected function destructurePayload( array $payload ): void
    {
        if (! isset($payload['job_id']) || empty($payload['job_id']) ) {
            throw new \InvalidArgumentException('Job ID is required in step payload');
        }
        if (! isset($payload['flow_step_id']) || empty($payload['flow_step_id']) ) {
            throw new \InvalidArgumentException('Flow step ID is required in step payload');
        }

        $this->job_id       = $payload['job_id'];
        $this->flow_step_id = $payload['flow_step_id'];
        $this->dataPackets  = is_array($payload['data'] ?? null) ? $payload['data'] : array();
        $engine             = $payload['engine'] ?? null;
        if (! $engine instanceof EngineData ) {
            $engine = new EngineData(datamachine_get_engine_data($this->job_id), $this->job_id);
        }

        $this->engine           = $engine;
        $this->engine_data      = $engine->all();
        $this->flow_step_config = $engine->getFlowStepConfig($this->flow_step_id);

        if (empty($this->flow_step_config) ) {
            throw new \RuntimeException('Flow step configuration missing from engine snapshot');
        }
    }

    /**
     * Centralized logging with consistent context.
     *
     * Automatically includes job_id, pipeline_id, and flow_id from engine context
     * to ensure all step logs can be filtered and queried effectively.
     *
     * @param  string $level   Log level (debug, info, warning, error)
     * @param  string $message Log message
     * @param  array  $context Additional context data
     * @return void
     */
    protected function log( string $level, string $message, array $context = array() ): void
    {
        $job_context = $this->engine->getJobContext();

        $full_context = array_merge(
            array(
            'flow_step_id' => $this->flow_step_id,
            'step_type'    => $this->step_type,
            'job_id'       => $job_context['job_id'] ?? $this->job_id,
            'pipeline_id'  => $job_context['pipeline_id'] ?? null,
            'flow_id'      => $job_context['flow_id'] ?? null,
            ),
            $context
        );

        // Remove null values to keep logs clean
        $full_context = array_filter($full_context, fn( $v ) => null !== $v);

        do_action('datamachine_log', $level, $message, $full_context);
    }



    /**
     * Log configuration errors with consistent formatting.
     *
     * @param  string $message            Error message
     * @param  array  $additional_context Additional context beyond flow_step_id
     * @return void
     */
    protected function logConfigurationError( string $message, array $additional_context = array() ): void
    {
        $this->log('error', $this->step_type . ': ' . $message, $additional_context);
    }



    /**
     * Get handler slug from flow step configuration.
     *
     * @return string|null Handler slug or null if not set
     */
    protected function getHandlerSlug(): ?string
    {
        return $this->flow_step_config['handler_slugs'][0] ?? null;
    }

    /**
     * Get handler configuration from flow step configuration.
     *
     * @return array Handler configuration array
     */
    protected function getHandlerConfig(): array
    {
        $slug = $this->getHandlerSlug();
        return ! empty( $slug ) ? ( $this->flow_step_config['handler_configs'][ $slug ] ?? array() ) : array();
    }

    /**
     * Get handler slugs (supports both singular and plural config).
     *
     * @return array Handler slug array
     */
    protected function getHandlerSlugs(): array
    {
        return $this->flow_step_config['handler_slugs'] ?? array();
    }

    /**
     * Get handler configs keyed by handler slug.
     *
     * @return array<string, array> Handler configs keyed by slug
     */
    protected function getHandlerConfigs(): array
    {
        return $this->flow_step_config['handler_configs'] ?? array();
    }

    /**
     * Handle exceptions with consistent logging and data packet return.
     *
     * @param  \Exception $e       Exception instance
     * @param  string     $context Context where exception occurred
     * @return array Data packet array (unchanged on exception)
     */
    protected function handleException( \Exception $e, string $context = 'execution' ): array
    {
        $this->log(
            'error',
            $this->step_type . ': Exception during ' . $context,
            array(
            'exception' => $e->getMessage(),
            'trace'     => $e->getTraceAsString(),
            )
        );

        return $this->dataPackets;
    }

    /**
     * Validate common configuration requirements shared by all steps.
     *
     * @return bool True if common validation passes, false otherwise
     */
    protected function validateCommonConfiguration(): bool
    {
        if (empty($this->flow_step_config) ) {
            $this->logConfigurationError('No step configuration provided');
            return false;
        }

        return true;
    }
}
