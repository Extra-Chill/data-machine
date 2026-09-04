<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\FigFile;

/**
 * Decodes the Kiwi schema/message pair embedded in modern canvas.fig files.
 */
final class FigKiwiDecoder
{
    private const KINDS = array('ENUM', 'STRUCT', 'MESSAGE');
    private const INVENTORY_SAMPLE_LIMIT = 3;
    private const INVENTORY_SAMPLE_STRING_BYTES = 120;
    private const INVENTORY_SAMPLE_ARRAY_ITEMS = 8;
    private const INVENTORY_DECODED_FIELD_NAMES = array(
        'colorVar' => true,
        'stopsVar' => true,
        'gradientInterpolation' => true,
        'interpolation' => true,
        'interpolationMode' => true,
        'colorInterpolation' => true,
        'colorSpace' => true,
        'interpolationColorSpace' => true,
    );

    private FigKiwiDecodePolicy $decodePolicy;
    private FigKiwiSchemaFields $schemaFields;

    /**
     * @var array<string, array<string, int>>
     */
    private array $allowedFieldCache = array();

    public function __construct(?FigKiwiDecodePolicy $decodePolicy = null, ?FigKiwiSchemaFields $schemaFields = null)
    {
        $this->decodePolicy = $decodePolicy ?? new FigKiwiDecodePolicy();
        $this->schemaFields = $schemaFields ?? new FigKiwiSchemaFields();
    }

