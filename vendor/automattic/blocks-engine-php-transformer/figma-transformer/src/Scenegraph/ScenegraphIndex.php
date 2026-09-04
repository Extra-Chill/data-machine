<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Builds deterministic Figma node maps and parent/child indexes from decoded node changes.
 */
final class ScenegraphIndex
{
    /**
     * @param array<string, mixed> $source Decoded NODE_CHANGES-shaped source array.
     * @return array<string, mixed>
     */
    public function build(array $source): array
    {
        $diagnostics = array();
        $rawNodes    = array();
        $roots       = $this->extractRootNodes($source);

        foreach ( $roots as $key => $root ) {
            if ( is_array($root) ) {
                $this->collectNode($root, is_string($key) ? $key : null, null, is_int($key) ? $key : null, $rawNodes, $diagnostics);
            }
        }

        ksort($rawNodes, SORT_NATURAL);

        $nodeMap       = array();
        $parentIndex   = array();
        $childrenIndex = array();

        foreach ( $rawNodes as $id => $entry ) {
            $node   = $entry['node'];
            $parent = $entry['parent'];

            $node['id']       = $id;
            $node['children'] = array();

            $nodeMap[$id]     = $node;
            $parentIndex[$id] = $parent;

            if ( null !== $parent ) {
                $childrenIndex[$parent] ??= array();
                $childrenIndex[$parent][] = $id;
            }
        }

        foreach ( $nodeMap as $id => $_node ) {
            $childrenIndex[$id] ??= array();
        }

        foreach ( $childrenIndex as $parent => $children ) {
            $childrenIndex[$parent] = $this->sortNodeIds($children, $nodeMap, $nodeMap[$parent] ?? array());
        }

        $topLevelNodeIds = array();
        foreach ( $parentIndex as $id => $parent ) {
            if ( null === $parent || ! isset($nodeMap[$parent]) ) {
                $topLevelNodeIds[] = $id;
            }
        }
        $topLevelNodeIds = $this->sortNodeIds($topLevelNodeIds, $nodeMap);

        foreach ( array_keys($nodeMap) as $id ) {
            $nodeMap[$id] = $this->hydrateNode($id, $nodeMap, $childrenIndex);
        }

        return array(
            'nodes'              => $nodeMap,
            'parent_index'       => $parentIndex,
            'children_index'     => $childrenIndex,
            'top_level_node_ids' => $topLevelNodeIds,
            'diagnostics'        => $diagnostics,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, array<int, string>> $childrenIndex
     * @param array<int, string> $trail
     * @return array<string, mixed>
     */
    private function hydrateNode(string $id, array $nodeMap, array $childrenIndex, array $trail = array()): array
    {
        $node = $nodeMap[$id];
        $node['children'] = array();

        if ( in_array($id, $trail, true) ) {
            return $node;
        }

        $trail[] = $id;
        foreach ( $childrenIndex[$id] ?? array() as $childId ) {
            if ( isset($nodeMap[$childId]) ) {
                $node['children'][] = $this->hydrateNode($childId, $nodeMap, $childrenIndex, $trail);
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<mixed>
     */
    private function extractRootNodes(array $source): array
    {
        foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges') as $key ) {
            if ( is_array($source[$key] ?? null) ) {
                return $source[$key];
            }
        }

        if ( is_array($source['document'] ?? null) ) {
            return array($source['document']);
        }

        if ( is_array($source['nodes'] ?? null) ) {
            return $source['nodes'];
        }

        return $source;
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, array{node: array<string, mixed>, parent: ?string}> $rawNodes
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function collectNode(array $value, ?string $fallbackId, ?string $parentId, ?int $sourceOrder, array &$rawNodes, array &$diagnostics): void
    {
        $node = $this->unwrapNodeChange($value);
        if ( null === $node ) {
            $diagnostics[] = array(
                'code'    => 'scenegraph_node_missing',
                'message' => 'Skipped a node change without a node payload.',
            );
            return;
        }

        $id = $this->readString($node, array('id', 'node_id', 'nodeId')) ?? $this->readGuid($node['guid'] ?? null) ?? $fallbackId;
        if ( null === $id || '' === $id ) {
            $diagnostics[] = array(
                'code'    => 'scenegraph_node_id_missing',
                'message' => 'Skipped a node without a stable id.',
            );
            return;
        }

        $explicitParent = $this->readString($node, array('parent', 'parentId', 'parent_id')) ?? $this->readParentGuid($node);
        $effectiveParent = $explicitParent ?? $parentId;

        $children = array();
        if ( is_array($node['children'] ?? null) ) {
            $children = $node['children'];
        }

        unset($node['children']);
        if ( null !== $sourceOrder ) {
            $node['_source_order'] = $sourceOrder;
        }
        $parentSortPosition = $this->readParentSortPosition($node);
        if ( null !== $parentSortPosition ) {
            $node['_parent_sort_position'] = $parentSortPosition;
        }

        if ( isset($rawNodes[$id]) ) {
            $diagnostics[] = array(
                'code'    => 'scenegraph_node_id_duplicate',
                'message' => 'Encountered a duplicate node id; kept the richer source node.',
                'node_id' => $id,
            );

            if ( $this->nodeRichness($node, $children) <= $this->nodeRichness($rawNodes[$id]['node'], $rawNodes[$id]['children'] ?? array()) ) {
                return;
            }
        }

        $rawNodes[$id] = array(
            'node'     => $node,
            'parent'   => $effectiveParent,
            'children' => $children,
        );

        foreach ( $children as $key => $child ) {
            if ( is_array($child) ) {
                $this->collectNode($child, is_string($key) ? $key : null, $id, is_int($key) ? $key : null, $rawNodes, $diagnostics);
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     * @return ?array<string, mixed>
     */
    private function unwrapNodeChange(array $value): ?array
    {
        foreach ( array('node', 'document', 'newValue', 'value') as $key ) {
            if ( is_array($value[$key] ?? null) ) {
                return $value[$key];
            }
        }

        if ( isset($value['type']) || isset($value['id']) || isset($value['guid']) || isset($value['children']) ) {
            return $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function readParentSortPosition(array $node): ?string
    {
        if ( isset($node['sortPosition']) && is_scalar($node['sortPosition']) ) {
            $position = (string) $node['sortPosition'];
            return '' === $position ? null : $position;
        }

        if ( isset($node['parentIndex']['position']) && is_scalar($node['parentIndex']['position']) ) {
            $position = (string) $node['parentIndex']['position'];
            return '' === $position ? null : $position;
        }

        if ( isset($node['parent_index']['position']) && is_scalar($node['parent_index']['position']) ) {
            $position = (string) $node['parent_index']['position'];
            return '' === $position ? null : $position;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $keys
     */
    private function readString(array $node, array $keys): ?string
    {
        foreach ( $keys as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                return (string) $node[$key];
            }
        }

        return null;
    }

    private function readGuid(mixed $guid): ?string
    {
        if ( is_array($guid) && isset($guid['sessionID'], $guid['localID']) ) {
            return (string) $guid['sessionID'] . ':' . (string) $guid['localID'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function readParentGuid(array $node): ?string
    {
        return $this->readGuid($node['parentIndex']['guid'] ?? null);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, mixed> $children
     */
    private function nodeRichness(array $node, array $children): int
    {
        return count($node, COUNT_RECURSIVE) + count($children, COUNT_RECURSIVE);
    }

    /**
     * @param array<int, string> $ids
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, string>
     */
    private function sortNodeIds(array $ids, array $nodeMap, array $parentNode = array()): array
    {
        $parentMode = strtoupper((string) ($parentNode['layoutMode'] ?? $parentNode['stackMode'] ?? ''));
        $hasExplicitLayerOrder = false;
        foreach ( $ids as $id ) {
            if ( isset($nodeMap[$id]['_parent_sort_position']) ) {
                $hasExplicitLayerOrder = true;
                break;
            }
        }

        usort(
            $ids,
            static function (string $left, string $right) use ($nodeMap, $parentMode, $parentNode, $hasExplicitLayerOrder): int {
                $leftNode  = $nodeMap[$left] ?? array();
                $rightNode = $nodeMap[$right] ?? array();

                if ( $hasExplicitLayerOrder ) {
                    return self::layerOrderKey($leftNode, $left) <=> self::layerOrderKey($rightNode, $right);
                }

                $leftBox  = self::readBounds($leftNode);
                $rightBox = self::readBounds($rightNode);

                if ( 'HORIZONTAL' === $parentMode ) {
                    return array($leftBox['x'], $leftBox['y'], (string) ($leftNode['name'] ?? ''), $left)
                        <=> array($rightBox['x'], $rightBox['y'], (string) ($rightNode['name'] ?? ''), $right);
                }

                $parentType = strtoupper((string) ($parentNode['type'] ?? ''));
                if ( '' === $parentMode && ( 'GROUP' === $parentType || true === ($parentNode['resizeToFit'] ?? false) ) ) {
                    return self::layerOrderKey($leftNode, $left) <=> self::layerOrderKey($rightNode, $right);
                }

                return array($leftBox['y'], $leftBox['x'], (string) ($leftNode['name'] ?? ''), $left)
                    <=> array($rightBox['y'], $rightBox['x'], (string) ($rightNode['name'] ?? ''), $right);
            }
        );

        return $ids;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{0: int, 1: int|float, 2: string|int, 3?: string}
     */
    private static function layerOrderKey(array $node, string $id): array
    {
        if ( isset($node['_parent_sort_position']) && is_scalar($node['_parent_sort_position']) ) {
            $position = (string) $node['_parent_sort_position'];
            if ( is_numeric($position) ) {
                return array(0, 0, (float) $position, $id);
            }

            return array(0, 1, $position, $id);
        }

        return array(1, (int) ($node['_source_order'] ?? 0), $id);
    }

    /**
     * @param array<string, mixed> $node
     * @return array{x: float, y: float}
     */
    private static function readBounds(array $node): array
    {
        foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds', 'relativeTransformBounds') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                return array(
                    'x' => is_numeric($node[$key]['x'] ?? null) ? (float) $node[$key]['x'] : 0.0,
                    'y' => is_numeric($node[$key]['y'] ?? null) ? (float) $node[$key]['y'] : 0.0,
                );
            }
        }

        if ( is_array($node['transform'] ?? null) ) {
            return array(
                'x' => is_numeric($node['transform']['m02'] ?? null) ? (float) $node['transform']['m02'] : 0.0,
                'y' => is_numeric($node['transform']['m12'] ?? null) ? (float) $node['transform']['m12'] : 0.0,
            );
        }

        return array('x' => 0.0, 'y' => 0.0);
    }
}
