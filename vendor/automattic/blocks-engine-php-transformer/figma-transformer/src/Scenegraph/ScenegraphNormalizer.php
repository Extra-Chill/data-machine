<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Normalizes decoded Figma scenegraph payloads into a deterministic transformer contract.
 */
final class ScenegraphNormalizer
{
    private const GEOMETRY_SEMANTICS_COMPONENT_SOURCE_CLONE = 'component_source_clone';

    private const DIMENSION_ZERO_EPSILON = 0.000001;

    private readonly TextNormalizer $textNormalizer;

    private readonly InstanceResolver $instanceResolver;

    private readonly ScenegraphDiagnostics $diagnosticsNormalizer;

    private readonly ScenegraphLayoutNormalizer $layoutNormalizer;

    private readonly ScenegraphSourceOrderPropagator $sourceOrderPropagator;

    private readonly ScenegraphVectorInstanceScaler $vectorInstanceScaler;

    private readonly ComponentSourceCloneGeometry $componentSourceCloneGeometry;

    public function __construct(
        private readonly ScenegraphIndex $index = new ScenegraphIndex(),
        private readonly VectorGeometryNormalizer $vectorGeometryNormalizer = new VectorGeometryNormalizer(),
        private readonly PaintNormalizer $paintNormalizer = new PaintNormalizer(),
        ?TextNormalizer $textNormalizer = null,
        ?ScenegraphDiagnostics $diagnosticsNormalizer = null,
        ?ScenegraphLayoutNormalizer $layoutNormalizer = null,
        ?ScenegraphSourceOrderPropagator $sourceOrderPropagator = null,
        ?ScenegraphVectorInstanceScaler $vectorInstanceScaler = null,
        ?ComponentSourceCloneGeometry $componentSourceCloneGeometry = null
    ) {
        $this->textNormalizer = $textNormalizer ?? new TextNormalizer($this->vectorGeometryNormalizer);
        $this->instanceResolver = new InstanceResolver();
        $this->diagnosticsNormalizer = $diagnosticsNormalizer ?? new ScenegraphDiagnostics($this->vectorGeometryNormalizer);
        $this->layoutNormalizer = $layoutNormalizer ?? new ScenegraphLayoutNormalizer();
        $this->sourceOrderPropagator = $sourceOrderPropagator ?? new ScenegraphSourceOrderPropagator();
        $this->vectorInstanceScaler = $vectorInstanceScaler ?? new ScenegraphVectorInstanceScaler();
        $this->componentSourceCloneGeometry = $componentSourceCloneGeometry ?? new ComponentSourceCloneGeometry($this->vectorInstanceScaler);
    }

    /**
     * @param array<string, mixed> $source Decoded NODE_CHANGES-shaped source array.
     * @param array<string, mixed> $options Normalization options.
     * @return array<string, mixed>
     */
    public function normalize(array $source, array $options = array()): array
    {
        $index       = $this->index->build($source);
        $diagnostics = $index['diagnostics'];
        $blobs       = is_array($source['blobs'] ?? null) ? $source['blobs'] : array();
        if ( isset($options['max_nodes']) && is_numeric($options['max_nodes']) && (int) $options['max_nodes'] > 0 && count($index['nodes']) > (int) $options['max_nodes'] ) {
            $preferredRootIds = $this->preferredLimitRootIds($options, $index['nodes']);
            $index = $this->limitIndexNodes($index, (int) $options['max_nodes'], $diagnostics, $preferredRootIds);
        }
        $paintStyles = $this->buildPaintStyleDefinitions($index['nodes'], $diagnostics);
        $textStyles  = $this->buildTextStyleDefinitions($index['nodes']);
        $nodeMap     = $this->normalizeNodeMap($index['nodes'], $diagnostics, $blobs, $paintStyles, $textStyles, $options);
        $this->applyReverseChildZIndex($nodeMap);
        $components  = $this->buildComponentDefinitions($nodeMap);
        $componentDefinitionCount = $this->countComponentDefinitions($nodeMap);
        $instanceReport = $this->resolveInstances($nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, $options);
        $this->applyReverseChildZIndex($nodeMap);
        $topLevelIds = $index['top_level_node_ids'];
        $frameIds    = $this->selectTopLevelFrameIds($topLevelIds, $nodeMap);

        $explicitSelectedFrameId = isset($options['frame_id']) && is_scalar($options['frame_id']) && isset($nodeMap[(string) $options['frame_id']]);
        $selectedFrameId = null;
        if ( $explicitSelectedFrameId ) {
            $selectedFrameId = (string) $options['frame_id'];
        } elseif ( ! empty($frameIds) ) {
            $selectedFrameId = $frameIds[0];
        } elseif ( ! empty($topLevelIds) ) {
            $selectedFrameId = $topLevelIds[0];
        }

        $renderIds = $topLevelIds;
        $renderDocument = ! empty($options['render_document']);
        if ( $renderDocument ) {
            $documentFrameIds = $this->documentFrameIds($options, $frameIds, $nodeMap);
            foreach ( $documentFrameIds as $documentFrameId ) {
                $rebasedFrame = $this->rebasePageFrameToLocalOrigin($this->refreshResolvedTree($nodeMap[$documentFrameId], $nodeMap));
                $this->appendNodeMap($rebasedFrame, $nodeMap);
            }
        }
        if ( ! $renderDocument && null !== $selectedFrameId && 1 === count($topLevelIds) && $selectedFrameId !== $topLevelIds[0] ) {
            $renderIds = array($selectedFrameId);
        }
        $renderNodes = array();
        foreach ( $renderIds as $id ) {
            if ( isset($nodeMap[$id]) ) {
                $node = $this->refreshResolvedTree($nodeMap[$id], $nodeMap);
                if ( $explicitSelectedFrameId && $id === $selectedFrameId ) {
                    $node = $this->rebasePageFrameToLocalOrigin($node);
                }
                $renderNodes[] = $node;
            }
        }

        $textInventory          = $this->buildTextInventory($nodeMap);
        $assetReferences        = $this->buildAssetReferences($nodeMap);
        $variableBindingSummary = $this->buildVariableBindingSummary($nodeMap);
        $sourceMetadata         = $this->normalizeDocumentMetadata($source);
        $sourceName             = $this->readSourceName($source, $renderNodes);
        $diagnostics            = $this->diagnosticsNormalizer->compact($diagnostics);

        return array(
            'schema'              => 'blocks-engine/figma-transformer/scenegraph/v1',
            'name'                => $sourceName,
            'nodes'               => $renderNodes,
            'assets'              => is_array($source['assets'] ?? null) ? $source['assets'] : array(),
            'figma_blobs'         => $blobs,
            'node_map'            => $nodeMap,
            'parent_index'        => $index['parent_index'],
            'children_index'      => $index['children_index'],
            'top_level_node_ids'  => $topLevelIds,
            'top_level_frame_ids' => $frameIds,
            'selected_frame_id'   => $selectedFrameId,
            'text_inventory'      => $textInventory,
            'asset_references'    => $assetReferences,
            'diagnostics'         => $diagnostics,
            'source_report'       => array(
                'schema'                => 'blocks-engine/figma-transformer/scenegraph-source/v1',
                'input_shape'           => $this->detectInputShape($source),
                'name'                  => $sourceName,
                'node_count'            => count($nodeMap),
                'top_level_node_ids'    => $topLevelIds,
                'top_level_frame_ids'   => $frameIds,
                'selected_frame_id'     => $selectedFrameId,
                'text_node_count'       => count($textInventory),
                'asset_reference_count' => count($assetReferences),
                'asset_references'      => $assetReferences,
                'figma_metadata'        => $sourceMetadata,
                'component_definition_count' => $componentDefinitionCount,
                'instance_node_count'   => $instanceReport['instance_node_count'],
                'resolved_instance_count' => $instanceReport['resolved_instance_count'],
                'unresolved_component_references' => $instanceReport['unresolved_component_references'],
                'variable_bindings'     => $variableBindingSummary,
                'diagnostic_count'      => count($diagnostics),
            ),
        );
    }

