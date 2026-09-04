<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Groups border-shell card layers into a synthetic local coordinate context.
 */
final class LocalBorderShellClusterResolver
{
    /**
     * @param array<string, mixed> $parent
     * @param array<int, mixed> $children
     * @return array{by_first_child_id: array<string, array<string, mixed>>, member_ids: array<string, string>}
     */
    public function resolve(array $parent, array $children): array
    {
        $nodes = array_values(array_filter($children, 'is_array'));
        if ( count($nodes) < 2 || ! $this->parentCanHostLocalClusters($parent) ) {
            return array('by_first_child_id' => array(), 'member_ids' => array());
        }

        $usedIds = array();
        $clusters = array();
        foreach ( $nodes as $index => $shell ) {
            $shellId = $this->nodeId($shell);
            if ( '' === $shellId || isset($usedIds[$shellId]) || ! $this->isBorderShell($shell) ) {
                continue;
            }

            $shellBox = $this->nodeBox($shell);
            if ( null === $shellBox ) {
                continue;
            }

            $members = array(array('index' => $index, 'node' => $shell));
            $hasText = false;
            $hasVisual = false;
            foreach ( $nodes as $candidateIndex => $candidate ) {
                $candidateId = $this->nodeId($candidate);
                if ( '' === $candidateId || $candidateId === $shellId || isset($usedIds[$candidateId]) ) {
                    continue;
                }
                if ( ! $this->nodeCenterIsInside($candidate, $shellBox) ) {
                    continue;
                }

                $members[] = array('index' => $candidateIndex, 'node' => $candidate);
                $type = strtoupper((string) ($candidate['type'] ?? ''));
                $hasText = $hasText || 'TEXT' === $type || $this->subtreeHasText($candidate);
                $hasVisual = $hasVisual || null !== $this->nodeAssetPath($candidate) || $this->hasRenderableVector($candidate);
            }

            $memberNodes = array_map(static fn (array $member): array => $member['node'], $members);
            $compactTextCard = $this->shellLooksLikeCompactTextCard($shellBox, $memberNodes);
            if ( ! $hasText || (count($members) < 3 && ! $compactTextCard) ) {
                continue;
            }
            if ( ! $hasVisual && ! $compactTextCard ) {
                continue;
            }

            usort($members, static fn (array $left, array $right): int => (int) $left['index'] <=> (int) $right['index']);
            $memberNodes = array_map(static fn (array $member): array => $member['node'], $members);
            $memberIds = array_values(array_filter(array_map(fn (array $member): string => $this->nodeId($member), $memberNodes)));
            foreach ( $memberIds as $memberId ) {
                $usedIds[$memberId] = true;
            }

            $localMembers = array_map(fn (array $member): array => $this->withLocalBox($member, $shellBox), $memberNodes);
            $clusters[] = array(
                'first_child_id' => $memberIds[0],
                'node' => $this->clusterNode($parent, $shell, $localMembers, $shellBox, $memberIds),
                'member_ids' => $memberIds,
            );
        }

        $byFirstChildId = array();
        $memberIds = array();
        foreach ( $clusters as $cluster ) {
            $byFirstChildId[$cluster['first_child_id']] = $cluster['node'];
            foreach ( $cluster['member_ids'] as $memberId ) {
                $memberIds[$memberId] = 'local_border_shell_cluster_member';
            }
        }

        return array('by_first_child_id' => $byFirstChildId, 'member_ids' => $memberIds);
    }

