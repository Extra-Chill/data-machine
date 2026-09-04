<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Carries source sibling order into normalized child layout metadata.
 */
final class ScenegraphSourceOrderPropagator
{
    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function apply(array $node, int $fallbackOrder): array
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $layout['source_order'] = isset($node['_source_order']) && is_numeric($node['_source_order'])
            ? (int) $node['_source_order']
            : $fallbackOrder;
        if ( isset($node['_parent_sort_position']) && is_scalar($node['_parent_sort_position']) ) {
            $layout['layer_order'] = (string) $node['_parent_sort_position'];
        }
        $node['layout'] = $layout;

        return $node;
    }
}