    /**
     * @return array{schema: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function decodeSchema(string $payload): array
    {
        try {
            $reader = new FigKiwiByteReader($payload);
            $definitions = array();
            $definitionCount = $reader->readVarUint();

            for ( $i = 0; $i < $definitionCount; $i++ ) {
                $kindIndex = null;
                $definition = array(
                    'name'   => $reader->readString(),
                    'kind'   => null,
                    'fields' => array(),
                );
                $kindIndex = $reader->readByte();
                $definition['kind'] = self::KINDS[$kindIndex] ?? 'UNKNOWN';
                $fieldCount = $reader->readVarUint();

                for ( $j = 0; $j < $fieldCount; $j++ ) {
                    $definition['fields'][] = array(
                        'name'          => $reader->readString(),
                        'type'          => $reader->readVarInt(),
                        'is_array'      => 1 === ($reader->readByte() & 1),
                        'is_deprecated' => false,
                        'value'         => $reader->readVarUint(),
                    );
                }

                $definitions[] = $definition;
            }

            foreach ( $definitions as $definitionIndex => $definition ) {
                foreach ( $definition['fields'] as $fieldIndex => $field ) {
                    $type = $field['type'];
                    if ( null !== $type && $type < 0 ) {
                        $definitions[$definitionIndex]['fields'][$fieldIndex]['type'] = FigKiwiSchemaFields::PRIMITIVE_TYPES[~$type] ?? null;
                    } elseif ( null !== $type ) {
                        $definitions[$definitionIndex]['fields'][$fieldIndex]['type'] = $definitions[$type]['name'] ?? null;
                    }
                }
            }

            return array('schema' => array('definitions' => $definitions), 'diagnostics' => array());
        } catch ( \Throwable $throwable ) {
            return array(
                'schema'      => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_schema_decode_failed', 'Kiwi schema chunk could not be decoded.', $throwable->getMessage())),
            );
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{message: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function decodeMessage(string $payload, array $schema, string $rootType = 'Message'): array
    {
        try {
            $definitions = $this->schemaFields->definitionsByName($schema);
            if ( ! isset($definitions[$rootType]) ) {
                return array('message' => null, 'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_schema_missing', 'Kiwi schema does not define the expected root message.', $rootType)));
            }

            $message = $this->decodeDefinition(new FigKiwiByteReader($payload), $definitions[$rootType], $definitions);
            return array('message' => is_array($message) ? $message : null, 'diagnostics' => array());
        } catch ( \Throwable $throwable ) {
            return array(
                'message'     => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_decode_failed', 'Kiwi message chunk could not be decoded.', $throwable->getMessage())),
            );
        }
    }

    /**
     * Decode only fields needed to build a static scenegraph from production Kiwi messages.
     *
     * @param array<string, mixed> $schema
     * @return array{message: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function decodeMessageSelective(string $payload, array $schema, string $rootType = 'Message', array $fieldPolicy = array(), array $options = array()): array
    {
        try {
            $definitions = $this->schemaFields->definitionsByName($schema);
            if ( ! isset($definitions[$rootType]) ) {
                return array('message' => null, 'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_schema_missing', 'Kiwi schema does not define the expected root message.', $rootType)));
            }

            $policy = empty($fieldPolicy) ? $this->decodePolicy->defaultScenegraphFieldPolicy() : $fieldPolicy;
            $gate = $this->decodeNodeGateOptions($options);
            $message = $this->decodeDefinitionSelective(new FigKiwiByteReader($payload), $definitions[$rootType], $definitions, $policy, $gate);
            $diagnostics = array();
            if ( null !== $gate ) {
                $diagnostics[] = array(
                    'code'    => 'figma_transformer_kiwi_message_gate_selective_decode_used',
                    'message' => 'Kiwi message nodeChanges were filtered through a bounded gate plan during selective decode.',
                    'source'  => 'FigKiwiDecoder',
                    'context' => array(
                        'selected_node_count' => count($gate['selected_node_ids']),
                        'decoded_node_count'  => $gate['decoded_node_count'],
                        'retained_node_count' => $gate['retained_node_count'],
                        'skipped_node_count'  => $gate['skipped_node_count'],
                    ),
                );
            }
            return array('message' => is_array($message) ? $message : null, 'diagnostics' => $diagnostics);
        } catch ( \Throwable $throwable ) {
            return array(
                'message'     => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_decode_failed', 'Kiwi message chunk could not be selectively decoded.', $throwable->getMessage())),
            );
        }
    }

    /**
     * Inventory fields skipped by the selective scenegraph decoder without changing
     * the production decoded payload shape.
     *
     * @param array<string, mixed> $schema
     * @return array{inventory: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function inventorySkippedFieldsSelective(string $payload, array $schema, string $rootType = 'Message', array $fieldPolicy = array()): array
    {
        try {
            $definitions = $this->schemaFields->definitionsByName($schema);
            if ( ! isset($definitions[$rootType]) ) {
                return array('inventory' => null, 'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_schema_missing', 'Kiwi schema does not define the expected root message.', $rootType)));
            }

            $policy = empty($fieldPolicy) ? $this->decodePolicy->defaultScenegraphFieldPolicy() : $fieldPolicy;
            $inventory = array(
                'schema'             => 'blocks-engine/figma-transformer/kiwi-skipped-field-inventory/v1',
                'root_type'          => $rootType,
                'policy_groups'      => $this->decodePolicy->scenegraphFieldPolicyGroups(),
                'schema_definitions' => $this->schemaFields->schemaDefinitionInventory($schema),
                'fields'             => array(),
                'decoded_fields'     => array(),
            );
            $context = $this->decodePolicy->initialInventoryContext($rootType);

            $this->inventoryDefinitionSelective(new FigKiwiByteReader($payload), $definitions[$rootType], $definitions, $policy, $inventory, $context);
            $inventory['summary'] = $this->decodePolicy->summarizeSkippedFieldInventory($inventory['fields']);
            $inventory['decoded_summary'] = $this->decodePolicy->summarizeSkippedFieldInventory($inventory['decoded_fields']);
            return array('inventory' => $inventory, 'diagnostics' => array());
        } catch ( \Throwable $throwable ) {
            return array(
                'inventory'   => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_decode_failed', 'Kiwi message chunk could not be inventoried.', $throwable->getMessage())),
            );
        }
    }

    /**
     * Inspect the minimal raw Kiwi fields needed to decide page/frame/node gates
     * before materializing the full scenegraph payload.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $options
     * @return array{gate: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function inspectNodeGate(string $payload, array $schema, string $rootType = 'Message', array $options = array()): array
    {
        $policy = array(
            'Message' => array('type', 'nodeChanges'),
            'NodeChange' => array(
                'guid', 'type', 'name', 'parentIndex', 'sortPosition', 'visible',
                'componentId', 'mainComponentId', 'symbolData', 'detachedSymbolId', 'detachedSymbolID', 'overriddenSymbolId', 'overriddenSymbolID', 'derivedSymbolData', 'derivedSymbolDataLayoutVersion',
                'styleID', 'styleIdForText', 'styleIdForFill', 'styleIdForStrokeFill', 'styleIdForStroke', 'styleIdForEffect',
                'fillPaints', 'strokePaints', 'backgroundPaints', 'effects',
                'variableConsumptionMap', 'parameterConsumptionMap', 'variableDataValues', 'variableSetID',
            ),
            'GUID' => array('sessionID', 'localID'),
            'ParentIndex' => array('guid', 'position'),
            'SymbolData' => array('symbolID', 'symbolOverrides', 'symbolOverride', 'overrides'),
            'DerivedSymbolData' => array('symbolID', 'symbolOverrides', 'symbolOverride', 'overrides'),
            'SymbolOverride' => array('nodeId', 'node_id', 'nodeID', 'id', 'guid', 'nodeGuid', 'guidPath', 'styleID', 'styleIdForText', 'styleIdForFill', 'styleIdForStrokeFill', 'styleIdForStroke', 'styleIdForEffect', 'fillPaints', 'fills', 'strokes', 'strokePaints', 'effects', 'variableConsumptionMap', 'parameterConsumptionMap', 'variableDataValues'),
            'GUIDPath' => array('guids', 'guid'),
            'SymbolId' => array('guid'),
            'StyleId' => array('guid'),
            'Paint' => array('type', 'colorVar', 'stops', 'stopsVar', 'image', 'imageThumbnail', 'assetRef', 'sourceImage', 'hash', 'publishID', 'sourceLibraryKey', 'libraryKey'),
            'ColorStop' => array('colorVar'),
            'Image' => array('hash', 'assetRef', 'sourceImage', 'publishID', 'sourceLibraryKey', 'libraryKey'),
            'SourceImage' => array('hash', 'assetRef', 'publishID', 'sourceLibraryKey', 'libraryKey'),
            'AssetRef' => array('id', 'key', 'nodeID', 'fileKey', 'libraryKey', 'publishID', 'sourceLibraryKey', 'guid'),
            'Effect' => array('styleID'),
            'VariableDataMap' => array('entries'),
            'VariableDataMapEntry' => array('nodeField', 'variableData', 'variableField'),
            'VariableData' => array('value', 'dataType', 'resolvedDataType'),
            'VariableAnyValue' => array('alias', 'colorValue', 'symbolIdValue', 'textDataValue', 'vectorValue', 'linkValue', 'propRefValue'),
            'VariableID' => array('guid', 'assetRef'),
            'VariableOverrideId' => array('guid', 'assetRef'),
            'VariableDataValues' => array('entries'),
            'VariableDataValuesEntry' => array('modeID', 'variableData'),
            'VariableSetID' => array('guid', 'assetRef'),
        );
        $messageResult = $this->decodeMessageSelective($payload, $schema, $rootType, $policy);
        if ( null === $messageResult['message'] ) {
            return array('gate' => null, 'diagnostics' => $messageResult['diagnostics']);
        }

        $nodeChanges = is_array($messageResult['message']['nodeChanges'] ?? null) ? $messageResult['message']['nodeChanges'] : array();
        $nodes = array();
        $children = array();
        $pages = array();
        $frames = array();
        $missingIds = 0;
        $missingParents = 0;

        foreach ( $nodeChanges as $index => $node ) {
            if ( ! is_array($node) ) {
                continue;
            }

            $id = $this->readGateNodeId($node);
            if ( null === $id || '' === $id ) {
                $missingIds++;
                continue;
            }

            $parentId = $this->readGateParentId($node);
            if ( null === $parentId ) {
                $missingParents++;
            } else {
                $children[$parentId] ??= array();
                $children[$parentId][] = $id;
            }

            $type = isset($node['type']) && is_scalar($node['type']) ? (string) $node['type'] : '';
            $summary = array_filter(
                array(
                    'id' => $id,
                    'type' => '' !== $type ? $type : null,
                    'name' => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : null,
                    'parent_id' => $parentId,
                    'source_order' => is_int($index) ? $index : null,
                ),
                static fn (mixed $value): bool => null !== $value
            );
            $nodes[$id] = $summary;

            if ( 'CANVAS' === $type ) {
                $pages[] = $summary;
            } elseif ( 'FRAME' === $type ) {
                $frames[] = $summary;
            }
        }

        $selectedIds = $this->planGateNodeIds($nodes, $children, $pages, $options);
        $dependencyGraph = $this->buildGateDependencyGraph($nodeChanges, $nodes, $selectedIds);
        $blockers = array();
        if ( $missingIds > 0 ) {
            $blockers[] = 'NodeChange.guid is missing on at least one node; stable filtering cannot preserve identities.';
        }
        if ( count($nodes) > 0 && $missingParents === count($nodes) ) {
            $blockers[] = 'NodeChange.parentIndex.guid is absent on every decoded node; page/frame subtree filtering cannot be computed from raw Kiwi identity fields.';
        }

        return array(
            'gate' => array(
                'schema' => 'blocks-engine/figma-transformer/kiwi-node-gate/v1',
                'root_type' => $rootType,
                'required_raw_fields' => array(
                    'Message.nodeChanges',
                    'NodeChange.guid.sessionID',
                    'NodeChange.guid.localID',
                    'NodeChange.type',
                    'NodeChange.name',
                    'NodeChange.parentIndex.guid.sessionID',
                    'NodeChange.parentIndex.guid.localID',
                    'NodeChange.parentIndex.position',
                ),
                'options' => array_filter(
                    array(
                        'max_pages' => isset($options['max_pages']) && is_numeric($options['max_pages']) ? (int) $options['max_pages'] : null,
                        'max_nodes' => isset($options['max_nodes']) && is_numeric($options['max_nodes']) ? (int) $options['max_nodes'] : null,
                        'frame_id' => isset($options['frame_id']) && is_scalar($options['frame_id']) ? (string) $options['frame_id'] : null,
                        'frame_ids' => is_array($options['frame_ids'] ?? null) ? array_values(array_filter($options['frame_ids'], 'is_scalar')) : null,
                    ),
                    static fn (mixed $value): bool => null !== $value && array() !== $value
                ),
                'node_count' => count($nodes),
                'page_count' => count($pages),
                'frame_count' => count($frames),
                'missing_id_count' => $missingIds,
                'missing_parent_count' => $missingParents,
                'pages_sample' => array_slice($pages, 0, 25),
                'frames_sample' => array_slice($frames, 0, 25),
                'nodes_sample' => array_slice(array_values($nodes), 0, 25),
                'gate_plan' => array(
                    'feasible' => empty($blockers) && count($nodes) > 0,
                    'selected_node_count' => count($selectedIds),
                    'selected_node_ids' => $selectedIds,
                    'selected_node_ids_sample' => array_slice($selectedIds, 0, 50),
                    'blockers' => $blockers,
                ),
                'dependency_graph' => $dependencyGraph['graph'],
            ),
            'diagnostics' => array_merge($messageResult['diagnostics'], $dependencyGraph['diagnostics']),
        );
    }

    /**
     * @param array<int, mixed>                   $nodeChanges
     * @param array<string, array<string, mixed>> $nodes
     * @param array<int, string>                  $selectedIds
     * @return array{graph: array<string, mixed>, diagnostics: array<int, array<string, mixed>>}
     */
    private function buildGateDependencyGraph(array $nodeChanges, array $nodes, array $selectedIds): array
    {
        $selected = array_flip($selectedIds);
        $references = array(
            'component_node' => array(),
            'symbol_node'    => array(),
            'style'          => array(),
            'asset'          => array(),
            'variable'       => array(),
        );
        $derivedSymbolDataNodes = array();

        foreach ( $nodeChanges as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }

            $nodeId = $this->readGateNodeId($node);
            if ( null === $nodeId || ! isset($selected[$nodeId]) ) {
                continue;
            }

            if ( isset($node['derivedSymbolData']) ) {
                $derivedSymbolDataNodes[$nodeId] = $nodeId;
            }

            $this->collectGateDependencyReferences($node, $nodeId, array(), $references);
        }

