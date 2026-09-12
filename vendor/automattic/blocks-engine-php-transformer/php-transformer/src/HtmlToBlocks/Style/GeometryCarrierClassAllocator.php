<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** @internal Deterministic, collision-safe carrier class allocation. */
final class GeometryCarrierClassAllocator
{
    /** @var callable(string): string */
    private $digest;

    /** @var array<string, string> */
    private array $classBySignature = array();

    /** @var array<string, string> */
    private array $signatureByClass = array();

    /** @param callable(string): string|null $digest */
    public function __construct(?callable $digest = null)
    {
        $this->digest = $digest ?? static fn (string $value): string => hash('sha256', $value);
    }

    public function allocate(string $signature): string
    {
        if (isset($this->classBySignature[$signature])) {
            return $this->classBySignature[$signature];
        }

        $base = 'be-inline-geometry-' . ($this->digest)($signature);
        $className = $base;
        $attempt = 0;
        while (isset($this->signatureByClass[$className]) && $this->signatureByClass[$className] !== $signature) {
            ++$attempt;
            $className = $base . '-' . hash('sha256', $signature . ':' . $attempt);
        }

        $this->classBySignature[$signature] = $className;
        $this->signatureByClass[$className] = $signature;
        return $className;
    }
}
