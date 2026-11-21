<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge;

interface FormatAdapterInterface
{
    public function slug(): string;

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, array<string, mixed>>
     */
    public function toBlocks(string $content, array $options = array()): array;

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param array<string, mixed> $options
     */
    public function fromBlocks(array $blocks, array $options = array()): string;

    public function detect(string $content): bool;
}
