<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

use InvalidArgumentException;

final class TransformerResult
{
    public const SCHEMA = 'blocks-engine/php-transformer/result/v1';

    /**
     * @param array<int, array<string, mixed>> $components
     * @param array<int, array<string, mixed>> $blockTypes
     * @param array<string, mixed> $sourceReports
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $documents
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $provenance
     * @param array<int, array<string, mixed>> $coverage
     * @param array<string, mixed> $context
     * @param array<string, int|float> $metrics
     */
    public function __construct(
        public readonly string $status = 'success',
        public readonly array $components = array(),
        public readonly array $blockTypes = array(),
        public readonly array $sourceReports = array(),
        public readonly array $blocks = array(),
        public readonly string $serializedBlocks = '',
        public readonly array $documents = array(),
        public readonly array $assets = array(),
        public readonly array $diagnostics = array(),
        public readonly array $fallbacks = array(),
        public readonly array $provenance = array(),
        public readonly array $coverage = array(),
        public readonly array $context = array(),
        public readonly array $metrics = array()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = array(
            'schema'            => self::SCHEMA,
            'status'            => $this->status,
            'components'        => $this->components,
            'block_types'       => $this->blockTypes,
            'source_reports'    => $this->sourceReports,
            'blocks'            => $this->blocks,
            'serialized_blocks' => $this->serializedBlocks,
            'documents'         => $this->documents,
            'assets'            => $this->assets,
            'diagnostics'       => $this->diagnostics,
            'fallbacks'         => $this->fallbacks,
            'provenance'        => $this->provenance,
            'coverage'          => $this->coverage,
            'context'           => $this->context,
            'metrics'           => $this->metrics,
        );

        self::assertCanonicalEnvelope($result);

        return $result;
    }

    /**
     * Validate the public result shape downstream wrappers should depend on.
     *
     * @param array<string, mixed> $result
     */
    public static function assertCanonicalEnvelope(array $result, bool $requireMaterializationPlan = false): void
    {
        foreach ( array( 'schema', 'status', 'components', 'block_types', 'source_reports', 'blocks', 'serialized_blocks', 'documents', 'assets', 'diagnostics', 'fallbacks', 'provenance', 'coverage', 'context', 'metrics' ) as $key ) {
            if ( ! array_key_exists($key, $result) ) {
                throw new InvalidArgumentException(sprintf('Canonical transformer result is missing "%s".', $key));
            }
        }

        if ( self::SCHEMA !== $result['schema'] ) {
            throw new InvalidArgumentException('Canonical transformer result has an unsupported schema.');
        }

        if ( array_key_exists('legacy_mapping', $result) ) {
            throw new InvalidArgumentException('Canonical transformer result must not expose compatibility-only legacy_mapping.');
        }

        foreach ( array( 'conversion_report', 'materialization_plan' ) as $key ) {
            if ( array_key_exists($key, $result) ) {
                throw new InvalidArgumentException(sprintf('Canonical transformer result must expose %s only under source_reports.', $key));
            }
        }

        if ( ! in_array($result['status'], array( 'success', 'success_with_warnings', 'failed' ), true) ) {
            throw new InvalidArgumentException('Canonical transformer result has an unsupported status.');
        }

        foreach ( array( 'components', 'block_types', 'blocks', 'documents', 'assets', 'diagnostics', 'fallbacks', 'provenance', 'coverage', 'context', 'metrics' ) as $key ) {
            if ( ! is_array($result[$key]) ) {
                throw new InvalidArgumentException(sprintf('Canonical transformer result %s must be an array.', $key));
            }
        }

        if ( ! is_array($result['source_reports']) ) {
            throw new InvalidArgumentException('Canonical transformer result source_reports must be an array.');
        }

        $sourceReports = $result['source_reports'];
        if ( array_key_exists('legacy_mapping', $sourceReports) ) {
            throw new InvalidArgumentException('Canonical transformer result source_reports must not expose compatibility-only legacy_mapping.');
        }

        $conversionReport = $sourceReports['conversion_report'] ?? null;
        if ( ! is_array($conversionReport) ) {
            throw new InvalidArgumentException('Canonical transformer result is missing source_reports.conversion_report.');
        }

        if ( ConversionReportProjection::SCHEMA !== ($conversionReport['schema'] ?? null) ) {
            throw new InvalidArgumentException('Canonical transformer result has an unsupported conversion report schema.');
        }

        if ( ! is_string($conversionReport['source_format'] ?? null) || '' === $conversionReport['source_format'] ) {
            throw new InvalidArgumentException('Canonical transformer result conversion report is missing source_format.');
        }

        foreach ( array( 'source_summary', 'selector_summary', 'fallback_diagnostics', 'asset_refs', 'navigation_candidates', 'presentation_gaps', 'metrics' ) as $key ) {
            if ( array_key_exists($key, $conversionReport) && ! is_array($conversionReport[$key]) ) {
                throw new InvalidArgumentException(sprintf('Canonical transformer result conversion report %s must be an array.', $key));
            }
        }

        $artifactLike = isset($sourceReports['artifact']) || isset($sourceReports['compiled_site']) || 'artifact' === ($conversionReport['source_format'] ?? null);
        if ( $requireMaterializationPlan || $artifactLike ) {
            $materializationPlan = $sourceReports['materialization_plan'] ?? null;
            if ( ! is_array($materializationPlan) ) {
                throw new InvalidArgumentException('Canonical artifact result is missing source_reports.materialization_plan.');
            }

            if ( 'blocks-engine/php-transformer/materialization-plan/v1' !== ($materializationPlan['schema'] ?? null) ) {
                throw new InvalidArgumentException('Canonical artifact result has an unsupported materialization plan schema.');
            }

            foreach ( array( 'pages', 'routes', 'navigation_links', 'menus', 'template_parts', 'template_part_writes', 'assets', 'theme', 'asset_rewrite_candidates', 'rewrite_candidates', 'totals' ) as $key ) {
                if ( ! array_key_exists($key, $materializationPlan) || ! is_array($materializationPlan[$key]) ) {
                    throw new InvalidArgumentException(sprintf('Canonical artifact result materialization plan %s must be an array.', $key));
                }
            }
        }
    }
}
