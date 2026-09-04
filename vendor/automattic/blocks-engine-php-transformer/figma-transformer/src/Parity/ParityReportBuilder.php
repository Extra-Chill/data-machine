<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Parity;

/**
 * Builds the stable parity report envelope from runner-supplied evidence.
 *
 * The envelope keeps the original single-viewport fields (`status`, `source`,
 * `generated`, `diff`, `viewport`, `metrics`, ...) for backward compatibility.
 * Runners that exercise multiple breakpoints can additionally supply a
 * `breakpoints` list; each entry is normalized through the same vocabulary as
 * the single-viewport evidence and rolled up into an `aggregate_status`.
 */
final class ParityReportBuilder
{
    public const SCHEMA = 'blocks-engine/figma-transformer/parity-report/v1';

    private const KNOWN_STATUSES = array('not_run', 'pending', 'compared', 'pass', 'fail');

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function build(array $evidence = array(), array $overrides = array()): array
    {
        $visual = $this->normalizeVisualEvidence($evidence, $overrides);

        $artifacts = $this->arrayValue($evidence, 'artifacts');
        $layoutDiagnostics = $this->arrayValue($evidence, 'layout_diagnostics');
        $layoutEvidence = $this->arrayValue($evidence, 'layout_evidence');
        $renderStyleEvidence = $this->arrayValue($evidence, 'render_style_evidence');

        $this->copyScalar($evidence, 'report_path', $artifacts, 'report_path');
        $this->copyScalar($evidence, 'report_artifact', $artifacts, 'report_artifact');
        $this->copyScalar($evidence, 'dom_boxes_path', $artifacts, 'dom_boxes_path');
        $this->copyScalar($evidence, 'layout_report_path', $artifacts, 'layout_report_path');
        $this->copyScalar($evidence, 'layout_mismatch_report_path', $artifacts, 'layout_mismatch_report_path');
        $this->copyScalar($evidence, 'render_evidence_path', $artifacts, 'render_evidence_path');
        $this->copyNumeric($evidence, 'layout_mismatch_count', $layoutDiagnostics, 'mismatch_count');

        if ( isset($evidence['layout_top_nodes']) && is_array($evidence['layout_top_nodes']) ) {
            $layoutDiagnostics['top_nodes'] = array_values($evidence['layout_top_nodes']);
        }

        $hasLayoutEvidence = ! empty($layoutDiagnostics)
            || isset($artifacts['dom_boxes_path'])
            || isset($artifacts['layout_report_path'])
            || isset($artifacts['layout_mismatch_report_path']);
        $layoutMismatchCount = $layoutDiagnostics['mismatch_count'] ?? null;
        if ( empty($layoutEvidence) && $hasLayoutEvidence ) {
            $layoutEvidence = array(
                'status' => is_numeric($layoutMismatchCount) ? (0 === (int) $layoutMismatchCount ? 'pass' : 'fail') : 'pending',
                'source' => isset($artifacts['dom_boxes_path']) ? 'dom_boxes' : 'layout_report',
                'mismatch_count' => is_numeric($layoutMismatchCount) ? (int) $layoutMismatchCount : null,
            );
        }

        if ( empty($renderStyleEvidence) ) {
            $renderStyleEvidence = array(
                'status' => isset($artifacts['render_evidence_path']) ? 'pending' : 'not_run',
            );
        }

        $breakpoints = $this->buildBreakpoints($evidence);
        $aggregateStatus = $this->aggregateStatus($visual['status'], $breakpoints);

        return array(
            'schema'       => self::SCHEMA,
            'status'       => $visual['status'],
            'reason'       => $visual['reason'],
            'artifacts'    => $artifacts,
            'source'       => $visual['source'],
            'generated'    => $visual['generated'],
            'side_by_side' => $evidence['side_by_side'] ?? null,
            'diff'         => empty($visual['diff']) ? null : $visual['diff'],
            'diff_summary' => $visual['diff_summary'],
            'layout_diagnostics' => $layoutDiagnostics,
            'layout_evidence' => $layoutEvidence,
            'render_style_evidence' => $renderStyleEvidence,
            'visual_pixel_status' => $visual['visual_pixel_status'],
            'metrics'      => $visual['metrics'],
            'viewport'     => $visual['viewport'],
            'breakpoints'  => $breakpoints,
            'aggregate_status' => $aggregateStatus,
        );
    }

