<?php
/**
 * Standalone-smoke bootstrap for the Core scheduler evidence provider.
 *
 * Smokes that require Core liveness classes without the Composer autoloader load
 * the evidence provider plus the classes whose hook constants it references.
 * Every file is declaration-only, so no WordPress runtime is needed.
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/../../inc/Core/DirectJobEnqueuer.php';
require_once __DIR__ . '/../../inc/Engine/AI/AIConcurrencyBackpressure.php';
require_once __DIR__ . '/../../inc/Abilities/Engine/PipelineBatchScheduler.php';
require_once __DIR__ . '/../../inc/Engine/Tasks/TaskScheduler.php';
require_once __DIR__ . '/../../inc/Engine/Scheduling/FlowRoutines.php';
require_once __DIR__ . '/../../inc/Engine/AI/System/Tasks/SystemTask.php';
require_once __DIR__ . '/../../inc/Core/Jobs/SchedulerEvidence.php';
require_once __DIR__ . '/../../inc/Core/RunMetrics.php';
