<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

/**
 * @internal Bundled adapters are implementation details of FormatBridge.
 */
final class HtmlAdapter implements FormatAdapterInterface
{
    public function __construct(
        private readonly HtmlTransformer $transformer = new HtmlTransformer(),
        private readonly Runtime $runtime = new Runtime()
    ) {
    }

    public function slug(): string
    {
        return 'html';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function toBlocks(string $content, array $options = array()): array
    {
        if ( '' === trim($content) ) {
            return array();
        }

        $result = $this->transformer->transform($content, $options)->toArray();

        return is_array($result['blocks'] ?? null) ? $result['blocks'] : array();
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param array<string, mixed> $options
     */
    public function fromBlocks(array $blocks, array $options = array()): string
    {
        unset($options);

        return $this->runtime->renderBlocks(array_values($blocks));
    }

    public function detect(string $content): bool
    {
        return (bool) preg_match('/<([a-z][a-z0-9-]*)\b[^>]*>/i', $content);
    }
}