    /**
     * Normalize the screenshot/diff/viewport evidence shared by the
     * single-viewport envelope and each per-breakpoint entry.
     *
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $overrides
     * @return array{
     *     status: string,
     *     reason: string,
     *     source: array<string, mixed>,
     *     generated: array<string, mixed>,
     *     diff: array<string, mixed>,
     *     diff_summary: array<string, mixed>,
     *     metrics: array<string, mixed>,
     *     viewport: array<string, mixed>,
     *     visual_pixel_status: string
     * }
     */
    private function normalizeVisualEvidence(array $evidence, array $overrides = array()): array
    {
        $status = (string) ($overrides['status'] ?? $evidence['status'] ?? 'not_run');
        if ( ! in_array($status, self::KNOWN_STATUSES, true) ) {
            $status = 'pending';
        }
        $reason = (string) ($overrides['reason'] ?? $evidence['reason'] ?? '');

        $source = $this->arrayValue($evidence, 'source');
        $generated = $this->arrayValue($evidence, 'generated');
        $diff = $this->nullableArrayValue($evidence, 'diff');
        $diffSummary = $this->arrayValue($evidence, 'diff_summary');
        $metrics = $this->arrayValue($evidence, 'metrics');
        $viewport = $this->arrayValue($evidence, 'viewport');

        $this->copyScalar($evidence, 'source_screenshot_path', $source, 'screenshot_path');
        $this->copyScalar($evidence, 'source_screenshot_url', $source, 'screenshot_url');
        $this->copyScalar($evidence, 'source_screenshot_artifact', $source, 'screenshot_artifact');
        $this->copyBool($evidence, 'source_screenshot_exists', $source, 'screenshot_exists');
        $this->copyBool($evidence, 'source_screenshot_readable', $source, 'screenshot_readable');
        $this->copyScalar($evidence, 'generated_screenshot_path', $generated, 'screenshot_path');
        $this->copyScalar($evidence, 'generated_screenshot_url', $generated, 'screenshot_url');
        $this->copyScalar($evidence, 'generated_screenshot_artifact', $generated, 'screenshot_artifact');
        $this->copyBool($evidence, 'generated_screenshot_exists', $generated, 'screenshot_exists');
        $this->copyBool($evidence, 'generated_screenshot_readable', $generated, 'screenshot_readable');
        $this->copyScalar($evidence, 'diff_image_path', $diff, 'image_path');
        $this->copyScalar($evidence, 'diff_image_url', $diff, 'image_url');
        $this->copyScalar($evidence, 'diff_image_artifact', $diff, 'image_artifact');
        $this->copyBool($evidence, 'diff_image_exists', $diff, 'image_exists');
        $this->copyBool($evidence, 'diff_image_readable', $diff, 'image_readable');
        $this->copyScalar($evidence, 'frame_id', $source, 'frame_id');
        $this->copyScalar($evidence, 'frame_id', $generated, 'frame_id');
        $this->copyNumeric($evidence, 'pixel_mismatch_count', $diffSummary, 'pixel_mismatch_count');
        $this->copyNumeric($evidence, 'pixel_mismatch_count', $metrics, 'pixel_mismatch_count');
        $this->copyNumeric($evidence, 'pixel_mismatch_ratio', $diffSummary, 'pixel_mismatch_ratio');
        $this->copyNumeric($evidence, 'pixel_mismatch_ratio', $metrics, 'pixel_mismatch_ratio');
        $this->copyNumeric($evidence, 'threshold', $diffSummary, 'threshold');

        if ( array_key_exists('threshold', $diffSummary) && array_key_exists('pixel_mismatch_ratio', $diffSummary) ) {
            $diffSummary['passed'] = (float) $diffSummary['pixel_mismatch_ratio'] <= (float) $diffSummary['threshold'];
        }

        $hasPixelEvidence = array_key_exists('pixel_mismatch_count', $metrics)
            || array_key_exists('pixel_mismatch_ratio', $metrics)
            || array_key_exists('pixel_mismatch_count', $diffSummary)
            || array_key_exists('pixel_mismatch_ratio', $diffSummary);
        $hasScreenshotCandidates = ! empty($source) || ! empty($generated);
        if ( ! $hasPixelEvidence && $hasScreenshotCandidates && 'not_run' === $status ) {
            $status = 'pending';
            $reason = '' !== $reason ? $reason : 'screenshot_evidence_configured';
        }
        $visualPixelStatus = $hasPixelEvidence ? $status : 'not_run';

        return array(
            'status'              => $status,
            'reason'              => $reason,
            'source'              => $source,
            'generated'           => $generated,
            'diff'                => $diff,
            'diff_summary'        => $diffSummary,
            'metrics'             => $metrics,
            'viewport'            => $viewport,
            'visual_pixel_status' => $visualPixelStatus,
        );
    }

