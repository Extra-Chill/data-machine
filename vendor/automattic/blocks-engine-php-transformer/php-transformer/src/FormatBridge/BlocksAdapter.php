<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge;

use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/**
 * @internal Bundled adapters are implementation details of FormatBridge.
 */
final class BlocksAdapter implements FormatAdapterInterface
{
    public function __construct(private readonly Runtime $runtime = new Runtime())
    {
    }

    public function slug(): string
    {
        return 'blocks';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function toBlocks(string $content, array $options = array()): array
    {
        unset($options);

        return $this->runtime->parseBlocks($content);
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param array<string, mixed> $options
     */
    public function fromBlocks(array $blocks, array $options = array()): string
    {
        unset($options);

        return $this->runtime->serializeBlocks(array_values($blocks));
    }

    public function detect(string $content): bool
    {
        return (bool) preg_match('/<!--\s*\/?wp:/', $content);
    }
}
