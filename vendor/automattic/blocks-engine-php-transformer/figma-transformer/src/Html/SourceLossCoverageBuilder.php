<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds source-loss coverage summaries for artifact-quality diagnostics.
 */
final class SourceLossCoverageBuilder
{
    /**
     * @param array<string, array<string, mixed>> $domains
     * @return array<string, mixed>
     */
    public function aggregate(array $domains): array
    {
        $decoded = 0;
        $emitted = 0;
        $notEmitted = 0;
        $unsupportedFields = 0;
        foreach ( $domains as $domain ) {
            $decoded += (int) ($domain['decoded_source_nodes'] ?? 0);
            $emitted += (int) ($domain['emitted_source_nodes'] ?? 0);
            $notEmitted += (int) ($domain['not_emitted_source_nodes'] ?? 0);
            $unsupportedFields += (int) ($domain['field_support']['unsupported_field_occurrences'] ?? 0);
        }

        $nodeCoverageRatio = $decoded > 0 ? round($emitted / $decoded, 3) : 1.0;
        $uncovered = max(0, $notEmitted, $decoded - $emitted);

        return array(
            'schema' => 'blocks-engine/figma-transformer/source-loss-coverage/v2',
            'decoded_source_nodes' => $decoded,
            'emitted_source_nodes' => $emitted,
            'not_emitted_source_nodes' => $notEmitted,
            'node_coverage' => array(
                'unit' => 'source_nodes',
                'decoded_source_nodes' => $decoded,
                'emitted_source_nodes' => $emitted,
                'not_emitted_source_nodes' => $notEmitted,
                'uncovered_source_nodes' => $uncovered,
                'coverage_ratio' => $nodeCoverageRatio,
            ),
            'field_support' => array(
                'unit' => 'field_occurrences',
                'unsupported_visual_field_occurrences' => $unsupportedFields,
                'support_status' => $unsupportedFields > 0 ? 'unsupported_fields_present' : 'not_measured',
            ),
            'full_coverage' => 0 === $uncovered && 0 === $unsupportedFields,
            'domains' => $domains,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function domain(int $decoded, int $emitted, int $notEmitted, int $intentionallySuppressed = 0, int $unsupported = 0, array $evidence = array()): array
    {
        $decoded = max(0, $decoded);
        $emitted = max(0, $emitted);
        $notEmitted = max(0, $notEmitted);
        $intentionallySuppressed = max(0, $intentionallySuppressed);
        $unsupported = max(0, $unsupported);
        $covered = min($decoded, $emitted + $intentionallySuppressed);
        $uncovered = max($notEmitted, $decoded - $covered);

        return array(
            'decoded_source_nodes' => $decoded,
            'emitted_source_nodes' => $covered,
            'intentionally_suppressed_source_nodes' => min($decoded, $intentionallySuppressed),
            'not_emitted_source_nodes' => $notEmitted,
            'node_coverage' => array(
                'unit' => 'source_nodes',
                'coverage_ratio' => $decoded > 0 ? round($covered / $decoded, 3) : 1.0,
                'uncovered_source_nodes' => $uncovered,
            ),
            'field_support' => array(
                'unit' => 'field_occurrences',
                'unsupported_field_occurrences' => $unsupported,
            ),
            'evidence' => $evidence,
        );
    }

    /**
     * Convert bounded Kiwi skipped-field evidence into visual-domain counts.
     * Metadata roles remain visible in the evidence summary but do not affect
     * visual coverage.
     *
     * @param array<string, mixed> $inventory
     * @return array{domains: array<string, int>, summary: array<string, mixed>}
     */
    public function skippedFieldEvidence(array $inventory): array
    {
        $summary = is_array($inventory['summary'] ?? null) ? $inventory['summary'] : $inventory;
        $byRole = is_array($summary['by_role'] ?? null) ? $summary['by_role'] : array();
        $domainByRole = array(
            'text_style' => 'text',
            'fills_images' => 'paint_style',
            'geometry_layout' => 'geometry_layout',
            'component_overrides' => 'component_overrides',
            'masks_effects' => 'paint_style',
            'vectors' => 'vectors',
        );
        $excludedRoles = array('document_metadata', 'dev_status', 'export_metadata');
        $domains = array();
        $excluded = 0;
        $unclassified = 0;
        $unclassifiedByRole = array();

        foreach ( $byRole as $role => $count ) {
            $count = max(0, (int) $count);
            if ( isset($domainByRole[$role]) ) {
                $domain = $domainByRole[$role];
                $domains[$domain] = ($domains[$domain] ?? 0) + $count;
            } elseif ( in_array($role, $excludedRoles, true) ) {
                $excluded += $count;
            } else {
                $unclassified += $count;
                $unclassifiedByRole[$role] = $count;
            }
        }
        ksort($domains);

        return array(
            'domains' => $domains,
            'summary' => array(
                'schema' => (string) ($inventory['schema'] ?? 'blocks-engine/figma-transformer/kiwi-skipped-field-inventory/v1'),
                'total_occurrences' => (int) ($summary['occurrences'] ?? array_sum($byRole)),
                'visually_meaningful_unsupported_occurrences' => array_sum($domains),
                'excluded_metadata_occurrences' => $excluded,
                'unclassified_occurrences' => $unclassified,
                'unclassified_by_role' => $unclassifiedByRole,
                'by_role' => $byRole,
            ),
        );
    }

    /**
     * @param array<string, mixed> $images
     * @return array<string, mixed>
     */
    public function imageDomain(array $images): array
    {
        $decoded = (int) ($images['node_refs'] ?? 0);
        $assetNodes = is_array($images['asset_nodes'] ?? null) ? $images['asset_nodes'] : array();
        if ( empty($assetNodes) ) {
            return $this->domain($decoded, (int) ($images['resolved_assets'] ?? 0), count($images['missing_assets'] ?? array()));
        }

        $emitted = 0;
        $suppressed = 0;
        foreach ( $assetNodes as $assetNode ) {
            if ( ! is_array($assetNode) ) {
                continue;
            }
            if ( true === ($assetNode['emitted'] ?? null) ) {
                ++$emitted;
                continue;
            }
            if ( $this->isIntentionallySuppressedAssetNode($assetNode) ) {
                ++$suppressed;
            }
        }

        return $this->domain($decoded, $emitted, $decoded - $emitted - $suppressed, $suppressed);
    }

    /**
     * @param array<string, mixed> $assetNode
     */
    private function isIntentionallySuppressedAssetNode(array $assetNode): bool
    {
        if ( isset($assetNode['source_loss_reason']) && is_scalar($assetNode['source_loss_reason']) && '' !== (string) $assetNode['source_loss_reason'] ) {
            return true;
        }

        $reason = isset($assetNode['reason']) && is_scalar($assetNode['reason']) ? (string) $assetNode['reason'] : '';
        return in_array($reason, array('hidden', 'hidden_parent', 'clipped_masked', 'zero_area'), true);
    }

    /**
     * @param array<string, mixed> $vectors
     * @return array<string, mixed>
     */
    public function vectorDomain(array $vectors): array
    {
        return $this->domain(
            (int) ($vectors['nodes'] ?? 0),
            (int) ($vectors['rendered_paths'] ?? 0) + (int) ($vectors['rendered_asset_fallbacks'] ?? 0),
            (int) ($vectors['placeholders'] ?? 0)
        );
    }
}