    /**
     * @param array<string, mixed>             $index
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function limitIndexNodes(array $index, int $maxNodes, array &$diagnostics, array $preferredRootIds = array()): array
    {
        $nodes = is_array($index['nodes'] ?? null) ? $index['nodes'] : array();
        $childrenIndex = is_array($index['children_index'] ?? null) ? $index['children_index'] : array();
        $allowedIds = array();
        foreach ( $preferredRootIds as $preferredRootId ) {
            if ( is_string($preferredRootId) && isset($nodes[$preferredRootId]) ) {
                foreach ( $this->limitedSubtreeIds($preferredRootId, $childrenIndex, $maxNodes) as $allowedId ) {
                    $allowedIds[$allowedId] = $allowedId;
                }
            }
        }
        $allowedIds = array() !== $allowedIds ? array_values($allowedIds) : array_slice(array_keys($nodes), 0, $maxNodes);
        $allowedIds = $this->includeComponentClosureIds($allowedIds, $nodes, is_array($index['children_index'] ?? null) ? $index['children_index'] : array());
        $allowed = array_fill_keys($allowedIds, true);
        $limitedNodes = array();

        foreach ( $allowedIds as $id ) {
            if ( isset($nodes[$id]) && is_array($nodes[$id]) ) {
                $limitedNodes[$id] = $this->pruneNodeChildren($nodes[$id], $allowed);
            }
        }

        $index['nodes'] = $limitedNodes;
        $index['parent_index'] = array_intersect_key(is_array($index['parent_index'] ?? null) ? $index['parent_index'] : array(), $allowed);
        $childrenIndex = array();
        foreach ( is_array($index['children_index'] ?? null) ? $index['children_index'] : array() as $parentId => $childIds ) {
            if ( ! isset($allowed[$parentId]) || ! is_array($childIds) ) {
                continue;
            }
            $childrenIndex[$parentId] = array_values(array_filter($childIds, static fn (string $childId): bool => isset($allowed[$childId])));
        }
        $index['children_index'] = $childrenIndex;
        $index['top_level_node_ids'] = array_values(array_filter(
            is_array($index['top_level_node_ids'] ?? null) ? $index['top_level_node_ids'] : array(),
            static fn (string $id): bool => isset($allowed[$id])
        ));

        $allowedPreferredRootIds = array_values(array_filter(
            $preferredRootIds,
            static fn (string $preferredRootId): bool => isset($allowed[$preferredRootId])
        ));
        if ( array() !== $allowedPreferredRootIds ) {
            $index['top_level_node_ids'] = $allowedPreferredRootIds;
        } elseif ( empty($index['top_level_node_ids']) && ! empty($allowedIds) ) {
            $index['top_level_node_ids'] = array($allowedIds[0]);
        }

        $diagnostics[] = array(
            'severity' => 'warning',
            'code'     => 'scenegraph_node_limit_applied',
            'message'  => 'Scenegraph normalization was limited to a configured maximum node count.',
            'source'   => 'ScenegraphNormalizer',
            'context'  => array(
                'original_node_count' => count($nodes),
                'max_nodes'           => $maxNodes,
                'selected_node_count' => count($allowedIds),
                'preferred_root_id'   => $preferredRootIds[0] ?? null,
                'preferred_root_ids'  => $preferredRootIds,
            ),
        );

        return $index;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $nodes
     * @return array<int, string>
     */
    private function preferredLimitRootIds(array $options, array $nodes): array
    {
        $ids = array();
        if ( isset($options['frame_id']) && is_scalar($options['frame_id']) ) {
            $ids[] = (string) $options['frame_id'];
        }
        if ( is_array($options['document_frame_ids'] ?? null) ) {
            foreach ( $options['document_frame_ids'] as $id ) {
                if ( is_scalar($id) ) {
                    $ids[] = (string) $id;
                }
            }
        }

        $deduped = array();
        foreach ( $ids as $id ) {
            if ( '' !== $id && isset($nodes[$id]) ) {
                $deduped[$id] = $id;
            }
        }

        return array_values($deduped);
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function compactGlyphCommandBlobDiagnostics(array $diagnostics): array
    {
        $compacted = array();
        $count = 0;
        $nodeIds = array();
        $sampleGlyphs = array();

        foreach ( $diagnostics as $diagnostic ) {
            if ( ! is_array($diagnostic) || 'unsupported_text_glyph_command_blob' !== ($diagnostic['code'] ?? null) ) {
                $compacted[] = $diagnostic;
                continue;
            }

            $count++;
            $context = is_array($diagnostic['context'] ?? null) ? $diagnostic['context'] : array();
            $nodeId = isset($context['node_id']) && is_scalar($context['node_id']) ? (string) $context['node_id'] : '';
            if ( '' !== $nodeId ) {
                $nodeIds[$nodeId] = true;
            }
            if ( count($sampleGlyphs) < 10 ) {
                $sampleGlyph = array(
                    'node_id'     => $nodeId,
                    'glyph_index' => isset($context['glyph_index']) && is_numeric($context['glyph_index']) ? (int) $context['glyph_index'] : null,
                );
                if ( isset($context['byte_length']) && is_numeric($context['byte_length']) ) {
                    $sampleGlyph['byte_length'] = (int) $context['byte_length'];
                }
                if ( isset($context['reason']) && is_scalar($context['reason']) ) {
                    $sampleGlyph['reason'] = (string) $context['reason'];
                }
                $sampleGlyphs[] = $sampleGlyph;
            }
        }

        if ( 0 === $count ) {
            return $compacted;
        }

        $sampleNodeIds = array_slice(array_keys($nodeIds), 0, 10);
        $compacted[] = array(
            'severity' => 'warning',
            'code'     => 'unsupported_text_glyph_command_blob',
            'message'  => 'Unsupported Figma text glyph command blobs were omitted from derived glyph metadata.',
            'source'   => 'ScenegraphNormalizer',
            'context'  => array(
                'total_count'         => $count,
                'affected_node_count' => count($nodeIds),
                'sample_node_ids'     => $sampleNodeIds,
                'sample_glyphs'       => $sampleGlyphs,
            ),
        );

        return $compacted;
    }

    /**
     * @param array<string, array<int, string>> $childrenIndex
     * @return array<int, string>
     */
    private function limitedSubtreeIds(string $rootId, array $childrenIndex, int $maxNodes): array
    {
        $ids = array();
        $queue = array($rootId);
        $seen = array();

        while ( ! empty($queue) && count($ids) < $maxNodes ) {
            $id = array_shift($queue);
            if ( ! is_string($id) || isset($seen[$id]) ) {
                continue;
            }

            $seen[$id] = true;
            $ids[] = $id;
            foreach ( $childrenIndex[$id] ?? array() as $childId ) {
                if ( is_string($childId) && ! isset($seen[$childId]) ) {
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }

    /**
     * Keep local component definitions reachable when a selected page subtree is scoped down.
     *
     * @param array<int, string>                $allowedIds
     * @param array<string, array<string,mixed>> $nodes
     * @param array<string, array<int, string>> $childrenIndex
     * @return array<int, string>
     */
    private function includeComponentClosureIds(array $allowedIds, array $nodes, array $childrenIndex): array
    {
        $definitionIds = $this->rawComponentDefinitionIds($nodes);
        $allowed = array_fill_keys($allowedIds, true);
        $queue = $allowedIds;

        while ( ! empty($queue) ) {
            $id = array_shift($queue);
            if ( ! is_string($id) || ! isset($nodes[$id]) || ! is_array($nodes[$id]) ) {
                continue;
            }

            $reference = $this->readComponentReference($nodes[$id]);
            if ( null === $reference || ! isset($definitionIds[$reference['id']]) ) {
                continue;
            }

            $componentRootId = $definitionIds[$reference['id']];
            foreach ( $this->subtreeIds($componentRootId, $childrenIndex) as $componentId ) {
                if ( isset($allowed[$componentId]) ) {
                    continue;
                }

                $allowed[$componentId] = true;
                $allowedIds[] = $componentId;
                $queue[] = $componentId;
            }
        }

        return $allowedIds;
    }

    /**
     * @param array<string, array<string,mixed>> $nodes
     * @return array<string, string>
     */
    private function rawComponentDefinitionIds(array $nodes): array
    {
        $definitions = array();
        foreach ( $nodes as $id => $node ) {
            if ( ! is_array($node) || ! in_array(strtoupper((string) ($node['type'] ?? '')), array('COMPONENT', 'COMPONENT_SET', 'SYMBOL'), true) ) {
                continue;
            }

            foreach ( array_unique(array_filter(array($id, $this->readString($node, array('componentId', 'component_id', 'key', 'componentKey', 'componentOrStateGroupKey'))))) as $definitionId ) {
                $definitions[(string) $definitionId] = $id;
            }
        }

        return $definitions;
    }

    /**
     * @param array<string, array<int, string>> $childrenIndex
     * @return array<int, string>
     */
    private function subtreeIds(string $rootId, array $childrenIndex): array
    {
        $ids = array();
        $queue = array($rootId);
        $seen = array();

        while ( ! empty($queue) ) {
            $id = array_shift($queue);
            if ( ! is_string($id) || isset($seen[$id]) ) {
                continue;
            }

            $seen[$id] = true;
            $ids[] = $id;
            foreach ( $childrenIndex[$id] ?? array() as $childId ) {
                if ( is_string($childId) && ! isset($seen[$childId]) ) {
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool>  $allowed
     * @return array<string, mixed>
     */
    private function pruneNodeChildren(array $node, array $allowed): array
    {
        foreach ( array('children', 'nodes') as $childrenKey ) {
            if ( ! is_array($node[$childrenKey] ?? null) ) {
                continue;
            }

            $children = array();
            foreach ( $node[$childrenKey] as $child ) {
                if ( ! is_array($child) ) {
                    continue;
                }
                $childId = isset($child['id']) && is_scalar($child['id']) ? (string) $child['id'] : null;
                if ( null !== $childId && isset($allowed[$childId]) ) {
                    $children[] = $this->pruneNodeChildren($child, $allowed);
                }
            }
            $node[$childrenKey] = $children;
        }

        return $node;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<int, array<string, mixed>>    $diagnostics
     * @return array<string, array<string, mixed>>
     */
    private function normalizeNodeMap(array $nodeMap, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array(), array $options = array()): array
    {
        foreach ( $nodeMap as $id => $node ) {
            $nodeMap[$id] = $this->normalizeNode($node, $diagnostics, $blobs, $paintStyles, $textStyles, $options);
        }

        return $nodeMap;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function normalizeNode(array $node, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array(), array $options = array()): array
    {
        $id = (string) ($node['id'] ?? '');
        $type = strtoupper((string) ($node['type'] ?? ''));

        $styleReferences = $this->normalizeStyleReferences($node);
        if ( ! empty($styleReferences) ) {
            $node['figma_style_references'] = $styleReferences;
        }

        $component = $this->normalizeComponentMetadata($node, $type);
        if ( ! empty($component) ) {
            $node['figma_component'] = $component;
        }

        $variableBindings = $this->normalizeVariableBindings($node);
        if ( ! empty($variableBindings) ) {
            $node['figma_variable_bindings'] = $variableBindings;
        }

        $metadata = $this->normalizeDocumentMetadata($node);
        if ( ! empty($metadata) ) {
            $node['figma_metadata'] = $metadata;
        }

        if ( 'TEXT' === $type ) {
            $text = $this->textNormalizer->normalizeText($node, $blobs, $id, $diagnostics, $paintStyles, $textStyles, $options);
            if ( ! empty($text) ) {
                $node['figma_text'] = $text;
            }
        }

        $paints = $this->normalizePaintCollections($node, $id, $diagnostics, $paintStyles);
        if ( ! empty($paints) ) {
            $node['figma_paints'] = $paints;
        }

        $vectorPaths = $this->vectorGeometryNormalizer->normalizeVectorPaths($node, $blobs, $id, $diagnostics);
        if ( ! empty($vectorPaths) ) {
            $node['figma_vector_paths'] = $vectorPaths;
        }

        $vectorScale = $this->vectorGeometryNormalizer->normalizedVectorScale($node);
        if ( null !== $vectorScale ) {
            $node['figma_vector_scale'] = $vectorScale;
        }

        $box = $this->normalizeVisualBox($node);
        if ( ! empty($box) ) {
            $node['figma_box'] = $box;
        }

        $layoutBox = $this->normalizeLayoutBox($node);
        if ( ! empty($layoutBox) ) {
            $node['box'] = $layoutBox;
            $this->appendNonPositiveDimensionDiagnostics($node, $layoutBox, $id, $diagnostics);
        }

        $layout = $this->normalizeLayout($node);
        if ( ! empty($layout) ) {
            $node['layout'] = $layout;
        }

        $effects = $this->normalizeEffects($node, $id, $diagnostics);
        if ( ! empty($effects) ) {
            $node['figma_effects'] = $effects;
        }

        $mask = $this->normalizeMask($node);
        if ( ! empty($mask) ) {
            $node['figma_mask'] = $mask;
        }

        $link = $this->normalizeLink($node, $type);
        if ( ! empty($link) ) {
            $node['figma_link'] = $link;
        }

        $assetMetadata = $this->normalizeAssetMetadata($node);
        if ( ! empty($assetMetadata) ) {
            $node['figma_asset_metadata'] = $assetMetadata;
        }

        $devStatus = ScenegraphDevStatus::resolve($node);
        if ( null !== $devStatus ) {
            // Clean public value (ready_for_dev|completed|null) plus the raw
            // internal token for auditability (#280).
            $node['dev_status']     = $devStatus['normalized'];
            $node['dev_status_raw'] = $devStatus['raw'];
        }

        foreach ( array('children', 'nodes') as $childrenKey ) {
            if ( ! is_array($node[$childrenKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$childrenKey] as $index => $child ) {
                if ( is_array($child) ) {
                    $normalizedChild = $this->normalizeNode($child, $diagnostics, $blobs, $paintStyles, $textStyles, $options);
                    $normalizedChild = $this->sourceOrderPropagator->apply($normalizedChild, (int) $index);
                    $node[$childrenKey][$index] = $normalizedChild;
                }
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<string, mixed>             $box
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function appendNonPositiveDimensionDiagnostics(array $node, array $box, string $nodeId, array &$diagnostics): void
    {
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) || (float) $box[$dimension] > 0.0 ) {
                continue;
            }

            $value = (float) $box[$dimension];
            $isZero = ! is_finite($value) || abs($value) <= self::DIMENSION_ZERO_EPSILON;

            $diagnostics[] = array(
                'severity' => $isZero ? 'info' : 'warning',
                'code'     => $isZero ? 'figma_node_zero_dimension' : 'figma_node_negative_dimension',
                'message'  => 'Figma node normalized with a non-positive layout dimension.',
                'context'  => array(
                    'node_id'   => $nodeId,
                    'node_name' => (string) ($node['name'] ?? ''),
                    'node_type' => strtoupper((string) ($node['type'] ?? '')),
                    'dimension' => $dimension,
                    'value'     => $isZero ? 0.0 : $value,
                    'source'    => (string) ($box['coordinate_space'] ?? 'unknown'),
                ),
            );
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, string>
     */
    private function normalizeStyleReferences(array &$node): array
    {
        if ( ! is_array($node['styles'] ?? null) ) {
            return array();
        }

        $references = array();
        foreach ( array(
            'fill'   => array('field' => 'styleIdForFill', 'source_keys' => array('fill', 'fills')),
            'stroke' => array('field' => 'styleIdForStroke', 'source_keys' => array('stroke', 'strokes')),
            'text'   => array('field' => 'styleIdForText', 'source_keys' => array('text')),
            'effect' => array('field' => 'styleIdForEffect', 'source_keys' => array('effect', 'effects')),
        ) as $role => $config ) {
            $styleId = $this->readFirstStyleMapId($node['styles'], $config['source_keys']);
            if ( null === $styleId ) {
                continue;
            }

            $references[$role] = $styleId;
            if ( ! isset($node[$config['field']]) ) {
                $node[$config['field']] = $styleId;
            }
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $styles
     * @param array<int, string> $keys
     */
    private function readFirstStyleMapId(array $styles, array $keys): ?string
    {
        foreach ( $keys as $key ) {
            $styleId = $this->readGuidId($styles[$key] ?? null);
            if ( null !== $styleId ) {
                return $styleId;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeAssetMetadata(array $node): array
    {
        $metadata = array();

        foreach ( array('publishID', 'sourceLibraryKey', 'libraryKey', 'originFileKey') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                $metadata[$key] = (string) $node[$key];
            }
        }

        if ( is_array($node['exportSettings'] ?? null) ) {
            $exportSettings = $this->normalizeExportSettings($node['exportSettings']);
            if ( ! empty($exportSettings) ) {
                $metadata['exportSettings'] = $exportSettings;
            }
        }

        return $metadata;
    }

    /**
     * @param array<int|string, mixed> $settings
     * @return array<int, array<string, mixed>>
     */
    private function normalizeExportSettings(array $settings): array
    {
        $items = array_is_list($settings) ? $settings : array($settings);
        $normalized = array();

        foreach ( $items as $setting ) {
            if ( ! is_array($setting) ) {
                continue;
            }

            $item = array();
            foreach ( array('format', 'suffix') as $key ) {
                if ( isset($setting[$key]) && is_scalar($setting[$key]) ) {
                    $item[$key] = (string) $setting[$key];
                }
            }
            foreach ( array('contentsOnly', 'useAbsoluteBounds') as $key ) {
                if ( isset($setting[$key]) && is_bool($setting[$key]) ) {
                    $item[$key] = $setting[$key];
                }
            }
            if ( is_array($setting['constraint'] ?? null) ) {
                $constraint = array();
                if ( isset($setting['constraint']['type']) && is_scalar($setting['constraint']['type']) ) {
                    $constraint['type'] = (string) $setting['constraint']['type'];
                }
                if ( isset($setting['constraint']['value']) && is_numeric($setting['constraint']['value']) ) {
                    $constraint['value'] = (float) $setting['constraint']['value'];
                }
                if ( ! empty($constraint) ) {
                    $item['constraint'] = $constraint;
                }
            }
            if ( ! empty($item) ) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeVariableBindings(array $node): array
    {
        $normalized = array();
        $bindings = array();
        $byRole = array();

        foreach ( array('variableConsumptionMap', 'parameterConsumptionMap') as $sourceKey ) {
            if ( ! is_array($node[$sourceKey]['entries'] ?? null) ) {
                continue;
            }

            foreach ( $node[$sourceKey]['entries'] as $entry ) {
                if ( ! is_array($entry) ) {
                    continue;
                }

                $variableField = isset($entry['variableField']) && is_scalar($entry['variableField']) ? (string) $entry['variableField'] : null;
                $nodeField = isset($entry['nodeField']) && is_scalar($entry['nodeField']) ? (string) $entry['nodeField'] : null;
                $targetField = $variableField;
                if ( null === $targetField && null !== $nodeField ) {
                    $targetField = 'nodeField:' . $nodeField;
                }
                if ( null === $targetField || '' === $targetField ) {
                    continue;
                }

                $variableData = is_array($entry['variableData'] ?? null) ? $entry['variableData'] : array();
                $binding = array(
                    'source'             => $sourceKey,
                    'target_field'       => $targetField,
                    'variable_field'     => $variableField,
                    'node_field'         => $nodeField,
                    'target_role'        => $this->classifyVariableBindingTarget($targetField),
                    'data_type'          => isset($variableData['dataType']) && is_scalar($variableData['dataType']) ? (string) $variableData['dataType'] : null,
                    'resolved_data_type' => isset($variableData['resolvedDataType']) && is_scalar($variableData['resolvedDataType']) ? (string) $variableData['resolvedDataType'] : null,
                );

                $value = is_array($variableData['value'] ?? null) ? $variableData['value'] : array();
                $valueMetadata = $this->normalizeVariableAnyValueMetadata($value);
                $binding = array_merge($binding, $valueMetadata);
                $directValue = $this->normalizeVariableAnyValue($value);
                if ( null !== $directValue ) {
                    $binding['value'] = $directValue;
                }

                $bindings[] = array_filter($binding, static fn (mixed $value): bool => null !== $value);
                $role = (string) $binding['target_role'];
                $byRole[$role] = ($byRole[$role] ?? 0) + 1;
            }
        }

        foreach ( $this->normalizeBoundVariableBindings($node) as $binding ) {
            $bindings[] = $binding;
            $role = (string) ($binding['target_role'] ?? 'unknown');
            $byRole[$role] = ($byRole[$role] ?? 0) + 1;
        }

        if ( ! empty($bindings) ) {
            arsort($byRole);
            $normalized['bindings'] = $bindings;
            $normalized['summary'] = array(
                'binding_count' => count($bindings),
                'by_role'       => $byRole,
            );
        }

        if ( isset($node['variableResolvedType']) && is_scalar($node['variableResolvedType']) ) {
            $normalized['resolved_type'] = (string) $node['variableResolvedType'];
        }

        if ( is_array($node['variableSetID'] ?? null) ) {
            $setId = $this->readGuidId($node['variableSetID']['guid'] ?? null);
            if ( null !== $setId ) {
                $normalized['variable_set_id'] = $setId;
            }
        }

        if ( is_array($node['variableScopes'] ?? null) ) {
            $scopes = array_values(array_filter($node['variableScopes'], 'is_scalar'));
            if ( ! empty($scopes) ) {
                $normalized['scopes'] = array_map('strval', $scopes);
            }
        }

        if ( is_array($node['variableSetModes'] ?? null) ) {
            $modes = array();
            foreach ( $node['variableSetModes'] as $mode ) {
                if ( ! is_array($mode) ) {
                    continue;
                }
                $modeId = $this->readGuidId($mode['id'] ?? null);
                if ( null === $modeId ) {
                    continue;
                }
                $normalizedMode = array('id' => $modeId);
                foreach ( array('name', 'sortPosition') as $key ) {
                    if ( isset($mode[$key]) && is_scalar($mode[$key]) ) {
                        $normalizedMode[$key] = (string) $mode[$key];
                    }
                }
                foreach ( array('parentVariableSetId' => 'parent_variable_set_id', 'parentModeId' => 'parent_mode_id') as $sourceKey => $targetKey ) {
                    $parentSource = $mode[$sourceKey] ?? null;
                    $parentId = $this->readGuidId(is_array($parentSource) && array_key_exists('guid', $parentSource) ? $parentSource['guid'] : $parentSource);
                    if ( null !== $parentId ) {
                        $normalizedMode[$targetKey] = $parentId;
                    }
                }
                $modes[] = $normalizedMode;
            }
            if ( ! empty($modes) ) {
                $normalized['modes'] = $modes;
            }
        }

        if ( is_array($node['variableDataValues']['entries'] ?? null) ) {
            $values = array();
            foreach ( $node['variableDataValues']['entries'] as $entry ) {
                if ( ! is_array($entry) ) {
                    continue;
                }
                $modeId = $this->readGuidId($entry['modeID'] ?? null);
                $variableData = is_array($entry['variableData'] ?? null) ? $entry['variableData'] : array();
                $rawValue = is_array($variableData['value'] ?? null) ? $variableData['value'] : array();
                $value = $this->normalizeVariableAnyValue($rawValue);
                $valueMetadata = $this->normalizeVariableAnyValueMetadata($rawValue);
                if ( null === $modeId && null === $value ) {
                    continue;
                }
                $values[] = array_filter(array_merge(array(
                    'mode_id'            => $modeId,
                    'value'              => $value,
                    'data_type'          => isset($variableData['dataType']) && is_scalar($variableData['dataType']) ? (string) $variableData['dataType'] : null,
                    'resolved_data_type' => isset($variableData['resolvedDataType']) && is_scalar($variableData['resolvedDataType']) ? (string) $variableData['resolvedDataType'] : null,
                ), $valueMetadata), static fn (mixed $value): bool => null !== $value);
            }
            if ( ! empty($values) ) {
                $normalized['values'] = $values;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBoundVariableBindings(array $node): array
    {
        if ( ! is_array($node['boundVariables'] ?? null) ) {
            return array();
        }

        $bindings = array();
        foreach ( $node['boundVariables'] as $targetField => $rawBinding ) {
            if ( ! is_string($targetField) || '' === $targetField ) {
                continue;
            }

            $items = array_is_list($rawBinding) ? $rawBinding : array($rawBinding);
            foreach ( $items as $item ) {
                $variableId = $this->readBoundVariableId($item);
                if ( null === $variableId ) {
                    continue;
                }

                $normalizedTarget = $this->normalizeBoundVariableTargetField($targetField);
                $bindings[] = array_filter(array(
                    'source'              => 'boundVariables',
                    'target_field'        => $normalizedTarget,
                    'target_role'         => $this->classifyVariableBindingTarget($normalizedTarget),
                    'value_type'          => 'alias',
                    'variable_id'         => $variableId,
                    'css_custom_property' => $this->cssCustomPropertyName($variableId),
                ), static fn (mixed $value): bool => null !== $value);
            }
        }

        return $bindings;
    }

    private function readBoundVariableId(mixed $binding): ?string
    {
        if ( is_scalar($binding) ) {
            return '' !== (string) $binding ? (string) $binding : null;
        }

        if ( ! is_array($binding) ) {
            return null;
        }

        foreach ( array('id', 'variableId', 'variableID', 'key') as $key ) {
            if ( isset($binding[$key]) && is_scalar($binding[$key]) && '' !== (string) $binding[$key] ) {
                return (string) $binding[$key];
            }
        }

        foreach ( array('guid', 'alias') as $key ) {
            $id = $this->readGuidId($binding[$key] ?? null);
            if ( null !== $id ) {
                return $id;
            }
        }

        return null;
    }

    private function normalizeBoundVariableTargetField(string $targetField): string
    {
        $normalized = preg_replace('/(?<!^)[A-Z]/', '_$0', $targetField) ?? $targetField;
        return strtoupper($normalized);
    }

    private function cssCustomPropertyName(string $variableId): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $variableId) ?? $variableId);
        $slug = trim($slug, '-');
        return '--figma-var-' . ('' !== $slug ? $slug : 'unknown');
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeDocumentMetadata(array $node): array
    {
        $metadata = array();

        foreach ( array('phase', 'autoRename', 'version', 'userFacingVersion', 'fileVersion', 'publishID', 'publishId', 'sourceLibraryKey', 'ancestorPathBeforeDeletion', 'ackID', 'ackId') as $key ) {
            if ( array_key_exists($key, $node) && is_scalar($node[$key]) ) {
                $metadata[$this->normalizeMetadataKey($key)] = $node[$key];
            }
        }

        foreach ( array('isPublishable', 'locked', 'isSoftDeleted', 'internalOnly', 'isPageDivider') as $key ) {
            if ( array_key_exists($key, $node) && is_bool($node[$key]) ) {
                $metadata[$this->normalizeMetadataKey($key)] = $node[$key];
            }
        }

        foreach ( array('originFileKey', 'fileKey', 'sessionID', 'sessionId') as $key ) {
            if ( ! array_key_exists($key, $node) ) {
                continue;
            }
            $value = $this->readGuidId($node[$key]);
            if ( null !== $value ) {
                $metadata[$this->normalizeMetadataKey($key)] = $value;
            }
        }

        foreach ( array('editInfo', 'pluginData', 'annotations', 'annotationCategories', 'categories', 'pluginRelaunchData', 'slideThemeMap') as $key ) {
            if ( ! array_key_exists($key, $node) ) {
                continue;
            }
            $value = $this->normalizeMetadataValue($node[$key]);
            if ( null !== $value && array() !== $value ) {
                $metadata[$this->normalizeMetadataKey($key)] = $value;
            }
        }

        return $metadata;
    }

    private function normalizeMetadataKey(string $key): string
    {
        return match ($key) {
            'autoRename' => 'auto_rename',
            'userFacingVersion' => 'user_facing_version',
            'fileVersion' => 'file_version',
            'publishID', 'publishId' => 'publish_id',
            'sourceLibraryKey' => 'source_library_key',
            'ancestorPathBeforeDeletion' => 'ancestor_path_before_deletion',
            'isPublishable' => 'is_publishable',
            'isSoftDeleted' => 'is_soft_deleted',
            'internalOnly' => 'internal_only',
            'isPageDivider' => 'is_page_divider',
            'originFileKey' => 'origin_file_key',
            'fileKey' => 'file_key',
            'sessionID', 'sessionId' => 'session_id',
            'ackID', 'ackId' => 'ack_id',
            'userID', 'userId' => 'user_id',
            'pluginID', 'pluginId' => 'plugin_id',
            'categoryID', 'categoryId' => 'category_id',
            'editInfo' => 'edit_info',
            'pluginData' => 'plugin_data',
            'annotationCategories' => 'annotation_categories',
            'pluginRelaunchData' => 'plugin_relaunch_data',
            'slideThemeMap' => 'slide_theme_map',
            default => $key,
        };
    }

    private function normalizeMetadataValue(mixed $value): mixed
    {
        if ( is_scalar($value) || null === $value ) {
            return $value;
        }

        if ( ! is_array($value) ) {
            return null;
        }

        $normalized = array();
        foreach ( $value as $key => $item ) {
            $normalizedItem = $this->normalizeMetadataValue($item);
            if ( null === $normalizedItem ) {
                continue;
            }
            $normalized[is_int($key) ? $key : $this->normalizeMetadataKey((string) $key)] = $normalizedItem;
        }

        return $normalized;
    }

    private function classifyVariableBindingTarget(string $targetField): string
    {
        return match ($targetField) {
            'TEXT_DATA', 'CHARACTERS', 'FONT_FAMILY', 'FONT_STYLE', 'FONT_VARIATIONS', 'FONT_SIZE', 'LETTER_SPACING', 'LINE_HEIGHT', 'PARAGRAPH_SPACING', 'PARAGRAPH_INDENT' => 'text',
            'STACK_SPACING', 'STACK_COUNTER_SPACING', 'STACK_PADDING_LEFT', 'STACK_PADDING_TOP', 'STACK_PADDING_RIGHT', 'STACK_PADDING_BOTTOM', 'ITEM_SPACING', 'COUNTER_AXIS_SPACING', 'PADDING_LEFT', 'PADDING_TOP', 'PADDING_RIGHT', 'PADDING_BOTTOM', 'PADDING_HORIZONTAL', 'PADDING_VERTICAL', 'WIDTH', 'HEIGHT', 'MIN_WIDTH', 'MAX_WIDTH', 'MIN_HEIGHT', 'MAX_HEIGHT', 'X_POSITION', 'Y_POSITION', 'ROTATION' => 'layout',
            'CORNER_RADIUS', 'RECTANGLE_TOP_LEFT_CORNER_RADIUS', 'RECTANGLE_TOP_RIGHT_CORNER_RADIUS', 'RECTANGLE_BOTTOM_LEFT_CORNER_RADIUS', 'RECTANGLE_BOTTOM_RIGHT_CORNER_RADIUS' => 'geometry',
            'VISIBLE' => 'visibility',
            'OPACITY', 'ALL_FILLS', 'FILLS', 'FRAME_FILL', 'SHAPE_FILL', 'TEXT_FILL', 'STROKES', 'STROKE', 'STROKE_FLOAT', 'COLOR_OPACITY' => 'paint',
            'VARIANT_PROPERTIES', 'OVERRIDDEN_SYMBOL_ID', 'SLOT_CONTENT_ID' => 'component',
            'HYPERLINK' => 'link',
            default => 'unknown',
        };
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<string, mixed>
     */
    private function buildVariableBindingSummary(array $nodeMap): array
    {
        $summary = array(
            'schema'                      => 'blocks-engine/figma-transformer/variable-bindings/v1',
            'node_count'                  => 0,
            'binding_count'               => 0,
            'value_count'                 => 0,
            'variable_definition_count'   => 0,
            'variable_set_count'          => 0,
            'by_source'                   => array(),
            'by_role'                     => array(),
            'by_value_type'               => array(),
            'by_resolved_data_type'       => array(),
            'sample_nodes'                => array(),
            'sample_limit'                => 10,
            'sample_nodes_truncated'      => false,
        );

        foreach ( $nodeMap as $nodeId => $node ) {
            $variableBindings = is_array($node['figma_variable_bindings'] ?? null) ? $node['figma_variable_bindings'] : array();
            if ( empty($variableBindings) ) {
                continue;
            }

            $summary['node_count']++;
            $nodeSample = array(
                'node_id' => (string) $nodeId,
                'type'    => isset($node['type']) && is_scalar($node['type']) ? (string) $node['type'] : null,
                'name'    => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : null,
            );

            if ( is_array($variableBindings['bindings'] ?? null) ) {
                foreach ( $variableBindings['bindings'] as $binding ) {
                    if ( ! is_array($binding) ) {
                        continue;
                    }
                    $summary['binding_count']++;
                    foreach ( array('source' => 'by_source', 'target_role' => 'by_role', 'value_type' => 'by_value_type', 'resolved_data_type' => 'by_resolved_data_type') as $sourceKey => $bucketKey ) {
                        if ( isset($binding[$sourceKey]) && is_scalar($binding[$sourceKey]) ) {
                            $bucket = (string) $binding[$sourceKey];
                            $summary[$bucketKey][$bucket] = ($summary[$bucketKey][$bucket] ?? 0) + 1;
                        }
                    }
                }
                $nodeSample['binding_count'] = count($variableBindings['bindings']);
            }

            if ( is_array($variableBindings['values'] ?? null) ) {
                $summary['value_count'] += count($variableBindings['values']);
                $summary['variable_definition_count']++;
                foreach ( $variableBindings['values'] as $value ) {
                    if ( ! is_array($value) ) {
                        continue;
                    }
                    foreach ( array('value_type' => 'by_value_type', 'resolved_data_type' => 'by_resolved_data_type') as $sourceKey => $bucketKey ) {
                        if ( isset($value[$sourceKey]) && is_scalar($value[$sourceKey]) ) {
                            $bucket = (string) $value[$sourceKey];
                            $summary[$bucketKey][$bucket] = ($summary[$bucketKey][$bucket] ?? 0) + 1;
                        }
                    }
                }
                $nodeSample['value_count'] = count($variableBindings['values']);
            }

            if ( is_array($variableBindings['modes'] ?? null) ) {
                $summary['variable_set_count']++;
                $nodeSample['mode_count'] = count($variableBindings['modes']);
            }

            if ( count($summary['sample_nodes']) < $summary['sample_limit'] ) {
                $summary['sample_nodes'][] = array_filter($nodeSample, static fn (mixed $value): bool => null !== $value);
            } else {
                $summary['sample_nodes_truncated'] = true;
            }
        }

        foreach ( array('by_source', 'by_role', 'by_value_type', 'by_resolved_data_type') as $bucketKey ) {
            arsort($summary[$bucketKey]);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function normalizeVariableAnyValue(array $value): mixed
    {
        foreach ( array('boolValue', 'textValue', 'floatValue') as $key ) {
            if ( array_key_exists($key, $value) && is_scalar($value[$key]) ) {
                return $value[$key];
            }
        }

        if ( is_array($value['colorValue'] ?? null) ) {
            return $value['colorValue'];
        }
        if ( is_array($value['textDataValue'] ?? null) ) {
            return $value['textDataValue'];
        }
        if ( is_array($value['symbolIdValue']['guid'] ?? null) ) {
            return $this->readGuidId($value['symbolIdValue']['guid']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function normalizeVariableAnyValueMetadata(array $value): array
    {
        foreach ( array('boolValue' => 'bool', 'textValue' => 'text', 'floatValue' => 'float') as $key => $type ) {
            if ( array_key_exists($key, $value) && is_scalar($value[$key]) ) {
                return array('value_type' => $type);
            }
        }

        if ( is_array($value['alias'] ?? null) ) {
            $variableId = $this->readGuidId($value['alias']['guid'] ?? null);
            return array_filter(array(
                'value_type'  => 'alias',
                'variable_id' => $variableId,
            ), static fn (mixed $value): bool => null !== $value);
        }

        if ( is_array($value['colorValue'] ?? null) ) {
            return array('value_type' => 'color');
        }
        if ( is_array($value['symbolIdValue']['guid'] ?? null) ) {
            return array(
                'value_type' => 'symbol_id',
                'symbol_id'  => $this->readGuidId($value['symbolIdValue']['guid']),
            );
        }
        if ( is_array($value['textDataValue'] ?? null) ) {
            return array('value_type' => 'text_data');
        }
        if ( is_array($value['vectorValue'] ?? null) ) {
            return array('value_type' => 'vector');
        }
        if ( is_array($value['linkValue'] ?? null) ) {
            return array('value_type' => 'link');
        }
        if ( is_array($value['propRefValue'] ?? null) ) {
            $propRefId = $this->readGuidId($value['propRefValue']['defId'] ?? null);
            return array_filter(array(
                'value_type'  => 'prop_ref',
                'prop_ref_id' => $propRefId,
            ), static fn (mixed $value): bool => null !== $value);
        }

        if ( ! empty($value) ) {
            return array(
                'value_type' => 'unresolved',
                'value_keys' => array_values(array_filter(array_keys($value), 'is_string')),
            );
        }

        return array();
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     */
    private function applyReverseChildZIndex(array &$nodeMap): void
    {
        foreach ( $nodeMap as $node ) {
            if ( true !== ($node['layout']['reverse_z_index'] ?? false) || ! is_array($node['children'] ?? null) ) {
                continue;
            }

            $layoutDisplay = is_scalar($node['layout']['display'] ?? null) ? (string) $node['layout']['display'] : '';
            if ( ! in_array($layoutDisplay, array('flex', 'inline-flex'), true) ) {
                continue;
            }

            $children = array_values(array_filter($node['children'], 'is_array'));
            $childCount = count($children);
            foreach ( $children as $index => $child ) {
                $childId = isset($child['id']) && is_scalar($child['id']) ? (string) $child['id'] : '';
                if ( '' === $childId || ! isset($nodeMap[$childId]) ) {
                    continue;
                }

                $layout = is_array($nodeMap[$childId]['layout'] ?? null) ? $nodeMap[$childId]['layout'] : array();
                $layout['z_index'] = $childCount - (int) $index;
                $layout['z_index_source'] = 'reverse_child_order';
                $nodeMap[$childId]['layout'] = $layout;
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeComponentMetadata(array $node, string $type): array
    {
        $metadata = array();

        if ( in_array($type, array('COMPONENT', 'COMPONENT_SET', 'SYMBOL'), true) ) {
            $metadata['role'] = 'definition';
            $metadata['definition_id'] = (string) ($node['id'] ?? '');
        } elseif ( 'INSTANCE' === $type ) {
            $metadata['role'] = 'instance';
            $metadata['instance_id'] = (string) ($node['id'] ?? '');
            $reference = $this->readComponentReference($node);
            if ( null !== $reference ) {
                $metadata['component_id'] = $reference['id'];
                $metadata['component_source_key'] = $reference['source_key'];
            }
        }

        if ( is_array($node['componentProperties'] ?? null) ) {
            $metadata['component_properties'] = $node['componentProperties'];
        }

        if ( is_array($node['componentPropDefs'] ?? null) ) {
            $metadata['component_prop_defs'] = $node['componentPropDefs'];
        }

        if ( is_array($node['componentPropRefs'] ?? null) ) {
            $metadata['component_prop_refs'] = $node['componentPropRefs'];
        }

        $overrideKey = $this->readGuidId($node['overrideKey'] ?? null);
        if ( null !== $overrideKey ) {
            $metadata['override_key'] = $overrideKey;
        }

        if ( array_key_exists('proportionsConstrained', $node) && is_bool($node['proportionsConstrained']) ) {
            $metadata['proportions_constrained'] = $node['proportionsConstrained'];
        }

        if ( is_array($node['targetAspectRatio'] ?? null) ) {
            $metadata['target_aspect_ratio'] = $this->normalizeMetadataValue($node['targetAspectRatio']);
        }

        if ( array_key_exists('derivedSymbolDataLayoutVersion', $node) && is_scalar($node['derivedSymbolDataLayoutVersion']) ) {
            $metadata['derived_symbol_data_layout_version'] = $node['derivedSymbolDataLayoutVersion'];
        }

        if ( true === ($node['isStateGroup'] ?? false) ) {
            $metadata['state_group'] = true;
        }

        if ( is_array($node['stateGroupPropertyValueOrders'] ?? null) ) {
            $orders = $this->normalizeStateGroupPropertyValueOrders($node['stateGroupPropertyValueOrders']);
            if ( ! empty($orders) ) {
                $metadata['state_group_property_value_orders'] = $orders;
            }
        }

        if ( is_array($node['variantPropSpecs'] ?? null) ) {
            $variantSpecs = $this->normalizeVariantPropSpecs($node['variantPropSpecs']);
            if ( ! empty($variantSpecs) ) {
                $metadata['variant_prop_specs'] = $variantSpecs;
            }
        }

        if ( is_array($node['overrides'] ?? null) ) {
            $metadata['overrides'] = $node['overrides'];
        }

        return $metadata;
    }

    /**
     * @param array<int, mixed> $orders
     * @return array<int, array{property: string, values: array<int, string>}>
     */
    private function normalizeStateGroupPropertyValueOrders(array $orders): array
    {
        $normalized = array();

        foreach ( $orders as $order ) {
            if ( ! is_array($order) || ! isset($order['property']) || ! is_scalar($order['property']) ) {
                continue;
            }

            $values = array();
            foreach ( is_array($order['values'] ?? null) ? $order['values'] : array() as $value ) {
                if ( is_scalar($value) ) {
                    $values[] = (string) $value;
                }
            }

            $normalized[] = array(
                'property' => (string) $order['property'],
                'values'   => $values,
            );
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $specs
     * @return array<int, array{prop_def_id: string, value: string}>
     */
    private function normalizeVariantPropSpecs(array $specs): array
    {
        $normalized = array();

        foreach ( $specs as $spec ) {
            if ( ! is_array($spec) || ! isset($spec['value']) || ! is_scalar($spec['value']) ) {
                continue;
            }

            $propDefId = $this->readGuidId($spec['propDefId'] ?? null);
            if ( null === $propDefId ) {
                continue;
            }

            $normalized[] = array(
                'prop_def_id' => $propDefId,
                'value'       => (string) $spec['value'],
            );
        }

        return $normalized;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<string, array<string, mixed>>
     */
    private function buildComponentDefinitions(array $nodeMap): array
    {
        $components = array();

        foreach ( $nodeMap as $id => $node ) {
            if ( ! in_array(strtoupper((string) ($node['type'] ?? '')), array('COMPONENT', 'COMPONENT_SET', 'SYMBOL'), true) ) {
                continue;
            }

            foreach ( array_unique(array_filter(array($id, $this->readString($node, array('componentId', 'component_id', 'key'))))) as $componentId ) {
                $components[(string) $componentId] = $node;
            }
        }

        return $components;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     */
    private function countComponentDefinitions(array $nodeMap): int
    {
        $count = 0;

        foreach ( $nodeMap as $node ) {
            if ( in_array(strtoupper((string) ($node['type'] ?? '')), array('COMPONENT', 'COMPONENT_SET', 'SYMBOL'), true) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<string, array<int, array<string, mixed>>>>
     */
    private function buildPaintStyleDefinitions(array $nodeMap, array &$diagnostics): array
    {
        $styles = array();
        foreach ( $nodeMap as $id => $node ) {
            if ( 'FILL' !== strtoupper((string) ($node['styleType'] ?? '')) ) {
                continue;
            }

            $paints = $this->paintNormalizer->normalizePaintList(is_array($node['fillPaints'] ?? null) ? $node['fillPaints'] : (is_array($node['fills'] ?? null) ? $node['fills'] : array()), $id, 'style.fillPaints', $diagnostics);
            if ( ! empty($paints) ) {
                $styles[$id]['fills'] = $paints;
            }
        }

        return $styles;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<string, array<string, mixed>>
     */
    private function buildTextStyleDefinitions(array $nodeMap): array
    {
        $styles = array();
        foreach ( $nodeMap as $id => $node ) {
            if ( 'TEXT' !== strtoupper((string) ($node['styleType'] ?? '')) || 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
                continue;
            }

            $styles[$id] = $node;
        }

        return $styles;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, array<string, mixed>> $components
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array{instance_node_count: int, resolved_instance_count: int, unresolved_component_references: array<int, array<string, string>>}
     */
    private function resolveInstances(array &$nodeMap, array $components, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array(), array $options = array()): array
    {
        $instanceCount = 0;
        $resolvedCount = 0;
        $unresolved = array();

        foreach ( $nodeMap as $id => $node ) {
            if ( 'INSTANCE' !== strtoupper((string) ($node['type'] ?? '')) ) {
                continue;
            }

            $instanceCount++;
            $reference = $this->readComponentReference($node);
            if ( null === $reference || ! isset($components[$reference['id']]) ) {
                $unresolved[] = array('instance_id' => $id, 'component_id' => $reference['id'] ?? '');
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'figma_instance_component_unresolved',
                    'message'  => 'Figma instance references a component definition that is not present in the same source graph.',
                    'context'  => array(
                        'instance_id'  => $id,
                        'component_id' => $reference['id'] ?? null,
                    ),
                );
                continue;
            }

            if ( ! empty($node['children']) ) {
                $unresolved[] = array('instance_id' => $id, 'component_id' => $reference['id']);
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'figma_instance_resolution_skipped',
                    'message'  => 'Figma instance resolution was skipped because the source instance already contains children.',
                    'context'  => array('instance_id' => $id, 'component_id' => $reference['id']),
                );
                continue;
            }

            $overrides = $this->normalizeInstanceOverrides($node, $id, $diagnostics);
            if ( null === $overrides ) {
                $unresolved[] = array('instance_id' => $id, 'component_id' => $reference['id']);
                continue;
            }

            $resolved = $this->cloneComponentForInstance($components[$reference['id']], $node, $reference['id'], $overrides, $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, $options, array($id));
            $nodeMap[$id] = $resolved;
            $resolvedCount++;
        }

        return array(
            'instance_node_count' => $instanceCount,
            'resolved_instance_count' => $resolvedCount,
            'unresolved_component_references' => $unresolved,
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<int, string> $trail
     * @return array<string, mixed>
     */
    private function refreshResolvedTree(array $node, array $nodeMap, array $trail = array()): array
    {
        $id = (string) ($node['id'] ?? '');
        $sourceId = (string) ($node['figma_component_source_id'] ?? '');
        $refreshId = '' !== $id && isset($nodeMap[$id]) ? $id : $sourceId;
        $refreshesComponentSourceClone = '' !== $sourceId && $refreshId === $sourceId && $this->componentSourceCloneGeometry->isRefreshable($node, $nodeMap[$refreshId] ?? array());
        if ( '' !== $refreshId && isset($nodeMap[$refreshId]) && ! in_array($refreshId, $trail, true) && ($refreshId === $id || ($refreshesComponentSourceClone && ! $this->componentSourceCloneGeometry->subtreeHasInstanceOverrideApplied($node))) ) {
            $node = $refreshId === $id
                ? $nodeMap[$refreshId]
                : $this->componentSourceCloneGeometry->mergeRefreshed($node, $nodeMap[$refreshId], $refreshId);
            $trail[] = $refreshId;
        }

        if ( ! is_array($node['children'] ?? null) ) {
            return $node;
        }

		foreach ( $node['children'] as $index => $child ) {
			if ( ! is_array($child) ) {
				continue;
			}

			$node['children'][$index] = $this->refreshResolvedTree($child, $nodeMap, $trail);
		}

		return $this->componentSourceCloneGeometry->repairFarGeometry($node, $nodeMap);
	}

    /**
     * Treat an explicitly selected page frame as the emitted document origin instead of preserving Figma canvas offsets.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebasePageFrameToLocalOrigin(array $node): array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $originX = isset($box['x']) && is_numeric($box['x']) ? (float) $box['x'] : 0.0;
        $originY = isset($box['y']) && is_numeric($box['y']) ? (float) $box['y'] : 0.0;
        $node['_selected_frame_root'] = true;

        return $this->rebaseCanvasCoordinateBoxesToPageLocal($node, $originX, $originY, true);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, string>   $fallbackFrameIds
     * @param array<string, mixed> $nodeMap
     * @return array<int, string>
     */
    private function documentFrameIds(array $options, array $fallbackFrameIds, array $nodeMap): array
    {
        $rawFrameIds = is_array($options['document_frame_ids'] ?? null) ? $options['document_frame_ids'] : $fallbackFrameIds;
        $frameIds = array();
        foreach ( $rawFrameIds as $id ) {
            if ( ! is_scalar($id) ) {
                continue;
            }

            $frameId = (string) $id;
            if ( '' !== $frameId && isset($nodeMap[$frameId]) ) {
                $frameIds[$frameId] = $frameId;
            }
        }

        return array_values($frameIds);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $nodeMap
     */
    private function appendNodeMap(array $node, array &$nodeMap): void
    {
        $id = (string) ($node['id'] ?? '');
        if ( '' !== $id ) {
            $nodeMap[$id] = $node;
        }

        foreach ( array('children', 'nodes') as $childrenKey ) {
            if ( ! is_array($node[$childrenKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$childrenKey] as $child ) {
                if ( is_array($child) ) {
                    $this->appendNodeMap($child, $nodeMap);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebaseCanvasCoordinateBoxesToPageLocal(array $node, float $originX, float $originY, bool $isRoot = false): array
    {
        $node = $this->rebaseCanvasCoordinateBoxToPageLocal($node, 'box', $originX, $originY, $isRoot);
        $node = $this->rebaseCanvasCoordinateBoxToPageLocal($node, 'figma_box', $originX, $originY, $isRoot);

        foreach ( array('children', 'nodes') as $childrenKey ) {
            if ( ! is_array($node[$childrenKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$childrenKey] as $index => $child ) {
                if ( is_array($child) ) {
                    $node[$childrenKey][$index] = $this->rebaseCanvasCoordinateBoxesToPageLocal($child, $originX, $originY);
                }
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function rebaseCanvasCoordinateBoxToPageLocal(array $node, string $boxKey, float $originX, float $originY, bool $isRoot): array
    {
        if ( ! is_array($node[$boxKey] ?? null) ) {
            return $node;
        }

        $box = $node[$boxKey];
        $componentCloneDecision = $this->componentSourceClonePageLocalDecision($node, $box, $isRoot);
        if ( 'restore-source-box' === $componentCloneDecision['action'] ) {
            $sourceBox = $node['_component_source_clone_source_box'];
            foreach ( array('x', 'y') as $dimension ) {
                if ( isset($sourceBox[$dimension]) && is_numeric($sourceBox[$dimension]) ) {
                    $box[$dimension] = (float) $sourceBox[$dimension];
                }
            }
            $box = GeometryBox::withoutProvenance($box);
            $box['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;
            $node[$boxKey] = $box;
            return $node;
        }
        if ( 'preserve-parent-local' === $componentCloneDecision['action'] ) {
            $box = GeometryBox::withoutProvenance($box);
            $box['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;
            $node[$boxKey] = $box;
            return $node;
        }

        $coordinateSpace = GeometryBox::coordinateSpace($box);
        if ( $isRoot || GeometryBox::COORDINATE_SPACE_CANVAS_ABSOLUTE === $coordinateSpace ) {
            if ( isset($box['x']) && is_numeric($box['x']) ) {
                $box['x'] = $isRoot ? 0.0 : (float) $box['x'] - $originX;
            }
            if ( isset($box['y']) && is_numeric($box['y']) ) {
                $box['y'] = $isRoot ? 0.0 : (float) $box['y'] - $originY;
            }
            $box = GeometryBox::withProvenance($box, GeometryBox::SOURCE_EXPLICIT_LOCAL, $isRoot);
            $box = GeometryBox::withoutProvenance($box);
            $box['local_origin'] = 'page';
        }

        $node[$boxKey] = $box;
        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $box
     * @return array{action: string, reason: string}
     */
    private function componentSourceClonePageLocalDecision(array $node, array $box, bool $isRoot): array
    {
        if ( $isRoot ) {
            return array('action' => 'normal-rebase', 'reason' => 'root-node');
        }

        if ( $this->isComponentSourceCloneTransformDescendant($node, $box) ) {
            return array('action' => 'restore-source-box', 'reason' => 'component-clone-transform-source-box');
        }

        if ( $this->isComponentSourceCloneDescendant($node, $box) ) {
            return array('action' => 'preserve-parent-local', 'reason' => 'component-clone-parent-local');
        }

        return array('action' => 'normal-rebase', 'reason' => 'not-component-clone');
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $box
     */
    private function isComponentSourceCloneDescendant(array $node, array $box): bool
    {
        $sourceKind = isset($box['component_clone_source_kind']) && is_scalar($box['component_clone_source_kind']) ? (string) $box['component_clone_source_kind'] : null;
        if ( in_array($sourceKind, array(GeometryBox::SOURCE_TRANSFORM, GeometryBox::SOURCE_ABSOLUTE_TRANSFORM, GeometryBox::SOURCE_OVERRIDE_TRANSFORM), true) ) {
            return false;
        }

        if ( ! empty($node['_component_source_clone_geometry']) || self::GEOMETRY_SEMANTICS_COMPONENT_SOURCE_CLONE === ($box['geometry_semantics'] ?? null) ) {
            return true;
        }

        $id = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
        $sourceId = isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) ? (string) $node['figma_component_source_id'] : '';
        if ( '' === $id || '' === $sourceId || $id === $sourceId || ! str_contains($id, '/') ) {
            return false;
        }

        $x = isset($box['x']) && is_numeric($box['x']) ? abs((float) $box['x']) : 0.0;
        $y = isset($box['y']) && is_numeric($box['y']) ? abs((float) $box['y']) : 0.0;
        return $x < 1000.0 && $y < 1000.0;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $box
     */
    private function isComponentSourceCloneTransformDescendant(array $node, array $box): bool
    {
        $sourceKind = isset($box['component_clone_source_kind']) && is_scalar($box['component_clone_source_kind']) ? (string) $box['component_clone_source_kind'] : null;
        if ( ! in_array($sourceKind, array(GeometryBox::SOURCE_TRANSFORM, GeometryBox::SOURCE_ABSOLUTE_TRANSFORM, GeometryBox::SOURCE_OVERRIDE_TRANSFORM), true) ) {
            return false;
        }

        if ( empty($node['_component_source_clone_geometry']) && self::GEOMETRY_SEMANTICS_COMPONENT_SOURCE_CLONE !== ($box['geometry_semantics'] ?? null) ) {
            return false;
        }

        $sourceBox = is_array($node['_component_source_clone_source_box'] ?? null) ? $node['_component_source_clone_source_box'] : array();
        return GeometryBox::COORDINATE_SPACE_PARENT_LOCAL === GeometryBox::coordinateSpace($sourceBox)
            && (isset($sourceBox['x']) || isset($sourceBox['y']));
    }

    /**
     * @param array<string, mixed> $node
     * @return array{id: string, source_key: string}|null
     */
    private function readComponentReference(array $node): ?array
    {
        foreach ( array('componentId', 'component_id', 'mainComponentId', 'main_component_id') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return array('id' => (string) $node[$key], 'source_key' => $key);
            }
        }

        foreach ( array('mainComponent', 'component') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $id = $this->readString($node[$key], array('id', 'key', 'componentId', 'node_id', 'nodeId'));
                if ( null !== $id && '' !== $id ) {
                    return array('id' => $id, 'source_key' => $key);
                }
            } elseif ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return array('id' => (string) $node[$key], 'source_key' => $key);
            }
        }

        foreach ( array('symbolData', 'derivedSymbolData') as $key ) {
            $symbolId = $this->readGuidId($node[$key]['symbolID'] ?? null);
            if ( null !== $symbolId ) {
                return array('id' => $symbolId, 'source_key' => $key . '.symbolID');
            }
        }

        return null;
    }

    private function readGuidId(mixed $guid): ?string
    {
        if ( is_array($guid) && isset($guid['guid']) ) {
            return $this->readGuidId($guid['guid']);
        }

        if ( is_array($guid) && isset($guid['sessionID'], $guid['localID']) ) {
            return (string) $guid['sessionID'] . ':' . (string) $guid['localID'];
        }

        if ( is_scalar($guid) && '' !== (string) $guid ) {
            return (string) $guid;
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
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return (string) $node[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<string, mixed>>|null
     */
    private function normalizeInstanceOverrides(array $node, string $instanceId, array &$diagnostics): ?array
    {
        return $this->instanceResolver->normalizeInstanceOverrides($node, $instanceId, $diagnostics);
    }

    /**
     * @param array<string, mixed> $component
     * @param array<string, mixed> $instance
     * @param array<string, array<string, mixed>> $overrides
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<string, mixed>
     */
    private function cloneComponentForInstance(array $component, array $instance, string $componentId, array $overrides, array $nodeMap, array $components, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array(), array $options = array(), array $resolutionTrail = array()): array
    {
        $context = $this->buildInstanceCloneContext($component, $instance, $componentId, $overrides);
        $resolved = $component;
        $resolved['id'] = $context['instance_id'];
        $resolved['type'] = 'INSTANCE';
        $resolved['name'] = (string) ($instance['name'] ?? $resolved['name'] ?? '');
        // The resolved node stands in for the instance placement, so its
        // visibility is governed by the instance, not the component definition.
        // Without this, a designer-hidden (visible:false) instance would inherit
        // the definition's visibility and incorrectly emit to HTML.
        $resolved['visible'] = $instance['visible'] ?? true;

        foreach ( array('box', 'figma_box', 'layout', 'figma_paints', 'figma_effects', 'figma_link', 'figma_vector_paths', 'figma_variable_bindings', 'componentProperties', 'fillPaints', 'effects', 'styleIdForFill', 'styleIdForStrokeFill', 'styleIdForStroke', 'styleIdForEffect', 'fillGeometry', 'strokeGeometry', 'vectorPaths', 'paths', 'pathData', 'path', 'd', 'strokeWeight', 'strokeAlign', 'dashPattern', 'borderStrokeWeightsIndependent', 'borderTopWeight', 'borderBottomWeight', 'borderLeftWeight', 'borderRightWeight', 'variableConsumptionMap', 'parameterConsumptionMap', 'variableDataValues', 'variableResolvedType', 'variableSetID', 'variableScopes', 'variableSetModes') as $key ) {
            if ( array_key_exists($key, $instance) ) {
                $resolved[$key] = $instance[$key];
            }
        }

		foreach ( array('size', 'transform', 'relativeTransform', 'absoluteTransform', 'absoluteBoundingBox') as $key ) {
			if ( array_key_exists($key, $instance) ) {
				$resolved[$key] = $instance[$key];
			} else {
				unset($resolved[$key]);
			}
		}

        $resolved['figma_component'] = array_merge(
            is_array($instance['figma_component'] ?? null) ? $instance['figma_component'] : array(),
            array(
                'role'               => 'instance',
                'instance_id'        => $context['instance_id'],
                'component_id'       => $context['component_id'],
                'definition_node_id' => $context['definition_node_id'],
                'resolved'           => true,
            )
        );
        $resolvedChildren = is_array($resolved['children'] ?? null) ? $resolved['children'] : array();
        $resolvedChildren = $this->resolveClonedInstanceChildren($resolvedChildren, $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, $options, $resolutionTrail);
        $componentBox = is_array($component['box'] ?? null) ? $component['box'] : array();
        $componentSourceX = isset($componentBox['x']) && is_numeric($componentBox['x']) ? (float) $componentBox['x'] : null;
        $componentSourceY = isset($componentBox['y']) && is_numeric($componentBox['y']) ? (float) $componentBox['y'] : null;
        $componentSourceWidth = isset($componentBox['width']) && is_numeric($componentBox['width']) ? (float) $componentBox['width'] : null;
        $componentSourceHeight = isset($componentBox['height']) && is_numeric($componentBox['height']) ? (float) $componentBox['height'] : null;
        if ( null !== $componentSourceX || null !== $componentSourceY ) {
            $rebasedSource = $this->componentSourceCloneGeometry->rebaseDescendants(array('children' => $resolvedChildren), $componentSourceX, $componentSourceY, $componentSourceWidth, $componentSourceHeight);
            $resolvedChildren = is_array($rebasedSource['children'] ?? null) ? $rebasedSource['children'] : $resolvedChildren;
        }
        $resolvedChildren = $this->vectorInstanceScaler->scaleVectorOnlyInstanceChildren($resolvedChildren, $component, $instance);
        // Figma binds per-instance text content through component properties: each
        // master text node references a property definition (componentPropRefs ->
        // componentPropNodeField: TEXT_DATA) and the instance assigns the real value
        // (componentPropAssignments -> value.textValue.characters). Fold those text
        // assignments into the override map keyed by the consuming node id so the
        // existing override machinery renders each instance's own content instead of
        // the component master's default placeholder.
        $overrides = $context['overrides'];
        if ( $this->instanceOverridesUseTransforms($overrides) ) {
            $resolved['layout'] = array('freeform' => true);
        }
        $resolvedChildren = $this->applyInstanceOverridesToChildren($resolvedChildren, $overrides, $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, $options);
        $resolvedChildren = $this->mergeComponentSourceCloneDescendantLayoutMetadata($resolvedChildren, $nodeMap);
        $resolved['children'] = $this->namespaceResolvedInstanceChildren($resolvedChildren, $context['instance_id']);

        return $resolved;
    }

    /**
     * @param array<int, mixed> $children
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, mixed>
     */
    private function mergeComponentSourceCloneDescendantLayoutMetadata(array $children, array $nodeMap): array
    {
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $sourceId = isset($child['figma_component_source_id']) && is_scalar($child['figma_component_source_id'])
                ? (string) $child['figma_component_source_id']
                : (isset($child['id']) && is_scalar($child['id']) ? (string) $child['id'] : '');
            if ( '' !== $sourceId && isset($nodeMap[$sourceId]['layout']['z_index']) && is_numeric($nodeMap[$sourceId]['layout']['z_index']) ) {
                $layout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
                if ( ! isset($layout['z_index']) || ! is_numeric($layout['z_index']) ) {
                    $layout['z_index'] = (int) $nodeMap[$sourceId]['layout']['z_index'];
                    $child['layout'] = $layout;
                }
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->mergeComponentSourceCloneDescendantLayoutMetadata($child['children'], $nodeMap);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * Gather the stable identifiers and normalized overrides that drive a resolved
     * instance clone. Keeping these together makes the clone steps below explicit:
     * preserve instance placement, refresh source children, apply overrides, then
     * namespace cloned source ids under the instance id.
     *
     * @param array<string, mixed>                 $component
     * @param array<string, mixed>                 $instance
     * @param array<string, array<string, mixed>> $overrides
     * @return array{instance_id: string, component_id: string, definition_node_id: string, overrides: array<string, array<string, mixed>>}
     */
    private function buildInstanceCloneContext(array $component, array $instance, string $componentId, array $overrides): array
    {
        return array(
            'instance_id'        => (string) ($instance['id'] ?? $component['id'] ?? ''),
            'component_id'       => $componentId,
            'definition_node_id' => (string) ($component['id'] ?? ''),
            'overrides'          => $this->mergeComponentPropertyOverrides($overrides, $instance, $component),
        );
    }

    /**
     * Fold component-property assignments into the override map.
     *
     * @param array<string, array<string, mixed>> $overrides Existing override map keyed by node id.
     * @param array<string, mixed>                 $instance  Instance node carrying componentPropAssignments.
     * @param array<string, mixed>                 $component Component definition whose nodes carry componentPropRefs.
     * @return array<string, array<string, mixed>>
     */
    private function mergeComponentPropertyOverrides(array $overrides, array $instance, array $component): array
    {
        $overrides = $this->mergeComponentPropertyTextOverrides($overrides, $instance, $component);
        $overrides = $this->mergeComponentPropertyVisibilityOverrides($overrides, $instance, $component);
        return $this->mergeComponentPropertyInstanceSwapOverrides($overrides, $instance, $component);
    }

    /**
     * Fold the instance's component-property text assignments into the override map.
     *
     * Figma stores per-instance text overrides as component properties rather than as
     * descendant node changes: the instance carries componentPropAssignments (defID ->
     * value.textValue.characters) and each master text node carries componentPropRefs
     * (defID -> componentPropNodeField: TEXT_DATA). Matching them by defID yields the
     * real per-instance characters for each consuming text node.
     *
     * @param array<string, array<string, mixed>> $overrides Existing override map keyed by node id.
     * @param array<string, mixed>                 $instance  Instance node carrying componentPropAssignments.
     * @param array<string, mixed>                 $component Component definition whose text nodes carry componentPropRefs.
     * @return array<string, array<string, mixed>>
     */
    private function mergeComponentPropertyTextOverrides(array $overrides, array $instance, array $component): array
    {
        $assignments = $this->componentPropertyTextAssignments($instance);
        if ( empty($assignments) ) {
            return $overrides;
        }

        $targets = array();
        $this->collectComponentPropertyTextTargets($component, $assignments, $targets);

        foreach ( $targets as $nodeId => $fields ) {
            foreach ( $fields as $field => $value ) {
                // Do not clobber a value an explicit override already established;
                // component-property assignments only fill content that is otherwise
                // left at the component master default.
                if ( ! isset($overrides[$nodeId][$field]) ) {
                    $overrides[$nodeId][$field] = $value;
                }
            }
        }

        return $overrides;
    }

    /**
     * Read the text-valued component property assignments from an instance.
     *
     * @param array<string, mixed> $instance
     * @return array<string, string> Map of property definition id => assigned characters.
     */
    private function componentPropertyTextAssignments(array $instance): array
    {
        $assignmentsRaw = $instance['componentPropAssignments'] ?? null;
        if ( ! is_array($assignmentsRaw) ) {
            return array();
        }

        $assignments = array();
        foreach ( $assignmentsRaw as $assignment ) {
            if ( ! is_array($assignment) ) {
                continue;
            }

            $defId = $this->readGuidId($assignment['defID'] ?? $assignment['defId'] ?? null);
            if ( null === $defId || '' === $defId ) {
                continue;
            }

            $characters = $this->readComponentPropertyAssignmentCharacters($assignment);
            if ( null === $characters ) {
                continue;
            }

            $assignments[$defId] = $characters;
        }

        return $assignments;
    }

    /**
     * @param array<string, mixed> $assignment
     */
    private function readComponentPropertyAssignmentCharacters(array $assignment): ?string
    {
        $paths = array(
            array('value', 'textValue', 'characters'),
            array('value', 'textDataValue', 'characters'),
            array('varValue', 'value', 'textDataValue', 'characters'),
            array('varValue', 'value', 'textValue', 'characters'),
        );

        foreach ( $paths as $path ) {
            $cursor = $assignment;
            foreach ( $path as $key ) {
                if ( ! is_array($cursor) || ! array_key_exists($key, $cursor) ) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$key];
            }
            if ( is_scalar($cursor) ) {
                return (string) $cursor;
            }
        }

        return null;
    }

    /**
     * Walk a component subtree and record text overrides for nodes whose TEXT_DATA
     * property reference matches an instance assignment.
     *
     * @param array<string, mixed>  $node
     * @param array<string, string> $assignments Map of property definition id => characters.
     * @param array<string, array<string, mixed>> $targets Accumulator keyed by node id.
     */
    private function collectComponentPropertyTextTargets(array $node, array $assignments, array &$targets): void
    {
        foreach ( $this->componentPropertyTextRefDefIds($node) as $defId ) {
            if ( ! isset($assignments[$defId]) ) {
                continue;
            }

            $nodeId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            if ( '' === $nodeId ) {
                continue;
            }

            $targets[$nodeId]['characters'] = $assignments[$defId];
            $targets[$nodeId]['text'] = $assignments[$defId];
            break;
        }

        if ( is_array($node['children'] ?? null) ) {
            foreach ( $node['children'] as $child ) {
                if ( is_array($child) ) {
                    $this->collectComponentPropertyTextTargets($child, $assignments, $targets);
                }
            }
        }
    }

    /**
     * Read the property definition ids bound to a node's TEXT_DATA field.
     *
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function componentPropertyTextRefDefIds(array $node): array
    {
        $refs = $node['componentPropRefs'] ?? $node['componentPropRef'] ?? null;
        if ( ! is_array($refs) ) {
            return array();
        }

        $defIds = array();
        foreach ( $refs as $ref ) {
            if ( ! is_array($ref) ) {
                continue;
            }

            $field = strtoupper((string) ($ref['componentPropNodeField'] ?? ''));
            if ( 'TEXT_DATA' !== $field && 'TEXT' !== $field && 'CHARACTERS' !== $field ) {
                continue;
            }

            $defId = $this->readGuidId($ref['defID'] ?? $ref['defId'] ?? null);
            if ( null !== $defId && '' !== $defId ) {
                $defIds[] = $defId;
            }
        }

        return $defIds;
    }

    /**
     * @param array<string, array<string, mixed>> $overrides Existing override map keyed by node id.
     * @param array<string, mixed>                 $instance  Instance node carrying componentPropAssignments.
     * @param array<string, mixed>                 $component Component definition whose nodes carry componentPropRefs.
     * @return array<string, array<string, mixed>>
     */
    private function mergeComponentPropertyVisibilityOverrides(array $overrides, array $instance, array $component): array
    {
        $assignments = $this->componentPropertyBooleanAssignments($instance);
        if ( empty($assignments) ) {
            return $overrides;
        }

        $targets = array();
        $this->collectComponentPropertyVisibilityTargets($component, $assignments, $targets);

        foreach ( $targets as $nodeId => $visible ) {
            if ( ! isset($overrides[$nodeId]['visible']) ) {
                $overrides[$nodeId]['visible'] = $visible;
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $instance
     * @return array<string, bool> Map of property definition id => assigned visibility.
     */
    private function componentPropertyBooleanAssignments(array $instance): array
    {
        $assignmentsRaw = $instance['componentPropAssignments'] ?? null;
        if ( ! is_array($assignmentsRaw) ) {
            return array();
        }

        $assignments = array();
        foreach ( $assignmentsRaw as $assignment ) {
            if ( ! is_array($assignment) ) {
                continue;
            }

            $defId = $this->readGuidId($assignment['defID'] ?? $assignment['defId'] ?? null);
            if ( null === $defId || '' === $defId ) {
                continue;
            }

            $visible = $this->readComponentPropertyAssignmentBoolean($assignment);
            if ( null !== $visible ) {
                $assignments[$defId] = $visible;
            }
        }

        return $assignments;
    }

    /**
     * @param array<string, mixed> $assignment
     */
    private function readComponentPropertyAssignmentBoolean(array $assignment): ?bool
    {
        $paths = array(
            array('value', 'boolValue'),
            array('value', 'booleanValue'),
            array('varValue', 'value', 'boolValue'),
            array('varValue', 'value', 'booleanValue'),
        );

        foreach ( $paths as $path ) {
            $cursor = $assignment;
            foreach ( $path as $key ) {
                if ( ! is_array($cursor) || ! array_key_exists($key, $cursor) ) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$key];
            }
            if ( is_bool($cursor) ) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool>  $assignments Map of property definition id => visibility.
     * @param array<string, bool>  $targets Accumulator keyed by node id.
     */
    private function collectComponentPropertyVisibilityTargets(array $node, array $assignments, array &$targets): void
    {
        foreach ( $this->componentPropertyVisibilityRefDefIds($node) as $defId ) {
            if ( ! array_key_exists($defId, $assignments) ) {
                continue;
            }

            $nodeId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            if ( '' !== $nodeId ) {
                $targets[$nodeId] = $assignments[$defId];
            }
            break;
        }

        if ( is_array($node['children'] ?? null) ) {
            foreach ( $node['children'] as $child ) {
                if ( is_array($child) ) {
                    $this->collectComponentPropertyVisibilityTargets($child, $assignments, $targets);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function componentPropertyVisibilityRefDefIds(array $node): array
    {
        $refs = $node['componentPropRefs'] ?? $node['componentPropRef'] ?? null;
        if ( ! is_array($refs) ) {
            return array();
        }

        $defIds = array();
        foreach ( $refs as $ref ) {
            if ( ! is_array($ref) ) {
                continue;
            }

            $field = strtoupper((string) ($ref['componentPropNodeField'] ?? ''));
            if ( 'VISIBLE' !== $field && 'VISIBILITY' !== $field ) {
                continue;
            }

            $defId = $this->readGuidId($ref['defID'] ?? $ref['defId'] ?? null);
            if ( null !== $defId && '' !== $defId ) {
                $defIds[] = $defId;
            }
        }

        return $defIds;
    }

    /**
     * @param array<string, array<string, mixed>> $overrides Existing override map keyed by node id.
     * @param array<string, mixed>                 $instance  Instance node carrying componentPropAssignments.
     * @param array<string, mixed>                 $component Component definition whose nested instances carry componentPropRefs.
     * @return array<string, array<string, mixed>>
     */
    private function mergeComponentPropertyInstanceSwapOverrides(array $overrides, array $instance, array $component): array
    {
        $assignments = $this->componentPropertyInstanceSwapAssignments($instance);
        if ( empty($assignments) ) {
            return $overrides;
        }

        $targets = array();
        $this->collectComponentPropertyInstanceSwapTargets($component, $assignments, $targets);

        foreach ( $targets as $nodeId => $componentId ) {
            if ( ! isset($overrides[$nodeId]['_figma_instance_swap_component_id']) ) {
                $overrides[$nodeId]['_figma_instance_swap_component_id'] = $componentId;
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $instance
     * @return array<string, string> Map of property definition id => replacement component id.
     */
    private function componentPropertyInstanceSwapAssignments(array $instance): array
    {
        $assignmentsRaw = $instance['componentPropAssignments'] ?? null;
        if ( ! is_array($assignmentsRaw) ) {
            return array();
        }

        $assignments = array();
        foreach ( $assignmentsRaw as $assignment ) {
            if ( ! is_array($assignment) ) {
                continue;
            }

            $defId = $this->readGuidId($assignment['defID'] ?? $assignment['defId'] ?? null);
            if ( null === $defId || '' === $defId ) {
                continue;
            }

            $componentId = $this->readComponentPropertyAssignmentGuid($assignment);
            if ( null !== $componentId && '' !== $componentId ) {
                $assignments[$defId] = $componentId;
            }
        }

        return $assignments;
    }

    /**
     * @param array<string, mixed> $assignment
     */
    private function readComponentPropertyAssignmentGuid(array $assignment): ?string
    {
        $paths = array(
            array('value', 'guidValue'),
            array('value', 'symbolIdValue', 'guid'),
            array('varValue', 'value', 'guidValue'),
            array('varValue', 'value', 'symbolIdValue', 'guid'),
        );

        foreach ( $paths as $path ) {
            $cursor = $assignment;
            foreach ( $path as $key ) {
                if ( ! is_array($cursor) || ! array_key_exists($key, $cursor) ) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$key];
            }

            $componentId = $this->readGuidId($cursor);
            if ( null !== $componentId ) {
                return $componentId;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>                 $node
     * @param array<string, string>                $assignments Map of property definition id => replacement component id.
     * @param array<string, string>                $targets Accumulator keyed by node id.
     */
    private function collectComponentPropertyInstanceSwapTargets(array $node, array $assignments, array &$targets): void
    {
        foreach ( $this->componentPropertyInstanceSwapRefDefIds($node) as $defId ) {
            if ( ! isset($assignments[$defId]) ) {
                continue;
            }

            $nodeId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            if ( '' !== $nodeId ) {
                $targets[$nodeId] = $assignments[$defId];
            }
            break;
        }

        if ( is_array($node['children'] ?? null) ) {
            foreach ( $node['children'] as $child ) {
                if ( is_array($child) ) {
                    $this->collectComponentPropertyInstanceSwapTargets($child, $assignments, $targets);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function componentPropertyInstanceSwapRefDefIds(array $node): array
    {
        $refs = $node['componentPropRefs'] ?? $node['componentPropRef'] ?? null;
        if ( ! is_array($refs) ) {
            return array();
        }

        $defIds = array();
        foreach ( $refs as $ref ) {
            if ( ! is_array($ref) ) {
                continue;
            }

            $field = strtoupper((string) ($ref['componentPropNodeField'] ?? ''));
            if ( 'OVERRIDDEN_SYMBOL_ID' !== $field && 'INSTANCE_SWAP' !== $field ) {
                continue;
            }

            $defId = $this->readGuidId($ref['defID'] ?? $ref['defId'] ?? null);
            if ( null !== $defId && '' !== $defId ) {
                $defIds[] = $defId;
            }
        }

        return $defIds;
    }

    /**
     * @param array<string, array<string, mixed>> $overrides
     */
    private function instanceOverridesUseTransforms(array $overrides): bool
    {
        foreach ( $overrides as $override ) {
            if ( is_array($override) && $this->isTransformOverrideGeometry($override) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $override
     */
    private function isTransformOverrideGeometry(array $override): bool
    {
        return is_array($override['transform'] ?? null);
    }

    /**
     * @param array<int, mixed> $children
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, mixed>
     */
    private function resolveClonedInstanceChildren(array $children, array $nodeMap, array $components, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array(), array $options = array(), array $resolutionTrail = array()): array
    {
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $id = (string) ($child['id'] ?? '');
            if ( 'INSTANCE' === strtoupper((string) ($child['type'] ?? '')) && '' !== $id && isset($nodeMap[$id]) ) {
                $refreshed = $nodeMap[$id];
                $reference = $this->readComponentReference($refreshed);
                if ( empty($refreshed['children']) && null !== $reference && isset($components[$reference['id']]) && ! in_array($id, $resolutionTrail, true) ) {
                    $overrides = $this->normalizeInstanceOverrides($refreshed, $id, $diagnostics);
                    if ( null !== $overrides ) {
                        $refreshed = $this->cloneComponentForInstance($components[$reference['id']], $refreshed, $reference['id'], $overrides, $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, $options, array_merge($resolutionTrail, array($id)));
                    }
                }
                $child = $this->componentSourceCloneGeometry->mergeRefreshed($child, $refreshed, $id);
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->resolveClonedInstanceChildren($child['children'], $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, $options, $resolutionTrail);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, mixed>
     */
    private function scaleVectorOnlyInstanceChildren(array $children, array $component, array $instance): array
    {
        if ( ! $this->isVectorOnlyComponent($component) ) {
            return $children;
        }

        $componentBox = is_array($component['box'] ?? null) ? $component['box'] : array();
        $instanceBox  = is_array($instance['box'] ?? null) ? $instance['box'] : array();
        $componentWidth = isset($componentBox['width']) && is_numeric($componentBox['width']) ? (float) $componentBox['width'] : 0.0;
        $componentHeight = isset($componentBox['height']) && is_numeric($componentBox['height']) ? (float) $componentBox['height'] : 0.0;
        $instanceWidth = isset($instanceBox['width']) && is_numeric($instanceBox['width']) ? (float) $instanceBox['width'] : 0.0;
        $instanceHeight = isset($instanceBox['height']) && is_numeric($instanceBox['height']) ? (float) $instanceBox['height'] : 0.0;
        if ( $componentWidth <= 0.0 || $componentHeight <= 0.0 || $instanceWidth <= 0.0 || $instanceHeight <= 0.0 ) {
            return $children;
        }

        $scaleX = $instanceWidth / $componentWidth;
        $scaleY = $instanceHeight / $componentHeight;
        if ( abs($scaleX - 1.0) < 0.0001 && abs($scaleY - 1.0) < 0.0001 ) {
            return $children;
        }

        return $this->scaleVectorChildren($children, $scaleX, $scaleY);
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, mixed>
     */
    private function scaleVectorChildren(array $children, float $scaleX, float $scaleY): array
    {
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            if ( is_array($child['box'] ?? null) ) {
                foreach ( array('x' => $scaleX, 'width' => $scaleX, 'y' => $scaleY, 'height' => $scaleY) as $key => $scale ) {
                    if ( isset($child['box'][$key]) && is_numeric($child['box'][$key]) ) {
                        $child['box'][$key] = (float) $child['box'][$key] * $scale;
                    }
                }
            }

            if ( is_array($child['figma_box'] ?? null) ) {
                foreach ( array('x' => $scaleX, 'width' => $scaleX, 'y' => $scaleY, 'height' => $scaleY) as $key => $scale ) {
                    if ( isset($child['figma_box'][$key]) && is_numeric($child['figma_box'][$key]) ) {
                        $child['figma_box'][$key] = (float) $child['figma_box'][$key] * $scale;
                    }
                }
            }

            foreach ( array('x' => $scaleX, 'width' => $scaleX, 'y' => $scaleY, 'height' => $scaleY) as $key => $scale ) {
                if ( isset($child[$key]) && is_numeric($child[$key]) ) {
                    $child[$key] = (float) $child[$key] * $scale;
                }
            }

            $child['_component_source_clone_scale'] = array('x' => $scaleX, 'y' => $scaleY);

            if ( $this->isScalableVectorType(strtoupper((string) ($child['type'] ?? ''))) ) {
                $child['figma_vector_scale'] = array('x' => $scaleX, 'y' => $scaleY);
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->scaleVectorChildren($child['children'], $scaleX, $scaleY);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $component
     */
    private function isVectorOnlyComponent(array $component): bool
    {
        $children = is_array($component['children'] ?? null) ? $component['children'] : array();
        if ( empty($children) ) {
            return false;
        }

        foreach ( $children as $child ) {
            if ( ! is_array($child) || ! $this->isVectorOnlyNode($child) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isVectorOnlyNode(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( 'INSTANCE' !== $type && ! $this->isScalableVectorType($type) ) {
            return false;
        }

        foreach ( is_array($node['children'] ?? null) ? $node['children'] : array() as $child ) {
            if ( ! is_array($child) || ! $this->isVectorOnlyNode($child) ) {
                return false;
            }
        }

        return true;
    }

    private function isScalableVectorType(string $type): bool
    {
        return in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'RECTANGLE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true);
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, mixed>
     */
    private function namespaceResolvedInstanceChildren(array $children, string $instanceId): array
    {
        if ( '' === $instanceId ) {
            return $children;
        }

        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $sourceId = (string) ($child['id'] ?? '');
            if ( '' !== $sourceId && ! str_starts_with($sourceId, $instanceId . '/') ) {
                $child['figma_component_source_id'] = $sourceId;
                $child['id'] = $instanceId . '/' . $sourceId;
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->namespaceResolvedInstanceChildren($child['children'], $instanceId);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<int, mixed> $children
     * @param array<string, array<string, mixed>> $overrides
     * @return array<int, mixed>
     */
    private function applyInstanceOverridesToChildren(array $children, array $overrides, array $nodeMap, array $components, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array(), array $options = array(), array $parentSourcePaths = array()): array
    {
		foreach ( $children as $index => $child ) {
			if ( ! is_array($child) ) {
				continue;
			}

			$id = (string) ($child['id'] ?? '');
			$sourceNode = '' !== $id && is_array($nodeMap[$id] ?? null) ? $nodeMap[$id] : array();
			$sourceChildBox = is_array($sourceNode['box'] ?? null) ? $sourceNode['box'] : (is_array($child['box'] ?? null) ? $child['box'] : null);
			$hasFieldOverride = false;
            $sourcePaths = $this->instanceChildOverrideSourcePaths($child, $parentSourcePaths);
            $overrideFields = $this->instanceOverrideFieldsForChild($child, $overrides, $sourcePaths);
            $paintOverrideFields = $this->instancePaintOverrideFields($overrideFields);
            if ( ! empty($paintOverrideFields) ) {
                $child['_figma_instance_paint_override_fields'] = $paintOverrideFields;
            }
            $swapComponentId = isset($overrideFields['_figma_instance_swap_component_id']) && is_scalar($overrideFields['_figma_instance_swap_component_id']) ? (string) $overrideFields['_figma_instance_swap_component_id'] : null;
            unset($overrideFields['_figma_instance_swap_component_id']);
            $nestedComponentPropertyOverrides = $this->nestedComponentPropertyOverridesForChild($child, $overrideFields, $components);
            unset($overrideFields['componentPropAssignments']);
            if ( null !== $swapComponentId && isset($components[$swapComponentId]) ) {
                $child = $this->componentSourceCloneGeometry->mergeRefreshed($child, $components[$swapComponentId], $swapComponentId);
                if ( is_array($child['children'] ?? null) ) {
                    $child['children'] = $this->resolveClonedInstanceChildren($child['children'], $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, $options);
                }
                $child['_figma_instance_override_applied'] = true;
            }
            foreach ( $overrideFields as $field => $value ) {
                $hasFieldOverride = true;
                if ( is_array($value) ) {
                    $value = $this->normalizeInstanceOverridePaintField($child, $field, $value);
                }
                $child[$field] = $value;
				if ( in_array($field, array('characters', 'text'), true) && is_array($child['figma_text'] ?? null) ) {
					$child['figma_text']['characters'] = (string) $value;
				} elseif ( 'textData' === $field && is_array($value) && isset($value['characters']) && is_scalar($value['characters']) && is_array($child['figma_text'] ?? null) ) {
					$child['figma_text']['characters'] = (string) $value['characters'];
				}
			}
			if ( $hasFieldOverride ) {
				$child = $this->normalizeOverriddenInstanceChild($child, $id, $overrideFields, $diagnostics, $blobs, $paintStyles, $textStyles, $options, $sourceChildBox);
			}

            if ( is_array($child['children'] ?? null) ) {
                $childOverrides = $this->descendantInstanceOverrideFieldsForChild($child, $overrides);
                $child['children'] = $this->applyInstanceOverridesToChildren($child['children'], array_merge($overrides, $childOverrides, $nestedComponentPropertyOverrides), $nodeMap, $components, $diagnostics, $blobs, $paintStyles, $textStyles, $options, $sourcePaths);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $overrideFields
     * @return array<string, array<int, string>>
     */
    private function instancePaintOverrideFields(array $overrideFields): array
    {
        $collections = array();
        foreach ( array(
            'fills'   => array('fillPaints', 'fills'),
            'strokes' => array('strokePaints', 'strokes'),
        ) as $collection => $fields ) {
            foreach ( $fields as $field ) {
                if ( array_key_exists($field, $overrideFields) ) {
                    $collections[$collection][] = $field;
                }
            }
            if ( isset($collections[$collection]) ) {
                sort($collections[$collection], SORT_STRING);
            }
        }

        return $collections;
    }

    /**
     * @param array<string, mixed> $child
     * @param array<int, mixed>    $value
     * @return array<int, mixed>
     */
    private function normalizeInstanceOverridePaintField(array $child, string $field, array $value): array
    {
        if ( ! in_array($field, array('fills', 'fillPaints', 'strokes', 'strokePaints'), true) ) {
            return $value;
        }

        $sourceFields = in_array($field, array('fills', 'fillPaints'), true)
            ? array('fillPaints', 'fills')
            : array('strokePaints', 'strokes');
        $sourcePaints = array();
        foreach ( $sourceFields as $sourceField ) {
            if ( is_array($child[$sourceField] ?? null) ) {
                $sourcePaints = $child[$sourceField];
                break;
            }
        }

        return $this->paintNormalizer->removeSourceImagePaintsFromOverrideList($value, $sourcePaints);
    }

    /**
     * @param array<string, mixed>                $child
     * @param array<string, mixed>                $overrideFields
     * @param array<string, array<string, mixed>> $components
     * @return array<string, array<string, mixed>>
     */
    private function nestedComponentPropertyOverridesForChild(array $child, array $overrideFields, array $components): array
    {
        if ( ! is_array($overrideFields['componentPropAssignments'] ?? null) || 'INSTANCE' !== strtoupper((string) ($child['type'] ?? '')) ) {
            return array();
        }

        $reference = $this->readComponentReference($child);
        $componentId = null !== $reference ? $reference['id'] : null;
        if ( null === $componentId && isset($child['figma_component']['component_id']) && is_scalar($child['figma_component']['component_id']) ) {
            $componentId = (string) $child['figma_component']['component_id'];
        }
        if ( null === $componentId || ! isset($components[$componentId]) ) {
            return array();
        }

        $instance = $child;
        $instance['componentPropAssignments'] = $overrideFields['componentPropAssignments'];

        return $this->mergeComponentPropertyOverrides(array(), $instance, $components[$componentId]);
    }

    /**
     * @param array<string, mixed> $child
     * @param array<string, array<string, mixed>> $overrides
     * @return array<string, mixed>
     */
    private function instanceOverrideFieldsForChild(array $child, array $overrides, array $sourcePaths = array()): array
    {
        $fields = array();
        foreach ( $this->instanceChildOverrideAliases($child) as $alias ) {
            if ( isset($overrides[$alias]) && is_array($overrides[$alias]) ) {
                $fields = array_merge($fields, $overrides[$alias]);
            }
        }

        foreach ( $sourcePaths as $sourcePath ) {
            if ( isset($overrides[$sourcePath]) && is_array($overrides[$sourcePath]) ) {
                $fields = array_merge($fields, $overrides[$sourcePath]);
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $child
     * @param array<int, string>   $parentSourcePaths
     * @return array<int, string>
     */
    private function instanceChildOverrideSourcePaths(array $child, array $parentSourcePaths = array()): array
    {
        $aliases = $this->instanceChildOverrideAliases($child);
        if ( empty($parentSourcePaths) ) {
            return $aliases;
        }

        $paths = array();
        foreach ( $parentSourcePaths as $parentSourcePath ) {
            foreach ( $aliases as $alias ) {
                $paths[] = $parentSourcePath . '/' . $alias;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Carry parent-scoped guidPath overrides into resolved nested instances.
     *
     * Figma can encode an override for a nested component child as
     * `nested-instance-guid/child-guid`. Once recursion enters the nested
     * instance, its descendants match the suffix (`child-guid`), not the full
     * parent path.
     *
     * @param array<string, mixed> $child
     * @param array<string, array<string, mixed>> $overrides
     * @return array<string, array<string, mixed>>
     */
    private function descendantInstanceOverrideFieldsForChild(array $child, array $overrides): array
    {
        $scoped = array();
        foreach ( $this->instanceChildOverrideAliases($child) as $alias ) {
            foreach ( $overrides as $target => $overrideFields ) {
                if ( ! is_string($target) || ! is_array($overrideFields) || ! str_starts_with($target, $alias . '/') ) {
                    continue;
                }

                $descendantTarget = substr($target, strlen($alias) + 1);
                if ( '' !== $descendantTarget ) {
                    $scoped[$descendantTarget] = array_merge($scoped[$descendantTarget] ?? array(), $overrideFields);
                }
            }
        }

        return $scoped;
    }

    /**
     * @param array<string, mixed> $child
     * @return array<int, string>
     */
    private function instanceChildOverrideAliases(array $child): array
    {
        $aliases = array();
        foreach ( array('id', 'figma_component_source_id') as $key ) {
            if ( isset($child[$key]) && is_scalar($child[$key]) && '' !== (string) $child[$key] ) {
                $id = (string) $child[$key];
                $aliases[] = $id;
                if ( str_contains($id, '/') ) {
                    $parts = explode('/', $id);
                    $aliases[] = (string) end($parts);
                }
            }
        }

        $guidId = $this->readGuidId($child['guid'] ?? null);
        if ( null !== $guidId ) {
            $aliases[] = $guidId;
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @param array<string, mixed>             $child
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
	private function normalizeOverriddenInstanceChild(array $child, string $id, array $overrideFields, array &$diagnostics, array $blobs = array(), array $paintStyles = array(), array $textStyles = array(), array $options = array(), ?array $sourceChildBox = null): array
	{
		$hasVectorGeometryOverride = array_key_exists('fillGeometry', $overrideFields) || array_key_exists('strokeGeometry', $overrideFields);
		$hasExplicitSizeOverride = array_key_exists('size', $overrideFields);
		$hasTransformGeometryOverride = is_array($overrideFields['transform'] ?? null) || is_array($overrideFields['absoluteTransform'] ?? null) || is_array($overrideFields['relativeTransform'] ?? null);
		$inheritedTextStyle = array();
		if ( 'TEXT' === strtoupper((string) ($child['type'] ?? '')) && ! $this->instanceOverrideFieldsIncludeTypography($overrideFields) && is_array($child['figma_text']['style'] ?? null) ) {
			$inheritedTextStyle = $child['figma_text']['style'];
		}
        if ( is_array($child['size'] ?? null) ) {
            foreach ( array('x' => 'width', 'y' => 'height') as $source => $target ) {
                if ( isset($child['size'][$source]) && is_numeric($child['size'][$source]) ) {
                    $child[$target] = (float) $child['size'][$source];
                }
            }
        }
        if ( is_array($child['relativeTransform'] ?? null) ) {
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( isset($child['relativeTransform'][$source]) && is_numeric($child['relativeTransform'][$source]) ) {
                    $child[$target] = (float) $child['relativeTransform'][$source];
                    $child[GeometryBox::PROVENANCE_KEY] = GeometryBox::SOURCE_OVERRIDE_TRANSFORM;
                }
            }
        }
        if ( is_array($child['absoluteTransform'] ?? null) ) {
            $absoluteBounds = is_array($child['absoluteBoundingBox'] ?? null) ? $child['absoluteBoundingBox'] : array();
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( isset($child['absoluteTransform'][$source]) && is_numeric($child['absoluteTransform'][$source]) ) {
                    $child[$target] = (float) $child['absoluteTransform'][$source];
                    $child[GeometryBox::PROVENANCE_KEY] = GeometryBox::SOURCE_ABSOLUTE_TRANSFORM;
                    $absoluteBounds[$target] = (float) $child['absoluteTransform'][$source];
                }
            }
            if ( ! empty($absoluteBounds) ) {
                $child['absoluteBoundingBox'] = $absoluteBounds;
            }
        }
        if ( is_array($child['transform'] ?? null) ) {
            // m02 and m12 carry canvas-global (absolute) coordinates, not parent-local
            // coordinates. Stamp them into absoluteBoundingBox so that normalizeLayoutBox
            // labels the resulting box coordinate_space='absolute'. Without this, the bare
            // x/y values written below are picked up by the local-coordinate fallback path
            // and mislabeled coordinate_space='local', causing positionOffset() to emit the
            // raw canvas value verbatim (e.g. 13842 px) instead of subtracting the
            // containing-block origin.
            $absoluteBounds = is_array($child['absoluteBoundingBox'] ?? null) ? $child['absoluteBoundingBox'] : array();
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( isset($child['transform'][$source]) && is_numeric($child['transform'][$source]) ) {
                    $child[$target] = (float) $child['transform'][$source];
                    $child[GeometryBox::PROVENANCE_KEY] = GeometryBox::SOURCE_ABSOLUTE_TRANSFORM;
                    // Only backfill absoluteBoundingBox where the dimension is not already
                    // present — preserve any richer absolute bounds the payload already has.
                    if ( ! isset($absoluteBounds[$target]) ) {
                        $absoluteBounds[$target] = (float) $child['transform'][$source];
                    }
                }
            }
            if ( ! empty($absoluteBounds) ) {
                $child['absoluteBoundingBox'] = $absoluteBounds;
            }
        }

        foreach ( array('figma_text', 'figma_paints', 'figma_vector_paths', 'figma_box', 'box', 'layout', 'figma_effects') as $key ) {
            unset($child[$key]);
        }
        unset($child['figma_vector_scale']);

		$child['_figma_instance_override_applied'] = true;
		$child = $this->normalizeNode($child, $diagnostics, $blobs, $paintStyles, $textStyles, $options);
		if ( ! empty($inheritedTextStyle) && is_array($child['figma_text'] ?? null) ) {
			$style = is_array($child['figma_text']['style'] ?? null) ? $child['figma_text']['style'] : array();
			$child['figma_text']['style'] = array_merge($inheritedTextStyle, $style);
		}
		if ( $hasTransformGeometryOverride && is_array($sourceChildBox) ) {
			$child = $this->preserveLocalSourceBoxForFarAbsoluteOverride($child, $sourceChildBox, $overrideFields, $diagnostics);
		}
        unset($child[GeometryBox::PROVENANCE_KEY]);
        if ( $hasVectorGeometryOverride && ! $hasExplicitSizeOverride ) {
            $bounds = $this->vectorGeometryNormalizer->normalizedVectorPathBounds(is_array($child['figma_vector_paths'] ?? null) ? $child['figma_vector_paths'] : array());
            if ( null !== $bounds ) {
                $box = is_array($child['box'] ?? null) ? $child['box'] : array();
                foreach ( array('width', 'height') as $dimension ) {
                    if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) || $bounds[$dimension] > (float) $box[$dimension] + 0.001 ) {
                        $child[$dimension] = $bounds[$dimension];
                        $child['box'][$dimension] = $bounds[$dimension];
                    }
                }
            }
        }

		return $child;
	}

	/**
	 * @param array<string, mixed> $overrideFields
	 */
	private function instanceOverrideFieldsIncludeTypography(array $overrideFields): bool
	{
		foreach ( array('style', 'styleIdForText', 'fontName', 'fontFamily', 'fontPostScriptName', 'fontWeight', 'fontSize', 'lineHeight', 'lineHeightPx', 'lineHeightPercent', 'letterSpacing', 'textTracking') as $field ) {
			if ( array_key_exists($field, $overrideFields) ) {
				return true;
			}
		}

		$textData = $overrideFields['textData'] ?? null;
		if ( is_array($textData) ) {
			foreach ( array('style', 'styleIdForText', 'fontName', 'fontFamily', 'fontPostScriptName', 'fontWeight', 'fontSize', 'lineHeight', 'lineHeightPx', 'lineHeightPercent', 'letterSpacing', 'textTracking') as $field ) {
				if ( array_key_exists($field, $textData) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $child
	 * @param array<string, mixed> $sourceChildBox
	 * @return array<string, mixed>
	 */
	private function preserveLocalSourceBoxForFarAbsoluteOverride(array $child, array $sourceChildBox, array $overrideFields, array &$diagnostics): array
	{
		if ( GeometryBox::COORDINATE_SPACE_PARENT_LOCAL !== GeometryBox::coordinateSpace($sourceChildBox) || ! is_array($child['box'] ?? null) ) {
			return $child;
		}

		$box = $child['box'];
		$preservedDimensions = array();
		foreach ( array('x', 'y') as $dimension ) {
			if ( ! isset($sourceChildBox[$dimension], $box[$dimension]) || ! is_numeric($sourceChildBox[$dimension]) || ! is_numeric($box[$dimension]) ) {
				continue;
			}

			$sourceCoordinate = (float) $sourceChildBox[$dimension];
			$overrideCoordinate = (float) $box[$dimension];
			if ( abs($overrideCoordinate - $sourceCoordinate) >= 100.0 || (abs($overrideCoordinate) < 0.001 && abs($sourceCoordinate) >= 0.001) ) {
				$preservedDimensions[] = $dimension;
			}
		}

		if ( ! empty($preservedDimensions) ) {
			$diagnostics[] = array(
				'severity' => 'warning',
				'code'     => 'figma_component_clone_transform_override_source_preserved',
				'message'  => 'A component clone transform override was far from the source component geometry, so the source-local coordinates were preserved.',
				'context'  => array(
					'node_id'              => isset($child['id']) && is_scalar($child['id']) ? (string) $child['id'] : null,
					'source_node_id'       => isset($child['figma_component_source_id']) && is_scalar($child['figma_component_source_id']) ? (string) $child['figma_component_source_id'] : null,
					'preserved_dimensions' => $preservedDimensions,
					'raw_override_fields'  => $this->diagnosticRawOverrideGeometryFields($overrideFields),
					'source_box'           => $this->diagnosticGeometryBox($sourceChildBox),
					'override_box'         => $this->diagnosticGeometryBox($box),
				),
			);
			foreach ( $preservedDimensions as $dimension ) {
				if ( ! isset($sourceChildBox[$dimension]) || ! is_numeric($sourceChildBox[$dimension]) ) {
					continue;
				}

				$child[$dimension] = (float) $sourceChildBox[$dimension];
				$child['box'][$dimension] = (float) $sourceChildBox[$dimension];
				if ( is_array($child['figma_box'] ?? null) && isset($child['figma_box'][$dimension]) && is_numeric($child['figma_box'][$dimension]) ) {
					$child['figma_box'][$dimension] = (float) $sourceChildBox[$dimension];
				}
			}
		}

		$child['box']['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;
		if ( is_array($child['figma_box'] ?? null) ) {
			$child['figma_box']['coordinate_space'] = GeometryBox::COORDINATE_SPACE_PARENT_LOCAL;
		}

		return $child;
	}

	/**
	 * @param array<string, mixed> $overrideFields
	 * @return array<string, mixed>
	 */
	private function diagnosticRawOverrideGeometryFields(array $overrideFields): array
	{
		$fields = array();
		foreach ( array('transform', 'relativeTransform', 'absoluteTransform', 'absoluteBoundingBox', 'size') as $field ) {
			if ( ! array_key_exists($field, $overrideFields) ) {
				continue;
			}

			$value = $overrideFields[$field];
			if ( is_array($value) ) {
				$fields[$field] = $this->diagnosticNumericArray($value);
			} elseif ( is_scalar($value) || null === $value ) {
				$fields[$field] = $value;
			}
		}

		return $fields;
	}

	/**
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	private function diagnosticNumericArray(array $value): array
	{
		$summary = array();
		foreach ( $value as $key => $item ) {
			if ( is_array($item) ) {
				$summary[$key] = $this->diagnosticNumericArray($item);
			} elseif ( is_numeric($item) ) {
				$summary[$key] = (float) $item;
			} elseif ( is_scalar($item) || null === $item ) {
				$summary[$key] = $item;
			}
		}

		return $summary;
	}

	/**
	 * @param array<string, mixed> $box
	 * @return array<string, float|string>
	 */
	private function diagnosticGeometryBox(array $box): array
	{
		$summary = array();
		foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
			if ( isset($box[$dimension]) && is_numeric($box[$dimension]) ) {
				$summary[$dimension] = (float) $box[$dimension];
			}
		}

		$coordinateSpace = GeometryBox::coordinateSpace($box);
		if ( null !== $coordinateSpace ) {
			$summary['coordinate_space'] = $coordinateSpace;
		}

		if ( isset($box['local_origin']) && is_scalar($box['local_origin']) ) {
			$summary['local_origin'] = (string) $box['local_origin'];
		}

		return $summary;
	}

	/**
	 * Capture Figma hyperlink and prototype navigation data so the emitter can produce real anchors.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeLink(array $node, string $type): array
    {
        if ( 'TEXT' === $type ) {
            if ( array_key_exists('hyperlink', $node) ) {
                $link = $this->normalizeHyperlinkValue($node['hyperlink']);
                if ( null !== $link ) {
                    $link['source'] = 'hyperlink';
                    return $link;
                }
            }

            $segmentLink = $this->textNormalizer->normalizeSegmentHyperlink($node);
            if ( null !== $segmentLink ) {
                return $segmentLink;
            }
        } elseif ( array_key_exists('hyperlink', $node) ) {
            $link = $this->normalizeHyperlinkValue($node['hyperlink']);
            if ( null !== $link ) {
                $link['source'] = 'hyperlink';
                return $link;
            }
        }

        $reactionLink = $this->normalizeReactionLink($node);
        if ( null !== $reactionLink ) {
            return $reactionLink;
        }

        return array();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeHyperlinkValue(mixed $hyperlink): ?array
    {
        if ( is_string($hyperlink) && '' !== trim($hyperlink) ) {
            return array('type' => 'url', 'url' => trim($hyperlink));
        }

        if ( ! is_array($hyperlink) ) {
            return null;
        }

        $type = strtoupper((string) ($hyperlink['type'] ?? ''));
        $url = $this->readString($hyperlink, array('url', 'href'));
        // The Kiwi `Hyperlink` struct has no `type` field and stores the node
        // target as a `guid` GUID struct, so bridge `guid` onto the REST-shaped
        // `nodeID` resolution (#328).
        $nodeId = $this->readString($hyperlink, array('nodeID', 'nodeId', 'node_id'))
            ?? $this->readGuidId($hyperlink['nodeID'] ?? ($hyperlink['nodeId'] ?? ($hyperlink['guid'] ?? null)));

        if ( 'URL' === $type && null !== $url ) {
            return array('type' => 'url', 'url' => $url);
        }
        if ( 'NODE' === $type && null !== $nodeId ) {
            return array('type' => 'node', 'target_node_id' => $nodeId);
        }
        if ( null !== $url ) {
            return array('type' => 'url', 'url' => $url);
        }
        if ( null !== $nodeId ) {
            return array('type' => 'node', 'target_node_id' => $nodeId);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function normalizeReactionLink(array $node): ?array
    {
        // `reactions` is the REST name; `prototypeInteractions` is the Kiwi name
        // for the same prototype-interaction list decoded from `.fig` (#328).
        $interactions = is_array($node['reactions'] ?? null) ? $node['reactions'] : array();
        if ( is_array($node['prototypeInteractions'] ?? null) ) {
            $interactions = array_merge($interactions, $node['prototypeInteractions']);
        }

        foreach ( $interactions as $reaction ) {
            if ( ! is_array($reaction) ) {
                continue;
            }

            $actions = is_array($reaction['actions'] ?? null)
                ? $reaction['actions']
                : (is_array($reaction['action'] ?? null) ? array($reaction['action']) : array());
            foreach ( $actions as $action ) {
                if ( ! is_array($action) ) {
                    continue;
                }

                $link = $this->normalizeActionLink($action);
                if ( null !== $link ) {
                    $link['source'] = 'reaction';
                    $this->appendPrototypeLinkContext($link, $reaction, $action);
                    return $link;
                }
            }
        }

        $transition = $this->readString($node, array('transitionNodeID', 'transitionNodeId'))
            ?? $this->readGuidId($node['transitionNodeID'] ?? null);
        if ( null !== $transition && '' !== $transition ) {
            return array('type' => 'node', 'target_node_id' => $transition, 'source' => 'transition');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>|null
     */
    private function normalizeActionLink(array $action): ?array
    {
        // REST uses `type`/`url`/`destinationId`/`navigation`; the Kiwi
        // `PrototypeAction` uses `connectionType` (URL/INTERNAL_NODE),
        // `connectionURL`, the `transitionNodeID` GUID, and `navigationType`.
        // Read both so the link path works from `.fig` and REST inputs (#328).
        $type = strtoupper((string) ($action['type'] ?? ''));
        $connectionType = strtoupper((string) ($action['connectionType'] ?? ''));
        $url = $this->readString($action, array('url', 'href', 'connectionURL'));
        if ( ( 'URL' === $type || 'URL' === $connectionType ) && null !== $url ) {
            $link = array('type' => 'url', 'url' => $url);
            $this->appendActionLinkMetadata($link, $action, $type, $connectionType, '');
            return $link;
        }

        $destination = $this->readString($action, array('destinationId', 'navigationDestinationId', 'transitionNodeID'))
            ?? $this->readGuidId($action['destinationId'] ?? ($action['transitionNodeID'] ?? null));
        $navigation = strtoupper((string) ($action['navigation'] ?? ($action['navigationType'] ?? '')));
        $navigatesToNode = 'NODE' === $type
            || 'INTERNAL_NODE' === $connectionType
            || in_array($navigation, array('NAVIGATE', 'OVERLAY', 'SWAP', 'SCROLL_TO'), true);
        if ( $navigatesToNode && null !== $destination && '' !== $destination ) {
            $link = array('type' => 'node', 'target_node_id' => $destination);
            $this->appendActionLinkMetadata($link, $action, $type, $connectionType, $navigation);
            return $link;
        }

        if ( null !== $url ) {
            $link = array('type' => 'url', 'url' => $url);
            $this->appendActionLinkMetadata($link, $action, $type, $connectionType, $navigation);
            return $link;
        }
        if ( null !== $destination && '' !== $destination ) {
            $link = array('type' => 'node', 'target_node_id' => $destination);
            $this->appendActionLinkMetadata($link, $action, $type, $connectionType, $navigation);
            return $link;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $link
     * @param array<string, mixed> $reaction
     * @param array<string, mixed> $action
     */
    private function appendPrototypeLinkContext(array &$link, array $reaction, array $action): void
    {
        $event = is_array($reaction['event'] ?? null) ? $reaction['event'] : array();
        $eventType = $this->readString($event, array('interactionType', 'type'));
        if ( null !== $eventType ) {
            $link['prototype_event'] = strtoupper($eventType);
        }

        if ( isset($reaction['id']) && is_scalar($reaction['id']) && '' !== (string) $reaction['id'] ) {
            $link['prototype_interaction_id'] = (string) $reaction['id'];
        }

        $this->appendPrototypeMetadataFields($link, $action);
    }

    /**
     * @param array<string, mixed> $link
     * @param array<string, mixed> $action
     */
    private function appendActionLinkMetadata(array &$link, array $action, string $type, string $connectionType, string $navigation): void
    {
        if ( '' !== $type ) {
            $link['prototype_action_type'] = $type;
        }
        if ( '' !== $connectionType ) {
            $link['prototype_connection_type'] = $connectionType;
        }
        if ( '' !== $navigation ) {
            $link['prototype_navigation_type'] = $navigation;
        }

        $this->appendPrototypeMetadataFields($link, $action);
    }

    /**
     * @param array<string, mixed> $link
     * @param array<string, mixed> $action
     */
    private function appendPrototypeMetadataFields(array &$link, array $action): void
    {
        foreach ( array('overlayPositionType', 'overlayBackgroundInteraction', 'urlTarget') as $field ) {
            if ( isset($action[$field]) && is_scalar($action[$field]) && '' !== (string) $action[$field] ) {
                $link['prototype_' . $this->camelToSnake($field)] = strtoupper((string) $action[$field]);
            }
        }

        foreach ( array('preserveScrollPosition', 'resetScrollPosition', 'resetVideoPosition', 'openUrlInNewTab') as $field ) {
            if ( isset($action[$field]) && is_bool($action[$field]) ) {
                $link['prototype_' . $this->camelToSnake($field)] = $action[$field];
            }
        }

        if ( isset($action['overlayRelativePosition']) && is_array($action['overlayRelativePosition']) ) {
            $link['prototype_overlay_relative_position'] = $action['overlayRelativePosition'];
        }
        if ( isset($action['overlayBackground']) && is_array($action['overlayBackground']) ) {
            $link['prototype_overlay_background'] = $action['overlayBackground'];
        }
    }

    private function camelToSnake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function normalizePaintCollections(array $node, string $nodeId, array &$diagnostics, array $paintStyles = array()): array
    {
        return $this->paintNormalizer->normalizePaintCollections($node, $nodeId, $diagnostics, $paintStyles);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeVisualBox(array $node): array
    {
        $box = array();

        if ( isset($node['opacity']) && is_numeric($node['opacity']) ) {
            $box['opacity'] = (float) $node['opacity'];
        }

        if ( isset($node['blendMode']) && is_scalar($node['blendMode']) ) {
            $box['blend_mode'] = strtoupper((string) $node['blendMode']);
        }

        foreach ( array('rotation' => 'rotation') as $sourceKey => $targetKey ) {
            if ( isset($node[$sourceKey]) && is_numeric($node[$sourceKey]) ) {
                $box[$targetKey] = (float) $node[$sourceKey];
            }
        }

        foreach ( array('relativeTransform', 'absoluteTransform', 'transform') as $sourceKey ) {
            if ( is_array($node[$sourceKey] ?? null) ) {
                $box['transform'] = $node[$sourceKey];
                break;
            }
        }

        foreach ( array('transformOrigin', 'rotationOrigin') as $originKey ) {
            if ( ! is_array($node[$originKey] ?? null) ) {
                continue;
            }
            $originX = $node[$originKey]['x'] ?? $node[$originKey][0] ?? null;
            $originY = $node[$originKey]['y'] ?? $node[$originKey][1] ?? null;
            if ( is_numeric($originX) && is_numeric($originY) ) {
                $box['transform_origin'] = array('x' => (float) $originX, 'y' => (float) $originY, 'source' => $originKey);
                break;
            }
        }

        $uniformRadius = null;
        if ( isset($node['cornerRadius']) && is_numeric($node['cornerRadius']) ) {
            $uniformRadius = (float) $node['cornerRadius'];
        }

        if ( array_key_exists('rectangleCornerRadiiIndependent', $node) ) {
            $box['corner_radii_independent'] = (bool) $node['rectangleCornerRadiiIndependent'];
        }

        // Per-corner radii arrive under REST API names (`topLeftRadius`) from
        // remote scenegraphs and under Kiwi names (`rectangleTopLeftCornerRadius`)
        // from decoded `.fig` archives. Read the REST name first and fall back to
        // the Kiwi name so mixed-radius nodes survive both ingestion paths.
        $perCorner = array();
        foreach ( array(
            'top_left_radius'     => array('topLeftRadius', 'rectangleTopLeftCornerRadius'),
            'top_right_radius'    => array('topRightRadius', 'rectangleTopRightCornerRadius'),
            'bottom_right_radius' => array('bottomRightRadius', 'rectangleBottomRightCornerRadius'),
            'bottom_left_radius'  => array('bottomLeftRadius', 'rectangleBottomLeftCornerRadius'),
        ) as $targetKey => $sourceKeys ) {
            foreach ( $sourceKeys as $sourceKey ) {
                if ( isset($node[$sourceKey]) && is_numeric($node[$sourceKey]) ) {
                    $perCorner[$targetKey] = (float) $node[$sourceKey];
                    break;
                }
            }
        }

        if ( ! empty($perCorner) ) {
            // Per-corner values override the uniform radius when present. Fill any
            // corner the source left unset from the uniform radius so partial
            // per-corner data still describes the full shape.
            foreach ( array('top_left_radius', 'top_right_radius', 'bottom_right_radius', 'bottom_left_radius') as $targetKey ) {
                if ( ! isset($perCorner[$targetKey]) && null !== $uniformRadius ) {
                    $perCorner[$targetKey] = $uniformRadius;
                }
                if ( isset($perCorner[$targetKey]) ) {
                    $box[$targetKey] = $perCorner[$targetKey];
                }
            }
        } elseif ( null !== $uniformRadius ) {
            $box['corner_radius'] = $uniformRadius;
        }

        return $box;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function normalizeEffects(array $node, string $nodeId, array &$diagnostics): array
    {
        $effectStyleId = $this->readGuidId($node['styleIdForEffect'] ?? null);
        if ( ! is_array($node['effects'] ?? null) ) {
            if ( null !== $effectStyleId ) {
                $this->appendMissingEffectStyleDiagnostic($diagnostics, $nodeId, $effectStyleId);
            }
            return array();
        }

        $effects = array();
        foreach ( $node['effects'] as $effect ) {
            if ( ! is_array($effect) || false === ($effect['visible'] ?? true) ) {
                continue;
            }

            $type = strtoupper((string) ($effect['type'] ?? 'UNKNOWN'));
            if ( in_array($type, array('DROP_SHADOW', 'INNER_SHADOW'), true) ) {
                $normalized = array(
                    'type' => 'DROP_SHADOW' === $type ? 'drop_shadow' : 'inner_shadow',
                    'source_type' => $type,
                    'offset_x' => is_numeric($effect['offset']['x'] ?? null) ? (float) $effect['offset']['x'] : 0.0,
                    'offset_y' => is_numeric($effect['offset']['y'] ?? null) ? (float) $effect['offset']['y'] : 0.0,
                    'radius' => is_numeric($effect['radius'] ?? null) ? (float) $effect['radius'] : 0.0,
                    'spread' => is_numeric($effect['spread'] ?? null) ? (float) $effect['spread'] : 0.0,
                );
                if ( array_key_exists('visible', $effect) ) {
                    $normalized['visible'] = true === $effect['visible'];
                }
                $color = $this->normalizeColor($effect['color'] ?? null);
                if ( null !== $color ) {
                    $normalized['color'] = $color;
                }
                if ( isset($effect['opacity']) && is_numeric($effect['opacity']) ) {
                    $normalized['opacity'] = (float) $effect['opacity'];
                }
                if ( isset($effect['blendMode']) && is_scalar($effect['blendMode']) ) {
                    $normalized['blend_mode'] = strtoupper((string) $effect['blendMode']);
                }
                if ( array_key_exists('showShadowBehindNode', $effect) ) {
                    $normalized['show_shadow_behind_node'] = true === $effect['showShadowBehindNode'];
                }
                $effects[] = $normalized;
                continue;
            }

            // The decoded Kiwi enum names layer blur `FOREGROUND_BLUR`; the REST
            // shape calls the same effect `LAYER_BLUR`. Bridge both onto the
            // emitter's `layer_blur` (→ `filter:blur()`) branch (#328).
            if ( in_array($type, array('LAYER_BLUR', 'FOREGROUND_BLUR', 'BACKGROUND_BLUR'), true) ) {
                $normalized = array(
                    'type' => 'BACKGROUND_BLUR' === $type ? 'background_blur' : 'layer_blur',
                    'source_type' => $type,
                    'radius' => is_numeric($effect['radius'] ?? null) ? (float) $effect['radius'] : 0.0,
                );
                if ( array_key_exists('visible', $effect) ) {
                    $normalized['visible'] = true === $effect['visible'];
                }
                if ( isset($effect['opacity']) && is_numeric($effect['opacity']) ) {
                    $normalized['opacity'] = (float) $effect['opacity'];
                }
                if ( isset($effect['blendMode']) && is_scalar($effect['blendMode']) ) {
                    $normalized['blend_mode'] = strtoupper((string) $effect['blendMode']);
                }
                $effects[] = $normalized;
                continue;
            }

            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => 'unsupported_figma_effect_type',
                'message'  => 'Unsupported Figma effect was omitted from static CSS.',
                'context'  => array(
                    'node_id' => $nodeId,
                    'type'    => $type,
                ),
            );
        }

        return $effects;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function appendMissingEffectStyleDiagnostic(array &$diagnostics, string $nodeId, string $styleId): void
    {
        foreach ( $diagnostics as $diagnostic ) {
            if ( 'figma_missing_effect_style_reference' !== ($diagnostic['code'] ?? null) || ! is_array($diagnostic['context'] ?? null) ) {
                continue;
            }

            $context = $diagnostic['context'];
            if ( $nodeId === ($context['node_id'] ?? null) && $styleId === ($context['style_id'] ?? null) ) {
                return;
            }
        }

        $diagnostics[] = array(
            'severity' => 'warning',
            'code'     => 'figma_missing_effect_style_reference',
            'message'  => 'Figma node references an effect style but the decoded source graph does not include resolvable effect values.',
            'source'   => 'ScenegraphNormalizer',
            'context'  => array(
                'node_id'  => $nodeId,
                'style_id' => $styleId,
            ),
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeMask(array $node): array
    {
        $isMask = $this->normalizeBoolean($node['isMask'] ?? $node['mask'] ?? null);
        $maskType = isset($node['maskType']) && is_scalar($node['maskType']) ? strtoupper((string) $node['maskType']) : null;
        $frameMaskDisabled = $this->normalizeBoolean($node['frameMaskDisabled'] ?? null);
        $isClip = $this->normalizeBoolean($node['isClip'] ?? null);

        if ( null === $isMask && null === $maskType && null === $frameMaskDisabled && null === $isClip ) {
            return array();
        }

        return array_filter(
            array(
                'is_mask'             => $isMask,
                'type'                => $maskType,
                'frame_mask_disabled' => $frameMaskDisabled,
                'is_clip'             => $isClip,
            ),
            static fn (mixed $value): bool => null !== $value
        );
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        if ( is_bool($value) ) {
            return $value;
        }

        if ( is_int($value) || is_float($value) ) {
            return 0 !== (int) $value;
        }

        if ( is_string($value) ) {
            return match ( strtolower($value) ) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => null,
            };
        }

        return null;
    }

    /**
     * @return array<string, float>|null
     */
    private function normalizeColor(mixed $value): ?array
    {
        if ( ! is_array($value) ) {
            return null;
        }

        $red = $this->normalizeColorChannel($value['r'] ?? $value['red'] ?? null);
        $green = $this->normalizeColorChannel($value['g'] ?? $value['green'] ?? null);
        $blue = $this->normalizeColorChannel($value['b'] ?? $value['blue'] ?? null);
        if ( null === $red || null === $green || null === $blue ) {
            return null;
        }

        $color = array('r' => $red, 'g' => $green, 'b' => $blue);
        if ( isset($value['a']) && is_numeric($value['a']) ) {
            $color['a'] = (float) $value['a'];
        }

        return $color;
    }

    private function normalizeColorChannel(mixed $value): ?float
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $channel = (float) $value;
        if ( $channel > 1 ) {
            $channel /= 255;
        }

        return max(0, min(1, $channel));
    }

    /**
     * @param array<int, string> $topLevelIds
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, string>
     */
    private function selectTopLevelFrameIds(array $topLevelIds, array $nodeMap): array
    {
        $frameIds = array();

        foreach ( $topLevelIds as $id ) {
            $type = strtoupper((string) ($nodeMap[$id]['type'] ?? ''));
            if ( in_array($type, array('FRAME', 'COMPONENT', 'INSTANCE'), true) ) {
                $frameIds[] = $id;
            }
        }

        if ( ! empty($frameIds) ) {
            return $frameIds;
        }

        foreach ( $topLevelIds as $id ) {
            foreach ( $this->collectFrameDescendantIds($nodeMap[$id]['children'] ?? array()) as $frameId ) {
                $frameIds[] = $frameId;
            }
        }

        return $frameIds;
    }

    /**
     * @param mixed $children
     * @return array<int, string>
     */
    private function collectFrameDescendantIds(mixed $children): array
    {
        if ( ! is_array($children) ) {
            return array();
        }

        $frameIds = array();
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $type = strtoupper((string) ($child['type'] ?? ''));
            if ( in_array($type, array('DOCUMENT', 'CANVAS'), true) ) {
                if ( 'CANVAS' === $type && (false === ($child['visible'] ?? true) || true === ($child['internalOnly'] ?? false)) ) {
                    continue;
                }

                foreach ( $this->collectFrameDescendantIds($child['children'] ?? array()) as $frameId ) {
                    $frameIds[] = $frameId;
                }
                continue;
            }

            if ( in_array($type, array('FRAME', 'COMPONENT', 'INSTANCE'), true) ) {
                $frameIds[] = (string) ($child['id'] ?? '');
                continue;
            }
        }

        return array_values(array_filter(array_unique($frameIds)));
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @param array<string, array<int, string>> $childrenIndex
     * @return array<string, array<string, mixed>>
     */
    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeLayoutBox(array $node): array
    {
        return $this->layoutNormalizer->normalizeLayoutBox($node);
    }

    /**
     * @param array<string, mixed> $node
     * @return array{x?: float, y?: float, _geometry_provenance?: string}
     */
    private function layoutBoxFromTransform(array $node): array
    {
        foreach ( array(
            'relativeTransform' => GeometryBox::SOURCE_TRANSFORM,
            'transform'         => GeometryBox::SOURCE_TRANSFORM,
            'absoluteTransform' => GeometryBox::SOURCE_ABSOLUTE_TRANSFORM,
        ) as $sourceKey => $sourceKind ) {
            if ( ! is_array($node[$sourceKey] ?? null) ) {
                continue;
            }

            $box = array(GeometryBox::PROVENANCE_KEY => $sourceKind);
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( isset($node[$sourceKey][$source]) && is_numeric($node[$sourceKey][$source]) ) {
                    $box[$target] = (float) $node[$sourceKey][$source];
                }
            }

            if ( isset($box['x']) || isset($box['y']) ) {
                return $box;
            }
        }

        return array();
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function normalizeLayout(array $node): array
    {
        return $this->layoutNormalizer->normalizeLayout($node);
    }

    /**
     * Normalize REST `*AxisSizingMode` and Kiwi `stack*Sizing` enum tokens onto a
     * single HUG/FILL/FIXED vocabulary the HTML emitter understands. REST uses
     * FIXED/AUTO, the .fig Kiwi `StackSize` enum uses FIXED/RESIZE_TO_FIT
     * (RESIZE_TO_FIT == HUG / resize-to-fit-content).
     */
    private function normalizeAxisSizingValue(string $value): string
    {
        return match ( strtoupper($value) ) {
            'AUTO', 'HUG', 'RESIZE_TO_FIT', 'RESIZE_TO_FIT_WITH_IMPLICIT_SIZE' => 'HUG',
            'FILL', 'STRETCH' => 'FILL',
            default => 'FIXED',
        };
    }

    /**
     * Translate a Kiwi `horizontalConstraint`/`verticalConstraint` enum token onto
     * the REST `constraints` vocabulary the emitter pins against. The Kiwi
     * `ConstraintType` enum is MIN/CENTER/MAX/STRETCH/SCALE; STRETCH is the
     * both-side pin (REST LEFT_RIGHT/TOP_BOTTOM), MIN is the near edge (LEFT/TOP),
     * MAX is the far edge (RIGHT/BOTTOM).
     */
    private function normalizeKiwiConstraint(string $value, string $axis): ?string
    {
        $isHorizontal = 'horizontal' === $axis;

        return match ( strtoupper($value) ) {
            'MIN' => $isHorizontal ? 'LEFT' : 'TOP',
            'MAX' => $isHorizontal ? 'RIGHT' : 'BOTTOM',
            'STRETCH' => $isHorizontal ? 'LEFT_RIGHT' : 'TOP_BOTTOM',
            'CENTER' => 'CENTER',
            'SCALE' => 'SCALE',
            default => null,
        };
    }

    private function cssAxisAlignment(string $alignment): ?string
    {
        return match ( strtoupper($alignment) ) {
            'MIN' => 'flex-start',
            'CENTER' => 'center',
            'MAX' => 'flex-end',
            'SPACE_BETWEEN' => 'space-between',
            'SPACE_EVENLY' => 'space-between',
            'BASELINE' => 'baseline',
            'STRETCH' => 'stretch',
            default => null,
        };
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, array<string, string>>
     */
    private function buildTextInventory(array $nodeMap): array
    {
        $inventory = array();

        foreach ( $nodeMap as $id => $node ) {
            if ( 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
                continue;
            }

            $text = null;
            foreach ( array('characters', 'text') as $key ) {
                if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                    $text = (string) $node[$key];
                    break;
                }
            }
            if ( null === $text && isset($node['textData']['characters']) && is_scalar($node['textData']['characters']) ) {
                $text = (string) $node['textData']['characters'];
            }
            if ( null === $text && isset($node['name']) && is_scalar($node['name']) ) {
                $text = (string) $node['name'];
            }

            $inventory[] = array(
                'id'   => $id,
                'name' => (string) ($node['name'] ?? ''),
                'text' => $text ?? '',
            );
        }

        return $inventory;
    }

    /**
     * @param array<string, array<string, mixed>> $nodeMap
     * @return array<int, array<string, string>>
     */
    private function buildAssetReferences(array $nodeMap): array
    {
        $references = array();

        foreach ( $nodeMap as $id => $node ) {
            foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef') as $assetKey ) {
                if ( isset($node[$assetKey]) && is_scalar($node[$assetKey]) && '' !== (string) $node[$assetKey] ) {
                    $references[] = array(
                        'node_id' => $id,
                        'paint'   => $assetKey,
                        'ref'     => (string) $node[$assetKey],
                    );
                    break;
                }
            }

            foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
                $paintCollections = array();
                if ( is_array($node[$paintKey] ?? null) ) {
                    $paintCollections[] = $node[$paintKey];
                }
                if ( is_array($node['figma_paints'][$paintKey] ?? null) ) {
                    $paintCollections[] = $node['figma_paints'][$paintKey];
                }

                foreach ( $paintCollections as $paints ) {
                    foreach ( $paints as $paint ) {
                    if ( ! is_array($paint) || 'IMAGE' !== strtoupper((string) ($paint['type'] ?? '')) ) {
                        continue;
                    }

                    foreach ( $this->readImageReferences($paint) as $reference ) {
                        $references[] = array(
                            'node_id'    => $id,
                            'paint'      => $paintKey,
                            'source_key' => $reference['source_key'],
                            'ref'        => $reference['ref'],
                        );
                    }
                }
                }
            }
        }

        $unique = array();
        foreach ( $references as $reference ) {
            $key = (string) ($reference['node_id'] ?? '') . '|' . (string) ($reference['paint'] ?? '') . '|' . (string) ($reference['ref'] ?? '');
            $unique[$key] = $reference;
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $paint
     * @return array<int, array{source_key: string, ref: string}>
     */
    private function readImageReferences(array $paint): array
    {
        $references = array();
        foreach ( array('ref', 'imageRef', 'imageHash', 'asset_id', 'image_ref') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                $references[] = array(
                    'source_key' => $key,
                    'ref'        => (string) $paint[$key],
                );
            }
        }

        if ( is_array($paint['assetRef'] ?? null) ) {
            foreach ( $this->readAssetRefReferences($paint['assetRef'], 'assetRef') as $reference ) {
                $references[] = $reference;
            }
        }

        foreach ( array('image', 'thumbnail', 'imageThumbnail', 'sourceImage') as $imageKey ) {
            if ( ! is_array($paint[$imageKey] ?? null) ) {
                continue;
            }
            if ( isset($paint[$imageKey]['hash']) && is_scalar($paint[$imageKey]['hash']) && '' !== (string) $paint[$imageKey]['hash'] ) {
                $references[] = array(
                    'source_key' => $imageKey . '.hash',
                    'ref'        => (string) $paint[$imageKey]['hash'],
                );
            }
            if ( is_array($paint[$imageKey]['assetRef'] ?? null) ) {
                foreach ( $this->readAssetRefReferences($paint[$imageKey]['assetRef'], $imageKey . '.assetRef') as $reference ) {
                    $references[] = $reference;
                }
            }
            if ( is_array($paint[$imageKey]['sourceImage'] ?? null) && isset($paint[$imageKey]['sourceImage']['hash']) && is_scalar($paint[$imageKey]['sourceImage']['hash']) && '' !== (string) $paint[$imageKey]['sourceImage']['hash'] ) {
                $references[] = array(
                    'source_key' => $imageKey . '.sourceImage.hash',
                    'ref'        => (string) $paint[$imageKey]['sourceImage']['hash'],
                );
            }
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $assetRef
     * @return array<int, array{source_key: string, ref: string}>
     */
    private function readAssetRefReferences(array $assetRef, string $prefix): array
    {
        $references = array();
        foreach ( array('id', 'key', 'nodeID', 'fileKey', 'libraryKey', 'publishID', 'sourceLibraryKey') as $assetKey ) {
            if ( isset($assetRef[$assetKey]) && is_scalar($assetRef[$assetKey]) && '' !== (string) $assetRef[$assetKey] ) {
                $references[] = array(
                    'source_key' => $prefix . '.' . $assetKey,
                    'ref'        => (string) $assetRef[$assetKey],
                );
            }
        }
        if ( is_array($assetRef['guid'] ?? null) && isset($assetRef['guid']['sessionID'], $assetRef['guid']['localID']) ) {
            $references[] = array(
                'source_key' => $prefix . '.guid',
                'ref'        => (string) $assetRef['guid']['sessionID'] . ':' . (string) $assetRef['guid']['localID'],
            );
        } elseif ( isset($assetRef['guid']) && is_scalar($assetRef['guid']) && '' !== (string) $assetRef['guid'] ) {
            $references[] = array(
                'source_key' => $prefix . '.guid',
                'ref'        => (string) $assetRef['guid'],
            );
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array<string, mixed>> $renderNodes
     */
    private function readSourceName(array $source, array $renderNodes): string
    {
        if ( isset($source['name']) && is_scalar($source['name']) && '' !== (string) $source['name'] ) {
            return (string) $source['name'];
        }

        if ( isset($renderNodes[0]['name']) && is_scalar($renderNodes[0]['name']) && '' !== (string) $renderNodes[0]['name'] ) {
            return (string) $renderNodes[0]['name'];
        }

        return 'Figma Site';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function detectInputShape(array $source): string
    {
        foreach ( array('NODE_CHANGES', 'node_changes', 'nodeChanges', 'document', 'nodes') as $key ) {
            if ( isset($source[$key]) ) {
                return $key;
            }
        }

        return 'unknown';
    }
}
