<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Normalizes diagnostic volume without changing diagnostic semantics.
 */
final class ScenegraphDiagnostics
{
    public function __construct(
        private readonly VectorGeometryNormalizer $vectorGeometryNormalizer = new VectorGeometryNormalizer()
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    public function compact(array $diagnostics): array
    {
        return $this->vectorGeometryNormalizer->compactUnsupportedVectorNetworkBlobDiagnostics($this->compactGlyphCommandBlobDiagnostics($diagnostics));
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

        $compacted[] = array(
            'severity' => 'warning',
            'code'     => 'unsupported_text_glyph_command_blob',
            'message'  => 'Unsupported Figma text glyph command blobs were omitted from derived glyph metadata.',
            'source'   => 'ScenegraphNormalizer',
            'context'  => array(
                'total_count'         => $count,
                'affected_node_count' => count($nodeIds),
                'sample_node_ids'     => array_slice(array_keys($nodeIds), 0, 10),
                'sample_glyphs'       => $sampleGlyphs,
            ),
        );

        return $compacted;
    }
}