        $nodeDependencies = $this->gateNodeDependencyReport($references, $nodes, $selected);
        $diagnostics = array();
        if ( ! empty($nodeDependencies['external_to_gate']) ) {
            $diagnostics[] = $this->diagnostic(
                'figma_transformer_kiwi_gate_selected_subtree_missing_node_dependencies',
                'Selected Kiwi gate subtree references component or symbol nodes outside the selected set.',
                array(
                    'external_reference_count' => count($nodeDependencies['external_to_gate']),
                    'references_sample'        => array_slice($nodeDependencies['external_to_gate'], 0, 25),
                )
            );
        }
        foreach ( array('asset', 'style', 'variable') as $kind ) {
            $ids = $this->uniqueGateDependencyReferenceIds($references[$kind]);
            if ( empty($ids) ) {
                continue;
            }
            $diagnostics[] = $this->diagnostic(
                'figma_transformer_kiwi_gate_selected_subtree_external_' . $kind . '_references',
                'Selected Kiwi gate subtree references external ' . $kind . ' dependencies that must be carried with the gated nodes.',
                array(
                    'reference_count' => count($references[$kind]),
                    'unique_reference_count' => count($ids),
                    'ids_sample' => array_slice($ids, 0, 25),
                )
            );
        }

        return array(
            'graph' => array(
                'schema' => 'blocks-engine/figma-transformer/kiwi-gate-dependency-graph/v1',
                'selected_node_count' => count($selectedIds),
                'reference_counts' => array(
                    'component_node' => count($references['component_node']),
                    'symbol_node' => count($references['symbol_node']),
                    'style' => count($references['style']),
                    'asset' => count($references['asset']),
                    'variable' => count($references['variable']),
                    'derived_symbol_data_node' => count($derivedSymbolDataNodes),
                ),
                'unique_reference_counts' => array(
                    'component_node' => count($this->uniqueGateDependencyReferenceIds($references['component_node'])),
                    'symbol_node' => count($this->uniqueGateDependencyReferenceIds($references['symbol_node'])),
                    'style' => count($this->uniqueGateDependencyReferenceIds($references['style'])),
                    'asset' => count($this->uniqueGateDependencyReferenceIds($references['asset'])),
                    'variable' => count($this->uniqueGateDependencyReferenceIds($references['variable'])),
                ),
                'references_sample' => array(
                    'component_node' => array_slice(array_values($references['component_node']), 0, 25),
                    'symbol_node' => array_slice(array_values($references['symbol_node']), 0, 25),
                    'style' => array_slice(array_values($references['style']), 0, 25),
                    'asset' => array_slice(array_values($references['asset']), 0, 25),
                    'variable' => array_slice(array_values($references['variable']), 0, 25),
                    'derived_symbol_data_node_ids' => array_slice(array_values($derivedSymbolDataNodes), 0, 25),
                ),
                'node_dependencies' => $nodeDependencies,
            ),
            'diagnostics' => $diagnostics,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @return array<int, string>
     */
    private function uniqueGateDependencyReferenceIds(array $references): array
    {
        $ids = array();
        foreach ( $references as $reference ) {
            $id = isset($reference['id']) && is_scalar($reference['id']) ? (string) $reference['id'] : '';
            if ( '' !== $id ) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<int|string, mixed>            $value
     * @param array<int, string>                  $path
     * @param array<string, array<string, mixed>> $references
     */
    private function collectGateDependencyReferences(mixed $value, string $sourceNodeId, array $path, array &$references): void
    {
        if ( ! is_array($value) ) {
            return;
        }

        foreach ( $value as $key => $child ) {
            $field = is_string($key) ? $key : '';
            $childPath = '' === $field ? $path : array_merge($path, array($field));
            $kind = $this->gateDependencyKind($field, $childPath);
            if ( null !== $kind ) {
                $referenceId = $this->readGateDependencyReferenceId($child);
                if ( null !== $referenceId ) {
                    $this->recordGateDependencyReference($references, $kind, $referenceId, $sourceNodeId, implode('.', $childPath));
                }
            }

            if ( is_array($child) ) {
                $this->collectGateDependencyReferences($child, $sourceNodeId, $childPath, $references);
            }
        }
    }

    /**
     * @param array<int, string> $path
     */
    private function gateDependencyKind(string $field, array $path): ?string
    {
        $normalized = strtolower($field);
        $pathText = strtolower(implode('.', $path));
        if ( in_array($normalized, array('componentid', 'maincomponentid', 'component', 'maincomponent'), true) ) {
            return 'component_node';
        }
        if ( in_array($normalized, array('symbolid', 'symbolidvalue', 'detachedsymbolid', 'overriddensymbolid'), true) ) {
            return 'symbol_node';
        }
        if ( str_contains($normalized, 'styleid') || 'styleid' === $normalized ) {
            return 'style';
        }
        if ( in_array($normalized, array('assetref', 'image', 'imagethumbnail', 'sourceimage', 'hash'), true) || str_contains($pathText, 'assetref') ) {
            return 'asset';
        }
        if ( str_contains($normalized, 'variable') || str_contains($normalized, 'colorvar') || str_contains($normalized, 'stopsvar') || in_array($normalized, array('alias', 'variablesetid', 'modeid'), true) ) {
            return 'variable';
        }

        return null;
    }

    private function readGateDependencyReferenceId(mixed $value): ?string
    {
        $guid = $this->readGateGuid($value);
        if ( null !== $guid ) {
            return $guid;
        }
        if ( is_array($value) ) {
            foreach ( array('id', 'key', 'nodeID', 'nodeId', 'node_id', 'hash', 'fileKey', 'libraryKey', 'publishID', 'sourceLibraryKey') as $key ) {
                if ( isset($value[$key]) && is_scalar($value[$key]) ) {
                    $id = $this->normalizeGateDependencyScalarId($value[$key]);
                    if ( null !== $id ) {
                        return $id;
                    }
                }
            }
            foreach ( array('guid', 'assetRef', 'alias') as $key ) {
                $nested = $this->readGateDependencyReferenceId($value[$key] ?? null);
                if ( null !== $nested ) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function normalizeGateDependencyScalarId(mixed $value): ?string
    {
        if ( ! is_scalar($value) ) {
            return null;
        }

        $id = (string) $value;
        if ( '' === $id || ! preg_match('/\A[\x20-\x7e]+\z/', $id) ) {
            return null;
        }

        return $id;
    }

    /**
     * @param array<string, array<string, mixed>> $references
     */
    private function recordGateDependencyReference(array &$references, string $kind, string $referenceId, string $sourceNodeId, string $path): void
    {
        $key = $kind . '|' . $referenceId . '|' . $sourceNodeId . '|' . $path;
        $references[$kind][$key] = array(
            'id' => $referenceId,
            'source_node_id' => $sourceNodeId,
            'path' => $path,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, int>                  $selected
     * @return array<string, mixed>
     */
    private function gateNodeDependencyReport(array $references, array $nodes, array $selected): array
    {
        $external = array();
        foreach ( array('component_node', 'symbol_node') as $kind ) {
            foreach ( $references[$kind] as $reference ) {
                $id = (string) ($reference['id'] ?? '');
                if ( '' === $id || isset($selected[$id]) ) {
                    continue;
                }
                $external[] = array(
                    'kind' => $kind,
                    'id' => $id,
                    'source_node_id' => (string) ($reference['source_node_id'] ?? ''),
                    'path' => (string) ($reference['path'] ?? ''),
                    'available_in_file' => isset($nodes[$id]),
                    'referenced_node' => isset($nodes[$id]) ? $nodes[$id] : null,
                );
            }
        }

        return array(
            'external_to_gate_count' => count($external),
            'external_to_gate' => array_slice($external, 0, 100),
        );
    }

    /**
     * @param array<string, mixed>                $definition
     * @param array<string, array<string, mixed>> $definitions
     */
    private function decodeDefinition(FigKiwiByteReader $reader, array $definition, array $definitions): array
    {
        $result = array();
        if ( 'MESSAGE' === ($definition['kind'] ?? null) ) {
            $fieldsByValue = $this->schemaFields->fieldsByValue($definition);

            while ( true ) {
                $fieldValue = $reader->readVarUint();
                if ( 0 === $fieldValue ) {
                    return $result;
                }
                if ( ! isset($fieldsByValue[$fieldValue]) ) {
                    throw new \RuntimeException('Attempted to parse invalid message field ' . $fieldValue . '.');
                }
                $this->decodeField($reader, $fieldsByValue[$fieldValue], $definitions, $result);
            }
        }

        foreach ( $this->schemaFields->fields($definition) as $field ) {
            $this->decodeField($reader, $field, $definitions, $result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed>                $definition
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     */
    private function decodeDefinitionSelective(FigKiwiByteReader $reader, array $definition, array $definitions, array $fieldPolicy, ?array &$gate = null): array
    {
        $result = array();
        $typeName = (string) ($definition['name'] ?? '');
        $allowed = $this->allowedFields($typeName, $fieldPolicy);

        if ( 'MESSAGE' === ($definition['kind'] ?? null) ) {
            $fieldsByValue = $this->schemaFields->fieldsByValue($definition);

            while ( true ) {
                $fieldValue = $reader->readVarUint();
                if ( 0 === $fieldValue ) {
                    return $result;
                }
                if ( ! isset($fieldsByValue[$fieldValue]) ) {
                    throw new \RuntimeException('Attempted to parse invalid message field ' . $fieldValue . '.');
                }

                $field = $fieldsByValue[$fieldValue];
                $fieldName = $this->schemaFields->fieldName($field);
                if ( isset($allowed[$fieldName]) ) {
                    $this->decodeFieldSelective($reader, $field, $definitions, $fieldPolicy, $result, $typeName, $gate);
                } else {
                    $this->skipField($reader, $field, $definitions);
                }
            }
        }

        foreach ( $this->schemaFields->fields($definition) as $field ) {
            $fieldName = $this->schemaFields->fieldName($field);
            if ( isset($allowed[$fieldName]) ) {
                $this->decodeFieldSelective($reader, $field, $definitions, $fieldPolicy, $result, $typeName, $gate);
            } else {
                $this->skipField($reader, $field, $definitions);
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<int, string>> $fieldPolicy
     * @return array<string, int>
     */
    private function allowedFields(string $typeName, array $fieldPolicy): array
    {
        $fieldNames = $fieldPolicy[$typeName] ?? array();
        $cacheKey = $typeName . '|' . implode(',', $fieldNames);
        if ( isset($this->allowedFieldCache[$cacheKey]) ) {
            return $this->allowedFieldCache[$cacheKey];
        }

        $this->allowedFieldCache[$cacheKey] = array_flip($fieldNames);
        return $this->allowedFieldCache[$cacheKey];
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     * @param array<string, mixed>                $result
     */
    private function decodeFieldSelective(FigKiwiByteReader $reader, array $field, array $definitions, array $fieldPolicy, array &$result, string $parentType = '', ?array &$gate = null): void
    {
        $fieldName = $this->schemaFields->fieldName($field);
        $type = $this->schemaFields->fieldType($field);
        if ( $this->schemaFields->isArrayField($field) ) {
            if ( 'byte' === $type ) {
                $value = $reader->readByteArray();
            } else {
                $length = $reader->readVarUint();
                $value = array();
                for ( $i = 0; $i < $length; $i++ ) {
                    $item = $this->decodeValueSelective($reader, $type, $definitions, $fieldPolicy, $gate);
                    if ( $this->shouldRetainDecodedArrayItem($parentType, $fieldName, $type, $item, $gate) ) {
                        $value[] = $item;
                    }
                }
            }
        } else {
            $value = $this->decodeValueSelective($reader, $type, $definitions, $fieldPolicy, $gate);
        }

        if ( ! $this->schemaFields->isDeprecatedField($field) && isset($field['name']) ) {
            $result[$fieldName] = $value;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     */
    private function decodeValueSelective(FigKiwiByteReader $reader, string $type, array $definitions, array $fieldPolicy, ?array &$gate = null): mixed
    {
        return match ( $type ) {
            'bool' => 0 !== $reader->readByte(),
            'byte' => $reader->readByte(),
            'int' => $reader->readVarInt(),
            'uint' => $reader->readVarUint(),
            'float' => $reader->readVarFloat(),
            'string' => $reader->readString(),
            'int64' => $reader->readVarInt64(),
            'uint64' => $reader->readVarUint64(),
            default => $this->decodeNamedValueSelective($reader, $type, $definitions, $fieldPolicy, $gate),
        };
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     */
    private function decodeNamedValueSelective(FigKiwiByteReader $reader, string $type, array $definitions, array $fieldPolicy, ?array &$gate = null): mixed
    {
        $definition = $definitions[$type] ?? null;
        if ( ! is_array($definition) ) {
            throw new \RuntimeException('Invalid Kiwi type ' . $type . '.');
        }

        if ( 'ENUM' === ($definition['kind'] ?? null) ) {
            $value = $reader->readVarUint();
            foreach ( $definition['fields'] ?? array() as $field ) {
                if ( is_array($field) && (int) ($field['value'] ?? -1) === $value ) {
                    return (string) ($field['name'] ?? $value);
                }
            }
            return $value;
        }

        return $this->decodeDefinitionSelective($reader, $definition, $definitions, $fieldPolicy, $gate);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{selected_node_ids: array<string, true>, decoded_node_count: int, retained_node_count: int, skipped_node_count: int}|null
     */
    private function decodeNodeGateOptions(array $options): ?array
    {
        if ( ! is_array($options['selected_node_ids'] ?? null) ) {
            return null;
        }

        $selected = array();
        foreach ( $options['selected_node_ids'] as $id ) {
            if ( is_scalar($id) && '' !== (string) $id ) {
                $selected[(string) $id] = true;
            }
        }

        if ( empty($selected) ) {
            return null;
        }

        return array(
            'selected_node_ids' => $selected,
            'decoded_node_count' => 0,
            'retained_node_count' => 0,
            'skipped_node_count' => 0,
        );
    }

    private function shouldRetainDecodedArrayItem(string $parentType, string $fieldName, string $type, mixed $item, ?array &$gate): bool
    {
        if ( null === $gate || 'Message' !== $parentType || 'nodeChanges' !== $fieldName || 'NodeChange' !== $type || ! is_array($item) ) {
            return true;
        }

        $gate['decoded_node_count']++;
        $nodeId = $this->readGateNodeId($item);
        if ( null !== $nodeId && isset($gate['selected_node_ids'][$nodeId]) ) {
            $gate['retained_node_count']++;
            return true;
        }

        $gate['skipped_node_count']++;
        return false;
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     */
    private function skipField(FigKiwiByteReader $reader, array $field, array $definitions): void
    {
        $type = $this->schemaFields->fieldType($field);
        if ( $this->schemaFields->isArrayField($field) ) {
            if ( 'byte' === $type ) {
                $reader->skipByteArray();
                return;
            }

            $length = $reader->readVarUint();
            for ( $i = 0; $i < $length; $i++ ) {
                $this->skipValue($reader, $type, $definitions);
            }
            return;
        }

        $this->skipValue($reader, $type, $definitions);
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function skipValue(FigKiwiByteReader $reader, string $type, array $definitions): void
    {
        match ( $type ) {
            'bool', 'byte' => $reader->readByte(),
            'int' => $reader->readVarInt(),
            'uint' => $reader->readVarUint(),
            'float' => $reader->readVarFloat(),
            'string' => $reader->skipString(),
            'int64' => $reader->readVarInt64(),
            'uint64' => $reader->readVarUint64(),
            default => $this->skipNamedValue($reader, $type, $definitions),
        };
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function skipNamedValue(FigKiwiByteReader $reader, string $type, array $definitions): void
    {
        $definition = $definitions[$type] ?? null;
        if ( ! is_array($definition) ) {
            throw new \RuntimeException('Invalid Kiwi type ' . $type . '.');
        }

        if ( 'ENUM' === ($definition['kind'] ?? null) ) {
            $reader->readVarUint();
            return;
        }

        if ( 'MESSAGE' === ($definition['kind'] ?? null) ) {
            $fieldsByValue = $this->schemaFields->fieldsByValue($definition);

            while ( true ) {
                $fieldValue = $reader->readVarUint();
                if ( 0 === $fieldValue ) {
                    return;
                }
                if ( ! isset($fieldsByValue[$fieldValue]) ) {
                    throw new \RuntimeException('Attempted to skip invalid message field ' . $fieldValue . '.');
                }
                $this->skipField($reader, $fieldsByValue[$fieldValue], $definitions);
            }
        }

        foreach ( $this->schemaFields->fields($definition) as $field ) {
            $this->skipField($reader, $field, $definitions);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function defaultScenegraphFieldPolicy(): array
    {
        return $this->decodePolicy->defaultScenegraphFieldPolicy();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function scenegraphFieldPolicyWithTextGlyphs(): array
    {
        return $this->decodePolicy->scenegraphFieldPolicyWithTextGlyphs();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function scenegraphFieldPolicyGroups(): array
    {
        return $this->decodePolicy->scenegraphFieldPolicyGroups();
    }

    /**
     * @param array<string, mixed>                $definition
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     * @param array<string, mixed>                $inventory
     * @param array<string, mixed>                $context
     */
    private function inventoryDefinitionSelective(FigKiwiByteReader $reader, array $definition, array $definitions, array $fieldPolicy, array &$inventory, array $context): array
    {
        $result = array();
        $typeName = (string) ($definition['name'] ?? '');
        $collectResult = in_array($typeName, array('Paint', 'ColorStop'), true);
        $allowed = array_flip($fieldPolicy[$typeName] ?? array());
        $context['parent_type'] = $typeName;

        if ( 'MESSAGE' === ($definition['kind'] ?? null) ) {
            $fieldsByValue = $this->schemaFields->fieldsByValue($definition);

            while ( true ) {
                $fieldValue = $reader->readVarUint();
                if ( 0 === $fieldValue ) {
                    return $result;
                }
                if ( ! isset($fieldsByValue[$fieldValue]) ) {
                    throw new \RuntimeException('Attempted to inventory invalid message field ' . $fieldValue . '.');
                }

                $field = $fieldsByValue[$fieldValue];
                $fieldName = $this->schemaFields->fieldName($field);
                if ( isset($allowed[$fieldName]) ) {
                    $value = $this->inventoryDecodeFieldSelective($reader, $field, $definitions, $fieldPolicy, $inventory, $context);
                    if ( $collectResult ) {
                        $result[$fieldName] = $value;
                    }
                } else {
                    $sample = $this->readFieldValue($reader, $field, $definitions);
                    $this->recordSkippedField($inventory, $field, $definitions, $context, $sample);
                }
            }
        }

        foreach ( $this->schemaFields->fields($definition) as $field ) {
            $fieldName = $this->schemaFields->fieldName($field);
            if ( isset($allowed[$fieldName]) ) {
                $value = $this->inventoryDecodeFieldSelective($reader, $field, $definitions, $fieldPolicy, $inventory, $context);
                if ( $collectResult ) {
                    $result[$fieldName] = $value;
                }
            } else {
                $sample = $this->readFieldValue($reader, $field, $definitions);
                $this->recordSkippedField($inventory, $field, $definitions, $context, $sample);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     * @param array<string, mixed>                $inventory
     * @param array<string, mixed>                $context
     */
    private function inventoryDecodeFieldSelective(FigKiwiByteReader $reader, array $field, array $definitions, array $fieldPolicy, array &$inventory, array &$context): mixed
    {
        $fieldName = $this->schemaFields->fieldName($field);
        $type = $this->schemaFields->fieldType($field);
        $fieldPath = $this->schemaFields->fieldPath((string) ($context['path'] ?? ''), $fieldName);

        if ( $this->schemaFields->isArrayField($field) ) {
            if ( 'byte' === $type ) {
                $value = $reader->readByteArray();
            } else {
                $length = $reader->readVarUint();
                $value = array();
                for ( $i = 0; $i < $length; $i++ ) {
                    $elementContext = $context;
                    $elementContext['path'] = $fieldPath . '[]';
                    $value[] = $this->inventoryDecodeValueSelective($reader, $type, $definitions, $fieldPolicy, $inventory, $elementContext);
                }
            }
        } else {
            $value = $this->inventoryDecodeValueSelective($reader, $type, $definitions, $fieldPolicy, $inventory, array_merge($context, array('path' => $fieldPath)));
        }

        if ( 'NodeChange' === ($context['parent_type'] ?? null) ) {
            if ( 'type' === $fieldName && is_scalar($value) ) {
                $context['node_type'] = (string) $value;
            } elseif ( 'guid' === $fieldName ) {
                $context['node_id'] = $this->decodePolicy->formatInventoryNodeId($value);
            }
        }
        if ( isset(self::INVENTORY_DECODED_FIELD_NAMES[$fieldName]) ) {
            $this->recordInventoryField($inventory, 'decoded_fields', $field, $definitions, $context, $value);
        }

        return $value;
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     * @param array<string, mixed>                $inventory
     * @param array<string, mixed>                $context
     */
    private function inventoryDecodeValueSelective(FigKiwiByteReader $reader, string $type, array $definitions, array $fieldPolicy, array &$inventory, array $context): mixed
    {
        return match ( $type ) {
            'bool' => 0 !== $reader->readByte(),
            'byte' => $reader->readByte(),
            'int' => $reader->readVarInt(),
            'uint' => $reader->readVarUint(),
            'float' => $reader->readVarFloat(),
            'string' => $reader->readString(),
            'int64' => $reader->readVarInt64(),
            'uint64' => $reader->readVarUint64(),
            default => $this->inventoryDecodeNamedValueSelective($reader, $type, $definitions, $fieldPolicy, $inventory, $context),
        };
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     * @param array<string, mixed>                $inventory
     * @param array<string, mixed>                $context
     */
    private function inventoryDecodeNamedValueSelective(FigKiwiByteReader $reader, string $type, array $definitions, array $fieldPolicy, array &$inventory, array $context): mixed
    {
        $definition = $definitions[$type] ?? null;
        if ( ! is_array($definition) ) {
            throw new \RuntimeException('Invalid Kiwi type ' . $type . '.');
        }

        if ( 'ENUM' === ($definition['kind'] ?? null) ) {
            $value = $reader->readVarUint();
            foreach ( $definition['fields'] ?? array() as $field ) {
                if ( is_array($field) && (int) ($field['value'] ?? -1) === $value ) {
                    return (string) ($field['name'] ?? $value);
                }
            }
            return $value;
        }

        if ( 'STRUCT' === ($definition['kind'] ?? null) && ! in_array($type, array('Paint', 'ColorStop'), true) ) {
            return $this->decodeDefinitionSelective($reader, $definition, $definitions, $fieldPolicy);
        }

        $childContext = $context;
        $childContext['parent_type'] = $type;
        if ( 'NodeChange' === $type ) {
            $childContext['node_type'] = null;
            $childContext['node_id'] = null;
        }

        return $this->inventoryDefinitionSelective($reader, $definition, $definitions, $fieldPolicy, $inventory, $childContext);
    }

    /**
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $field
     * @param array<string, mixed> $context
     */
    private function recordSkippedField(array &$inventory, array $field, array $definitions, array $context, mixed $sample): void
    {
        $this->recordInventoryField($inventory, 'fields', $field, $definitions, $context, $sample);
    }

    /**
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $field
     * @param array<string, mixed> $context
     */
    private function recordInventoryField(array &$inventory, string $bucket, array $field, array $definitions, array $context, mixed $sample): void
    {
        $fieldName = $this->schemaFields->fieldName($field);
        $type = $this->schemaFields->fieldType($field);
        $parentType = (string) ($context['parent_type'] ?? '');
        $path = $this->schemaFields->fieldPath((string) ($context['path'] ?? $parentType), $fieldName);
        $role = $this->decodePolicy->classifySkippedFieldRole($fieldName, $type, $parentType);
        $key = $this->schemaFields->inventoryKey($parentType, $path, $fieldName, $type);
        $typeDefinition = in_array($type, array('NodeChange'), true)
            ? array('name' => $type, 'kind' => 'MESSAGE', 'fields_omitted' => 'large_recursive_definition')
            : $this->schemaFields->typeDefinition($type, $definitions);

        if ( ! isset($inventory[$bucket][$key]) ) {
            $inventory[$bucket][$key] = array(
                'path'              => $path,
                'field'             => $fieldName,
                'type'              => $type,
                'type_kind'         => $typeDefinition['kind'] ?? 'PRIMITIVE',
                'wire_type'         => $this->schemaFields->wireType($field, $definitions),
                'type_definition'   => $typeDefinition,
                'parent_message'    => $parentType,
                'field_role'        => $role,
                'is_array'          => $this->schemaFields->isArrayField($field),
                'field_number'      => $this->schemaFields->fieldNumber($field),
                'occurrences'       => 0,
                'node_types'        => array(),
                'sample_node_ids'   => array(),
                'sample_nodes'      => array(),
                'sample_raw_values' => array(),
            );
        }

        $inventory[$bucket][$key]['occurrences']++;
        $nodeType = is_scalar($context['node_type'] ?? null) ? (string) $context['node_type'] : 'unknown';
        $inventory[$bucket][$key]['node_types'][$nodeType] = ($inventory[$bucket][$key]['node_types'][$nodeType] ?? 0) + 1;
        $nodeId = is_scalar($context['node_id'] ?? null) ? (string) $context['node_id'] : '';
        if ( '' !== $nodeId && count($inventory[$bucket][$key]['sample_node_ids']) < 5 && ! in_array($nodeId, $inventory[$bucket][$key]['sample_node_ids'], true) ) {
            $inventory[$bucket][$key]['sample_node_ids'][] = $nodeId;
        }

        $normalized = $this->normalizeInventorySample($sample);
        if ( count($inventory[$bucket][$key]['sample_nodes']) < self::INVENTORY_SAMPLE_LIMIT ) {
            $nodeSample = array_filter(array(
                'node_id'   => '' !== $nodeId ? $nodeId : null,
                'node_type' => $nodeType,
                'path'      => $path,
                'raw_value' => $normalized,
            ), static fn (mixed $value): bool => null !== $value);
            if ( ! in_array($nodeSample, $inventory[$bucket][$key]['sample_nodes'], true) ) {
                $inventory[$bucket][$key]['sample_nodes'][] = $nodeSample;
            }
        }

        if ( count($inventory[$bucket][$key]['sample_raw_values']) < self::INVENTORY_SAMPLE_LIMIT ) {
            if ( ! in_array($normalized, $inventory[$bucket][$key]['sample_raw_values'], true) ) {
                $inventory[$bucket][$key]['sample_raw_values'][] = $normalized;
            }
        }
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     */
    private function readFieldValue(FigKiwiByteReader $reader, array $field, array $definitions): mixed
    {
        $type = $this->schemaFields->fieldType($field);
        if ( $this->schemaFields->isArrayField($field) ) {
            if ( 'byte' === $type ) {
                return $reader->readByteArray();
            }

            $length = $reader->readVarUint();
            $value = array();
            for ( $i = 0; $i < $length; $i++ ) {
                $value[] = $this->decodeValue($reader, $type, $definitions);
            }
            return $value;
        }

        return $this->decodeValue($reader, $type, $definitions);
    }

    /**
     * @return mixed
     */
    private function normalizeInventorySample(mixed $value): mixed
    {
        if ( is_string($value) ) {
            $bytes = strlen($value);
            $printable = preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', $value) ? $value : null;
            return array(
                'kind'        => 'string',
                'bytes'       => $bytes,
                'value'       => null === $printable ? null : substr($printable, 0, self::INVENTORY_SAMPLE_STRING_BYTES),
                'truncated'   => null !== $printable && $bytes > self::INVENTORY_SAMPLE_STRING_BYTES,
                'preview_hex' => bin2hex(substr($value, 0, 32)),
            );
        }

        if ( is_array($value) ) {
            $items = array();
            $count = 0;
            foreach ( $value as $key => $item ) {
                if ( $count >= self::INVENTORY_SAMPLE_ARRAY_ITEMS ) {
                    break;
                }
                $items[(string) $key] = $this->normalizeInventorySample($item);
                $count++;
            }
            return array(
                'kind'      => 'array',
                'count'     => count($value),
                'items'     => $items,
                'truncated' => count($value) > self::INVENTORY_SAMPLE_ARRAY_ITEMS,
            );
        }

        if ( is_bool($value) ) {
            return array('kind' => 'bool', 'value' => $value);
        }
        if ( is_int($value) ) {
            return array('kind' => 'int', 'value' => $value);
        }
        if ( is_float($value) ) {
            return array('kind' => 'float', 'value' => $value);
        }
        if ( null === $value ) {
            return array('kind' => 'null', 'value' => null);
        }

        return array('kind' => get_debug_type($value));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function readGateNodeId(array $node): ?string
    {
        if ( isset($node['id']) && is_scalar($node['id']) ) {
            return (string) $node['id'];
        }

        return $this->readGateGuid($node['guid'] ?? null);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function readGateParentId(array $node): ?string
    {
        $parentIndex = $node['parentIndex'] ?? null;
        if ( ! is_array($parentIndex) ) {
            return null;
        }

        return $this->readGateGuid($parentIndex['guid'] ?? null);
    }

    private function readGateGuid(mixed $guid): ?string
    {
        if ( is_array($guid) && isset($guid['sessionID'], $guid['localID']) && is_scalar($guid['sessionID']) && is_scalar($guid['localID']) ) {
            return (string) $guid['sessionID'] . ':' . (string) $guid['localID'];
        }

        return is_scalar($guid) ? $this->normalizeGateDependencyScalarId($guid) : null;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<int, string>>   $children
     * @param array<int, array<string, mixed>>    $pages
     * @param array<string, mixed>                $options
     * @return array<int, string>
     */
    private function planGateNodeIds(array $nodes, array $children, array $pages, array $options): array
    {
        $roots = array();
        $frameIds = array();
        if ( isset($options['frame_id']) && is_scalar($options['frame_id']) ) {
            $frameIds[] = (string) $options['frame_id'];
        }
        if ( is_array($options['frame_ids'] ?? null) ) {
            foreach ( $options['frame_ids'] as $frameId ) {
                if ( is_scalar($frameId) ) {
                    $frameIds[] = (string) $frameId;
                }
            }
        }

        foreach ( array_values(array_unique($frameIds)) as $frameId ) {
            if ( isset($nodes[$frameId]) ) {
                $roots[] = $frameId;
            }
        }

        if ( empty($roots) && isset($options['max_pages']) && is_numeric($options['max_pages']) && (int) $options['max_pages'] > 0 ) {
            foreach ( array_slice($pages, 0, (int) $options['max_pages']) as $page ) {
                if ( isset($page['id']) && is_scalar($page['id']) ) {
                    $roots[] = (string) $page['id'];
                }
            }
        }

        $selected = empty($roots) ? array_keys($nodes) : $this->gateSubtreeIds($roots, $children);
        if ( isset($options['max_nodes']) && is_numeric($options['max_nodes']) && (int) $options['max_nodes'] > 0 ) {
            $selected = array_slice($selected, 0, (int) $options['max_nodes']);
        }

        return array_values(array_unique($selected));
    }

    /**
     * @param array<int, string>                $roots
     * @param array<string, array<int, string>> $children
     * @return array<int, string>
     */
    private function gateSubtreeIds(array $roots, array $children): array
    {
        $selected = array();
        $queue = array_values($roots);
        while ( ! empty($queue) ) {
            $id = array_shift($queue);
            if ( ! is_string($id) || isset($selected[$id]) ) {
                continue;
            }
            $selected[$id] = $id;
            foreach ( $children[$id] ?? array() as $childId ) {
                $queue[] = $childId;
            }
        }

        return array_values($selected);
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, mixed>                $result
     */
    private function decodeField(FigKiwiByteReader $reader, array $field, array $definitions, array &$result): void
    {
        $type = $this->schemaFields->fieldType($field);
        if ( $this->schemaFields->isArrayField($field) ) {
            if ( 'byte' === $type ) {
                $value = $reader->readByteArray();
            } else {
                $length = $reader->readVarUint();
                $value = array();
                for ( $i = 0; $i < $length; $i++ ) {
                    $value[] = $this->decodeValue($reader, $type, $definitions);
                }
            }
        } else {
            $value = $this->decodeValue($reader, $type, $definitions);
        }

        if ( ! $this->schemaFields->isDeprecatedField($field) && isset($field['name']) ) {
            $result[$this->schemaFields->fieldName($field)] = $value;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function decodeValue(FigKiwiByteReader $reader, string $type, array $definitions): mixed
    {
        return match ( $type ) {
            'bool' => 0 !== $reader->readByte(),
            'byte' => $reader->readByte(),
            'int' => $reader->readVarInt(),
            'uint' => $reader->readVarUint(),
            'float' => $reader->readVarFloat(),
            'string' => $reader->readString(),
            'int64' => $reader->readVarInt64(),
            'uint64' => $reader->readVarUint64(),
            default => $this->decodeNamedValue($reader, $type, $definitions),
        };
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function decodeNamedValue(FigKiwiByteReader $reader, string $type, array $definitions): mixed
    {
        $definition = $definitions[$type] ?? null;
        if ( ! is_array($definition) ) {
            throw new \RuntimeException('Invalid Kiwi type ' . $type . '.');
        }

        if ( 'ENUM' === ($definition['kind'] ?? null) ) {
            $value = $reader->readVarUint();
            foreach ( $definition['fields'] ?? array() as $field ) {
                if ( is_array($field) && (int) ($field['value'] ?? -1) === $value ) {
                    return (string) ($field['name'] ?? $value);
                }
            }
            return $value;
        }

        return $this->decodeDefinition($reader, $definition, $definitions);
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $message, mixed $error): array
    {
        return array('code' => $code, 'message' => $message, 'source' => 'FigKiwiDecoder', 'context' => is_array($error) ? $error : array('error' => (string) $error));
    }
}

final class FigKiwiByteReader
{
    private int $offset = 0;

    public function __construct(private readonly string $data)
    {
    }

    public function readByte(): int
    {
        if ( $this->offset >= strlen($this->data) ) {
            throw new \RuntimeException('Index out of bounds.');
        }

        return ord($this->data[$this->offset++]);
    }

    public function readByteArray(): string
    {
        $length = $this->readVarUint();
        if ( $this->offset + $length > strlen($this->data) ) {
            throw new \RuntimeException('Read array out of bounds.');
        }
        $value = substr($this->data, $this->offset, $length);
        $this->offset += $length;
        return $value;
    }

    public function skipByteArray(): void
    {
        $this->skipBytes($this->readVarUint());
    }

    public function readVarUint(): int
    {
        $value = 0;
        $shift = 0;
        do {
            $byte = $this->readByte();
            $value |= ($byte & 0x7f) << $shift;
            $shift += 7;
        } while ( ($byte & 0x80) && $shift < 35 );

        return $value;
    }

    public function readVarInt(): int
    {
        $value = $this->readVarUint();
        return ($value & 1) ? ~($value >> 1) : ($value >> 1);
    }

    public function readVarUint64(): int
    {
        $value = 0;
        $shift = 0;
        do {
            $byte = $this->readByte();
            $value |= ($byte & 0x7f) << $shift;
            $shift += 7;
        } while ( ($byte & 0x80) && $shift < 63 );

        return $value;
    }

    public function readVarInt64(): int
    {
        $value = $this->readVarUint64();
        return ($value & 1) ? ~($value >> 1) : ($value >> 1);
    }

    public function readVarFloat(): float
    {
        $first = $this->readByte();
        if ( 0 === $first ) {
            return 0.0;
        }

        $bits = unpack('V', chr($first) . chr($this->readByte()) . chr($this->readByte()) . chr($this->readByte()));
        $value = is_array($bits) ? (int) $bits[1] : 0;
        $rotated = (($value << 23) & 0xffffffff) | (($value >> 9) & 0x7fffff);
        $float = unpack('f', pack('V', $rotated));

        return is_array($float) ? (float) $float[1] : 0.0;
    }

    public function readString(): string
    {
        $bytes = '';
        while ( true ) {
            $byte = $this->readByte();
            if ( 0 === $byte ) {
                return $bytes;
            }
            $bytes .= chr($byte);
        }
    }

    public function skipString(): void
    {
        while ( 0 !== $this->readByte() ) {
            // Strings are null-terminated in the Kiwi schema chunk.
        }
    }

    public function skipBytes(int $length): void
    {
        if ( $length < 0 || $this->offset + $length > strlen($this->data) ) {
            throw new \RuntimeException('Skip out of bounds.');
        }

        $this->offset += $length;
    }
}
