<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Compression;

/**
 * Reports and executes available Zstandard decoding for PHP runtimes.
 */
final class ZstdCapability
{
    /**
     * @param callable|null $decoder Optional adapter: fn (string $payload, array $context): string|array{data?: string|null, diagnostics?: array<int, array<string, mixed>>}|false|null
     */
    public function __construct(
        private readonly mixed $decoder = null
    ) {
    }

    public function isAvailable(): bool
    {
        $status = $this->status();
        return true === $status['available'];
    }

    /**
     * @return array{available: bool, provider: string|null, extension_loaded: bool, extension_version: string|null, functions: array<string, bool>, adapter_registered: bool, wordpress_filter_registered: bool}
     */
    public function status(): array
    {
        $extensionLoaded = extension_loaded('zstd');
        $nativeAvailable = $extensionLoaded && function_exists('zstd_uncompress');
        $adapterRegistered = is_callable($this->decoder);
        $filterRegistered = $this->hasWordPressFilterDecoder();

        $provider = null;
        if ( $adapterRegistered ) {
            $provider = 'adapter';
        } elseif ( $nativeAvailable ) {
            $provider = 'ext-zstd';
        } elseif ( $filterRegistered ) {
            $provider = 'wordpress_filter';
        }

        return array(
            'available'         => null !== $provider,
            'provider'          => $provider,
            'extension_loaded'  => $extensionLoaded,
            'extension_version' => $extensionLoaded ? phpversion('zstd') ?: null : null,
            'functions'         => array(
                'zstd_compress'   => function_exists('zstd_compress'),
                'zstd_uncompress' => function_exists('zstd_uncompress'),
            ),
            'adapter_registered' => $adapterRegistered,
            'wordpress_filter_registered' => $filterRegistered,
        );
    }

    /**
     * @return array{data: string|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function uncompress(string $payload, string $source, int $chunkIndex, array $context = array()): array
    {
        $status = $this->status();

        if ( 'ext-zstd' === $status['provider'] ) {
            return $this->uncompressWithNativeExtension($payload, $source, $chunkIndex);
        }

        $decoder = $this->decoder();
        if ( null === $decoder ) {
            return array(
                'data'        => null,
                'diagnostics' => array($this->diagnostic($source, $chunkIndex)),
            );
        }

        try {
            $decoded = $decoder($payload, array_merge($context, array('source' => $source, 'chunk_index' => $chunkIndex, 'status' => $status)));
        } catch ( \Throwable $throwable ) {
            return array(
                'data'        => null,
                'diagnostics' => array(
                    array(
                        'code'    => 'figma_transformer_zstd_adapter_failed',
                        'message' => 'Zstandard chunk detected but the configured decoder adapter raised an error.',
                        'source'  => $source,
                        'context' => array_merge(
                            array(
                                'chunk_index' => $chunkIndex,
                                'error'       => $throwable->getMessage(),
                            ),
                            $status
                        ),
                    ),
                ),
            );
        }

        $diagnostics = array($this->diagnostic($source, $chunkIndex));
        if ( is_array($decoded) ) {
            $diagnostics = array_merge($diagnostics, is_array($decoded['diagnostics'] ?? null) ? $decoded['diagnostics'] : array());
            $decoded = $decoded['data'] ?? null;
            if ( ! is_string($decoded) ) {
                return array(
                    'data'        => null,
                    'diagnostics' => $diagnostics,
                );
            }
        }

        if ( is_string($decoded) ) {
            return array(
                'data'        => $decoded,
                'diagnostics' => $diagnostics,
            );
        }

        return array(
            'data'        => null,
            'diagnostics' => array(
                array(
                    'code'    => 'figma_transformer_zstd_adapter_failed',
                    'message' => 'Zstandard chunk detected but the configured decoder adapter did not return decoded bytes.',
                    'source'  => $source,
                    'context' => array_merge(array('chunk_index' => $chunkIndex), $status),
                ),
            ),
        );
    }

    /**
     * @return array{data: string|null, diagnostics: array<int, array<string, mixed>>}
     */
    private function uncompressWithNativeExtension(string $payload, string $source, int $chunkIndex): array
    {
        try {
            $decoded = zstd_uncompress($payload);
        } catch ( \Throwable $throwable ) {
            return array(
                'data'        => null,
                'diagnostics' => array(
                    array(
                        'code'    => 'figma_transformer_zstd_uncompress_failed',
                        'message' => 'Zstandard chunk detected but ext-zstd raised an error while decoding the payload.',
                        'source'  => $source,
                        'context' => array_merge(
                            array(
                                'chunk_index' => $chunkIndex,
                                'error'       => $throwable->getMessage(),
                            ),
                            $this->status()
                        ),
                    ),
                ),
            );
        }

        if ( false === $decoded ) {
            return array(
                'data'        => null,
                'diagnostics' => array(
                    array(
                        'code'    => 'figma_transformer_zstd_uncompress_failed',
                        'message' => 'Zstandard chunk detected but ext-zstd could not decode the payload.',
                        'source'  => $source,
                        'context' => array_merge(array('chunk_index' => $chunkIndex), $this->status()),
                    ),
                ),
            );
        }

        return array(
            'data'        => $decoded,
            'diagnostics' => array($this->diagnostic($source, $chunkIndex)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostic(string $source, int $chunkIndex): array
    {
        $status = $this->status();

        if ( 'ext-zstd' === $status['provider'] ) {
            return array(
                'code'    => 'figma_transformer_zstd_available',
                'message' => 'Zstandard chunk detected and ext-zstd is available.',
                'source'  => $source,
                'context' => array_merge(array('chunk_index' => $chunkIndex), $status),
            );
        }

        if ( null !== $status['provider'] ) {
            return array(
                'code'    => 'figma_transformer_zstd_adapter_available',
                'message' => 'Zstandard chunk detected and a configured decoder adapter is available.',
                'source'  => $source,
                'context' => array_merge(array('chunk_index' => $chunkIndex), $status),
            );
        }

        if ( true === $status['extension_loaded'] ) {
            return array(
                'code'    => 'figma_transformer_zstd_function_missing',
                'message' => 'Zstandard chunk detected; ext-zstd is loaded but zstd_uncompress is unavailable.',
                'source'  => $source,
                'context' => array_merge(array('chunk_index' => $chunkIndex), $status),
            );
        }

        return array(
            'code'    => 'figma_transformer_zstd_extension_missing',
            'message' => 'Zstandard chunk detected; install ext-zstd or register a decoder adapter to decode zstd-compressed fig-kiwi chunks.',
            'source'  => $source,
            'context' => array_merge(array('chunk_index' => $chunkIndex), $status),
        );
    }

    /**
     * @return callable|null
     */
    private function decoder(): ?callable
    {
        if ( is_callable($this->decoder) ) {
            return $this->decoder;
        }

        if ( function_exists('apply_filters') ) {
            $decoder = apply_filters('blocks_engine_figma_transformer_zstd_decoder', null, $this);
            if ( is_callable($decoder) ) {
                return $decoder;
            }
        }

        return null;
    }

    private function hasWordPressFilterDecoder(): bool
    {
        if ( ! function_exists('has_filter') || ! function_exists('apply_filters') ) {
            return false;
        }

        if ( false === has_filter('blocks_engine_figma_transformer_zstd_decoder') ) {
            return false;
        }

        return is_callable(apply_filters('blocks_engine_figma_transformer_zstd_decoder', null, $this));
    }
}
