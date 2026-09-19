<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

/**
 * Immutable classification verdict produced by {@see SubtreeClassifier}.
 *
 * `bucket` is one of the {@see SubtreeClassifier} BUCKET_* constants, `confidence`
 * is a 0..1 score, and `signals` is the structural evidence (booleans, counts and
 * per-bucket scores) that drove the verdict — surfaced purely for diagnostics.
 */
final class ClassificationResult
{
    /**
     * @param array<string, mixed> $signals
     */
    public function __construct(
        private readonly string $bucket,
        private readonly float $confidence,
        private readonly array $signals = array()
    ) {
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function confidence(): float
    {
        return $this->confidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function signals(): array
    {
        return $this->signals;
    }

    public function is(string $bucket): bool
    {
        return $this->bucket === $bucket;
    }

    /**
     * @return array{bucket: string, confidence: float, signals: array<string, mixed>}
     */
    public function toArray(): array
    {
        return array(
            'bucket'     => $this->bucket,
            'confidence' => $this->confidence,
            'signals'    => $this->signals,
        );
    }
}
