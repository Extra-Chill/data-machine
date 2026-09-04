<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Coordinates sticky duplicate suppression and primary-node style adjustments.
 */
final class StickyLayoutCoordinator
{
    /**
     * @var callable(array<string, mixed>): array<int, mixed>
     */
    private $nodeList;

    /**
     * @var callable(array<string, mixed>): string
     */
    private $textContent;

    /**
     * @var array<string, bool>
     */
    private array $stickyGhostNodeIds = array();

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $stickyPrimaryNodeIds = array();

    /**
     * @var array<string, bool>
     */
    private array $stickyAncestorNodeIds = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $stickyGhostCandidates = array();

    /**
     * @param callable(array<string, mixed>): array<int, mixed> $nodeList
     * @param callable(array<string, mixed>): string            $textContent
     */
    public function __construct(callable $nodeList, callable $textContent)
    {
        $this->nodeList = $nodeList;
        $this->textContent = $textContent;
    }

    public function reset(): void
    {
        $this->stickyGhostNodeIds = array();
        $this->stickyPrimaryNodeIds = array();
        $this->stickyAncestorNodeIds = array();
        $this->stickyGhostCandidates = array();
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    public function detectStickyGhostCandidates(array $nodes): void
    {
        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $this->detectStickyGhostCandidatesInNode($node);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    public function isSuppressedStickyGhost(array $node): bool
    {
        $id = (string) ($node['id'] ?? '');
        return '' !== $id && isset($this->stickyGhostNodeIds[$id]);
    }

    /**
     * @param array<string, mixed> $node
     */
    public function isStickyPrimary(array $node): bool
    {
        $id = (string) ($node['id'] ?? '');
        return '' !== $id && isset($this->stickyPrimaryNodeIds[$id]);
    }

    /**
     * @param array<string, mixed> $node
     */
    public function containsStickyPrimary(array $node): bool
    {
        $id = (string) ($node['id'] ?? '');
        return '' !== $id && isset($this->stickyAncestorNodeIds[$id]);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string>   $styles
     * @return array<int, string>
     */
    public function stickyAwareStyleDeclarations(array $node, array $styles): array
    {
        if ( ! $this->isStickyPrimary($node) ) {
            return $styles;
        }

        $styles = array_values(array_filter(
            $styles,
            static fn (string $style): bool => ! str_starts_with($style, 'position:') && ! str_starts_with($style, 'top:')
        ));
        $styles[] = 'position:sticky';
        $styles[] = 'top:0';
        $styles[] = 'align-self:flex-start';

        return $styles;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function stickyGhostCandidates(): array
    {
        return $this->stickyGhostCandidates;
    }

    /**
     * @param array<string, mixed> $parentNode
     * @param array<int, string> $ancestorIds
     */
    private function detectStickyGhostCandidatesInNode(array $parentNode, array $ancestorIds = array()): void
    {
        $children = array_values(array_filter(($this->nodeList)($parentNode), 'is_array'));
        $count = count($children);
        $parentId = (string) ($parentNode['id'] ?? '');
        $stickyAncestorIds = '' === $parentId ? $ancestorIds : array_merge($ancestorIds, array($parentId));
        for ( $i = 0; $i < $count; $i++ ) {
            for ( $j = $i + 1; $j < $count; $j++ ) {
                $this->detectStickyGhostCandidatePair($parentNode, $children[$i], $children[$j], $stickyAncestorIds);
            }
        }

        foreach ( $children as $child ) {
            $this->detectStickyGhostCandidatesInNode($child, $stickyAncestorIds);
        }
    }

    /**
     * @param array<string, mixed> $parentNode
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param array<int, string> $stickyAncestorIds
     */
    private function detectStickyGhostCandidatePair(array $parentNode, array $a, array $b, array $stickyAncestorIds): void
    {
        $aAbsolute = $this->isAbsoluteLayoutNode($a);
        $bAbsolute = $this->isAbsoluteLayoutNode($b);
        if ( $aAbsolute === $bAbsolute ) {
            return;
        }

        $flow = $aAbsolute ? $b : $a;
        $ghost = $aAbsolute ? $a : $b;
        if ( $this->nodeOpacity($ghost) > 0.25 || $this->nodeOpacity($flow) < 0.99 ) {
            return;
        }

        $flowBox = is_array($flow['box'] ?? null) ? $flow['box'] : array();
        $ghostBox = is_array($ghost['box'] ?? null) ? $ghost['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        if ( ! $this->sameNodeSize($flowBox, $ghostBox) || ! $this->isFarVerticalEdgeDuplicate($ghost, $ghostBox, $parentBox) ) {
            return;
        }

        $signature = $this->stickyGhostSemanticSignature($flow);
        $ghostSignature = $this->stickyGhostSemanticSignature($ghost);
        if ( '' === $signature || $signature !== $ghostSignature ) {
            $signature = $this->stickyGhostContentSignature($flow);
            if ( '' === $signature || $signature !== $this->stickyGhostContentSignature($ghost) ) {
                return;
            }
        }

        $primaryId = (string) ($flow['id'] ?? '');
        $ghostId = (string) ($ghost['id'] ?? '');
        if ( '' === $primaryId || '' === $ghostId || isset($this->stickyGhostNodeIds[$ghostId]) ) {
            return;
        }

        $this->stickyPrimaryNodeIds[$primaryId] = array('ghost_id' => $ghostId, 'signature' => $signature);
        foreach ( $stickyAncestorIds as $ancestorId ) {
            if ( '' !== $ancestorId ) {
                $this->stickyAncestorNodeIds[$ancestorId] = true;
            }
        }
        $this->stickyGhostNodeIds[$ghostId] = true;
        $this->stickyGhostCandidates[] = array(
            'primary_id' => $primaryId,
            'ghost_id' => $ghostId,
            'parent_id' => (string) ($parentNode['id'] ?? ''),
            'signature' => $signature,
            'ghost_opacity' => $this->nodeOpacity($ghost),
            'evidence' => array(
                'primary_positioning' => 'flow',
                'ghost_positioning' => 'absolute',
                'ghost_vertical_constraint' => $this->verticalConstraint($ghost),
                'same_size' => true,
            ),
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isAbsoluteLayoutNode(array $node): bool
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        return 'absolute' === ($layout['positioning'] ?? null);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeOpacity(array $node): float
    {
        $box = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array();
        if ( isset($box['opacity']) && is_numeric($box['opacity']) ) {
            return (float) $box['opacity'];
        }
        if ( isset($node['opacity']) && is_numeric($node['opacity']) ) {
            return (float) $node['opacity'];
        }

        return 1.0;
    }

    /**
     * @param array<string, mixed> $flowBox
     * @param array<string, mixed> $ghostBox
     */
    private function sameNodeSize(array $flowBox, array $ghostBox): bool
    {
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($flowBox[$dimension], $ghostBox[$dimension]) || ! is_numeric($flowBox[$dimension]) || ! is_numeric($ghostBox[$dimension]) ) {
                return false;
            }
            if ( abs((float) $flowBox[$dimension] - (float) $ghostBox[$dimension]) > 1.0 ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $ghost
     * @param array<string, mixed> $ghostBox
     * @param array<string, mixed> $parentBox
     */
    private function isFarVerticalEdgeDuplicate(array $ghost, array $ghostBox, array $parentBox): bool
    {
        if ( ! isset($ghostBox['y'], $ghostBox['height'], $parentBox['height']) || ! is_numeric($ghostBox['y']) || ! is_numeric($ghostBox['height']) || ! is_numeric($parentBox['height']) ) {
            return false;
        }

        $bottomGap = (float) $parentBox['height'] - (float) $ghostBox['y'] - (float) $ghostBox['height'];
        $constraint = $this->verticalConstraint($ghost);
        $isFarPinned = in_array($constraint, array('BOTTOM', 'MAX'), true);

        return $isFarPinned && (float) $ghostBox['y'] > (float) $ghostBox['height'] && $bottomGap >= -1.0 && $bottomGap <= 32.0;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function verticalConstraint(array $node): string
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $constraints = is_array($layout['constraints'] ?? null) ? $layout['constraints'] : array();
        if ( isset($constraints['vertical']) && is_scalar($constraints['vertical']) ) {
            return strtoupper((string) $constraints['vertical']);
        }
        if ( isset($node['constraints']['vertical']) && is_scalar($node['constraints']['vertical']) ) {
            return strtoupper((string) $node['constraints']['vertical']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function stickyGhostSemanticSignature(array $node): string
    {
        $sourceId = isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) ? trim((string) $node['figma_component_source_id']) : '';
        if ( '' !== $sourceId ) {
            return 'component:' . $sourceId;
        }

        return $this->stickyGhostContentSignature($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function stickyGhostContentSignature(array $node): string
    {
        $textSequence = $this->descendantTextSequence($node);
        if ( empty($textSequence) ) {
            return '';
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $type = strtoupper((string) ($node['type'] ?? ''));
        $name = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($node['name'] ?? '')) ?? ''));
        $width = isset($box['width']) && is_numeric($box['width']) ? (string) round((float) $box['width']) : '';
        $height = isset($box['height']) && is_numeric($box['height']) ? (string) round((float) $box['height']) : '';

        return 'content:' . implode('|', array($type, $name, $width, $height, sha1(implode("\n", $textSequence))));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function descendantTextSequence(array $node): array
    {
        $sequence = array();
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            $text = trim(preg_replace('/\s+/', ' ', ($this->textContent)($node)) ?? '');
            if ( '' !== $text ) {
                $sequence[] = $text;
            }
        }

        foreach ( ($this->nodeList)($node) as $child ) {
            if ( is_array($child) ) {
                array_push($sequence, ...$this->descendantTextSequence($child));
            }
        }

        return $sequence;
    }
}
