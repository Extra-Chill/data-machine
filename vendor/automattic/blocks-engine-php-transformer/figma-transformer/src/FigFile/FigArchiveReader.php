<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\FigFile;

use ZipArchive;

/**
 * Reads safe metadata from Figma .fig archives and .fig wrapper ZIP files.
 */
final class FigArchiveReader
{
    private const DEFAULT_MAX_CANVAS_BYTES = 67108864;
    private const DEFAULT_MAX_NESTED_FIG_BYTES = 134217728;
    private const DEFAULT_MAX_ARCHIVE_ASSET_CONTENT_BYTES = 134217728;

    public function __construct(
        private readonly FigKiwiParser $figKiwiParser = new FigKiwiParser()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $path, array $options = array()): array
    {
        if ( ! is_readable($path) ) {
            return $this->errorResult($path, 'figma_transformer_unreadable_file', 'Figma file is not readable.');
        }

        $bytes = filesize($path);
        $input = array(
            'path'  => $path,
            'bytes' => false === $bytes ? 0 : $bytes,
        );

        if ( ! class_exists(ZipArchive::class) ) {
            return array(
                'input'       => $input,
                'archive'     => array(),
                'meta'        => array(),
                'assets'      => array(),
                'diagnostics' => array($this->diagnostic('figma_transformer_zip_extension_missing', 'ZipArchive is required to inspect .fig archives.', 'FigArchiveReader')),
            );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open($path) ) {
            return $this->errorResult($path, 'figma_transformer_invalid_zip', 'Figma file is not a readable ZIP archive.');
        }

        $entries = $this->entries($zip);
        $archiveMetrics = $this->archiveMetrics($zip, $entries);
        $figEntry = $this->findNestedFigEntry($entries);

        if ( null !== $figEntry ) {
            $nestedStat = $zip->statName($figEntry);
            $nestedBytes = is_array($nestedStat) ? (int) ($nestedStat['size'] ?? 0) : 0;
            $maxNestedFigBytes = $this->optionBytes($options, 'max_nested_fig_bytes', self::DEFAULT_MAX_NESTED_FIG_BYTES);
            if ( $maxNestedFigBytes > 0 && $nestedBytes > $maxNestedFigBytes ) {
                $zip->close();
                return array(
                    'input'       => $input + array('nested_fig' => $figEntry),
                    'archive'     => array(
                        'entries' => $entries,
                        'metrics' => $archiveMetrics,
                    ),
                    'meta'        => array(),
                    'assets'      => array(),
                    'diagnostics' => array($this->diagnostic(
                        'figma_transformer_nested_fig_preflight_failed',
                        'Nested .fig entry exceeds the configured safe read limit.',
                        'FigArchiveReader',
                        array(
                            'entry'          => $figEntry,
                            'bytes'          => $nestedBytes,
                            'max_read_bytes' => $maxNestedFigBytes,
                            'recommended_next_step' => 'Inspect the wrapper archive and raise max_nested_fig_bytes only when the PHP memory limit can safely hold the nested .fig bytes.',
                        )
                    )),
                );
            }

            $stream = $zip->getFromName($figEntry);
            $zip->close();

            if ( false === $stream ) {
                return $this->errorResult($path, 'figma_transformer_nested_fig_unreadable', 'Nested .fig file could not be read.');
            }

            $tmp = tempnam(sys_get_temp_dir(), 'blocks-engine-figma-');
            if ( false === $tmp ) {
                return $this->errorResult($path, 'figma_transformer_tempfile_failed', 'Temporary file could not be created for nested .fig inspection.');
            }

            file_put_contents($tmp, $stream);
            $result = $this->read($tmp, $options);
            @unlink($tmp);
            $result['input'] = $input + array('nested_fig' => $figEntry);
            return $result;
        }

        $meta = $this->readMeta($zip);
        $assetResult = $this->assetManifest($zip, $options);
        $assets = $assetResult['assets'];
        $canvasResult = $this->readCanvas($zip, $options);
        $canvas = $canvasResult['canvas'];
        $zip->close();

        $diagnostics = array_merge($assetResult['diagnostics'], $canvasResult['diagnostics']);
        if ( null === $canvas ) {
            $diagnostics[] = $this->diagnostic('figma_transformer_missing_canvas', 'Archive does not contain canvas.fig.', 'FigArchiveReader');
        }

        return array(
            'input'       => $input,
            'archive'     => array(
                'entries' => $entries,
                'metrics' => array_merge($archiveMetrics, $assetResult['metrics']),
                'canvas'  => $canvas,
            ),
            'meta'        => $meta,
            'assets'      => $assets,
            'diagnostics' => $diagnostics,
        );
    }

