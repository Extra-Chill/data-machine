<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Contract;

/**
 * Stable result envelope for Figma transformation calls.
 */
final class FigmaTransformResult
{
    public const SCHEMA = 'blocks-engine/figma-transformer/result/v1';

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $assets
     * @param array<string, mixed>             $sourceReports
     * @param array<string, mixed>             $parity
     * @param array<string, mixed>             $metrics
     */
    public function __construct(
        private readonly string $status,
        private readonly array $diagnostics = array(),
        private readonly array $files = array(),
        private readonly array $assets = array(),
        private readonly array $sourceReports = array(),
        private readonly array $parity = array(),
        private readonly array $metrics = array()
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $assets
     * @param array<string, mixed>             $sourceReports
     * @param array<string, mixed>             $parity
     * @param array<string, mixed>             $metrics
     */
    public static function create(
        string $status,
        array $diagnostics = array(),
        array $files = array(),
        array $assets = array(),
        array $sourceReports = array(),
        array $parity = array(),
        array $metrics = array()
    ): self {
        return new self($status, $diagnostics, $files, $assets, $sourceReports, $parity, $metrics);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'schema'         => self::SCHEMA,
            'status'         => $this->status,
            'diagnostics'    => $this->diagnostics,
            'files'          => $this->files,
            'assets'         => $this->assets,
            'source_reports' => $this->sourceReports,
            'parity'         => $this->parity,
            'metrics'        => $this->metrics,
        );
    }
}