    /** @param array<string, mixed> $parent */
    private function parentCanHostLocalClusters(array $parent): bool
    {
        if ( is_array($parent['_figma_synthetic_local_cluster'] ?? null) ) {
            return false;
        }

        $type = strtoupper((string) ($parent['type'] ?? ''));
        return in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'SECTION'), true);
    }

    /** @param array<string, mixed> $node */
    private function isBorderShell(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, array('RECTANGLE', 'ROUNDED_RECTANGLE', 'VECTOR', 'BOOLEAN_OPERATION'), true) ) {
            return false;
        }
        if ( $this->subtreeHasText($node) || null !== $this->nodeAssetPath($node) ) {
            return false;
        }

        $paints = is_array($node['figma_paints'] ?? null) ? $node['figma_paints'] : array();
        $strokes = is_array($paints['strokes'] ?? null) ? $paints['strokes'] : array();
        if ( empty($strokes) ) {
            return false;
        }
        $fills = is_array($paints['fills'] ?? null) ? $paints['fills'] : array();
        foreach ( $fills as $fill ) {
            if ( is_array($fill) && strtoupper((string) ($fill['type'] ?? '')) !== 'NONE' && (float) ($fill['opacity'] ?? 1.0) > 0.01 ) {
                return false;
            }
        }

        $box = $this->nodeBox($node);
        return null !== $box && $box['width'] >= 120.0 && $box['height'] >= 80.0;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $shellBox
     * @param array<int, array<string, mixed>> $members
     */
    private function shellLooksLikeCompactTextCard(array $shellBox, array $members): bool
    {
        $textCount = 0;
        foreach ( $members as $member ) {
            if ( 'TEXT' === strtoupper((string) ($member['type'] ?? '')) || $this->subtreeHasText($member) ) {
                ++$textCount;
            }
        }

        return $textCount >= 1 && $shellBox['width'] <= 480.0 && $shellBox['height'] <= 260.0;
    }

    /**
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $shell
     * @param array<int, array<string, mixed>> $members
     * @param array{x: float, y: float, width: float, height: float} $shellBox
     * @param array<int, string> $memberIds
     * @return array<string, mixed>
     */
    private function clusterNode(array $parent, array $shell, array $members, array $shellBox, array $memberIds): array
    {
        $shellId = $this->nodeId($shell);
        $parentId = $this->nodeId($parent);
        return array(
            'id' => ('' !== $parentId ? $parentId . '/' : '') . 'local-cluster-' . $shellId,
            'type' => 'GROUP',
            'name' => 'Local border shell cluster',
            'box' => array(
                'x' => $shellBox['x'],
                'y' => $shellBox['y'],
                'width' => $shellBox['width'],
                'height' => $shellBox['height'],
            ),
            'layout' => array('freeform' => true),
            'children' => $members,
            '_figma_synthetic_local_cluster' => array(
                'reason_code' => 'local_border_shell_cluster',
                'shell_id' => $shellId,
                'member_ids' => $memberIds,
            ),
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array{x: float, y: float, width: float, height: float} $shellBox
     * @return array<string, mixed>
     */
    private function withLocalBox(array $node, array $shellBox): array
    {
        $box = $this->nodeBox($node);
        if ( null === $box ) {
            return $node;
        }

        $localBox = array(
            'x' => $box['x'] - $shellBox['x'],
            'y' => $box['y'] - $shellBox['y'],
            'width' => $box['width'],
            'height' => $box['height'],
            'coordinate_space' => 'local',
        );
        $node['box'] = $localBox;
        $node['x'] = $localBox['x'];
        $node['y'] = $localBox['y'];
        $node['width'] = $localBox['width'];
        $node['height'] = $localBox['height'];

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @param array{x: float, y: float, width: float, height: float} $shellBox
     */
    private function nodeCenterIsInside(array $node, array $shellBox): bool
    {
        $box = $this->nodeBox($node);
        if ( null === $box ) {
            return false;
        }
        if ( $box['width'] <= 0.0 || $box['height'] <= 0.0 ) {
            return false;
        }

        $centerX = $box['x'] + ($box['width'] / 2.0);
        $centerY = $box['y'] + ($box['height'] / 2.0);
        return $centerX >= $shellBox['x'] - 1.5
            && $centerY >= $shellBox['y'] - 1.5
            && $centerX <= $shellBox['x'] + $shellBox['width'] + 1.5
            && $centerY <= $shellBox['y'] + $shellBox['height'] + 1.5;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function nodeBox(array $node): ?array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        foreach ( array('x', 'y', 'width', 'height') as $key ) {
            if ( ! isset($box[$key]) || ! is_numeric($box[$key]) ) {
                return null;
            }
        }
        return array('x' => (float) $box['x'], 'y' => (float) $box['y'], 'width' => (float) $box['width'], 'height' => (float) $box['height']);
    }

    /** @param array<string, mixed> $node */
    private function nodeId(array $node): string
    {
        return isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
    }

    /** @param array<string, mixed> $node */
    private function nodeAssetPath(array $node): ?string
    {
        foreach ( array('asset_path', '_figma_asset_path') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return (string) $node[$key];
            }
        }
        $paints = is_array($node['figma_paints'] ?? null) ? $node['figma_paints'] : array();
        $fills = is_array($paints['fills'] ?? null) ? $paints['fills'] : array();
        foreach ( $fills as $paint ) {
            if ( is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                return 'image-paint';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $node */
    private function hasRenderableVector(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        return in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true);
    }

    /** @param array<string, mixed> $node */
    private function subtreeHasText(array $node): bool
    {
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            return true;
        }
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasText($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, mixed>
     */
    private function nodeList(array $node): array
    {
        if ( is_array($node['nodes'] ?? null) ) {
            return array_values($node['nodes']);
        }
        if ( is_array($node['children'] ?? null) ) {
            return array_values($node['children']);
        }

        return array();
    }
}
