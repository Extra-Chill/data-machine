<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\FigFile;

use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCapability;

/**
 * Parses the lightweight fig-kiwi envelope used by canvas.fig.
 */
final class FigKiwiParser
{
    private const PRELUDE = 'fig-kiwi';
    private const ZSTD_MAGIC = "\x28\xb5\x2f\xfd";
    private const WIRE_TYPE_VARINT = 0;
    private const WIRE_TYPE_FIXED64 = 1;
    private const WIRE_TYPE_LENGTH_DELIMITED = 2;
    private const WIRE_TYPE_FIXED32 = 5;
    private const WIRE_RECORD_LIMIT = 64;
    private const DEFAULT_MAX_KIWI_MESSAGE_DECODE_BYTES = 16777216;
    private const DEFAULT_MAX_KIWI_SELECTIVE_MESSAGE_DECODE_BYTES = 33554432;
    private const DEFAULT_MAX_ZSTD_INFLATED_BYTES = 67108864;

    public function __construct(
        private readonly ZstdCapability $zstdCapability = new ZstdCapability(),
        private readonly FigKiwiDecoder $kiwiDecoder = new FigKiwiDecoder()
    ) {
    }

    /**
     * @return array{canvas: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function parse(string $raw, array $options = array()): array
    {
        if ( strlen($raw) < 12 ) {
            return array(
                'canvas'      => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_canvas_too_short', 'canvas.fig is too short to contain a fig-kiwi header.')),
            );
        }

        $prelude = substr($raw, 0, 8);
        $version = $this->uint32(substr($raw, 8, 4));

        $canvas = array(
            'prelude' => $prelude,
            'version' => $version,
            'bytes'   => strlen($raw),
        );

        if ( self::PRELUDE !== $prelude ) {
            return array(
                'canvas'      => $canvas,
                'diagnostics' => array(),
            );
        }

        $chunks = array();
        $diagnostics = array();
        $kiwiSchema = null;
        $offset = 12;
        $index = 0;
        $totalBytes = strlen($raw);

        while ( $offset < $totalBytes ) {
            if ( $offset + 4 > $totalBytes ) {
                $diagnostics[] = $this->diagnostic('figma_transformer_kiwi_truncated_chunk_table', 'fig-kiwi chunk table ends with an incomplete chunk length.', array('offset' => $offset));
                break;
            }

            $compressedBytes = $this->uint32(substr($raw, $offset, 4));
            $dataOffset = $offset + 4;
            $nextOffset = $dataOffset + $compressedBytes;

            if ( $nextOffset > $totalBytes ) {
                $diagnostics[] = $this->diagnostic(
                    'figma_transformer_kiwi_truncated_chunk',
                    'fig-kiwi chunk length exceeds the available canvas.fig bytes.',
                    array(
                        'chunk_index'      => $index,
                        'offset'           => $offset,
                        'compressed_bytes' => $compressedBytes,
                    )
                );
                break;
            }

            $payload = substr($raw, $dataOffset, $compressedBytes);
            $chunk = array(
                'index'            => $index,
                'offset'           => $offset,
                'data_offset'      => $dataOffset,
                'compressed_bytes' => $compressedBytes,
                'compression'      => $this->detectCompression($payload),
            );

            if ( 'zlib' === $chunk['compression'] ) {
                $inflated = @gzinflate($payload);
                if ( false === $inflated ) {
                    $diagnostics[] = $this->diagnostic('figma_transformer_kiwi_zlib_inflate_failed', 'zlib-compressed fig-kiwi chunk could not be inflated.', array('chunk_index' => $index));
                } else {
                    $chunk['inflated_bytes'] = strlen($inflated);
                    $chunk['inflated_preview_hex'] = bin2hex(substr($inflated, 0, 32));
                    $chunk['payload'] = $this->classifyPayload($inflated, $kiwiSchema, $diagnostics, $options);
                }
            } elseif ( 'zstd' === $chunk['compression'] ) {
                $zstdResult = $this->zstdCapability->uncompress(
                    $payload,
                    'FigKiwiParser',
                    $index,
                    array('max_decoded_bytes' => $this->optionBytes($options, 'max_zstd_inflated_bytes', self::DEFAULT_MAX_ZSTD_INFLATED_BYTES))
                );
                $diagnostics = array_merge($diagnostics, $zstdResult['diagnostics']);
                if ( null !== $zstdResult['data'] ) {
                    $chunk['inflated_bytes'] = strlen($zstdResult['data']);
                    $chunk['inflated_preview_hex'] = bin2hex(substr($zstdResult['data'], 0, 32));
                    $chunk['payload'] = $this->classifyPayload($zstdResult['data'], $kiwiSchema, $diagnostics, $options);
                }
            } else {
                $chunk['payload'] = $this->classifyPayload($payload, $kiwiSchema, $diagnostics, $options);
                $diagnostics[] = $this->diagnostic('figma_transformer_kiwi_unknown_compression', 'fig-kiwi chunk compression could not be identified.', array('chunk_index' => $index));
            }

            $chunks[] = $chunk;
            $offset = $nextOffset;
            $index++;
        }

        $canvas['chunks'] = $chunks;

        return array(
            'canvas'      => $canvas,
            'diagnostics' => $diagnostics,
        );
    }

    private function uint32(string $bytes): int
    {
        $value = unpack('V', $bytes);
        return is_array($value) ? (int) $value[1] : 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function optionBytes(array $options, string $key, int $default): int
    {
        if ( isset($options[$key]) && is_numeric($options[$key]) ) {
            return max(0, (int) $options[$key]);
        }

        return $default;
    }

    private function detectCompression(string $payload): string
    {
        if ( str_starts_with($payload, self::ZSTD_MAGIC) ) {
            return 'zstd';
        }

        if ( false !== @gzinflate($payload) ) {
            return 'zlib';
        }

        return 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyPayload(string $payload, ?array &$kiwiSchema, array &$diagnostics, array $options = array()): array
    {
        $classification = $this->looksJsonLike($payload) ? 'json_invalid' : 'binary';
        $metadata = array(
            'classification' => $classification,
            'bytes'          => strlen($payload),
            'preview_hex'    => bin2hex(substr($payload, 0, 32)),
        );

        if ( 'json_invalid' !== $classification ) {
            if ( null === $kiwiSchema ) {
                $schemaResult = $this->kiwiDecoder->decodeSchema($payload);
                if ( null !== $schemaResult['schema'] && ! empty($schemaResult['schema']['definitions']) ) {
                    $diagnostics = array_merge($diagnostics, $schemaResult['diagnostics']);
                    $kiwiSchema = $schemaResult['schema'];
                    $metadata['classification'] = 'kiwi_schema';
                    $metadata['kiwi_schema'] = array(
                        'definition_count' => count($kiwiSchema['definitions'] ?? array()),
                        'message_root'     => $this->hasKiwiDefinition($kiwiSchema, 'Message'),
                    );
                    return $metadata;
                }
            } else {
                $maxMessageDecodeBytes = (int) ($options['max_kiwi_message_decode_bytes'] ?? self::DEFAULT_MAX_KIWI_MESSAGE_DECODE_BYTES);
                $nodeGate = $this->inspectNodeGate($payload, $kiwiSchema, $diagnostics, $options);
                if ( true === ($options['kiwi_gate_only'] ?? false) ) {
                    $metadata['classification'] = null !== $nodeGate ? 'kiwi_message_gate' : 'kiwi_message_skipped';
                    if ( null !== $nodeGate ) {
                        $metadata['kiwi_node_gate'] = $nodeGate;
                    }
                    return $metadata;
                }
                if ( $maxMessageDecodeBytes > 0 && strlen($payload) > $maxMessageDecodeBytes ) {
                    $maxSelectiveMessageDecodeBytes = (int) ($options['max_kiwi_selective_message_decode_bytes'] ?? self::DEFAULT_MAX_KIWI_SELECTIVE_MESSAGE_DECODE_BYTES);
                    $gateDecodeOptions = $this->gateDecodeOptions($nodeGate);
                    if ( empty($gateDecodeOptions) && $maxSelectiveMessageDecodeBytes > 0 && strlen($payload) > $maxSelectiveMessageDecodeBytes ) {
                        $diagnostics[] = $this->diagnostic(
                            'figma_transformer_kiwi_message_decode_skipped_preflight',
                            'Kiwi message chunk exceeds the configured selective decode safety limit and was not decoded.',
                            array(
                                'bytes'                      => strlen($payload),
                                'max_decode_bytes'           => $maxMessageDecodeBytes,
                                'max_selective_decode_bytes' => $maxSelectiveMessageDecodeBytes,
                                'recommended_next_step'      => 'Expand bounded selective Kiwi decoding before raising this limit; full decoded arrays can exceed PHP memory on fatal-scale files.',
                            )
                        );
                        $metadata['classification'] = 'kiwi_message_skipped';
                        if ( null !== $nodeGate ) {
                            $metadata['kiwi_node_gate'] = $nodeGate;
                        }
                        $metadata['kiwi_message_decode'] = 'skipped_preflight';
                        return $metadata;
                    }

                    $fieldPolicy = true === ($options['render_text_glyph_paths'] ?? false) ? $this->kiwiDecoder->scenegraphFieldPolicyWithTextGlyphs() : array();
                    $messageResult = $this->kiwiDecoder->decodeMessageSelective($payload, $kiwiSchema, 'Message', $fieldPolicy, $gateDecodeOptions);
                    $diagnostics = array_merge($diagnostics, $messageResult['diagnostics']);
                    if ( null !== $messageResult['message'] ) {
                        $diagnostics[] = $this->diagnostic(
                            empty($gateDecodeOptions) ? 'figma_transformer_kiwi_message_selective_decode_used' : 'figma_transformer_kiwi_message_gate_selective_decode_used',
                            empty($gateDecodeOptions) ? 'Kiwi message chunk exceeded the eager decode byte limit and was selectively decoded for scenegraph fields.' : 'Kiwi message chunk exceeded the eager decode byte limit and was selectively decoded through a bounded gate plan.',
                            array(
                                'bytes'               => strlen($payload),
                                'max_decode_bytes'    => $maxMessageDecodeBytes,
                                'selected_node_count' => count($gateDecodeOptions['selected_node_ids'] ?? array()),
                            )
                        );
                        $metadata['classification'] = 'kiwi_message';
                        $metadata['kiwi_message'] = $messageResult['message'];
                        $this->attachSkippedFieldInventory($metadata, $payload, $kiwiSchema, $fieldPolicy, $diagnostics);
                        if ( null !== $nodeGate ) {
                            $metadata['kiwi_node_gate'] = $nodeGate;
                        }
                        $metadata['kiwi_message_decode'] = empty($gateDecodeOptions) ? 'selective' : 'gate_selective';
                        return $metadata;
                    }

                    $diagnostics[] = $this->diagnostic(
                        'figma_transformer_kiwi_message_decode_skipped_size',
                        'Kiwi message chunk exceeds the configured eager decode byte limit and selective decode did not produce a message.',
                        array(
                            'bytes'                 => strlen($payload),
                            'max_decode_bytes'      => $maxMessageDecodeBytes,
                            'recommended_next_step' => 'Expand selective Kiwi decoding for production .fig scenegraph extraction.',
                        )
                    );
                    $metadata['classification'] = 'kiwi_message_skipped';
                    return $metadata;
                }

                $messageResult = $this->kiwiDecoder->decodeMessage($payload, $kiwiSchema);
                $diagnostics = array_merge($diagnostics, $messageResult['diagnostics']);
                if ( null !== $messageResult['message'] ) {
                    $metadata['classification'] = 'kiwi_message';
                    $metadata['kiwi_message'] = $messageResult['message'];
                    $fieldPolicy = true === ($options['render_text_glyph_paths'] ?? false) ? $this->kiwiDecoder->scenegraphFieldPolicyWithTextGlyphs() : array();
                    $this->attachSkippedFieldInventory($metadata, $payload, $kiwiSchema, $fieldPolicy, $diagnostics);
                    if ( null !== $nodeGate ) {
                        $metadata['kiwi_node_gate'] = $nodeGate;
                    }
                    return $metadata;
                }
            }

            $wire = $this->describeWirePayload($payload);
            if ( $wire['record_count'] > 0 || true === $wire['truncated'] || null !== $wire['reason'] ) {
                $metadata['wire'] = $wire;
            }

            return $metadata;
        }

        $decoded = json_decode($payload, true);
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            $metadata['json_error'] = json_last_error_msg();
            return $metadata;
        }

        $metadata['classification'] = 'json';
        if ( is_array($decoded) ) {
            $metadata['json'] = $decoded;
        }

        return $metadata;
    }

    /**
     * Record the selective decoder's bounded skipped-field evidence alongside
     * the real Kiwi message so downstream artifact diagnostics can use it.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $fieldPolicy
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function attachSkippedFieldInventory(array &$metadata, string $payload, array $schema, array $fieldPolicy, array &$diagnostics): void
    {
        $inventoryResult = $this->kiwiDecoder->inventorySkippedFieldsSelective($payload, $schema, 'Message', $fieldPolicy);
        $diagnostics = array_merge($diagnostics, $inventoryResult['diagnostics']);
        if ( is_array($inventoryResult['inventory'] ?? null) ) {
            $metadata['kiwi_skipped_field_inventory'] = $inventoryResult['inventory'];
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<string, mixed> $options
     */
    private function inspectNodeGate(string $payload, array $schema, array &$diagnostics, array $options): ?array
    {
        if ( true !== ($options['inspect_kiwi_gate'] ?? false) ) {
            return null;
        }

        $gateResult = $this->kiwiDecoder->inspectNodeGate($payload, $schema, 'Message', $options);
        $diagnostics = array_merge($diagnostics, $gateResult['diagnostics']);
        return $gateResult['gate'];
    }

