<?php
/**
 * Universal AI tool execution infrastructure.
 *
 * Shared tool execution logic used by both Chat and Pipeline agents.
 * Handles tool discovery, validation, execution, and parameter building.
 *
 * @package DataMachine\Engine\AI\Tools
 * @since   0.2.0
 */

namespace DataMachine\Engine\AI\Tools;

defined('ABSPATH') || exit;

class ToolExecutor
{

    /**
     * Get available tools for AI agent execution.
     * Used by both chat and pipeline agents.
     *
     * @param  array|null  $previous_step_config     Previous step configuration (pipeline only)
     * @param  array|null  $next_step_config         Next step configuration (pipeline only)
     * @param  string|null $current_pipeline_step_id Current pipeline step ID (pipeline only)
     * @param  array       $engine_data              Engine data snapshot for dynamic tool generation
     * @return array Available tools array
     */
    public static function getAvailableTools( ?array $previous_step_config = null, ?array $next_step_config = null, ?string $current_pipeline_step_id = null, array $engine_data = array() ): array
    {
        $available_tools = array();
        $tool_manager    = new ToolManager();

        // Load tools from adjacent steps (supports both singular and plural handler config)
        foreach ( array( $previous_step_config, $next_step_config ) as $step_config ) {
            if (! $step_config ) {
                continue;
            }

            // Data is normalized at the DB layer — handler_slugs/handler_configs are canonical.
            $handler_slugs       = $step_config['handler_slugs'] ?? array();
            $handler_configs_map = $step_config['handler_configs'] ?? array();

            foreach ( $handler_slugs as $slug ) {
                $handler_config  = $handler_configs_map[ $slug ] ?? array();
                $tools           = apply_filters('chubes_ai_tools', array(), $slug, $handler_config, $engine_data);
                $tools           = $tool_manager->resolveAllTools($tools);
                $allowed         = self::getAllowedTools($tools, $slug, $current_pipeline_step_id, $tool_manager);
                $available_tools = array_merge($available_tools, $allowed);
            }
        }

        // Load global tools (available to all AI agents) - use ToolManager which resolves callables
        $global_tools         = $tool_manager->get_global_tools();
        $allowed_global_tools = self::getAllowedTools($global_tools, null, $current_pipeline_step_id, $tool_manager);
        $available_tools      = array_merge($available_tools, $allowed_global_tools);

        return array_unique($available_tools, SORT_REGULAR);
    }

    /**
     * Get allowed tools based on enablement and configuration.
     *
     * @param  array       $all_tools        All available tools (must be resolved, not callables)
     * @param  string|null $handler_slug     Handler slug for filtering
     * @param  string|null $pipeline_step_id Pipeline step ID (pipeline only, null for chat)
     * @param  ToolManager $tool_manager     Tool manager instance for availability checks
     * @return array Filtered allowed tools
     */
    private static function getAllowedTools( array $all_tools, ?string $handler_slug, ?string $pipeline_step_id, ToolManager $tool_manager ): array
    {
        $allowed_tools = array();

        foreach ( $all_tools as $tool_name => $tool_config ) {
            // Skip if not a valid array definition
            if (! is_array($tool_config) ) {
                continue;
            }

            if (isset($tool_config['handler']) ) {
                if ($tool_config['handler'] === $handler_slug ) {
                    $allowed_tools[ $tool_name ] = $tool_config;
                }
                continue;
            }

            // Direct ToolManager call replaces filter
            if ($tool_manager->is_tool_available($tool_name, $pipeline_step_id) ) {
                $allowed_tools[ $tool_name ] = $tool_config;
            }
        }

        return $allowed_tools;
    }

    /**
     * Execute tool with parameter merging and comprehensive error handling.
     * Builds complete parameters by combining AI parameters with step payload.
     *
     * @param  string $tool_name       Tool name to execute
     * @param  array  $tool_parameters Parameters from AI
     * @param  array  $available_tools Available tools array
     * @param  array  $payload         Step payload (job_id, flow_step_id, data, flow_step_config)
     * @return array Tool execution result
     */
    public static function executeTool( string $tool_name, array $tool_parameters, array $available_tools, array $payload ): array
    {
        $tool_def = $available_tools[ $tool_name ] ?? null;
        if (! $tool_def ) {
            return array(
            'success'   => false,
            'error'     => "Tool '{$tool_name}' not found",
            'tool_name' => $tool_name,
            );
        }

        $validation = self::validateRequiredParameters($tool_parameters, $tool_def);
        if (! $validation['valid'] ) {
            return array(
            'success'   => false,
            'error'     => sprintf(
                '%s requires the following parameters: %s. Please provide these parameters and try again.',
                ucwords(str_replace('_', ' ', $tool_name)),
                implode(', ', $validation['missing'])
            ),
            'tool_name' => $tool_name,
            );
        }

        $complete_parameters = ToolParameters::buildParameters(
            $tool_parameters,
            $payload,
            $tool_def
        );

        // Ensure tool definition has required 'class' key
        if (! isset($tool_def['class']) || empty($tool_def['class']) ) {
            return array(
            'success'   => false,
            'error'     => "Tool '{$tool_name}' is missing required 'class' definition. This may indicate the tool was not properly resolved from a callable.",
            'tool_name' => $tool_name,
            );
        }

        $class_name = $tool_def['class'];
        if (! class_exists($class_name) ) {
            return array(
            'success'   => false,
            'error'     => "Tool class '{$class_name}' not found",
            'tool_name' => $tool_name,
            );
        }

        $tool_handler = new $class_name();
        $tool_result  = $tool_handler->handle_tool_call($complete_parameters, $tool_def);

        return $tool_result;
    }

    /**
     * Validate that all required parameters are present.
     *
     * @param  array $tool_parameters Parameters from AI
     * @param  array $tool_def        Tool definition with parameter specs
     * @return array Validation result with 'valid', 'required', and 'missing' keys
     */
    private static function validateRequiredParameters( array $tool_parameters, array $tool_def ): array
    {
        $required = array();
        $missing  = array();

        $param_defs = $tool_def['parameters'] ?? array();

        foreach ( $param_defs as $param_name => $param_config ) {
            if (! is_array($param_config) ) {
                continue;
            }

            if (! empty($param_config['required']) ) {
                $required[] = $param_name;

                if (! isset($tool_parameters[ $param_name ]) || '' === $tool_parameters[ $param_name ] ) {
                    $missing[] = $param_name;
                }
            }
        }

        return array(
        'valid'    => empty($missing),
        'required' => $required,
        'missing'  => $missing,
        );
    }
}
