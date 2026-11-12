<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\AssetAnalysis;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;

final class ReferenceAnalyzer
{
    /**
     * @param array<int, array<string, mixed>> $files
     * @param callable(array<string, mixed>): bool|null $isLinkableDocument
     * @param callable(array<string, mixed>): bool|null $isSafeImageAsset
     * @return array{internal_links: array<int, array<string, mixed>>, asset_references: array<int, array<string, mixed>>, image_references: array<int, array<string, mixed>>}
     */
    public function referenceReports(array $files, ?callable $isLinkableDocument = null, ?callable $isSafeImageAsset = null): array
    {
        $internalLinks = array();
        $assetReferences = array();
        $imageReferences = array();
		$filesByPath = array();
		foreach ( $files as $file ) {
			if ( is_string($file['path'] ?? null) ) {
				$filesByPath[$file['path']] = $file;
			}
		}

        foreach ( $files as $file ) {
            if ( ! empty($file['binary']) ) {
                continue;
            }

            if ( 'html' === ($file['kind'] ?? '') || 'blocks' === ($file['kind'] ?? '') ) {
                foreach ( $this->htmlReferenceCandidates((string) ($file['content'] ?? ''), (string) ($file['path'] ?? '')) as $candidate ) {
                    if ( '' === $candidate['url'] || ! $this->isLocalReference($candidate['url']) ) {
                        continue;
                    }

                    $reference = $this->normalizeReferenceCandidate($candidate, $files, $isLinkableDocument, $isSafeImageAsset, $filesByPath);
                    $target = $reference['target'] ?? null;
                    if ( is_array($target) && $this->isLinkableDocument($target, $isLinkableDocument) && 'a' === $candidate['element'] ) {
                        unset($reference['target']);
                        $internalLinks[] = $reference;
                        continue;
                    }

                    if ( is_array($target) && ! $this->isLinkableDocument($target, $isLinkableDocument) ) {
                        unset($reference['target']);
                        $assetReferences[] = $reference;
                        if ( str_starts_with((string) ($target['mime_type'] ?? ''), 'image/') ) {
                            $imageReferences[] = $this->legacyImageReference($reference, count($imageReferences));
                        }
                    }
                }
            }

            if ( 'css' === ($file['kind'] ?? '') ) {
                foreach ( $this->cssReferenceCandidates((string) ($file['content'] ?? ''), (string) ($file['path'] ?? '')) as $candidate ) {
                    if ( '' === $candidate['url'] || ! $this->isLocalReference($candidate['url']) ) {
                        continue;
                    }

                    $reference = $this->normalizeReferenceCandidate($candidate, $files, $isLinkableDocument, $isSafeImageAsset, $filesByPath);
                    $target = $reference['target'] ?? null;
                    if ( is_array($target) && ! $this->isLinkableDocument($target, $isLinkableDocument) ) {
                        unset($reference['target']);
                        $assetReferences[] = $reference;
                        if ( str_starts_with((string) ($target['mime_type'] ?? ''), 'image/') ) {
                            $imageReferences[] = $this->legacyImageReference($reference, count($imageReferences));
                        }
                    }
                }
            }
        }

        return array(
            'internal_links'   => $internalLinks,
            'asset_references' => $assetReferences,
            'image_references' => $imageReferences,
        );
    }