    /**
     * Hydrate one archive asset by metadata path/hash without reading every asset.
     *
     * @param array<string, mixed> $asset
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function hydrateAssetContent(string $path, array $asset, array $options = array()): ?array
    {
        if ( ! is_readable($path) || ! class_exists(ZipArchive::class) ) {
            return null;
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open($path) ) {
            return null;
        }

        $entries = $this->entries($zip);
        $figEntry = $this->findNestedFigEntry($entries);
        if ( null !== $figEntry ) {
            $stream = $zip->getFromName($figEntry);
            $zip->close();
            if ( false === $stream ) {
                return null;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'blocks-engine-figma-asset-');
            if ( false === $tmp ) {
                return null;
            }

            file_put_contents($tmp, $stream);
            $hydrated = $this->hydrateAssetContent($tmp, $asset, $options);
            @unlink($tmp);
            return $hydrated;
        }

        $assetPath = $this->assetArchivePath($asset);
        if ( null === $assetPath ) {
            $zip->close();
            return null;
        }

        $stat = $zip->statName($assetPath);
        if ( ! is_array($stat) ) {
            $zip->close();
            return null;
        }

        $bytes = (int) ($stat['size'] ?? 0);
        $maxAssetBytes = $this->optionBytes($options, 'max_archive_asset_hydration_bytes', self::DEFAULT_MAX_ARCHIVE_ASSET_CONTENT_BYTES);
        if ( $maxAssetBytes > 0 && $bytes > $maxAssetBytes ) {
            $zip->close();
            return array_merge($asset, array('content_omitted' => true));
        }

        if ( ! $this->hasMemoryHeadroomForAsset($bytes) ) {
            $zip->close();
            return array_merge($asset, array('content_omitted' => true));
        }

        $content = $zip->getFromName($assetPath);
        $zip->close();
        if ( false === $content ) {
            return null;
        }

        return array_merge($asset, array(
            'path'      => $assetPath,
            'content'   => $content,
            'mime_type' => $this->mimeTypeForPath($assetPath, $content),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function entries(ZipArchive $zip): array
    {
        $entries = array();
        for ( $index = 0; $index < $zip->numFiles; $index++ ) {
            $name = $zip->getNameIndex($index);
            if ( false !== $name && ! str_starts_with($name, '__MACOSX/') ) {
                $entries[] = $name;
            }
        }

        return $entries;
    }

    /**
     * @param array<int, string> $entries
     */
    private function findNestedFigEntry(array $entries): ?string
    {
        foreach ( $entries as $entry ) {
            if ( str_ends_with(strtolower($entry), '.fig') && 'canvas.fig' !== $entry ) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('meta.json');
        if ( false === $raw ) {
            return array();
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @return array{assets: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>, metrics: array<string, mixed>}
     */
    private function assetManifest(ZipArchive $zip, array $options = array()): array
    {
        $assets = array();
        $diagnostics = array();
        $includeContent = false !== ($options['include_asset_content'] ?? true);
        $assetStats = $this->assetStats($zip);
        $maxAssetContentBytes = $this->optionBytes($options, 'max_archive_asset_content_bytes', self::DEFAULT_MAX_ARCHIVE_ASSET_CONTENT_BYTES);

        if ( $includeContent && $maxAssetContentBytes > 0 && $assetStats['total_asset_bytes'] > $maxAssetContentBytes ) {
            $includeContent = false;
            $diagnostics[] = $this->diagnostic(
                'figma_transformer_archive_asset_content_omitted_size',
                'Embedded asset content exceeds the configured safe read limit; asset metadata was retained without loading all bytes into memory.',
                'FigArchiveReader',
                array(
                    'asset_count'             => $assetStats['asset_count'],
                    'total_asset_bytes'       => $assetStats['total_asset_bytes'],
                    'largest_asset_bytes'     => $assetStats['largest_asset_bytes'],
                    'largest_asset_path'      => $assetStats['largest_asset_path'],
                    'max_asset_content_bytes' => $maxAssetContentBytes,
                    'recommended_next_step'   => 'Run with --omit-asset-content for archive inspection, or raise max_archive_asset_content_bytes only when the PHP memory limit can safely hold embedded asset bytes.',
                )
            );
        }

        for ( $index = 0; $index < $zip->numFiles; $index++ ) {
            $name = $zip->getNameIndex($index);
            if ( false === $name || ! str_starts_with($name, 'images/') || str_ends_with($name, '/') ) {
                continue;
            }

            $stat = $zip->statIndex($index);
            $content = $includeContent ? $zip->getFromIndex($index) : $zip->getFromIndex($index, 64);
            $hash = basename($name);
            $contentString = false === $content ? '' : $content;
            $asset = array(
                'id'        => $hash,
                'name'      => $hash,
                'path'      => $name,
                'hash'      => $hash,
                'bytes'     => is_array($stat) ? (int) ($stat['size'] ?? 0) : 0,
                'mime_type' => $this->mimeTypeForPath($name, $contentString),
            );

            if ( $includeContent ) {
                $asset['content'] = $contentString;
            } else {
                $asset['content_omitted'] = true;
            }

            $assets[] = $asset;
        }

        return array(
            'assets'      => $assets,
            'diagnostics' => $diagnostics,
            'metrics'     => $assetStats + array(
                'asset_content_included' => $includeContent,
                'max_archive_asset_content_bytes' => $maxAssetContentBytes,
            ),
        );
    }

    private function mimeTypeForPath(string $path, string $content = ''): string
    {
        $extensionMimeType = match ( strtolower(pathinfo($path, PATHINFO_EXTENSION)) ) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        if ( 'application/octet-stream' !== $extensionMimeType || '' === $content ) {
            return $extensionMimeType;
        }

        if ( str_starts_with($content, "\x89PNG\r\n\x1a\n") ) {
            return 'image/png';
        }
        if ( str_starts_with($content, "\xff\xd8\xff") ) {
            return 'image/jpeg';
        }
        if ( str_starts_with($content, 'RIFF') && 'WEBP' === substr($content, 8, 4) ) {
            return 'image/webp';
        }
        if ( str_starts_with(ltrim($content), '<svg') ) {
            return 'image/svg+xml';
        }

        return 'application/octet-stream';
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function assetArchivePath(array $asset): ?string
    {
        if ( isset($asset['path']) && is_scalar($asset['path']) ) {
            $path = (string) $asset['path'];
            return str_starts_with($path, 'images/') && ! str_ends_with($path, '/') ? $path : null;
        }

        foreach ( array('hash', 'id', 'name') as $key ) {
            if ( isset($asset[$key]) && is_scalar($asset[$key]) && '' !== (string) $asset[$key] ) {
                return 'images/' . (string) $asset[$key];
            }
        }

        return null;
    }

    private function hasMemoryHeadroomForAsset(int $bytes): bool
    {
        $memoryLimit = $this->memoryLimitBytes();
        if ( $memoryLimit <= 0 || $bytes <= 0 ) {
            return true;
        }

        $available = $memoryLimit - memory_get_usage(true);
        // ZipArchive materializes the string and PHP may transiently duplicate it
        // while the caller stores the hydrated asset. Keep a small fixed cushion.
        $required = ($bytes * 2) + 8388608;

        return $available > $required;
    }

    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ( '' === $value || '-1' === $value ) {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        if ( $number <= 0 ) {
            return 0;
        }

        return (int) match ( $unit ) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @return array{canvas: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    private function readCanvas(ZipArchive $zip, array $options = array()): array
    {
        $stat = $zip->statName('canvas.fig');
        if ( is_array($stat) ) {
            $canvasBytes = (int) ($stat['size'] ?? 0);
            $maxCanvasBytes = $this->optionBytes($options, 'max_canvas_bytes', self::DEFAULT_MAX_CANVAS_BYTES);
            if ( $maxCanvasBytes > 0 && $canvasBytes > $maxCanvasBytes ) {
                return array(
                    'canvas'      => array(
                        'bytes'   => $canvasBytes,
                        'skipped' => true,
                        'reason'  => 'canvas_decode_preflight_size_limit',
                        'stat'    => $this->zipStatReport($stat),
                    ),
                    'diagnostics' => array($this->diagnostic(
                        'figma_transformer_canvas_decode_preflight_failed',
                        'canvas.fig exceeds the configured safe decode read limit.',
                        'FigArchiveReader',
                        array(
                            'bytes'          => $canvasBytes,
                            'max_read_bytes' => $maxCanvasBytes,
                            'recommended_next_step' => 'Raise max_canvas_bytes only when the PHP memory limit can safely hold canvas.fig and inflated decode chunks, or inspect the archive with a lower-scope fixture.',
                        )
                    )),
                );
            }
        }

        $raw = $zip->getFromName('canvas.fig');
        if ( false === $raw ) {
            return array(
                'canvas'      => null,
                'diagnostics' => array(),
            );
        }

        return $this->figKiwiParser->parse($raw, $options);
    }

    /**
     * @param array<int, string> $entries
     * @return array<string, mixed>
     */
    private function archiveMetrics(ZipArchive $zip, array $entries): array
    {
        $assetStats = $this->assetStats($zip);
        $canvasStat = $zip->statName('canvas.fig');

        return array_merge(
            array(
                'entry_count' => count($entries),
                'canvas'     => is_array($canvasStat) ? $this->zipStatReport($canvasStat) : null,
            ),
            $assetStats
        );
    }

    /**
     * @return array{asset_count: int, total_asset_bytes: int, largest_asset_bytes: int, largest_asset_path: string|null}
     */
    private function assetStats(ZipArchive $zip): array
    {
        $assetCount = 0;
        $totalAssetBytes = 0;
        $largestAssetBytes = 0;
        $largestAssetPath = null;

        for ( $index = 0; $index < $zip->numFiles; $index++ ) {
            $name = $zip->getNameIndex($index);
            if ( false === $name || ! str_starts_with($name, 'images/') || str_ends_with($name, '/') ) {
                continue;
            }

            $stat = $zip->statIndex($index);
            $bytes = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
            $assetCount++;
            $totalAssetBytes += $bytes;
            if ( $bytes > $largestAssetBytes ) {
                $largestAssetBytes = $bytes;
                $largestAssetPath = $name;
            }
        }

        return array(
            'asset_count'         => $assetCount,
            'total_asset_bytes'   => $totalAssetBytes,
            'largest_asset_bytes' => $largestAssetBytes,
            'largest_asset_path'  => $largestAssetPath,
        );
    }

    /**
     * @param array<string, mixed> $stat
     * @return array<string, int|string>
     */
    private function zipStatReport(array $stat): array
    {
        return array(
            'name'             => (string) ($stat['name'] ?? ''),
            'bytes'            => (int) ($stat['size'] ?? 0),
            'compressed_bytes' => (int) ($stat['comp_size'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function optionBytes(array $options, string $key, int $default): int
    {
        if ( isset($options[$key]) && is_numeric($options[$key]) ) {
            return max(0, (int) $options[$key]);
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResult(string $path, string $code, string $message): array
    {
        return array(
            'input'       => array('path' => $path, 'bytes' => 0),
            'archive'     => array(),
            'meta'        => array(),
            'assets'      => array(),
            'diagnostics' => array($this->diagnostic($code, $message, 'FigArchiveReader')),
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $message, string $source, array $context = array()): array
    {
        return array(
            'code'    => $code,
            'message' => $message,
            'source'  => $source,
            'context' => $context,
        );
    }
}
