<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use InvalidArgumentException;
use Throwable;

final class FormatBridge
{
    public function __construct(
        private readonly AdapterRegistry $registry = new AdapterRegistry(),
        private readonly Normalizer $normalizer = new Normalizer()
    ) {
        $this->registry->register(new BlocksAdapter());
        $this->registry->register(new HtmlAdapter());
        $this->registry->register(new MarkdownAdapter());
    }

    public function registerAdapter(FormatAdapterInterface $adapter): void
    {
        $this->registry->register($adapter);
    }

    /**
     * @return list<string>
     */
    public function supportedFormats(): array
    {
        return $this->registry->slugs();
    }

    public function supports(string $format): bool
    {
        return null !== $this->registry->get($format);
    }

    /**
     * @param array<string, mixed> $options
     *
     * Transitional helper for compatibility wrappers that must preserve string
     * return types. New callers should use convertResult().
     */
    public function normalize(string $content, string $format, array $options = array()): string
    {
        return $this->normalizer->normalize($content, $format, $this->registry, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, array<string, mixed>>
     *
     * Transitional helper for compatibility wrappers that must preserve block
     * array return types. New callers should use convertResult().
     */
    public function toBlocks(string $content, string $from, array $options = array()): array
    {
        $this->normalize($content, $from, $options);

        $adapter = $this->registry->get($from);

        return $adapter ? array_values($adapter->toBlocks($content, $options)) : array();
    }

    /**
     * @param array<string, mixed> $options
     *
     * Transitional helper for compatibility wrappers that must preserve string
     * return types. New callers should use convertResult().
     */
    public function convert(string $content, string $from, string $to, array $options = array()): string
    {
        if ( $from === $to ) {
            return $this->normalize($content, $from, $options);
        }

        $blocks = $this->toBlocks($content, $from, $options);
        if ( 'blocks' === $to ) {
            $adapter = $this->registry->get($to);

            if ( null === $adapter ) {
                throw new InvalidArgumentException(sprintf('No format adapter is registered for format "%s".', $to));
            }

            return $adapter->fromBlocks($blocks, $options);
        }

        $adapter = $this->registry->get($to);

        if ( null === $adapter ) {
            throw new InvalidArgumentException(sprintf('No format adapter is registered for format "%s".', $to));
        }

        return $adapter->fromBlocks($blocks, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function convertResult(string $content, string $from, string $to, array $options = array()): TransformerResult
    {
        $context    = TransformationOptions::context($options);
        $provenance = array(
            array_merge(array(
                'source_format' => $from,
                'target_format' => $to,
                'input_bytes'   => strlen($content),
                'transformer'   => self::class,
            ), TransformationOptions::provenance($options)),
        );

        if ( ! $this->supports($from) ) {
            return $this->failedResult('unsupported_source_format', sprintf('No format adapter is registered for source format "%s".', $from), $provenance, $context);
        }

        if ( ! $this->supports($to) ) {
            return $this->failedResult('unsupported_target_format', sprintf('No format adapter is registered for target format "%s".', $to), $provenance, $context);
        }

        try {
            $sourceAdapter = $this->registry->get($from);
            $targetAdapter = $this->registry->get($to);

            if ( null === $sourceAdapter || null === $targetAdapter ) {
                return $this->failedResult('format_bridge_adapter_missing', 'A required format adapter was not available during conversion.', $provenance, $context);
            }

            $normalizedContent = $this->normalize($content, $from, $options);
            $blocks = array_values($sourceAdapter->toBlocks($normalizedContent, $options));
            $output = $from === $to ? $normalizedContent : $targetAdapter->fromBlocks($blocks, $options);
            $metrics = array(
                'input_bytes'      => strlen($content),
                'output_bytes'     => strlen($output),
                'block_count'      => count($blocks),
                'fallback_count'   => 0,
                'diagnostic_count' => 1,
            );
            $sourceReports = array(
                'format_bridge' => array(
                    'source_format' => $from,
                    'target_format' => $to,
                    'input_bytes'   => strlen($content),
                    'output_bytes'  => strlen($output),
                ),
            );
            $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts($from, $blocks, array(), $sourceReports, array(), $provenance, $metrics);

            return new TransformerResult(
                sourceReports: $sourceReports,
                blocks: $blocks,
                serializedBlocks: 'blocks' === $to ? $output : '',
                documents: array(
                    array(
                        'format'  => $to,
                        'content' => $output,
                    ),
                ),
                diagnostics: array(
                    array(
                        'code'    => 'format_bridge_conversion_completed',
                        'message' => sprintf('Converted %s content to %s through the format bridge.', $from, $to),
                        'source'  => self::class,
                    ),
                ),
                provenance: $provenance,
                context: $context,
                metrics: $metrics
            );
        } catch ( InvalidArgumentException $exception ) {
            return $this->failedResult('format_bridge_validation_failed', $exception->getMessage(), $provenance, $context);
        } catch ( Throwable $throwable ) {
            return $this->failedResult('format_bridge_conversion_failed', $throwable->getMessage(), $provenance, $context);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $provenance
     * @param array{strict: bool, allow_fallbacks: bool} $context
     */
    private function failedResult(string $code, string $message, array $provenance, array $context): TransformerResult
    {
        $metrics = array(
            'input_bytes'      => (int) ($provenance[0]['input_bytes'] ?? 0),
            'block_count'      => 0,
            'fallback_count'   => 0,
            'diagnostic_count' => 1,
            'output_bytes'     => 0,
        );
        $sourceFormat = (string) ($provenance[0]['source_format'] ?? 'unknown');
        $sourceReports = array(
            'format_bridge' => array(
                'source_format' => $sourceFormat,
                'target_format' => (string) ($provenance[0]['target_format'] ?? ''),
                'error_code'    => $code,
            ),
        );
        $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts($sourceFormat, array(), array(), $sourceReports, array(), $provenance, $metrics);

        return new TransformerResult(
            status: 'failed',
            sourceReports: $sourceReports,
            diagnostics: array(
                array(
                    'code'    => $code,
                    'message' => $message,
                    'source'  => self::class,
                ),
            ),
            provenance: $provenance,
            context: $context,
            metrics: $metrics
        );
    }
}
