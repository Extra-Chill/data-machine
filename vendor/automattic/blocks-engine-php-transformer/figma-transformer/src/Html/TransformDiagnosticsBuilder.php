<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds aggregate transform diagnostic summaries from collected emitter data.
 */
final class TransformDiagnosticsBuilder
{
    /**
     * @param array<string, mixed> $image
     * @param array<string, mixed> $vectors
     * @param array<string, mixed> $fonts
     * @param array<string, mixed> $assets
     * @param array<string, mixed> $generatedSvgAssets
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $links
     * @param array<string, mixed> $text
     * @param array<string, mixed> $components
     * @param array<string, mixed> $effects
     * @param array<string, mixed> $maskEffectClipping
     * @param array<string, mixed> $css
     * @param array<string, mixed> $htmlArtifact
     * @param array<string, mixed> $decisionTraces
     * @param array<int, array<string, mixed>> $sourceDiagnostics
     * @param array<string, mixed> $sourceLossEvidence
     * @return array<string, mixed>
     */
    public function artifactQualityDiagnostics(array $image, array $vectors, array $fonts, array $assets, array $generatedSvgAssets, array $layout, array $links = array(), array $text = array(), array $components = array(), array $effects = array(), array $maskEffectClipping = array(), array $css = array(), array $htmlArtifact = array(), array $decisionTraces = array(), array $sourceDiagnostics = array(), array $sourceLossEvidence = array()): array
    {
        $signals = array();

        if ( ! empty($image['missing_assets']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'missing_render_assets',
                'count' => count($image['missing_assets']),
                'sample_nodes' => array_slice(is_array($image['missing_assets'] ?? null) ? $image['missing_assets'] : array(), 0, 10),
            );
        }
        if ( ! empty($vectors['placeholders']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'vector_placeholders',
                'count' => (int) $vectors['placeholders'],
            );
        }
        if ( ! empty($fonts['missing_css']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'font_css_missing',
                'count' => count($fonts['missing_css']),
                'font_usage' => $this->fontUsageForFamilies(is_array($fonts['usage'] ?? null) ? $fonts['usage'] : array(), $fonts['missing_css']),
            );
        }
        if ( ! empty($layout['large_negative_left_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'off_canvas_left_css',
                'count' => (int) $layout['large_negative_left_count'],
                'sample_nodes' => array_slice(is_array($layout['large_css_offset_nodes'] ?? null) ? $layout['large_css_offset_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['large_css_offset_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'large_css_offsets',
                'count' => (int) $layout['large_css_offset_count'],
                'sample_nodes' => array_slice(is_array($layout['large_css_offset_nodes'] ?? null) ? $layout['large_css_offset_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['invalid_css_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'invalid_css_tokens',
                'count' => (int) $layout['invalid_css_count'],
                'sample_tokens' => array_slice(is_array($layout['invalid_css_tokens'] ?? null) ? $layout['invalid_css_tokens'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['off_canvas_visual_node_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'off_canvas_visual_nodes',
                'count' => (int) $layout['off_canvas_visual_node_count'],
                'sample_nodes' => array_slice(is_array($layout['off_canvas_visual_nodes'] ?? null) ? $layout['off_canvas_visual_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['clipped_visual_node_count']) && (float) ($layout['clipped_visual_area_ratio'] ?? 0.0) >= 0.25 ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'clipped_visual_area',
                'count' => (int) $layout['clipped_visual_node_count'],
                'clipped_area_ratio' => (float) ($layout['clipped_visual_area_ratio'] ?? 0.0),
                'sample_nodes' => array_slice(is_array($layout['clipped_visual_nodes'] ?? null) ? $layout['clipped_visual_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['large_absolute_offset_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'large_absolute_offsets',
                'count' => (int) $layout['large_absolute_offset_count'],
                'sample_nodes' => array_slice(is_array($layout['large_absolute_offset_nodes'] ?? null) ? $layout['large_absolute_offset_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($layout['image_heavy_landmark_candidates']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'image_heavy_landmark_candidate',
                'count' => count($layout['image_heavy_landmark_candidates']),
            );
        }
        $imageBlockCount = (int) ($image['image_block_count'] ?? 0);
        $totalNodeCount = max(0, (int) ($image['total_node_count'] ?? 0));
        $imageNodeDensity = $totalNodeCount > 0 ? $imageBlockCount / $totalNodeCount : 0.0;
        if ( $imageBlockCount >= 12 && ($imageNodeDensity >= 0.35 || ! empty($layout['image_heavy_landmark_candidates'])) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'excessive_image_blocks',
                'count' => $imageBlockCount,
                'threshold' => 12,
                'image_node_density' => round($imageNodeDensity, 3),
                'sample_nodes' => array_slice(is_array($image['image_block_nodes'] ?? null) ? $image['image_block_nodes'] : array(), 0, 10),
            );
        }
        if ( (int) ($vectors['rendered_asset_fallbacks'] ?? 0) >= 8 ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'excessive_vector_image_fallbacks',
                'count' => (int) $vectors['rendered_asset_fallbacks'],
            );
        }
        if ( (int) ($generatedSvgAssets['bytes'] ?? 0) > 1048576 ) {
            $signals[] = array(
                'severity' => 'info',
                'code' => 'large_generated_svg_assets',
                'count' => (int) ($generatedSvgAssets['count'] ?? 0),
                'bytes' => (int) ($generatedSvgAssets['bytes'] ?? 0),
            );
        }
        if ( ! empty($links['unresolved']) ) {
            $signals[] = array(
                'severity' => 'info',
                'code' => 'link_target_unresolved',
                'count' => (int) $links['unresolved'],
                'sample_nodes' => array_slice(is_array($links['unresolved_targets'] ?? null) ? $links['unresolved_targets'] : array(), 0, 10),
            );
        }
        if ( ! empty($text['missing_emitted_text_node_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'decoded_text_not_emitted',
                'count' => (int) $text['missing_emitted_text_node_count'],
                'sample_nodes' => array_slice(is_array($text['missing_emitted_text_nodes'] ?? null) ? $text['missing_emitted_text_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($text['empty_decoded_text_node_count']) ) {
            $signals[] = array(
                'severity' => 'info',
                'code' => 'decoded_text_empty',
                'count' => (int) $text['empty_decoded_text_node_count'],
                'sample_nodes' => array_slice(is_array($text['empty_decoded_text_nodes'] ?? null) ? $text['empty_decoded_text_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($components['missing_emitted_clone_node_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'component_clone_not_emitted',
                'count' => (int) $components['missing_emitted_clone_node_count'],
                'omission_reason_counts' => is_array($components['omission_reason_counts'] ?? null) ? $components['omission_reason_counts'] : array(),
                'sample_nodes' => array_slice(is_array($components['missing_emitted_clone_nodes'] ?? null) ? $components['missing_emitted_clone_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($effects['missing_emitted_effect_node_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'effect_node_not_emitted',
                'count' => (int) $effects['missing_emitted_effect_node_count'],
                'omission_reason_counts' => is_array($effects['omission_reason_counts'] ?? null) ? $effects['omission_reason_counts'] : array(),
                'sample_nodes' => array_slice(is_array($effects['missing_emitted_effect_nodes'] ?? null) ? $effects['missing_emitted_effect_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($vectors['child_composition']['uncomposed_vector_child_node_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'vector_child_composition_incomplete',
                'count' => (int) $vectors['child_composition']['uncomposed_vector_child_node_count'],
                'sample_nodes' => array_slice(is_array($vectors['child_composition']['sample_nodes'] ?? null) ? $vectors['child_composition']['sample_nodes'] : array(), 0, 10),
            );
        }
        if ( ! empty($css['invalid_numeric_token_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'invalid_css_numeric_token',
                'count' => (int) $css['invalid_numeric_token_count'],
                'sample_tokens' => array_slice(is_array($css['invalid_numeric_tokens'] ?? null) ? $css['invalid_numeric_tokens'] : array(), 0, 10),
            );
        }
        if ( ! empty($htmlArtifact['canvas_like_dom']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'canvas_like_dom',
                'element_count' => (int) ($htmlArtifact['element_count'] ?? 0),
                'div_ratio' => (float) ($htmlArtifact['div_ratio'] ?? 0.0),
                'semantic_density' => (float) ($htmlArtifact['semantic_density'] ?? 0.0),
            );
        }
        if ( ! empty($htmlArtifact['semantic_sparsity']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'semantic_sparsity',
                'element_count' => (int) ($htmlArtifact['element_count'] ?? 0),
                'semantic_element_count' => (int) ($htmlArtifact['semantic_element_count'] ?? 0),
                'semantic_density' => (float) ($htmlArtifact['semantic_density'] ?? 0.0),
            );
        }
        if ( ! empty($htmlArtifact['desktop_canvas_without_responsive_breakpoints']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'desktop_canvas_without_responsive_breakpoints',
                'media_query_count' => (int) ($htmlArtifact['media_query_count'] ?? 0),
                'fixed_width_over_desktop_count' => (int) ($htmlArtifact['fixed_width_over_desktop_count'] ?? 0),
                'large_fixed_canvas_height' => (bool) ($htmlArtifact['large_fixed_canvas_height'] ?? false),
            );
        }
        if ( (int) ($htmlArtifact['fixed_width_declaration_count'] ?? 0) >= 8 && (float) ($htmlArtifact['effective_responsive_coverage_ratio'] ?? 1.0) < 0.35 ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'low_effective_responsive_coverage',
                'coverage_ratio' => (float) ($htmlArtifact['effective_responsive_coverage_ratio'] ?? 0.0),
                'fixed_width_declaration_count' => (int) ($htmlArtifact['fixed_width_declaration_count'] ?? 0),
                'fixed_width_without_responsive_override_count' => (int) ($htmlArtifact['fixed_width_without_responsive_override_count'] ?? 0),
                'sample_rules' => array_slice(is_array($htmlArtifact['fixed_width_samples'] ?? null) ? $htmlArtifact['fixed_width_samples'] : array(), 0, 10),
            );
        }
        if ( ! empty($htmlArtifact['giant_fixed_section_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'giant_fixed_section_risk',
                'count' => (int) $htmlArtifact['giant_fixed_section_count'],
                'sample_rules' => array_slice(is_array($htmlArtifact['giant_fixed_sections'] ?? null) ? $htmlArtifact['giant_fixed_sections'] : array(), 0, 10),
            );
        }
        if ( ! empty($htmlArtifact['large_overflow_risk_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'large_overflow_risk',
                'count' => (int) $htmlArtifact['large_overflow_risk_count'],
                'sample_rules' => array_slice(is_array($htmlArtifact['large_overflow_risks'] ?? null) ? $htmlArtifact['large_overflow_risks'] : array(), 0, 10),
            );
        }
        $fallbackProneIslandCount = (int) ($htmlArtifact['fallback_prone_form_island_count'] ?? 0) + (int) ($htmlArtifact['fallback_prone_svg_island_count'] ?? 0) + (int) ($htmlArtifact['fallback_prone_input_island_count'] ?? 0);
        if ( $fallbackProneIslandCount >= 3 ) {
            $signals[] = array(
                'severity' => 'info',
                'code' => 'fallback_prone_html_islands',
                'form_islands' => (int) ($htmlArtifact['fallback_prone_form_island_count'] ?? 0),
                'svg_islands' => (int) ($htmlArtifact['fallback_prone_svg_island_count'] ?? 0),
                'input_islands' => (int) ($htmlArtifact['fallback_prone_input_island_count'] ?? 0),
            );
        }
        if ( ! empty($htmlArtifact['invalid_list_child_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'invalid_list_children',
                'count' => (int) $htmlArtifact['invalid_list_child_count'],
            );
        }
        if ( (int) ($htmlArtifact['missing_semantic_role_count'] ?? 0) >= 2 ) {
            $signals[] = array(
                'severity' => 'info',
                'code' => 'missing_semantic_roles',
                'count' => (int) $htmlArtifact['missing_semantic_role_count'],
                'sample_nodes' => array_slice(is_array($htmlArtifact['semantic_role_samples'] ?? null) ? $htmlArtifact['semantic_role_samples'] : array(), 0, 10),
            );
        }
        if ( ! empty($htmlArtifact['fixed_width_over_desktop_uncovered_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'uncovered_fixed_desktop_widths',
                'count' => (int) $htmlArtifact['fixed_width_over_desktop_uncovered_count'],
                'raw_count' => (int) ($htmlArtifact['fixed_width_over_desktop_count'] ?? 0),
                'covered_count' => (int) ($htmlArtifact['fixed_width_over_desktop_covered_count'] ?? 0),
                'sample_classes' => array_slice(is_array($htmlArtifact['fixed_width_over_desktop_uncovered_classes'] ?? null) ? $htmlArtifact['fixed_width_over_desktop_uncovered_classes'] : array(), 0, 10),
            );
        }
        if ( ! empty($htmlArtifact['overlarge_inline_svg_ratio']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'overlarge_inline_svg_ratio',
                'inline_svg_bytes' => (int) ($htmlArtifact['inline_svg_bytes'] ?? 0),
                'html_bytes' => (int) ($htmlArtifact['html_bytes'] ?? 0),
                'inline_svg_byte_ratio' => (float) ($htmlArtifact['inline_svg_byte_ratio'] ?? 0.0),
                'inline_svg_count' => (int) ($htmlArtifact['inline_svg_count'] ?? 0),
            );
        }
        if ( ! empty($htmlArtifact['breakpoint_override_leak_count']) ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'breakpoint_override_leak',
                'count' => (int) $htmlArtifact['breakpoint_override_leak_count'],
                'sample_rules' => array_slice(is_array($htmlArtifact['breakpoint_override_leaks'] ?? null) ? $htmlArtifact['breakpoint_override_leaks'] : array(), 0, 10),
            );
        }
        if ( ! empty($htmlArtifact['absolute_to_flow_conversion_count']) ) {
            $absoluteToFlowEvidence = $this->absoluteToFlowDecisionEvidence(
                is_array($htmlArtifact['absolute_to_flow_conversions'] ?? null) ? $htmlArtifact['absolute_to_flow_conversions'] : array(),
                $decisionTraces
            );
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'suspicious_absolute_to_flow_conversion',
                'count' => (int) $htmlArtifact['absolute_to_flow_conversion_count'],
                'sample_rules' => $absoluteToFlowEvidence['sample_rules'],
            ) + $absoluteToFlowEvidence['summary'];
        }

        $sourceLossCoverage = $this->sourceLossCoverage($image, $vectors, $text, $components, $effects, $maskEffectClipping, $htmlArtifact, $sourceDiagnostics, $sourceLossEvidence);
        $uncoveredNodes = (int) ($sourceLossCoverage['node_coverage']['uncovered_source_nodes'] ?? 0);
        $unsupportedFields = (int) ($sourceLossCoverage['field_support']['unsupported_visual_field_occurrences'] ?? 0);
        if ( $uncoveredNodes > 0 || $unsupportedFields > 0 ) {
            $signals[] = array(
                'severity' => 'warning',
                'code' => 'source_loss_coverage_gap',
                'uncovered_source_nodes' => $uncoveredNodes,
                'unsupported_visual_field_occurrences' => $unsupportedFields,
                'node_coverage_ratio' => (float) ($sourceLossCoverage['node_coverage']['coverage_ratio'] ?? 1.0),
                'domains' => $sourceLossCoverage['domains'],
            );
        }

        $failCodes = array('missing_render_assets', 'vector_placeholders', 'invalid_css_numeric_token');
        $signals = array_map(
            static function (array $signal): array {
                if ( ! isset($signal['reason_code']) && isset($signal['code']) ) {
                    $signal['reason_code'] = (string) $signal['code'];
                }

                return $signal;
            },
            $signals
        );
        $failCount = count(array_filter($signals, static fn (array $signal): bool => in_array((string) ($signal['code'] ?? ''), $failCodes, true)));
        $warningCount = count(array_filter($signals, static fn (array $signal): bool => 'warning' === ($signal['severity'] ?? null)));
        $qualityStatus = $failCount > 0 ? 'fail' : (empty($signals) ? 'pass' : 'warn');

        return array(
            'schema' => 'blocks-engine/figma-transformer/artifact-quality/v1',
            'status' => $warningCount > 0 ? 'needs_review' : (empty($signals) ? 'clean' : 'info'),
            'quality_status' => $qualityStatus,
            'signals' => $signals,
            'summary' => array(
                'missing_asset_nodes' => count($image['missing_assets'] ?? array()),
                'vector_placeholders' => (int) ($vectors['placeholders'] ?? 0),
                'missing_font_css' => count($fonts['missing_css'] ?? array()),
                'emitted_asset_files' => (int) ($assets['emitted_files'] ?? 0),
                'image_block_count' => $imageBlockCount,
                'asset_node_refs' => (int) ($image['node_refs'] ?? 0),
                'asset_node_reason_categories' => is_array($image['asset_node_reason_categories'] ?? null) ? $image['asset_node_reason_categories'] : array(),
                'image_node_density' => round($imageNodeDensity, 3),
                'total_node_count' => $totalNodeCount,
                'vector_image_fallbacks' => (int) ($vectors['rendered_asset_fallbacks'] ?? 0),
                'vector_nodes' => (int) ($vectors['nodes'] ?? 0),
                'vector_decoded_to_svg' => (int) ($vectors['rendered_paths'] ?? 0),
                'vector_network_decoded' => (int) ($vectors['vector_network_decoded'] ?? 0),
                'boolean_operations_composed' => (int) ($vectors['boolean_operations_composed'] ?? 0),
                'vector_decode_coverage_ratio' => (float) ($vectors['decode_coverage']['coverage_ratio'] ?? 0.0),
                'vector_placeholder_reason_categories' => is_array($vectors['decode_coverage']['placeholder_reason_categories'] ?? null) ? $vectors['decode_coverage']['placeholder_reason_categories'] : array(),
                'generated_svg_count' => (int) ($vectors['rendered_paths'] ?? 0),
                'externalized_svg_asset_count' => (int) ($generatedSvgAssets['count'] ?? 0),
                'generated_svg_bytes' => (int) ($generatedSvgAssets['bytes'] ?? 0),
                'large_negative_left_count' => (int) ($layout['large_negative_left_count'] ?? 0),
                'large_css_offset_count' => (int) ($layout['large_css_offset_count'] ?? 0),
                'invalid_css_count' => (int) ($layout['invalid_css_count'] ?? 0),
                'off_canvas_visual_node_count' => (int) ($layout['off_canvas_visual_node_count'] ?? 0),
                'clipped_visual_node_count' => (int) ($layout['clipped_visual_node_count'] ?? 0),
                'clipped_visual_area_ratio' => (float) ($layout['clipped_visual_area_ratio'] ?? 0.0),
                'large_absolute_offset_count' => (int) ($layout['large_absolute_offset_count'] ?? 0),
                'suppressed_large_absolute_offset_count' => (int) ($layout['suppressed_large_absolute_offset_count'] ?? 0),
                'suppressed_large_absolute_offset_reason_counts' => is_array($layout['suppressed_large_absolute_offset_reason_counts'] ?? null) ? $layout['suppressed_large_absolute_offset_reason_counts'] : array(),
                'empty_visible_container_count' => (int) ($layout['empty_visible_container_count'] ?? 0),
                'empty_visible_container_blocker_count' => (int) ($layout['empty_visible_container_blocker_count'] ?? 0),
                'media_query_count' => (int) ($htmlArtifact['media_query_count'] ?? 0),
                'fixed_width_over_desktop_count' => (int) ($htmlArtifact['fixed_width_over_desktop_count'] ?? 0),
                'effective_responsive_coverage_ratio' => (float) ($htmlArtifact['effective_responsive_coverage_ratio'] ?? 1.0),
                'fixed_width_declaration_count' => (int) ($htmlArtifact['fixed_width_declaration_count'] ?? 0),
                'fixed_width_with_responsive_override_count' => (int) ($htmlArtifact['fixed_width_with_responsive_override_count'] ?? 0),
                'fixed_width_without_responsive_override_count' => (int) ($htmlArtifact['fixed_width_without_responsive_override_count'] ?? 0),
                'fixed_width_over_desktop_class_count' => (int) ($htmlArtifact['fixed_width_over_desktop_class_count'] ?? 0),
                'fixed_width_over_desktop_covered_count' => (int) ($htmlArtifact['fixed_width_over_desktop_covered_count'] ?? 0),
                'fixed_width_over_desktop_uncovered_count' => (int) ($htmlArtifact['fixed_width_over_desktop_uncovered_count'] ?? 0),
                'desktop_canvas_without_responsive_breakpoints' => (bool) ($htmlArtifact['desktop_canvas_without_responsive_breakpoints'] ?? false),
                'giant_fixed_section_count' => (int) ($htmlArtifact['giant_fixed_section_count'] ?? 0),
                'large_overflow_risk_count' => (int) ($htmlArtifact['large_overflow_risk_count'] ?? 0),
                'fallback_prone_form_island_count' => (int) ($htmlArtifact['fallback_prone_form_island_count'] ?? 0),
                'fallback_prone_svg_island_count' => (int) ($htmlArtifact['fallback_prone_svg_island_count'] ?? 0),
                'fallback_prone_input_island_count' => (int) ($htmlArtifact['fallback_prone_input_island_count'] ?? 0),
                'invalid_list_child_count' => (int) ($htmlArtifact['invalid_list_child_count'] ?? 0),
                'missing_semantic_role_count' => (int) ($htmlArtifact['missing_semantic_role_count'] ?? 0),
                'decoded_text_nodes' => (int) ($text['decoded_text_node_count'] ?? 0),
                'emitted_text_nodes' => (int) ($text['emitted_text_node_count'] ?? 0),
                'intentionally_suppressed_text_nodes' => (int) ($text['intentionally_suppressed_text_node_count'] ?? 0),
                'text_intentional_suppression_reason_counts' => is_array($text['intentional_suppression_reason_counts'] ?? null) ? $text['intentional_suppression_reason_counts'] : array(),
                'empty_decoded_text_nodes' => (int) ($text['empty_decoded_text_node_count'] ?? 0),
                'missing_emitted_text_nodes' => (int) ($text['missing_emitted_text_node_count'] ?? 0),
                'image_heavy_landmark_candidates' => count($layout['image_heavy_landmark_candidates'] ?? array()),
                'layout_mismatch_count' => (int) ($layout['layout_mismatch_count'] ?? 0),
                'layout_mismatch_status' => (string) ($layout['layout_mismatch_status'] ?? 'not_evaluated'),
                'link_sources_found' => (int) ($links['sources_found'] ?? 0),
                'anchors_emitted' => (int) ($links['anchors_emitted'] ?? 0),
                'link_targets_unresolved' => (int) ($links['unresolved'] ?? 0),
                'component_clone_source_nodes' => (int) ($components['clone_source_node_count'] ?? 0),
                'component_clone_nodes_emitted' => (int) ($components['emitted_clone_node_count'] ?? 0),
                'component_clone_omission_reason_counts' => is_array($components['omission_reason_counts'] ?? null) ? $components['omission_reason_counts'] : array(),
                'component_clone_intentionally_suppressed_nodes' => (int) ($components['intentionally_suppressed_clone_node_count'] ?? 0),
                'component_clone_intentional_suppression_reason_counts' => is_array($components['intentional_suppression_reason_counts'] ?? null) ? $components['intentional_suppression_reason_counts'] : array(),
                'component_override_candidates' => (int) ($components['override_candidate_node_count'] ?? 0),
                'component_overrides_applied' => (int) ($components['override_applied_node_count'] ?? 0),
                'effect_source_nodes' => (int) ($effects['source_effect_node_count'] ?? 0),
                'effect_nodes_emitted' => (int) ($effects['emitted_effect_node_count'] ?? 0),
                'effect_intentionally_suppressed_nodes' => (int) ($effects['intentionally_suppressed_effect_node_count'] ?? 0),
                'effect_intentional_suppression_reason_counts' => is_array($effects['intentional_suppression_reason_counts'] ?? null) ? $effects['intentional_suppression_reason_counts'] : array(),
                'effect_field_coverage' => is_array($effects['field_coverage'] ?? null) ? $effects['field_coverage'] : array(),
                'mask_nodes' => (int) ($maskEffectClipping['mask_node_count'] ?? 0),
                'mask_metadata_nodes' => (int) ($maskEffectClipping['mask_metadata_node_count'] ?? 0),
                'emitted_mask_source_nodes' => (int) ($maskEffectClipping['emitted_mask_source_node_count'] ?? 0),
                'suppressed_mask_source_nodes' => (int) ($maskEffectClipping['suppressed_mask_source_node_count'] ?? 0),
                'clips_content_nodes' => (int) ($maskEffectClipping['clips_content_node_count'] ?? 0),
                'clipped_effect_nodes' => (int) ($maskEffectClipping['clipped_effect_node_count'] ?? 0),
                'mixed_positioning_parent_count' => (int) ($layout['stacking_order']['mixed_positioning_parent_count'] ?? 0),
                'uncomposed_vector_child_nodes' => (int) ($vectors['child_composition']['uncomposed_vector_child_node_count'] ?? 0),
                'invalid_css_numeric_tokens' => (int) ($css['invalid_numeric_token_count'] ?? 0),
                'html_artifact' => $htmlArtifact,
                'source_loss_coverage' => $sourceLossCoverage,
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param array<string, mixed>             $decisionTraces
     * @return array{sample_rules: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    private function absoluteToFlowDecisionEvidence(array $rules, array $decisionTraces): array
    {
        $tracesByClass = array();
        $samples = array_is_list($decisionTraces)
            ? $decisionTraces
            : (is_array($decisionTraces['samples'] ?? null) ? $decisionTraces['samples'] : array());
        foreach ( $samples as $sample ) {
            if ( ! is_array($sample) || true !== ($sample['evidence']['absolute_to_flow_conversion'] ?? null) ) {
                continue;
            }

            $class = isset($sample['class']) && is_scalar($sample['class']) ? (string) $sample['class'] : '';
            if ( '' === $class ) {
                continue;
            }

            $tracesByClass[$class][] = $sample;
        }

        $sourceCounts = array();
        $matchedGeometryCounts = array('matched' => 0, 'fallback' => 0);
        $sampleRules = array();
        foreach ( array_slice($rules, 0, 10) as $rule ) {
            if ( ! is_array($rule) ) {
                continue;
            }

            $class = isset($rule['class']) && is_scalar($rule['class']) ? (string) $rule['class'] : '';
            $trace = '' !== $class && isset($tracesByClass[$class][0]) ? $tracesByClass[$class][0] : null;
            if ( is_array($trace) ) {
                $source = isset($trace['evidence']['source']) && is_scalar($trace['evidence']['source']) ? (string) $trace['evidence']['source'] : 'unknown';
                $sourceCounts[$source] = (int) ($sourceCounts[$source] ?? 0) + 1;
                if ( true === ($trace['evidence']['matched_breakpoint_geometry'] ?? null) ) {
                    ++$matchedGeometryCounts['matched'];
                } else {
                    ++$matchedGeometryCounts['fallback'];
                }

                $rule['decision_trace'] = array_filter(array(
                    'source' => $source,
                    'matched_breakpoint_geometry' => true === ($trace['evidence']['matched_breakpoint_geometry'] ?? null),
                    'reason_code' => isset($trace['reason_code']) && is_scalar($trace['reason_code']) ? (string) $trace['reason_code'] : null,
                    'node_id' => isset($trace['node_id']) && is_scalar($trace['node_id']) ? (string) $trace['node_id'] : null,
                    'variant_node_id' => isset($trace['evidence']['variant_node_id']) && is_scalar($trace['evidence']['variant_node_id']) ? (string) $trace['evidence']['variant_node_id'] : null,
                    'trace_count' => isset($trace['count']) && is_numeric($trace['count']) ? (int) $trace['count'] : null,
                ), static fn (mixed $value): bool => null !== $value && ! (is_string($value) && '' === $value));
            }

            $sampleRules[] = $rule;
        }

        ksort($sourceCounts);

        $summary = array();
        if ( ! empty($sourceCounts) ) {
            $summary['decision_trace_source_counts'] = $sourceCounts;
            $summary['matched_breakpoint_geometry_counts'] = array_filter($matchedGeometryCounts, static fn (int $count): bool => $count > 0);
        }

        return array(
            'sample_rules' => $sampleRules,
            'summary' => $summary,
        );
    }

    /**
     * @param array<string, mixed> $image
     * @param array<string, mixed> $vectors
     * @param array<string, mixed> $text
     * @param array<string, mixed> $components
     * @param array<string, mixed> $effects
     * @param array<string, mixed> $maskEffectClipping
     * @param array<string, mixed> $htmlArtifact
     * @param array<int, array<string, mixed>> $sourceDiagnostics
     * @param array<string, mixed> $sourceLossEvidence
     * @return array<string, mixed>
     */
    private function sourceLossCoverage(array $image, array $vectors, array $text, array $components, array $effects, array $maskEffectClipping, array $htmlArtifact, array $sourceDiagnostics, array $sourceLossEvidence): array
    {
        $sourceLossCoverageBuilder = new SourceLossCoverageBuilder();
        $diagnosticCounts = array_count_values(array_values(array_filter(array_map(
            static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
            $sourceDiagnostics
        ))));
        $skippedFields = $sourceLossCoverageBuilder->skippedFieldEvidence(
            is_array($sourceLossEvidence['skipped_field_inventory'] ?? null) ? $sourceLossEvidence['skipped_field_inventory'] : array()
        );
        $skippedByDomain = $skippedFields['domains'];
        $paintStyleDiagnosticCounts = (int) ($diagnosticCounts['figma_local_style_paint_conflict'] ?? 0)
            + (int) ($diagnosticCounts['figma_missing_paint_style_reference'] ?? 0)
            + (int) ($diagnosticCounts['figma_missing_effect_style_reference'] ?? 0);
        $textStyleDiagnosticCount = (int) ($diagnosticCounts['figma_missing_text_style_reference'] ?? 0);
        $overrideCandidates = (int) ($components['override_candidate_node_count'] ?? 0);
        $overridesApplied = (int) ($components['override_applied_node_count'] ?? 0);
        $unsupportedOverrides = (int) ($diagnosticCounts['figma_instance_override_unsupported'] ?? 0);
        $domains = array(
            'text' => $sourceLossCoverageBuilder->domain(
                (int) ($text['decoded_text_node_count'] ?? 0),
                (int) ($text['emitted_text_node_count'] ?? 0),
                (int) ($text['missing_emitted_text_node_count'] ?? 0),
                (int) ($text['intentionally_suppressed_text_node_count'] ?? 0),
                (int) ($skippedByDomain['text'] ?? 0),
                array('informational_style_diagnostic_count' => $textStyleDiagnosticCount)
            ),
            'paint_style' => $sourceLossCoverageBuilder->domain(
                0,
                0,
                0,
                0,
                (int) ($skippedByDomain['paint_style'] ?? 0),
                array('informational_style_diagnostic_count' => $paintStyleDiagnosticCounts)
            ),
            'geometry_layout' => $sourceLossCoverageBuilder->domain(
                0,
                0,
                0,
                0,
                (int) ($skippedByDomain['geometry_layout'] ?? 0),
                array('absolute_to_flow_conversion_count' => (int) ($htmlArtifact['absolute_to_flow_conversion_count'] ?? 0))
            ),
            'component_overrides' => $sourceLossCoverageBuilder->domain(
                $overrideCandidates,
                $overridesApplied,
                max(0, $overrideCandidates - $overridesApplied),
                0,
                $unsupportedOverrides + (int) ($skippedByDomain['component_overrides'] ?? 0),
                array('unsupported_override_diagnostic_count' => $unsupportedOverrides)
            ),
            'images' => $sourceLossCoverageBuilder->imageDomain($image),
            'vectors' => $sourceLossCoverageBuilder->domain(
                (int) ($vectors['nodes'] ?? 0),
                (int) ($vectors['rendered_paths'] ?? 0) + (int) ($vectors['rendered_asset_fallbacks'] ?? 0),
                (int) ($vectors['placeholders'] ?? 0),
                0,
                (int) ($skippedByDomain['vectors'] ?? 0)
            ),
            'components' => $sourceLossCoverageBuilder->domain(
                (int) ($components['clone_source_node_count'] ?? 0),
                (int) ($components['emitted_clone_node_count'] ?? 0),
                (int) ($components['missing_emitted_clone_node_count'] ?? 0),
                (int) ($components['intentionally_suppressed_clone_node_count'] ?? 0)
            ),
            'effects' => $sourceLossCoverageBuilder->domain(
                (int) ($effects['source_effect_node_count'] ?? 0),
                (int) ($effects['emitted_effect_node_count'] ?? 0),
                (int) ($effects['missing_emitted_effect_node_count'] ?? 0),
                (int) ($effects['intentionally_suppressed_effect_node_count'] ?? 0)
            ),
            'masks' => $sourceLossCoverageBuilder->domain(
                (int) ($maskEffectClipping['mask_node_count'] ?? 0),
                (int) ($maskEffectClipping['emitted_mask_source_node_count'] ?? 0) + (int) ($maskEffectClipping['suppressed_mask_source_node_count'] ?? 0),
                0
            ),
        );

        $coverage = $sourceLossCoverageBuilder->aggregate($domains);
        $coverage['skipped_field_evidence'] = $skippedFields['summary'];
        return $coverage;
    }

    /**
     * Summarize vector-decode coverage: how many vector-like nodes became real
     * inline SVG geometry versus how many remain placeholders, with the remaining
     * placeholders grouped into actionable reason categories.
     *
     * @param array<string, mixed> $vectors
     * @return array<string, mixed>
     */
    public function vectorDecodeCoverage(array $vectors): array
    {
        $nodes = (int) ($vectors['nodes'] ?? 0);
        $decoded = (int) ($vectors['rendered_paths'] ?? 0);
        $assetFallbacks = (int) ($vectors['rendered_asset_fallbacks'] ?? 0);
        $networkDecoded = (int) ($vectors['vector_network_decoded'] ?? 0);
        $booleanComposed = (int) ($vectors['boolean_operations_composed'] ?? 0);
        $placeholders = (int) ($vectors['placeholders'] ?? 0);
        $reasons = is_array($vectors['placeholder_reasons'] ?? null) ? $vectors['placeholder_reasons'] : array();

        $categoryByReason = array(
            'missing_vector_geometry'                => 'no_geometry_available',
            'missing_dimensions'                     => 'no_geometry_available',
            'unsupported_vector_network_blob'        => 'vector_network_blob_unsupported',
            'unsupported_path_data'                  => 'path_data_unsupported',
            'oversized_path_data'                    => 'path_data_unsupported',
            'unsupported_vector_geometry'            => 'path_data_unsupported',
            'unsupported_boolean_operation_children' => 'boolean_operation_unsupported',
            'unresolved_asset_fallback'              => 'asset_unresolved',
        );

        $categories = array();
        foreach ( $reasons as $reason => $count ) {
            $category = $categoryByReason[(string) $reason] ?? 'other';
            $categories[$category] = (int) ($categories[$category] ?? 0) + (int) $count;
        }
        ksort($categories);

        return array(
            'schema'                     => 'blocks-engine/figma-transformer/vector-decode-coverage/v1',
            'vector_nodes'               => $nodes,
            'decoded_to_svg'             => $decoded,
            'vector_network_decoded'     => $networkDecoded,
            'boolean_operations_composed' => $booleanComposed,
            'asset_fallbacks'            => $assetFallbacks,
            'placeholders'               => $placeholders,
            'coverage_ratio'             => $nodes > 0 ? round($decoded / $nodes, 3) : 0.0,
            'placeholder_reasons'        => $reasons,
            'placeholder_reason_categories' => $categories,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fontUsage
     * @param array<int, string> $families
     * @return array<int, array<string, mixed>>
     */
    private function fontUsageForFamilies(array $fontUsage, array $families): array
    {
        $wanted = array_fill_keys(array_map('strtolower', $families), true);
        return array_values(array_filter(
            $fontUsage,
            static fn (array $usage): bool => isset($wanted[strtolower((string) ($usage['family'] ?? ''))])
        ));
    }
}
