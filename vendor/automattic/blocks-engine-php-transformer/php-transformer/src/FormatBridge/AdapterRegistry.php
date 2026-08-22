<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge;

/**
 * @internal Adapter storage is an implementation detail of FormatBridge.
 */
final class AdapterRegistry
{
    /**
     * @var array<string, FormatAdapterInterface>
     */
    private array $adapters = array();

    /**
     * @param list<string> $supportedFormats
     */
    public function __construct(
        private array $supportedFormats = array( 'blocks', 'html', 'markdown' )
    ) {
    }

    public function register(FormatAdapterInterface $adapter): void
    {
        $slug = $adapter->slug();

        $this->adapters[$slug] = $adapter;
        if ( ! in_array($slug, $this->supportedFormats, true) ) {
            $this->supportedFormats[] = $slug;
        }
    }

    public function get(string $slug): ?FormatAdapterInterface
    {
        return $this->adapters[$slug] ?? null;
    }

    public function supports(string $slug): bool
    {
        return in_array($slug, $this->supportedFormats, true);
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_values($this->supportedFormats);
    }
}