    /**
     * @return array<string, mixed>
     */
    private function gateDecodeOptions(?array $nodeGate): array
    {
        if ( ! is_array($nodeGate['gate_plan'] ?? null) || true !== ($nodeGate['gate_plan']['feasible'] ?? null) ) {
            return array();
        }

        $selected = is_array($nodeGate['gate_plan']['selected_node_ids'] ?? null)
            ? $nodeGate['gate_plan']['selected_node_ids']
            : array();

        if ( empty($selected) ) {
            return array();
        }

        return array('selected_node_ids' => $selected);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function hasKiwiDefinition(array $schema, string $name): bool
    {
        foreach ( $schema['definitions'] ?? array() as $definition ) {
            if ( is_array($definition) && $name === ($definition['name'] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Describes protobuf-style wire records without claiming a schema-level decode.
     *
     * @return array<string, mixed>
     */
    private function describeWirePayload(string $payload): array
    {
        $bytes = strlen($payload);
        $offset = 0;
        $records = array();
        $truncated = false;
        $reason = null;

        while ( $offset < $bytes && count($records) < self::WIRE_RECORD_LIMIT ) {
            $recordOffset = $offset;
            $key = $this->readVarint($payload, $offset);
            if ( null === $key ) {
                $truncated = true;
                $reason = 'truncated_key_varint';
                break;
            }

            if ( 0 === $key['value'] ) {
                $reason = 'zero_field_key';
                break;
            }

            $fieldNumber = intdiv($key['value'], 8);
            $wireType = $key['value'] % 8;
            if ( 0 === $fieldNumber ) {
                $reason = 'zero_field_number';
                break;
            }

            $record = array(
                'offset'       => $recordOffset,
                'field_number' => $fieldNumber,
                'wire_type'    => $wireType,
            );

            if ( self::WIRE_TYPE_VARINT === $wireType ) {
                $value = $this->readVarint($payload, $offset);
                if ( null === $value ) {
                    $truncated = true;
                    $reason = 'truncated_varint_value';
                    break;
                }
                $record['value'] = $value['value'];
            } elseif ( self::WIRE_TYPE_FIXED64 === $wireType ) {
                if ( $offset + 8 > $bytes ) {
                    $truncated = true;
                    $reason = 'truncated_fixed64_value';
                    break;
                }
                $record['bytes'] = 8;
                $record['preview_hex'] = bin2hex(substr($payload, $offset, 8));
                $offset += 8;
            } elseif ( self::WIRE_TYPE_LENGTH_DELIMITED === $wireType ) {
                $length = $this->readVarint($payload, $offset);
                if ( null === $length ) {
                    $truncated = true;
                    $reason = 'truncated_length_varint';
                    break;
                }
                if ( $offset + $length['value'] > $bytes ) {
                    $truncated = true;
                    $reason = 'truncated_length_delimited_value';
                    break;
                }
                $value = substr($payload, $offset, $length['value']);
                $record['bytes'] = $length['value'];
                $record['preview_hex'] = bin2hex(substr($value, 0, 32));
                if ( '' !== $value && preg_match('/\A[\x09\x0a\x0d\x20-\x7e]*\z/', $value) ) {
                    $record['text_preview'] = substr($value, 0, 64);
                }
                $offset += $length['value'];
            } elseif ( self::WIRE_TYPE_FIXED32 === $wireType ) {
                if ( $offset + 4 > $bytes ) {
                    $truncated = true;
                    $reason = 'truncated_fixed32_value';
                    break;
                }
                $record['bytes'] = 4;
                $record['preview_hex'] = bin2hex(substr($payload, $offset, 4));
                $offset += 4;
            } else {
                $reason = 'unsupported_wire_type';
                break;
            }

            $records[] = $record;
        }

        return array(
            'format'       => 'protobuf_wire',
            'complete'     => $offset === $bytes && null === $reason,
            'truncated'    => $truncated,
            'record_count' => count($records),
            'records'      => $records,
            'next_offset'  => $offset,
            'reason'       => $reason,
        );
    }

    /**
     * @return array{value: int, bytes: int}|null
     */
    private function readVarint(string $payload, int &$offset): ?array
    {
        $value = 0;
        $shift = 0;
        $start = $offset;
        $bytes = strlen($payload);

        while ( $offset < $bytes && $shift <= 63 ) {
            $byte = ord($payload[$offset]);
            $offset++;
            $value += ($byte & 0x7f) << $shift;

            if ( 0 === ($byte & 0x80) ) {
                return array(
                    'value' => $value,
                    'bytes' => $offset - $start,
                );
            }

            $shift += 7;
        }

        return null;
    }

    private function looksJsonLike(string $payload): bool
    {
        $trimmed = ltrim($payload);
        return '' !== $trimmed && ('{' === $trimmed[0] || '[' === $trimmed[0]);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $message, array $context = array()): array
    {
        return array(
            'code'    => $code,
            'message' => $message,
            'source'  => 'FigKiwiParser',
            'context' => $context,
        );
    }
}
