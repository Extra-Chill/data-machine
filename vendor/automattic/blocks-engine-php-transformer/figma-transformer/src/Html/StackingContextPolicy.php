<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Centralizes local stacking/z-index precedence so layering decisions carry a
 * stable reason code for CSS emission and diagnostics.
 */
final class StackingContextPolicy
{
    public const STACK_REASON_ABSOLUTE_CHILD = 'local_absolute_child';
    public const STACK_REASON_DECORATIVE_UNDERLAY = 'local_decorative_underlay';
    public const STACK_REASON_FREEFORM_CONTAINER = 'local_freeform_container';
    public const STACK_REASON_MIXED_POSITIONING_CHILDREN = 'local_mixed_positioning_children';
    public const STACK_REASON_OVERLAPPING_STACKED_CHILD = 'local_overlapping_stacked_child';
    public const STACK_REASON_SOURCE_Z_INDEX = 'source_z_index';
    public const STACK_REASON_SIBLING_LAYER_RANK = 'overlapping_sibling_layer_rank';
    public const STACK_REASON_Z_INDEXED_CHILD = 'local_z_indexed_child';

    public const LAYER_ROLE_UNDERLAY = 'underlay';
    public const LAYER_ROLE_CONTENT = 'content';
    public const LAYER_ROLE_CHROME = 'chrome';

    /**
     * @param array<int, string> $localReasons
     * @param array<int, string> $isolationReasons
     * @param array{role?: string|null, overlaps_sibling?: bool, z_index?: int|null} $siblingStackPlan
     * @return array{manages_local_stacking: bool, needs_isolation: bool, local_reasons: array<int, string>, sibling_role: string|null, overlaps_sibling: bool, z_index: int|null, z_index_reason: string|null}
     */
    public function plan(array $localReasons, array $isolationReasons, array $siblingStackPlan, bool $isDecorativeUnderlay, ?int $sourceZIndex): array
    {
        $siblingZIndex = isset($siblingStackPlan['z_index']) && is_int($siblingStackPlan['z_index']) ? $siblingStackPlan['z_index'] : null;
        $zIndexDecision = $this->zIndexDecision($isDecorativeUnderlay, $sourceZIndex, $siblingZIndex);

        return array(
            'manages_local_stacking' => ! empty($localReasons),
            'needs_isolation' => ! empty($isolationReasons),
            'local_reasons' => array_values(array_unique(array_merge($localReasons, $isolationReasons))),
            'sibling_role' => is_string($siblingStackPlan['role'] ?? null) ? $siblingStackPlan['role'] : null,
            'overlaps_sibling' => true === ($siblingStackPlan['overlaps_sibling'] ?? false),
            'z_index' => $zIndexDecision['z_index'],
            'z_index_reason' => $zIndexDecision['reason'],
        );
    }

    /**
     * @return array{z_index: int|null, reason: string|null}
     */
    public function zIndexDecision(bool $isDecorativeUnderlay, ?int $sourceZIndex, ?int $siblingZIndex): array
    {
        if ( $isDecorativeUnderlay ) {
            return array(
                'z_index' => $siblingZIndex ?? 0,
                'reason' => null !== $siblingZIndex ? self::STACK_REASON_SIBLING_LAYER_RANK : self::STACK_REASON_DECORATIVE_UNDERLAY,
            );
        }

        if ( null !== $siblingZIndex ) {
            return array('z_index' => $siblingZIndex, 'reason' => self::STACK_REASON_SIBLING_LAYER_RANK);
        }

        if ( null !== $sourceZIndex ) {
            return array('z_index' => $sourceZIndex, 'reason' => self::STACK_REASON_SOURCE_Z_INDEX);
        }

        return array('z_index' => null, 'reason' => null);
    }
}