    /**
     * @return array<int, array{source_path: string, selector: string, element: string, attribute: string, value: string, url: string}>
     */
    public function htmlReferenceCandidates(string $html, string $sourcePath): array
    {
        if ( '' === trim($html) || ! preg_match_all('/<\s*([a-z][a-z0-9:-]*)\b([^>]*)>/i', $html, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $candidates = array();
        $counts = array();
        foreach ( $matches as $match ) {
            $element = strtolower((string) $match[1]);
            $attributes = $this->htmlAttributes((string) $match[2]);
            $counts[$element] = ($counts[$element] ?? 0) + 1;
            $selector = $element . ':nth-of-type(' . $counts[$element] . ')';

            foreach ( $this->referenceAttributesForElement($element, $attributes) as $attribute ) {
                $value = (string) ($attributes[$attribute] ?? '');
                foreach ( $this->urlsFromAttributeValue($attribute, $value) as $url ) {
                    $candidates[] = array(
                        'source_path' => $sourcePath,
                        'selector'    => $selector,
                        'element'     => $element,
                        'attribute'   => $attribute,
                        'value'       => $value,
                        'url'         => $url,
                    );
                }
            }

            if ( isset($attributes['style']) ) {
                $value = (string) $attributes['style'];
                foreach ( $this->cssReferenceCandidates($value, $sourcePath) as $styleCandidate ) {
                    $candidates[] = array(
                        'source_path' => $sourcePath,
                        'selector'    => $selector,
                        'element'     => $element,
                        'attribute'   => 'style',
                        'value'       => $value,
                        'url'         => $styleCandidate['url'],
                        'context'     => 'inline-style',
                    );
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array<int, array{source_path: string, selector: string, element: string, attribute: string, value: string, url: string, context?: string}>
     */
    public function cssReferenceCandidates(string $css, string $sourcePath): array
    {
        if ( '' === trim($css) ) {
            return array();
        }

        $candidates = array();

        if ( preg_match_all('/@import\s+(?:url\(\s*)?(["\']?)([^"\'\)\s;]+)\1\s*\)?[^;]*;/i', $css, $matches, PREG_SET_ORDER) ) {
            foreach ( $matches as $index => $match ) {
                $url = html_entity_decode(trim((string) $match[2]), ENT_QUOTES | ENT_HTML5);
                $candidates[] = array(
                    'source_path' => $sourcePath,
                    'selector'    => 'css:@import(' . ($index + 1) . ')',
                    'element'     => 'style',
                    'attribute'   => '@import',
                    'value'       => $url,
                    'url'         => $url,
                    'context'     => 'css-import',
                );
            }
        }

        if ( ! preg_match_all('/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', $css, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) ) {
            return $candidates;
        }

        foreach ( $matches as $index => $match ) {
            $url = html_entity_decode(trim((string) $match[2][0]), ENT_QUOTES | ENT_HTML5);
            $ruleContext = $this->cssRuleContext($css, (int) $match[0][1]);
            $candidates[] = array(
                'source_path' => $sourcePath,
                'selector'    => ('font-face' === $ruleContext ? 'css:@font-face:url(' : 'css:url(') . ($index + 1) . ')',
                'element'     => 'style',
                'attribute'   => 'url',
                'value'       => $url,
                'url'         => $url,
                'context'     => 'font-face' === $ruleContext ? 'css-font-face' : 'css-url',
            );
        }

        return $candidates;
    }

    /**
     * @param array{source_path: string, selector: string, element: string, attribute: string, value: string, url: string, context?: string} $candidate
     * @param array<int, array<string, mixed>> $files
     * @param callable(array<string, mixed>): bool|null $isLinkableDocument
     * @param callable(array<string, mixed>): bool|null $isSafeImageAsset
     * @return array<string, mixed>
     */
    public function normalizeReferenceCandidate(array $candidate, array $files, ?callable $isLinkableDocument = null, ?callable $isSafeImageAsset = null, ?array $filesByPath = null): array
    {
        $resolvedPath = ArtifactPath::resolveRelativePath($candidate['url'], $candidate['source_path']);
        $target = '' === $resolvedPath ? null : (null === $filesByPath ? $this->findFileByPath($resolvedPath, $files) : ($filesByPath[$resolvedPath] ?? null));
        $reference = array_filter(
            array(
                'source_path'   => $candidate['source_path'],
                'selector'      => $candidate['selector'],
                'element'       => $candidate['element'],
                'attribute'     => $candidate['attribute'],
                'value'         => $candidate['value'],
                'url'           => $candidate['url'],
                'context'       => $candidate['context'] ?? '',
                'resolved_path' => $resolvedPath,
            ),
            static fn (mixed $value): bool => '' !== $value
        );

        if ( is_array($target) ) {
            $targetPath = (string) ($target['path'] ?? '');
            if ( $this->isLinkableDocument($target, $isLinkableDocument) ) {
                $reference['target_path'] = $targetPath;
            } else {
                $reference['asset_path'] = $targetPath;
            }
            $reference['kind'] = $target['kind'] ?? '';
            $reference['role'] = $target['role'] ?? '';
            $reference['mime_type'] = $target['mime_type'] ?? '';
            $reference['bytes'] = $target['bytes'] ?? 0;
            if ( str_starts_with((string) ($target['mime_type'] ?? ''), 'image/') ) {
                $reference['safe'] = is_callable($isSafeImageAsset) ? (bool) $isSafeImageAsset($target) : true;
            }
            $reference['target'] = $target;
        }

        return $reference;
    }

    /**
     * @return array<string, string>
     */
    private function htmlAttributes(string $attributeText): array
    {
        $attributes = array();
        if ( ! preg_match_all('/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*(?:(["\'])(.*?)\2|([^\s"\'>]+))/s', $attributeText, $matches, PREG_SET_ORDER) ) {
            return $attributes;
        }

        foreach ( $matches as $match ) {
            $attributes[strtolower((string) $match[1])] = html_entity_decode((string) ('' !== ($match[3] ?? '') ? $match[3] : ($match[4] ?? '')), ENT_QUOTES | ENT_HTML5);
        }

        return $attributes;
    }

    /**
     * @param array<string, string> $attributes
     * @return array<int, string>
     */
    private function referenceAttributesForElement(string $element, array $attributes): array
    {
        $attributesByElement = array(
            'a'      => array('href'),
            'audio'  => array('src'),
            'img'    => array('src', 'srcset'),
            'script' => array('src'),
            'link'   => array('href'),
            'source' => array('src', 'srcset'),
            'video'  => array('src', 'poster'),
            'image'  => array('href', 'xlink:href'),
        );

        return array_values(array_filter(
            $attributesByElement[$element] ?? array(),
            static fn (string $attribute): bool => isset($attributes[$attribute])
        ));
    }

    /**
     * @return array<int, string>
     */
    private function urlsFromAttributeValue(string $attribute, string $value): array
    {
        if ( 'srcset' !== $attribute ) {
            return array(trim($value));
        }

        $urls = array();
        foreach ( explode(',', $value) as $candidate ) {
            $parts = preg_split('/\s+/', trim($candidate));
            if ( is_array($parts) && '' !== ($parts[0] ?? '') ) {
                $urls[] = $parts[0];
            }
        }

        return $urls;
    }

    private function cssRuleContext(string $css, int $offset): string
    {
        $before = substr($css, 0, $offset);
        $ruleStart = strrpos($before, '{');
        if ( false === $ruleStart ) {
            return '';
        }

        $prefix = substr($css, max(0, $ruleStart - 256), $ruleStart - max(0, $ruleStart - 256));
        return preg_match('/@font-face\s*$/i', $prefix) ? 'font-face' : '';
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function findFileByPath(string $path, array $files): ?array
    {
        foreach ( $files as $file ) {
            if ( $path === ($file['path'] ?? '') ) {
                return $file;
            }
        }

        return null;
    }

    private function isLocalReference(string $reference): bool
    {
        $reference = trim($reference);
        if ( '' === $reference || str_starts_with($reference, '#') || str_starts_with($reference, '//') ) {
            return false;
        }

        return ! preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference);
    }

    /**
     * @param array<string, mixed> $file
     * @param callable(array<string, mixed>): bool|null $isLinkableDocument
     */
    private function isLinkableDocument(array $file, ?callable $isLinkableDocument): bool
    {
        if ( is_callable($isLinkableDocument) ) {
            return (bool) $isLinkableDocument($file);
        }

        return in_array($file['kind'] ?? '', array('html', 'blocks'), true);
    }

    /**
     * @param array<string, mixed> $reference
     * @return array<string, mixed>
     */
    private function legacyImageReference(array $reference, int $index): array
    {
        return array_filter(
            array(
                'source_path'   => $reference['source_path'] ?? '',
                'selector'      => $reference['selector'] ?? 'image-reference:nth-of-type(' . ($index + 1) . ')',
                'src'           => $reference['url'] ?? '',
                'resolved_path' => $reference['resolved_path'] ?? '',
                'asset_path'    => $reference['asset_path'] ?? '',
                'mime_type'     => $reference['mime_type'] ?? '',
                'bytes'         => $reference['bytes'] ?? 0,
                'safe'          => $reference['safe'] ?? null,
                'element'       => $reference['element'] ?? '',
                'attribute'     => $reference['attribute'] ?? '',
                'context'       => $reference['context'] ?? '',
            ),
            static fn (mixed $value): bool => null !== $value && '' !== $value
        );
    }
}
