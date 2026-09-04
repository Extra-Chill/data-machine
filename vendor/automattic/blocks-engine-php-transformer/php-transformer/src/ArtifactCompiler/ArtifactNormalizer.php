<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\ReferenceAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;

/**
 * Normalizes loose website artifact envelopes into compiler-ready file records.
 *
 * @internal Artifact normalization is owned by ArtifactCompiler.
 */
final class ArtifactNormalizer
{
    public const DEFAULT_MAX_FILES = 500;
    public const DEFAULT_MAX_FILE_BYTES = 5242880;
    public const DEFAULT_MAX_TOTAL_BYTES = 52428800;
    public const MAX_FILES = 5000;
    public const MAX_FILE_BYTES = 10485760;
    public const MAX_TOTAL_BYTES = 335544320;

    /**
     * @param array<string, mixed> $artifact
     * @return array{files: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>, rejected_count: int, bytes: int, limits: array{max_files:int,max_file_bytes:int,max_total_bytes:int}, entrypoints: array<int, string>, source_hash: string, hash_payload: string, runtime_declarations: array<int,array<string,mixed>>, truncation_impact: array<string,mixed>|null}
     */
    public function normalize(array $artifact): array
    {
        $runtimeDeclarations = RuntimeDeclarations::normalize($artifact);
        $diagnostics = array();
        $files = array();
        $entrypoints = array();
        $rejected = 0;
        $bytes = 0;
        $truncationImpact = null;
        $seenPaths = array();
        $limits = $this->limits($artifact);

        foreach ( array('entrypoint', 'entry', 'main') as $key ) {
            if ( is_string($artifact[$key] ?? null) ) {
                $entrypoints[] = $artifact[$key];
            }
        }
        if ( is_array($artifact['entrypoints'] ?? null) ) {
            foreach ( $artifact['entrypoints'] as $entrypoint ) {
                if ( is_string($entrypoint) ) {
                    $entrypoints[] = $entrypoint;
                }
            }
        }

        $rawFiles = $this->rawFiles($artifact);
        $reservedPaths = array();
        foreach ( $rawFiles as $file ) {
            $path = ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''));
            if ( '' !== $path ) {
                $reservedPaths[$path] = true;
            }
        }
        $rawFiles = $this->sourceFilesBeforeGeneratedExpansions(
            $this->withInlineScriptFiles($this->withInlineStyleFiles($rawFiles, $reservedPaths)),
            $limits['max_files']
        );
        $safeEntrypoints = array();
        foreach ( array_unique($entrypoints) as $entrypoint ) {
            $path = ArtifactPath::safeRelativePath($entrypoint);
            if ( '' === $path ) {
                $diagnostics[] = $this->diagnostic('unsafe_entrypoint_path', 'warning', 'An artifact entrypoint was ignored because its path is empty, absolute, or escapes the artifact root.', array('path' => $entrypoint));
                continue;
            }
            $safeEntrypoints[] = $path;
        }

        foreach ( $rawFiles as $index => $file ) {
            if ( count($files) >= $limits['max_files'] ) {
                ++$rejected;
                $truncationImpact = $this->truncationImpact(array_slice($rawFiles, $index), $files);
                $diagnostics[] = $this->diagnostic('file_limit_exceeded', 'warning', 'Additional artifact files were ignored because the file limit was reached.', array('max_files' => $limits['max_files'], 'truncation_impact' => $truncationImpact));
                break;
            }

            $path = ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''));
            if ( '' === $path ) {
                ++$rejected;
                $diagnostics[] = $this->diagnostic('unsafe_artifact_path', 'warning', 'An artifact file was ignored because its path is empty, absolute, or escapes the artifact root.', array('index' => $index));
                continue;
            }

            $payload = $this->payload($file, $path);
            $diagnostics = array_merge($diagnostics, $payload['diagnostics']);
            if ( ! $payload['accepted'] ) {
                ++$rejected;
                continue;
            }

            if ( $payload['bytes'] > $limits['max_file_bytes'] ) {
                ++$rejected;
                $diagnostics[] = $this->diagnostic('artifact_file_too_large', 'warning', 'An artifact file was ignored because it exceeds the per-file byte limit.', array('path' => $path, 'bytes' => $payload['bytes'], 'max_file_bytes' => $limits['max_file_bytes']));
                continue;
            }

            if ( $bytes + $payload['bytes'] > $limits['max_total_bytes'] ) {
                ++$rejected;
                $diagnostics[] = $this->diagnostic('artifact_total_too_large', 'warning', 'An artifact file was ignored because the bundle byte limit was reached.', array('path' => $path, 'bytes' => $payload['bytes'], 'max_total_bytes' => $limits['max_total_bytes']));
                continue;
            }

            $path = $this->dedupePath($path, $seenPaths);
            $seenPaths[$path] = true;
            $mimeType = $this->mimeType((string) ($file['mime_type'] ?? $file['mime'] ?? $file['media_type'] ?? (str_contains((string) ($file['type'] ?? ''), '/') ? $file['type'] : '')), $path);
            $kind = $this->kind((string) ($file['kind'] ?? $file['type'] ?? ''), $path, $payload['content'], $mimeType);
            $declaredRole = $this->sanitizeKey((string) ($file['role'] ?? ''));
            $role = $this->role($declaredRole, $kind, $mimeType, $path);
            $intent = $this->intent((string) ($file['intent'] ?? ''), $kind, $role);
            $binary = $payload['binary'] || ( ! $this->isTextKind($kind) && $this->isBinaryMimeType($mimeType) );
            $contentBase64 = $payload['content_base64'];
            if ( $binary && '' === $contentBase64 && !is_array($payload['payload_reference'] ?? null) ) {
                $contentBase64 = base64_encode($payload['content']);
            }
            $entrypoint = in_array($path, $safeEntrypoints, true) || ! empty($file['entrypoint']) || 'entry' === $declaredRole;
            if ( $entrypoint && ! in_array($path, $safeEntrypoints, true) ) {
                $safeEntrypoints[] = $path;
            }

            $normalized = array(
                'path'       => $path,
                'content'    => $payload['content'],
                'kind'       => $kind,
                'bytes'      => $payload['bytes'],
                'source'     => (string) ($file['source'] ?? 'artifact'),
                'mime_type'  => $mimeType,
                'role'       => $role,
                'encoding'   => $payload['encoding'],
                'binary'     => $binary,
                'entrypoint' => $entrypoint,
                'provenance' => array(
                    'source_path' => $path,
                    'source'      => (string) ($file['source'] ?? 'artifact'),
                    'hash'        => hash('sha256', '' !== $contentBase64 ? $contentBase64 : $payload['content']),
                ),
            );
            $rawSha256 = is_array($payload['payload_reference'] ?? null)
                ? $payload['payload_reference']['sha256']
                : hash('sha256', '' !== $contentBase64 ? (string) base64_decode($contentBase64, true) : $payload['content']);
            if ($binary) {
                $normalized['raw_sha256'] = $rawSha256;
                // The established wire digest hashes canonical base64. A raw-byte
                // digest alone cannot yield it without hydrating the payload.
                if ('' !== $contentBase64) $normalized['transport_sha256'] = hash('sha256', $contentBase64);
            }
            if ( '' !== $contentBase64 ) {
                $normalized['content_base64'] = $contentBase64;
            }
            if ( is_array($payload['payload_reference'] ?? null) ) {
                $normalized['payload_reference'] = $payload['payload_reference'];
            }
            if ( '' !== $intent ) {
                $normalized['intent'] = $intent;
            }
            if ( is_array($file['metadata'] ?? null) ) {
                $metadata = array();
                if ( is_string($file['metadata']['route_path'] ?? null) && '' !== trim($file['metadata']['route_path']) ) {
                    $metadata['route_path'] = trim($file['metadata']['route_path']);
                }
                if ( is_string($file['metadata']['post_type'] ?? null) && in_array(strtolower($file['metadata']['post_type']), array('page', 'post'), true) ) {
                    $metadata['post_type'] = strtolower($file['metadata']['post_type']);
                }
                if ( is_array($file['metadata']['compilation'] ?? null) ) {
                    $metadata['compilation'] = $file['metadata']['compilation'];
                }
                if ( array() !== $metadata ) {
                    $normalized['metadata'] = $metadata;
                }
            }
            foreach ( array('placement', 'type', 'media', 'source_path', 'selector', 'stylesheet_index', 'superseded_by') as $field ) {
                if ( isset($file[$field]) && is_scalar($file[$field]) && '' !== trim((string) $file[$field]) ) {
                    $normalized[$field] = (string) $file[$field];
                }
            }
            foreach ( array('defer', 'async') as $field ) {
                if ( isset($file[$field]) ) {
                    $normalized[$field] = (bool) $file[$field];
                }
            }

            if ( 'mdx' === $kind ) {
                $diagnostics[] = $this->diagnostic('mdx_source_document_detected', 'warning', 'MDX source document support is partial; the source was preserved and inspectable document/component metadata was extracted.', array('path' => $path));
            }

            $bytes += $normalized['bytes'];
            $files[] = $normalized;
        }

        $runtimeDeclarations = RuntimeDeclarations::bindAssetPublications($runtimeDeclarations, $files);
        $sourceHash = $this->sourceHash($files, $runtimeDeclarations);
        return array(
            'files'          => $files,
            'diagnostics'    => $this->dedupeDiagnostics($diagnostics),
            'rejected_count' => $rejected,
            'bytes'          => $bytes,
            'limits'         => $limits,
            'entrypoints'    => array_values(array_unique($safeEntrypoints)),
            'source_hash'    => $sourceHash,
            'hash_payload'   => $sourceHash,
            'runtime_declarations' => $runtimeDeclarations,
            'truncation_impact' => $truncationImpact,
        );
    }

    /**
     * Bounded evidence for files omitted solely because the file limit was
     * reached. Only references from admitted files can affect the compiled
     * output, so those determine whether the omission is a gating loss.
     *
     * @param array<int,array<string,mixed>> $omittedFiles
     * @param array<int,array<string,mixed>> $admittedFiles
     * @return array<string,mixed>
     */
    private function truncationImpact(array $omittedFiles, array $admittedFiles): array
    {
        $byClass = array(
            'generated' => array('count' => 0, 'bytes' => 0),
            'source' => array('count' => 0, 'bytes' => 0),
        );
        $omittedPaths = array();
        $pathSamples = array();
        $seenPaths = array_fill_keys(array_column($admittedFiles, 'path'), true);
        foreach ( $omittedFiles as $file ) {
            $path = ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''));
            if ( '' === $path ) {
                continue;
            }
            $payload = $this->payload($file, $path);
            if ( ! $payload['accepted'] ) {
                continue;
            }
            // Omitted rows share the same canonical namespace as admitted rows.
            // A later duplicate becomes assets/logo-2.svg, not assets/logo.svg.
            $path = $this->dedupePath($path, $seenPaths);
            $seenPaths[$path] = true;
            $class = in_array((string) ($file['source'] ?? ''), array('inline-style', 'inline-script'), true) ? 'generated' : 'source';
            ++$byClass[$class]['count'];
            $byClass[$class]['bytes'] += $payload['bytes'];
            $omittedPaths[$path] = $class;
            $pathSamples[] = array('path' => $path, 'source_class' => $class, 'bytes' => $payload['bytes']);
        }
        usort($pathSamples, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));

        $referenceSamples = array();
        $references = new ReferenceAnalyzer();
        foreach ( $admittedFiles as $file ) {
            if ( ! empty($file['binary']) ) {
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            $candidates = 'css' === ($file['kind'] ?? '')
                ? $references->cssReferenceCandidates((string) ($file['content'] ?? ''), $path)
                : (in_array($file['kind'] ?? '', array('html', 'blocks'), true) ? $references->htmlReferenceCandidates((string) ($file['content'] ?? ''), $path) : array());
            foreach ( $candidates as $candidate ) {
                $resolvedPath = ArtifactPath::resolveRelativePath($candidate['url'], $path);
                if ( ! isset($omittedPaths[$resolvedPath]) ) {
                    continue;
                }
                $referenceSamples[] = array(
                    'source_path' => $path,
                    'selector' => $candidate['selector'],
                    'attribute' => $candidate['attribute'],
                    'resolved_path' => $resolvedPath,
                    'source_class' => $omittedPaths[$resolvedPath],
                );
            }
        }
        usort($referenceSamples, static fn(array $left, array $right): int => strcmp(json_encode($left) ?: '', json_encode($right) ?: ''));

        $omittedCount = $byClass['generated']['count'] + $byClass['source']['count'];
        $omittedBytes = $byClass['generated']['bytes'] + $byClass['source']['bytes'];
        $impact = array(
            'schema' => 'blocks-engine/artifact-truncation-impact/v1',
            'completeness' => array() === $referenceSamples ? 'warning' : 'gating_loss',
            'omitted_count' => $omittedCount,
            'omitted_bytes' => $omittedBytes,
            'omitted_by_source_class' => $byClass,
            'omitted_path_samples' => array_slice($pathSamples, 0, 10),
            'reference_reachability' => array(
                'referenced_omitted_count' => count(array_unique(array_column($referenceSamples, 'resolved_path'))),
                'reference_samples' => array_slice($referenceSamples, 0, 10),
            ),
        );
        $impact['evidence_hash'] = hash('sha256', RuntimeDeclarations::canonicalJson($impact));
        return $impact;
    }

    /** @param array<string,mixed> $artifact @return array{max_files:int,max_file_bytes:int,max_total_bytes:int} */
    private function limits(array $artifact): array
    {
        $requested = is_array($artifact['compiler_limits'] ?? null) ? $artifact['compiler_limits'] : array();
        return array(
            'max_files'       => min(self::MAX_FILES, max(1, (int) ($requested['max_files'] ?? self::DEFAULT_MAX_FILES))),
            'max_file_bytes'  => min(self::MAX_FILE_BYTES, max(1, (int) ($requested['max_file_bytes'] ?? self::DEFAULT_MAX_FILE_BYTES))),
            'max_total_bytes' => min(self::MAX_TOTAL_BYTES, max(1, (int) ($requested['max_total_bytes'] ?? self::DEFAULT_MAX_TOTAL_BYTES))),
        );
    }

    /**
     * @param array<string, mixed> $artifact
     * @return array<int, array<string, mixed>>
     */
    private function rawFiles(array $artifact): array
    {
        $files = array();
        foreach ( array('files', 'artifacts', 'outputs') as $key ) {
            if ( ! is_array($artifact[$key] ?? null) ) {
                continue;
            }
            foreach ( $artifact[$key] as $path => $file ) {
                if ( is_array($file) ) {
                    $pathSource = $file['path'] ?? $file['name'] ?? $path;
                    $file['path'] = is_scalar($pathSource) ? (string) $pathSource : '';
                    $file['source'] = is_scalar($file['source'] ?? null) ? (string) $file['source'] : $key;
                    $files[] = $file;
                    continue;
                }
                if ( is_string($file) ) {
                    $files[] = array(
                        'path'    => is_string($path) ? $path : 'artifact-' . $path . '.html',
                        'content' => $file,
                        'kind'    => '',
                        'source'  => $key,
                    );
                }
            }
        }
        foreach ( array('html', 'generated_html', 'content', 'body') as $key ) {
            if ( is_string($artifact[$key] ?? null) && '' !== trim($artifact[$key]) ) {
                $files[] = array(
                    'path'    => 'index.html',
                    'content' => $artifact[$key],
                    'kind'    => 'html',
                    'source'  => $key,
                );
            }
        }
        foreach ( array(
            'css'        => 'style.css',
            'styles'     => 'style.css',
            'javascript' => 'site.js',
            'js'         => 'site.js',
            'script'     => 'site.js',
        ) as $key => $path ) {
            if ( is_string($artifact[$key] ?? null) && '' !== trim($artifact[$key]) ) {
                $files[] = array(
                    'path'    => $path,
                    'content' => $artifact[$key],
                    'kind'    => str_contains($path, '.css') ? 'css' : 'js',
                    'source'  => $key,
                );
            }
        }

        return $files;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function withInlineStyleFiles(array $files, array &$reservedPaths): array
    {
        $expandedSources = $this->inlineExpandedSourcePaths($files, 'inline-style');
        $expanded = array();
        foreach ( $files as $file ) {
            $expanded[] = $file;

            if ( isset($expandedSources[ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''))]) ) {
                continue;
            }
            $content = $this->payload($file, (string) ($file['path'] ?? ''))['content'];
            if ( '' === trim($content) || ! $this->isHtmlLikeFile($file) || ! preg_match_all('@<style\b([^>]*)>(.*?)</style>@is', $content, $matches, PREG_SET_ORDER) ) {
                continue;
            }

            $styles = array();
            foreach ( $matches as $match ) {
                $attributes = (string) $match[1];
                $css = trim((string) $match[2]);
                if ( '' === $css || ! $this->isCssType($this->htmlAttribute($attributes, 'type')) ) {
                    continue;
                }
                $styles[] = array( 'content' => $css, 'media' => $this->htmlAttribute($attributes, 'media'), 'type' => $this->htmlAttribute($attributes, 'type') );
            }
            foreach ( $styles as $index => $style ) {
                $path = $this->allocateGeneratedPath($this->inlineStylePath((string) ($file['path'] ?? 'index.html'), count($styles), $index + 1), $reservedPaths);
                $expanded[] = $this->withInheritedCompilation($file, array(
                    'path'      => $path,
                    'content'   => $style['content'],
                    'kind'      => 'css',
                    'mime_type' => 'text/css',
                    'role'      => 'stylesheet',
                    'intent'    => 'style',
                    'source'    => 'inline-style',
                    'source_path' => ArtifactPath::safeRelativePath((string) ($file['path'] ?? 'index.html')),
                    'stylesheet_index' => $index + 1,
                    'media' => $style['media'],
                    'type' => $style['type'],
                ));
            }
        }

        return $expanded;
    }

    /** @param array<int,array<string,mixed>> $files @return array<int,array<string,mixed>> */
    private function sourceFilesBeforeGeneratedExpansions(array $files, int $maxFiles): array
    {
        if (count($files) <= $maxFiles) return $files;
        $sourceFiles = array();
        $generatedFiles = array();
        foreach ($files as $file) {
            if (in_array((string) ($file['source'] ?? ''), array('inline-style', 'inline-script'), true)) {
                $generatedFiles[] = $file;
            } else {
                $sourceFiles[] = $file;
            }
        }
        return array_merge($sourceFiles, $generatedFiles);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isHtmlLikeFile(array $file): bool
    {
        $kind = strtolower((string) ($file['kind'] ?? $file['type'] ?? ''));
        $mimeType = strtolower((string) ($file['mime_type'] ?? $file['mime'] ?? $file['media_type'] ?? ''));
        $path = strtolower((string) ($file['path'] ?? ''));

        return ( in_array($kind, array( '', 'html' ), true) || str_contains($kind, 'html') )
            && ( '' === $mimeType || str_contains($mimeType, 'html') )
            && ( '' === $path || preg_match('/\.html?$/', $path) || 'index.html' === $path );
    }

    /**
     * The source path of the file an inline style/script row was expanded
     * from, or '' when the row is not an inline expansion. Expansion rows
     * are recognizable by the source marker and source_path normalization
     * itself emits.
     *
     * @param array<string, mixed> $file
     */
    public static function inlineExpansionSourcePath(array $file): string
    {
        if ( ! in_array((string) ($file['source'] ?? ''), array( 'inline-style', 'inline-script' ), true) ) {
            return '';
        }
        return (string) ($file['source_path'] ?? '');
    }

    /**
     * Source paths whose inline assets of the given kind were already
     * expanded by a previous normalize() pass. Skipping them keeps
     * normalize() idempotent: the staged compilation flow re-normalizes
     * already-normalized file lists, which must not re-expand the same
     * inline assets into duplicate generated files.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, bool>
     */
    private function inlineExpandedSourcePaths(array $files, string $source): array
    {
        $sources = array();
        foreach ( $files as $file ) {
            if ( $source !== (string) ($file['source'] ?? '') ) {
                continue;
            }
            $sourcePath = self::inlineExpansionSourcePath($file);
            if ( '' !== $sourcePath ) {
                $sources[$sourcePath] = true;
            }
        }
        return $sources;
    }

    /**
     * Inline-expanded files inherit the parent file's compilation ownership.
     *
     * @param array<string,mixed> $file
     * @param array<string,mixed> $expandedFile
     * @return array<string,mixed>
     */
    private function withInheritedCompilation(array $file, array $expandedFile): array
    {
        if ( is_array($file['metadata']['compilation'] ?? null) ) {
            $expandedFile['metadata'] = array( 'compilation' => $file['metadata']['compilation'] );
        }
        return $expandedFile;
    }

    private function inlineStylePath(string $htmlPath, int $count = 1, int $index = 1): string
    {
        $path = ArtifactPath::safeRelativePath($htmlPath);
        if ( '' === $path ) {
            return 1 === $count ? 'inline-styles.css' : 'inline-styles-' . $index . '.css';
        }

        $directory = trim((string) pathinfo($path, PATHINFO_DIRNAME), '.');
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $stylePath = ('' === $filename ? 'inline' : $filename) . '.inline' . (1 === $count ? '' : '-' . $index) . '.css';

        return '' === $directory ? $stylePath : $directory . '/' . $stylePath;
    }

    /** @param array<string, true> $reservedPaths */
    private function allocateGeneratedPath(string $candidate, array &$reservedPaths): string
    {
        $path = $candidate;
        $index = 1;
        while ( isset($reservedPaths[$path]) ) {
            $extension = pathinfo($candidate, PATHINFO_EXTENSION);
            $base = '' === $extension ? $candidate : substr($candidate, 0, -strlen($extension) - 1);
            $path = $base . '-generated-' . $index++ . ('' === $extension ? '' : '.' . $extension);
        }
        $reservedPaths[$path] = true;
        return $path;
    }

    private function isCssType(string $type): bool
    {
        $type = strtolower(trim($type));
        return '' === $type || 1 === preg_match("/^text\\/css(?:\\s*;\\s*[!#$%&'*+\\-.^_`|~0-9a-z]+(?:\\s*=\\s*(?:[!#$%&'*+\\-.^_`|~0-9a-z]+|\"(?:[^\"\\\\]|\\\\.)*\"))?)*\\s*$/i", $type);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function withInlineScriptFiles(array $files): array
    {
        $expandedSources = $this->inlineExpandedSourcePaths($files, 'inline-script');
        $expanded = array();
        foreach ( $files as $file ) {
            $expanded[] = $file;

            if ( isset($expandedSources[ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''))]) ) {
                continue;
            }
            $content = $this->payload($file, (string) ($file['path'] ?? ''))['content'];
            if ( '' === trim($content) || ! $this->isHtmlLikeFile($file) || ! preg_match_all('@<script\b([^>]*)>(.*?)</script>@is', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) ) {
                continue;
            }

            $scriptIndex = 0;
            foreach ( $matches as $match ) {
                ++$scriptIndex;
                $attributes = (string) $match[1][0];
                $body = trim((string) $match[2][0]);
                if ( '' === $body || '' !== $this->htmlAttribute($attributes, 'src') || ! $this->isExecutableScriptType($this->htmlAttribute($attributes, 'type')) ) {
                    continue;
                }

                $expanded[] = $this->withInheritedCompilation($file, array(
                    'path'        => $this->inlineScriptPath((string) ($file['path'] ?? 'index.html'), $scriptIndex),
                    'content'     => $body,
                    'kind'        => 'js',
                    'mime_type'   => 'text/javascript',
                    'role'        => 'script',
                    'intent'      => 'behavior',
                    'source'      => 'inline-script',
                    'placement'   => $this->scriptPlacement($content, (int) $match[0][1]),
                    'type'        => $this->htmlAttribute($attributes, 'type'),
                    'defer'       => $this->hasBooleanAttribute($attributes, 'defer'),
                    'async'       => $this->hasBooleanAttribute($attributes, 'async'),
                    'superseded_by' => $this->htmlAttribute($attributes, 'data-blocks-engine-superseded-by'),
                    'source_path' => ArtifactPath::safeRelativePath((string) ($file['path'] ?? 'index.html')),
                    'selector'    => 'script:nth-of-type(' . $scriptIndex . ')',
                ));
            }
        }

        return $expanded;
    }

    private function inlineScriptPath(string $htmlPath, int $index): string
    {
        $path = ArtifactPath::safeRelativePath($htmlPath);
        if ( '' === $path ) {
            return 1 === $index ? 'inline.js' : 'inline-' . $index . '.js';
        }

        $directory = trim((string) pathinfo($path, PATHINFO_DIRNAME), '.');
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $scriptPath = ('' === $filename ? 'inline' : $filename) . (1 === $index ? '.inline.js' : '.inline-' . $index . '.js');

        return '' === $directory ? $scriptPath : $directory . '/' . $scriptPath;
    }

    private function isExecutableScriptType(string $type): bool
    {
        $type = strtolower(trim($type));
        return '' === $type || in_array($type, array('module', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript'), true);
    }

    private function htmlAttribute(string $attributes, string $name): string
    {
        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/i', $attributes, $match) ) {
            return html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*([^\s>]+)/i', $attributes, $match) ) {
            return html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    private function hasBooleanAttribute(string $attributes, string $name): bool
    {
        return 1 === preg_match('/(?:^|\s)' . preg_quote($name, '/') . '(?:\s|=|$)/i', $attributes);
    }

    private function scriptPlacement(string $html, int $offset): string
    {
        $headClose = stripos($html, '</head>');
        return false !== $headClose && $offset < $headClose ? 'head' : 'body';
    }

    /**
     * @param array<string, mixed> $file
     * @return array{accepted: bool, content: string, content_base64: string, encoding: string, binary: bool, bytes: int, diagnostics: array<int, array<string, mixed>>, payload_reference?: array{schema:string,id:string,bytes:int,sha256:string}}
     */
    private function payload(array $file, string $path): array
    {
        if (is_array($file['payload_reference'] ?? null)) {
            $reference = $file['payload_reference'];
            if ('blocks-engine/payload-reference/v1' !== ($reference['schema'] ?? null) || !is_string($reference['id'] ?? null) || '' === $reference['id'] || !is_int($reference['bytes'] ?? null) || $reference['bytes'] < 0 || !is_string($reference['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $reference['sha256'])) {
                return array('accepted' => false, 'content' => '', 'content_base64' => '', 'encoding' => 'reference', 'binary' => false, 'bytes' => 0, 'diagnostics' => array($this->diagnostic('invalid_payload_reference', 'warning', 'An artifact file was ignored because its payload reference is invalid.', array('path' => $path))));
            }
            return array('accepted' => true, 'content' => '', 'content_base64' => '', 'encoding' => 'reference', 'binary' => true, 'bytes' => $reference['bytes'], 'diagnostics' => array(), 'payload_reference' => array('schema' => $reference['schema'], 'id' => $reference['id'], 'bytes' => $reference['bytes'], 'sha256' => $reference['sha256']));
        }
        if ( is_string($file['content_base64'] ?? null) ) {
            $base64 = preg_replace('/\s+/', '', $file['content_base64']) ?? '';
            $decoded = base64_decode($base64, true);
            if ( false === $decoded ) {
                return array('accepted' => false, 'content' => '', 'content_base64' => '', 'encoding' => 'base64', 'binary' => false, 'bytes' => 0, 'diagnostics' => array($this->diagnostic('invalid_base64_content', 'warning', 'An artifact file was ignored because content_base64 is not valid base64.', array('path' => $path))));
            }

            $binary = $this->looksBinary($decoded);
            $diagnostics = array();
            if ( ! $binary && is_string($file['content'] ?? null) && '' !== $file['content'] && $file['content'] !== $decoded ) {
                $diagnostics[] = $this->diagnostic('content_base64_preferred', 'info', 'Both content and content_base64 were provided; decoded content_base64 was used as the canonical payload.', array('path' => $path));
            }

            return array('accepted' => true, 'content' => $binary ? '' : $decoded, 'content_base64' => $base64, 'encoding' => 'base64', 'binary' => $binary, 'bytes' => strlen($decoded), 'diagnostics' => $diagnostics);
        }

        $contentKey = array_key_exists('content', $file) ? 'content' : (array_key_exists('body', $file) ? 'body' : (array_key_exists('text', $file) ? 'text' : null));
        if (null === $contentKey || !is_string($file[$contentKey])) {
            return array('accepted' => false, 'content' => '', 'content_base64' => '', 'encoding' => 'text', 'binary' => false, 'bytes' => 0, 'diagnostics' => array($this->diagnostic('missing_file_payload', 'warning', 'An artifact file was ignored because it has no explicit text or base64 payload.', array('path' => $path))));
        }
        $content = $this->normalizeContent($file[$contentKey]);
        return array('accepted' => true, 'content' => $content, 'content_base64' => '', 'encoding' => 'text', 'binary' => false, 'bytes' => strlen($content), 'diagnostics' => array());
    }

    private function kind(string $kind, string $path, string $content, string $mimeType): string
    {
        $kind = $this->sanitizeKey($kind);
        if ( in_array($kind, array('html', 'css', 'js', 'jsx', 'tsx', 'json', 'markdown', 'mdx', 'asset', 'blocks'), true) ) {
            return $kind;
        }
        if ( str_contains($mimeType, '/') ) {
            if ( str_contains($mimeType, 'html') ) {
                return 'html';
            }
            if ( 'text/css' === $mimeType ) {
                return 'css';
            }
            if ( in_array($mimeType, array('application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript'), true) ) {
                return 'js';
            }
            if ( 'application/json' === $mimeType ) {
                return 'json';
            }
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($extension) {
            'html', 'htm' => 'html',
            'css' => 'css',
            'js', 'mjs' => 'js',
            'jsx' => 'jsx',
            'tsx' => 'tsx',
            'json' => 'json',
            'md', 'markdown' => 'markdown',
            'mdx' => 'mdx',
            default => str_contains($content, '<!-- wp:') ? 'blocks' : 'asset',
        };
    }

    private function mimeType(string $mimeType, string $path): string
    {
        $mimeType = strtolower(trim($mimeType));
        if ( preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mimeType) ) {
            return $mimeType;
        }
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html',
            'css' => 'text/css',
            'js', 'mjs' => 'application/javascript',
            'jsx' => 'text/jsx',
            'tsx' => 'text/tsx',
            'json' => 'application/json',
            'md', 'markdown' => 'text/markdown',
            'mdx' => 'text/mdx',
            'txt' => 'text/plain',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'wav' => 'audio/wav',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'pdf' => 'application/pdf',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            default => 'application/octet-stream',
        };
    }

    private function isBinaryMimeType(string $mimeType): bool
    {
        return ! str_starts_with($mimeType, 'text/') && ! in_array($mimeType, array('application/json', 'application/javascript', 'image/svg+xml'), true);
    }

    private function isTextKind(string $kind): bool
    {
        return in_array($kind, array('html', 'css', 'js', 'jsx', 'tsx', 'json', 'markdown', 'mdx', 'blocks'), true);
    }

    private function role(string $role, string $kind, string $mimeType, string $path): string
    {
        $role = $this->sanitizeKey($role);
        if ( '' !== $role ) {
            return $role;
        }
        if ( 'html' === $kind ) {
            return preg_match('#(^|/)index\.html?$#i', $path) ? 'entry' : 'document';
        }
        if ( 'css' === $kind ) {
            return 'stylesheet';
        }
        if ( 'js' === $kind ) {
            return 'script';
        }
        if ( str_starts_with($mimeType, 'image/') ) {
            return 'image';
        }
        if ( str_starts_with($mimeType, 'audio/') ) {
            return 'audio';
        }
        if ( str_starts_with($mimeType, 'video/') ) {
            return 'video';
        }
        if ( 'application/pdf' === $mimeType ) {
            return 'document';
        }
        if ( str_starts_with($mimeType, 'font/') ) {
            return 'font';
        }
        if ( in_array($kind, array('json', 'markdown'), true) ) {
            return 'data';
        }

        return 'asset';
    }

    private function intent(string $intent, string $kind, string $role): string
    {
        $intent = $this->sanitizeKey($intent);
        if ( '' !== $intent ) {
            return $intent;
        }
        if ( 'css' === $kind || 'stylesheet' === $role ) {
            return 'style';
        }
        if ( 'js' === $kind || 'script' === $role ) {
            return 'behavior';
        }

        return '';
    }

    private function looksBinary(string $content): bool
    {
        return str_contains($content, "\0");
    }

    private function normalizeContent(mixed $content): string
    {
        if ( is_string($content) ) {
            return str_replace("\r\n", "\n", str_replace("\r", "\n", $content));
        }
        if ( is_scalar($content) ) {
            return (string) $content;
        }

        return '';
    }

    /**
     * @param array<string, bool> $seen
     */
    private function dedupePath(string $path, array $seen): string
    {
        if ( ! isset($seen[$path]) ) {
            return $path;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = '' === $extension ? $path : substr($path, 0, -1 - strlen($extension));
        $suffix = '' === $extension ? '' : '.' . $extension;
        $index = 2;
        while ( isset($seen[$base . '-' . $index . $suffix]) ) {
            ++$index;
        }

        return $base . '-' . $index . $suffix;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function sourceHash(array $files, array $runtimeDeclarations): string
    {
        // Source identity is independent of transport and preparation order.
        usort($files, static fn(array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path']));
        $context = hash_init('sha256');
        foreach ( $files as $file ) {
            $content = isset($file['content_base64']) ? (string) $file['content_base64'] : (isset($file['payload_reference']) ? (string) $file['payload_reference']['sha256'] : (string) $file['content']);
            hash_update($context, $file['path'] . "\0" . $file['kind'] . "\0" . ($file['mime_type'] ?? '') . "\0");
            hash_update($context, $content);
            hash_update($context, "\0");
        }
        hash_update($context, "\n" . RuntimeDeclarations::canonicalJson($runtimeDeclarations));
        return hash_final($context);
    }

    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim($key))) ?? '';
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function dedupeDiagnostics(array $diagnostics): array
    {
        $seen = array();
        $deduped = array();
        foreach ( $diagnostics as $diagnostic ) {
            $key = json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: serialize($diagnostic);
            if ( isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $diagnostic;
        }

        return $deduped;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $severity, string $message, array $context = array()): array
    {
        return array_filter(
            array(
                'code'     => $code,
                'severity' => $severity,
                'message'  => $message,
                'source'   => ArtifactCompiler::class,
                'context'  => $context,
            ),
            static fn (mixed $value): bool => array() !== $value
        );
    }
}