    /**
     * Build the per-breakpoint parity entries from runner-supplied evidence.
     *
     * @param array<string, mixed> $evidence
     * @return array<int, array<string, mixed>>
     */
    private function buildBreakpoints(array $evidence): array
    {
        if ( ! isset($evidence['breakpoints']) || ! is_array($evidence['breakpoints']) ) {
            return array();
        }

        $entries = array();
        foreach ( $evidence['breakpoints'] as $entry ) {
            if ( ! is_array($entry) ) {
                continue;
            }
            $entries[] = $this->buildBreakpointEntry($entry);
        }

        return $entries;
    }

    /**
     * Normalize a single breakpoint entry into the stable parity vocabulary.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function buildBreakpointEntry(array $entry): array
    {
        $visual = $this->normalizeVisualEvidence($entry);
        $frameId = $visual['source']['frame_id'] ?? ($visual['generated']['frame_id'] ?? null);

        return array(
            'status'              => $visual['status'],
            'reason'              => $visual['reason'],
            'viewport'            => $visual['viewport'],
            'frame_id'            => $frameId,
            'source'              => $visual['source'],
            'generated'           => $visual['generated'],
            'diff'                => empty($visual['diff']) ? null : $visual['diff'],
            'diff_summary'        => $visual['diff_summary'],
            'visual_pixel_status' => $visual['visual_pixel_status'],
            'metrics'             => $visual['metrics'],
        );
    }

    /**
     * Roll a list of per-breakpoint statuses up into a single aggregate status.
     *
     * `pass` requires every breakpoint to pass; any failing breakpoint fails the
     * aggregate. When no breakpoints are supplied the single-viewport status is
     * authoritative, preserving the original contract.
     *
     * @param array<int, array<string, mixed>> $breakpoints
     */
    private function aggregateStatus(string $singleViewportStatus, array $breakpoints): string
    {
        if ( empty($breakpoints) ) {
            return $singleViewportStatus;
        }

        $statuses = array();
        foreach ( $breakpoints as $breakpoint ) {
            $statuses[] = (string) ($breakpoint['status'] ?? 'not_run');
        }

        if ( in_array('fail', $statuses, true) ) {
            return 'fail';
        }
        $runStatuses = array_filter($statuses, static fn (string $status): bool => 'not_run' !== $status);
        if ( empty($runStatuses) ) {
            return 'not_run';
        }
        if ( in_array('pending', $statuses, true) || in_array('not_run', $statuses, true) ) {
            return 'pending';
        }
        if ( in_array('compared', $statuses, true) ) {
            return 'compared';
        }

        return 'pass';
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function arrayValue(array $values, string $key): array
    {
        return isset($values[$key]) && is_array($values[$key]) ? $values[$key] : array();
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function nullableArrayValue(array $values, string $key): array
    {
        return isset($values[$key]) && is_array($values[$key]) ? $values[$key] : array();
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function copyScalar(array $source, string $sourceKey, array &$target, string $targetKey): void
    {
        if ( isset($source[$sourceKey]) && is_scalar($source[$sourceKey]) ) {
            $target[$targetKey] = (string) $source[$sourceKey];
        }
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function copyBool(array $source, string $sourceKey, array &$target, string $targetKey): void
    {
        if ( isset($source[$sourceKey]) && is_bool($source[$sourceKey]) ) {
            $target[$targetKey] = $source[$sourceKey];
        }
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function copyNumeric(array $source, string $sourceKey, array &$target, string $targetKey): void
    {
        if ( isset($source[$sourceKey]) && is_numeric($source[$sourceKey]) ) {
            $target[$targetKey] = str_contains((string) $source[$sourceKey], '.') ? (float) $source[$sourceKey] : (int) $source[$sourceKey];
        }
    }
}
