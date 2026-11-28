<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

final class FallbackDiagnostic
{
    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $provenance
     * @return array<string, mixed>
     */
    public static function build(array $fields, array $provenance = array()): array
    {
        return self::withGenericFindingMetadata(array_merge(self::defaults($fields), $fields, $provenance));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function withGenericFindingMetadata(array $fields): array
    {
        return FallbackFindingNormalizer::normalize($fields);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function defaults(array $fields): array
    {
        $code = (string) ($fields['diagnostic_code'] ?? '');
        $formHasControls = 'html_form_fallback' === $code && ! empty($fields['controls']) && is_array($fields['controls']);

        return match ( $code ) {
            'html_form_fallback' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'            => 'runtime_island_preserved',
                'diagnostic_class'      => 'runtime_island_preserved',
                'preservation_strategy' => 'fallback_metadata_with_readable_blocks',
                'runtime_requirement'   => 'server_or_client_form_handler',
                'recoverability'        => $formHasControls ? 'recoverable_with_form_provider_materialization' : 'recoverable_with_runtime_mapping',
                'actionability'         => $formHasControls ? 'materialize_detected_form_with_form_provider' : 'map_form_runtime_or_preserve_handler',
                'suggested_repair_class' => $formHasControls ? 'materialize_form_provider' : 'preserve_runtime_island',
                'suggested_primitive'   => 'form',
                'materialization_hint'  => $formHasControls ? 'map_form_action_controls_labels_options_and_submit_text_to_a_form_provider' : 'preserve_form_runtime_source_until_controls_can_be_mapped',
                'materialization_target' => $formHasControls ? array(
                    'capability'    => 'form',
                    'entity'        => 'form',
                    'provider_role' => 'form_provider',
                    'requires'      => array( 'controls', 'form' ),
                ) : array(),
            ),
            'html_product_grid_fallback' => array(
                'severity'              => 'info',
                'conversion_classification' => 'editable_approximation',
                'loss_class'            => 'native_conversion',
                'diagnostic_class'      => 'commerce_structure_detected',
                'preservation_strategy' => 'layout_blocks_with_structured_product_metadata',
                'runtime_requirement'   => 'none',
                'recoverability'        => 'recoverable_with_commerce_product_materialization',
                'actionability'         => 'materialize_detected_products_in_a_commerce_provider',
                'suggested_repair_class' => 'materialize_commerce_products',
                'suggested_primitive'   => 'product_grid',
                'materialization_hint'  => 'layout_blocks_are_emitted_as_is; map_detected_product_names_prices_images_and_descriptions_to_a_shop_provider',
                'materialization_target' => array(
                    'capability'    => 'shop',
                    'entity'        => 'product',
                    'provider_role' => 'commerce_product_provider',
                    'requires'      => array( 'products' ),
                ),
            ),
            'html_commerce_controls_fallback' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'            => 'runtime_island_preserved',
                'diagnostic_class'      => 'commerce_runtime_controls_detected',
                'preservation_strategy' => 'layout_blocks_with_commerce_control_metadata',
                'runtime_requirement'   => 'commerce_cart_runtime',
                'recoverability'        => 'recoverable_with_commerce_cart_runtime_binding',
                'actionability'         => 'bind_quantity_and_add_to_cart_controls_to_cart_runtime_after_product_materialization',
                'suggested_repair_class' => 'materialize_commerce_runtime',
                'suggested_primitive'   => 'commerce_controls',
                'materialization_hint'  => 'product_data_can_be_seeded_by_a_shop_provider; core_blocks_cannot_provide_cart_state_or_quantity_mutation_without_runtime_binding',
                'materialization_target' => array(
                    'capability'    => 'shop',
                    'entity'        => 'commerce_controls',
                    'provider_role' => 'commerce_cart_runtime',
                    'requires'      => array( 'controls', 'seeded_products' ),
                ),
            ),
            'html_script_fallback' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'            => 'runtime_island_preserved',
                'diagnostic_class'      => 'runtime_island_preserved',
                'disposition'           => 'preserve',
                'preservation_status'   => 'accepted_runtime_preservation',
                'js_handling'           => 'preserve_verbatim',
                'preservation_strategy' => 'scoped_runtime_metadata',
                'runtime_requirement'   => 'client_script_execution',
                'recoverability'        => 'recoverable_with_script_enqueue_or_component_runtime',
                'actionability'         => 'review_script_source_and_enqueue_or_rebuild_behavior',
                'suggested_repair_class' => 'preserve_runtime_island',
                'suggested_primitive'   => 'script_asset',
                'materialization_hint'  => 'enqueue_script_or_rebuild_as_interactive_block',
            ),
            'html_inline_svg_fallback' => array(
                'severity'              => 'info',
                'conversion_classification' => 'editable_approximation',
                'preservation_strategy' => 'sanitized_static_markup_or_image',
                'runtime_requirement'   => 'none',
                'recoverability'        => 'recoverable_as_static_markup_or_image_asset',
                'actionability'         => 'review_sanitized_svg_and_materialize_as_image_or_html',
                'suggested_primitive'   => 'image_or_html',
                'materialization_hint'  => 'materialize_safe_svg_as_image_asset_or_core_html',
            ),
            'html_unsafe_inline_svg' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'unsupported_loss',
                'preservation_strategy' => 'diagnostic_only_until_security_review',
                'runtime_requirement'   => 'sanitization_review',
                'recoverability'        => 'recoverable_after_security_review',
                'actionability'         => 'remove_scriptable_svg_content_or_replace_with_safe_asset',
                'suggested_primitive'   => 'image_asset',
                'materialization_hint'  => 'sanitize_svg_before_materializing_asset',
            ),
            'html_responsive_image_fallback' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'editable_approximation',
                'loss_class'            => 'native_block_gap_preserved',
                'diagnostic_class'      => 'responsive_image_preserved',
                'preservation_strategy' => 'sanitized_core_html',
                'runtime_requirement'   => 'none',
                'recoverability'        => 'recoverable_with_native_responsive_image_block_support',
                'actionability'         => 'retain_core_html_or_materialize_responsive_sources_as_media_attachments',
                'suggested_repair_class' => 'preserve_responsive_image_markup',
                'suggested_primitive'   => 'core/html',
                'materialization_hint'  => 'preserve_picture_and_srcset_markup_until_core_image_can_serialize_the_source_selection',
            ),
            'html_iframe_embed_fallback' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'            => 'runtime_island_preserved',
                'diagnostic_class'      => 'runtime_island_preserved',
                'preservation_strategy' => 'sanitized_embed_markup',
                'runtime_requirement'   => 'third_party_embed_runtime',
                'recoverability'        => 'recoverable_with_embed_provider_or_html_preservation',
                'actionability'         => 'map_iframe_src_to_supported_embed_provider_or_preserve_html',
                'suggested_repair_class' => 'preserve_runtime_island',
                'suggested_primitive'   => 'embed',
                'materialization_hint'  => 'convert_supported_src_to_core_embed_or_preserve_sanitized_iframe_html',
            ),
            'html_canvas_runtime_fallback' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'            => 'runtime_island_preserved',
                'diagnostic_class'      => 'runtime_island_preserved',
                'preservation_strategy' => 'bounded_raw_html_runtime_island',
                'runtime_requirement'   => 'canvas_element_and_client_script_execution',
                'recoverability'        => 'recoverable_with_canvas_markup_preservation_or_rebuilt_interactive_block',
                'actionability'         => 'preserve_canvas_markup_with_matching_script_runtime_or_rebuild_canvas_behavior',
                'suggested_repair_class' => 'preserve_runtime_island',
                'suggested_primitive'   => 'runtime_canvas',
                'materialization_hint'  => 'core_blocks_cannot_emit_a_native_canvas_element_without_raw_html; preserve_bounded_canvas_metadata_for_runtime_mapping',
            ),
            'html_template_metadata' => array(
                'severity'              => 'info',
                'conversion_classification' => 'native_conversion',
                'loss_class'            => 'native_conversion',
                'diagnostic_class'      => 'static_metadata_preserved',
                'preservation_strategy' => 'bounded_inert_template_metadata',
                'runtime_requirement'   => 'none',
                'recoverability'        => 'recoverable_from_source_metadata',
                'actionability'         => 'review_template_content_if_needed',
                'suggested_repair_class' => 'preserve_static_metadata',
                'suggested_primitive'   => 'metadata',
                'materialization_hint'  => 'html_template_elements_are_inert_and_have_no_visual_output; preserve_bounded_metadata_without_emitting_blocks',
            ),
            'html_template_runtime_fallback' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'            => 'runtime_island_preserved',
                'diagnostic_class'      => 'runtime_island_preserved',
                'preservation_strategy' => 'bounded_inert_template_runtime_island',
                'runtime_requirement'   => 'client_template_instantiation',
                'recoverability'        => 'recoverable_with_client_template_runtime_or_component_rebuild',
                'actionability'         => 'preserve_template_source_for_runtime_or_rebuild_as_interactive_component',
                'suggested_repair_class' => 'preserve_runtime_island',
                'suggested_primitive'   => 'template',
                'materialization_hint'  => 'template_content_is_inert_until_client_runtime_clones_or_instantiates_it; preserve_bounded_source_metadata_without_visual_blocks',
            ),
            'html_unsupported_element' => array(
                'severity'              => 'info',
                'conversion_classification' => 'unsupported_loss',
                'loss_class'            => 'unsupported_element_loss',
                'diagnostic_class'      => 'unsupported_element',
                'preservation_strategy' => 'diagnostic_only',
                'runtime_requirement'   => 'unknown',
                'recoverability'        => 'recoverable_with_manual_mapping',
                'actionability'         => 'map_element_to_supported_block_or_preserve_html',
                'suggested_repair_class' => 'add_generic_pattern_recognizer',
                'suggested_primitive'   => 'core/html',
                'materialization_hint'  => 'preserve_sanitized_markup_until_a_specific_block_mapping_exists',
            ),
            'interactive_control_behavior_lost' => array(
                'severity'              => 'warning',
                'conversion_classification' => 'behavior_loss',
                'loss_class'            => 'interactive_behavior_loss',
                'diagnostic_class'      => 'interactive_behavior_loss',
                'preservation_strategy' => 'none_behavior_dropped',
                'runtime_requirement'   => 'client_event_handler',
                'recoverability'        => 'recoverable_with_interactive_block_or_script_runtime',
                'actionability'         => 'rebuild_control_behavior_as_interactive_block_or_enqueue_handler_script',
                'suggested_repair_class' => 'restore_interactive_behavior',
                'suggested_primitive'   => 'interactive_control',
                'materialization_hint'  => 'rebuild_as_interactive_block_or_preserve_handler_via_script_runtime',
            ),
            default => array(
                'severity'              => 'warning',
                'conversion_classification' => 'unsupported_loss',
                'loss_class'            => 'unsupported_loss',
                'diagnostic_class'      => 'fallback_metadata',
                'preservation_strategy' => 'diagnostic_only',
                'runtime_requirement'   => 'unknown',
                'recoverability'        => 'unknown',
                'actionability'         => 'review_fallback_metadata',
                'suggested_repair_class' => 'review_generic_mapping',
                'suggested_primitive'   => 'core/html',
                'materialization_hint'  => 'preserve_fallback_metadata_for_manual_review',
            ),
        };
    }

}
