<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Refreshes namespaced component-source clone geometry while preserving instance placement.
 */
final class ComponentSourceCloneGeometry
{
    private const GEOMETRY_SEMANTICS_COMPONENT_SOURCE_CLONE = 'component_source_clone';

    public function __construct(
        private readonly ScenegraphVectorInstanceScaler $vectorInstanceScaler = new ScenegraphVectorInstanceScaler()
    ) {
    }

    /**
     * @param array<string, mixed> $clone
     * @param array<string, mixed> $refreshed
     */
    public function isRefreshable(array $clone, array $refreshed): bool
    {
        $cloneType = strtoupper((string) ($clone['type'] ?? ''));
        $refreshedType = strtoupper((string) ($refreshed['type'] ?? ''));

        return 'INSTANCE' === $cloneType
            || 'INSTANCE' === $refreshedType
            || true === ($clone['figma_component']['resolved'] ?? false)
            || true === ($refreshed['figma_component']['resolved'] ?? false);
    }

    /**
     * @param array<string, mixed> $node
     */
    public function subtreeHasInstanceOverrideApplied(array $node): bool
    {
        if ( true === ($node['_figma_instance_override_applied'] ?? false) ) {
            return true;
        }

        foreach ( is_array($node['children'] ?? null) ? $node['children'] : array() as $child ) {
            if ( is_array($child) && $this->subtreeHasInstanceOverrideApplied($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<string, mixed>
     */
    public function repairFarGeometry(array $node, array $nodeMap): array
    {
        $sourceId = isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) ? (string) $node['figma_component_source_id'] : '';
        if ( '' === $sourceId || ! is_array($nodeMap[$sourceId]['box'] ?? null) || ! is_array($node['box'] ?? null) ) {
            return $node;
        }

        $sourceBox = $nodeMap[$sourceId]['box'];
        if ( GeometryBox::COORDINATE_SPACE_PARENT_LOCAL !== GeometryBox::coordinateSpace($sourceBox) ) {
            return $node;
        }

        $repaired = false;
        foreach ( array('x', 'y') as $dimension ) {
            if ( isset($sourceBox[$dimension], $node['box'][$dimension]) && is_numeric($sourceBox[$dimension]) && is_numeric($node['box'][$dimension]) && abs((float) $node['box'][$dimension] - (float) $sourceBox[$dimension]) >= 1000.0 ) {
                $node[$dimension] = (float) $sourceBox[$dimension];
                $node['box'][$dimension] = (float) $sourceBox[$dimension];
                if ( is_array($node['figma_box'] ?? null) && isset($node['figma_box'][$dimension]) && is_numeric($node['figma_box'][$dimension]) ) {
                    $node['figma_box'][$dimension] = (float) $sourceBox[$dimension];
                }
                $repaired = true;
            }
        }

        if ( $repaired ) {
            $node['box']['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;
            if ( is_array($node['figma_box'] ?? null) ) {
                $node['figma_box']['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $clone
     * @param array<string, mixed> $refreshed
     * @return array<string, mixed>
     */
    public function mergeRefreshed(array $clone, array $refreshed, string $sourceId): array
    {
        $cloneId = (string) ($clone['id'] ?? '');
        $sourceBox = is_array($refreshed['box'] ?? null) ? $refreshed['box'] : array();
        $merged = '' !== $cloneId ? $this->retargetSourceIds($refreshed, $sourceId, $cloneId) : $refreshed;

        $geometryDecision = $this->decideGeometrySource($clone, $refreshed);
        $preferRefreshedGeometry = $geometryDecision->useRefreshedGeometry;
        foreach ( array('id', 'figma_component_source_id', 'box', 'figma_box', 'layout', 'x', 'y', 'width', 'height') as $key ) {
            if ( $preferRefreshedGeometry && in_array($key, array('box', 'figma_box', 'x', 'y'), true) ) {
                continue;
            }
            if ( array_key_exists($key, $clone) ) {
                $merged[$key] = $clone[$key];
            }
        }
        $merged = $this->mergeLayoutMetadata($merged, $clone, $refreshed);
        $merged['_component_source_clone_geometry_decision'] = $geometryDecision->toArray();
        if ( $preferRefreshedGeometry && is_array($refreshed['box'] ?? null) ) {
            foreach ( array('x', 'y') as $dimension ) {
                if ( isset($refreshed['box'][$dimension]) && is_numeric($refreshed['box'][$dimension]) ) {
                    $merged[$dimension] = (float) $refreshed['box'][$dimension];
                }
            }
        }

        $sourceX = isset($sourceBox['x']) && is_numeric($sourceBox['x']) ? (float) $sourceBox['x'] : null;
        $sourceY = isset($sourceBox['y']) && is_numeric($sourceBox['y']) ? (float) $sourceBox['y'] : null;
        $sourceWidth = isset($sourceBox['width']) && is_numeric($sourceBox['width']) ? (float) $sourceBox['width'] : null;
        $sourceHeight = isset($sourceBox['height']) && is_numeric($sourceBox['height']) ? (float) $sourceBox['height'] : null;
        if ( null !== $sourceX || null !== $sourceY ) {
            $merged = $this->rebaseDescendants($merged, $sourceX, $sourceY, $sourceWidth, $sourceHeight);
        }

        if ( is_array($clone['_component_source_clone_scale'] ?? null) && is_array($merged['children'] ?? null) ) {
            $scaleX = isset($clone['_component_source_clone_scale']['x']) && is_numeric($clone['_component_source_clone_scale']['x']) ? (float) $clone['_component_source_clone_scale']['x'] : 1.0;
            $scaleY = isset($clone['_component_source_clone_scale']['y']) && is_numeric($clone['_component_source_clone_scale']['y']) ? (float) $clone['_component_source_clone_scale']['y'] : 1.0;
            if ( abs($scaleX - 1.0) >= 0.0001 || abs($scaleY - 1.0) >= 0.0001 ) {
                $merged['children'] = $this->vectorInstanceScaler->scaleVectorChildren($merged['children'], $scaleX, $scaleY);
                $merged['_component_source_clone_scale'] = array('x' => $scaleX, 'y' => $scaleY);
            }
        }

        return $this->markGeometry($merged);
    }

    /**
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $clone
     * @param array<string, mixed> $refreshed
     * @return array<string, mixed>
     */
    private function mergeLayoutMetadata(array $merged, array $clone, array $refreshed): array
    {
        $refreshedLayout = is_array($refreshed['layout'] ?? null) ? $refreshed['layout'] : array();
        if ( ! isset($refreshedLayout['z_index']) || ! is_numeric($refreshedLayout['z_index']) ) {
            return $merged;
        }

        $cloneLayout = is_array($clone['layout'] ?? null) ? $clone['layout'] : array();
        if ( isset($cloneLayout['z_index']) && is_numeric($cloneLayout['z_index']) ) {
            return $merged;
        }

        $mergedLayout = is_array($merged['layout'] ?? null) ? $merged['layout'] : array();
        $mergedLayout['z_index'] = (int) $refreshedLayout['z_index'];
        if ( is_string($refreshedLayout['z_index_source'] ?? null) ) {
            $mergedLayout['z_index_source'] = $refreshedLayout['z_index_source'];
        }
        $merged['layout'] = $mergedLayout;

        return $merged;
    }

    /**
     * @param array<string, mixed> $clone
     * @param array<string, mixed> $refreshed
     */
    public function decideGeometrySource(array $clone, array $refreshed): ComponentSourceCloneGeometryDecision
    {
        $cloneBox = is_array($clone['box'] ?? null) ? $clone['box'] : array();
        $refreshedBox = is_array($refreshed['box'] ?? null) ? $refreshed['box'] : array();
        $refreshedCoordinateSpace = GeometryBox::coordinateSpace($refreshedBox);
        if ( GeometryBox::COORDINATE_SPACE_PARENT_LOCAL !== $refreshedCoordinateSpace ) {
            return new ComponentSourceCloneGeometryDecision(
                false,
                ComponentSourceCloneGeometryDecision::REASON_REFRESHED_BOX_NOT_PARENT_LOCAL,
                null,
                $refreshedCoordinateSpace,
                $this->hasComponentSourceIdentity($clone, $cloneBox)
            );
        }

        $hasComponentSourceIdentity = $this->hasComponentSourceIdentity($clone, $cloneBox);
        if ( ! $hasComponentSourceIdentity ) {
            return new ComponentSourceCloneGeometryDecision(
                false,
                ComponentSourceCloneGeometryDecision::REASON_CLONE_NOT_COMPONENT_SOURCE,
                null,
                $refreshedCoordinateSpace,
                false
            );
        }

        foreach ( array('x', 'y') as $dimension ) {
            if ( $this->cloneBoxDisagreesWithMatchingScalar($clone, $cloneBox, $refreshedBox, $dimension) ) {
                return new ComponentSourceCloneGeometryDecision(
                    true,
                    'x' === $dimension ? ComponentSourceCloneGeometryDecision::REASON_CLONE_BOX_X_DISAGREES_WITH_SCALAR : ComponentSourceCloneGeometryDecision::REASON_CLONE_BOX_Y_DISAGREES_WITH_SCALAR,
                    $dimension,
                    $refreshedCoordinateSpace,
                    true
                );
            }

            if ( isset($cloneBox[$dimension], $refreshedBox[$dimension]) && is_numeric($cloneBox[$dimension]) && is_numeric($refreshedBox[$dimension]) && abs((float) $cloneBox[$dimension] - (float) $refreshedBox[$dimension]) >= 1000.0 ) {
                return new ComponentSourceCloneGeometryDecision(
                    true,
                    'x' === $dimension ? ComponentSourceCloneGeometryDecision::REASON_CLONE_BOX_X_FAR_FROM_REFRESHED : ComponentSourceCloneGeometryDecision::REASON_CLONE_BOX_Y_FAR_FROM_REFRESHED,
                    $dimension,
                    $refreshedCoordinateSpace,
                    true
                );
            }
            if ( isset($clone[$dimension], $refreshedBox[$dimension]) && is_numeric($clone[$dimension]) && is_numeric($refreshedBox[$dimension]) && abs((float) $clone[$dimension] - (float) $refreshedBox[$dimension]) >= 1000.0 ) {
                return new ComponentSourceCloneGeometryDecision(
                    true,
                    'x' === $dimension ? ComponentSourceCloneGeometryDecision::REASON_CLONE_X_FAR_FROM_REFRESHED : ComponentSourceCloneGeometryDecision::REASON_CLONE_Y_FAR_FROM_REFRESHED,
                    $dimension,
                    $refreshedCoordinateSpace,
                    true
                );
            }
        }

        return new ComponentSourceCloneGeometryDecision(
            false,
            ComponentSourceCloneGeometryDecision::REASON_CLONE_GEOMETRY_PRESERVED,
            null,
            $refreshedCoordinateSpace,
            true
        );
    }

    /**
     * @param array<string, mixed> $clone
     * @param array<string, mixed> $cloneBox
     * @param array<string, mixed> $refreshedBox
     */
    private function cloneBoxDisagreesWithMatchingScalar(array $clone, array $cloneBox, array $refreshedBox, string $dimension): bool
    {
        if ( ! isset($clone[$dimension], $cloneBox[$dimension], $refreshedBox[$dimension]) || ! is_numeric($clone[$dimension]) || ! is_numeric($cloneBox[$dimension]) || ! is_numeric($refreshedBox[$dimension]) ) {
            return false;
        }

        $cloneScalar = (float) $clone[$dimension];
        $cloneBoxCoordinate = (float) $cloneBox[$dimension];
        $refreshedCoordinate = (float) $refreshedBox[$dimension];

        return abs($cloneBoxCoordinate - $cloneScalar) > 0.5 && abs($cloneScalar - $refreshedCoordinate) <= 0.5;
    }

    /**
     * @param array<string, mixed> $clone
     * @param array<string, mixed> $cloneBox
     */
    private function hasComponentSourceIdentity(array $clone, array $cloneBox): bool
    {
        return (isset($clone['figma_component_source_id']) && is_scalar($clone['figma_component_source_id']) && '' !== (string) $clone['figma_component_source_id'])
            || ! empty($clone['_component_source_clone_geometry'])
            || self::GEOMETRY_SEMANTICS_COMPONENT_SOURCE_CLONE === ($cloneBox['geometry_semantics'] ?? null);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function rebaseDescendants(array $node, ?float $parentSourceX, ?float $parentSourceY, ?float $parentSourceWidth = null, ?float $parentSourceHeight = null): array
    {
        if ( ! is_array($node['children'] ?? null) ) {
            return $node;
        }

        foreach ( $node['children'] as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childSourceX = $this->boxCoordinate($child, 'x');
            $childSourceY = $this->boxCoordinate($child, 'y');
            $childSourceWidth = $this->boxCoordinate($child, 'width');
            $childSourceHeight = $this->boxCoordinate($child, 'height');
            $child = $this->rebaseBox($child, 'box', $parentSourceX, $parentSourceY, $parentSourceWidth, $parentSourceHeight);
            $child = $this->rebaseBox($child, 'figma_box', $parentSourceX, $parentSourceY, $parentSourceWidth, $parentSourceHeight);
            $node['children'][$index] = $this->rebaseDescendants(
                $child,
                null !== $childSourceX ? $childSourceX : $parentSourceX,
                null !== $childSourceY ? $childSourceY : $parentSourceY,
                null !== $childSourceWidth ? $childSourceWidth : $parentSourceWidth,
                null !== $childSourceHeight ? $childSourceHeight : $parentSourceHeight
            );
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function boxCoordinate(array $node, string $dimension): ?float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        return isset($box[$dimension]) && is_numeric($box[$dimension]) ? (float) $box[$dimension] : null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebaseBox(array $node, string $boxKey, ?float $parentSourceX, ?float $parentSourceY, ?float $parentSourceWidth = null, ?float $parentSourceHeight = null): array
    {
        if ( ! is_array($node[$boxKey] ?? null) ) {
            return $node;
        }

        $box = $node[$boxKey];
        if ( 'page' !== ($box['local_origin'] ?? null) && GeometryBox::COORDINATE_SPACE_CANVAS_ABSOLUTE !== GeometryBox::coordinateSpace($box) ) {
            return $node;
        }

        foreach ( array('x' => array($parentSourceX, $parentSourceWidth), 'y' => array($parentSourceY, $parentSourceHeight)) as $dimension => $parentSource ) {
            [$parentSourceCoordinate, $parentSourceSize] = $parentSource;
            if ( null === $parentSourceCoordinate || ! isset($node[$boxKey][$dimension]) || ! is_numeric($node[$boxKey][$dimension]) ) {
                continue;
            }
            if ( GeometryBox::COORDINATE_SPACE_CANVAS_ABSOLUTE === GeometryBox::coordinateSpace($box) && ! $this->coordinateOverlapsParent((float) $node[$boxKey][$dimension], $parentSourceCoordinate, $parentSourceSize) ) {
                continue;
            }

            $node[$boxKey][$dimension] = (float) $node[$boxKey][$dimension] - $parentSourceCoordinate;
        }

        unset($node[$boxKey]['local_origin']);
        $node[$boxKey]['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;

        return $node;
    }

    private function coordinateOverlapsParent(float $coordinate, float $parentSourceCoordinate, ?float $parentSourceSize): bool
    {
        if ( null === $parentSourceSize ) {
            return $coordinate >= $parentSourceCoordinate - 0.5;
        }

        return $coordinate >= $parentSourceCoordinate - 0.5 && $coordinate <= $parentSourceCoordinate + $parentSourceSize + 0.5;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function retargetSourceIds(array $node, string $sourceId, string $cloneId): array
    {
        foreach ( array('id', 'figma_component_source_id') as $key ) {
            if ( ! isset($node[$key]) || ! is_scalar($node[$key]) ) {
                continue;
            }

            $id = (string) $node[$key];
            if ( $sourceId === $id ) {
                $node[$key] = 'id' === $key ? $cloneId : $sourceId;
            } elseif ( str_starts_with($id, $sourceId . '/') ) {
                $node[$key] = ('id' === $key ? $cloneId : $sourceId) . substr($id, strlen($sourceId));
            }
        }

        if ( is_array($node['children'] ?? null) ) {
            foreach ( $node['children'] as $index => $child ) {
                if ( is_array($child) ) {
                    $node['children'][$index] = $this->retargetSourceIds($child, $sourceId, $cloneId);
                }
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function markGeometry(array $node): array
    {
        $node['_component_source_clone_geometry'] = true;
        foreach ( array('box', 'figma_box') as $boxKey ) {
            if ( ! is_array($node[$boxKey] ?? null) ) {
                continue;
            }

            if ( 'box' === $boxKey && ! isset($node['_component_source_clone_source_box']) ) {
                $node['_component_source_clone_source_box'] = $node[$boxKey];
            }

            $sourceKind = GeometryBox::sourceKind($node[$boxKey]);
            foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
                $hasRebasedLocalCoordinate = in_array($dimension, array('x', 'y'), true)
                    && isset($node[$boxKey][$dimension])
                    && GeometryBox::COORDINATE_SPACE_PARENT_LOCAL === GeometryBox::coordinateSpace($node[$boxKey]);
                if ( ! $hasRebasedLocalCoordinate && isset($node[$dimension]) && is_numeric($node[$dimension]) ) {
                    $node[$boxKey][$dimension] = (float) $node[$dimension];
                }
            }

            $node[$boxKey] = GeometryBox::withoutProvenance(GeometryBox::withProvenance($node[$boxKey], GeometryBox::SOURCE_COMPONENT_CLONE));
            $node[$boxKey]['geometry_semantics'] = self::GEOMETRY_SEMANTICS_COMPONENT_SOURCE_CLONE;
            if ( null !== $sourceKind ) {
                $node[$boxKey]['component_clone_source_kind'] = $sourceKind;
            }
        }

        if ( is_array($node['children'] ?? null) ) {
            foreach ( $node['children'] as $index => $child ) {
                if ( is_array($child) ) {
                    $node['children'][$index] = $this->markGeometry($child);
                }
            }
        }

        return $node;
    }
}
