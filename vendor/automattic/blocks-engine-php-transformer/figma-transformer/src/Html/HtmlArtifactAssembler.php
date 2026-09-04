<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Assembles static HTML artifact wrappers and shared stylesheet scaffolding.
 */
final class HtmlArtifactAssembler
{
    /**
     * @param callable(string): string $attributeSanitizer
     */
    public function __construct(
        private readonly mixed $attributeSanitizer,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function baseCssRules(bool $renderTextGlyphPaths): array
    {
        $rules = array(
            'html{box-sizing:border-box}',
            '*,*::before,*::after{box-sizing:inherit}',
            'body{margin:0}',
            '.figma-root{position:relative;width:100%;display:flex;flex-direction:column;align-items:center}',
            'p,h1,h2,h3,h4,h5,h6{margin:0}',
            'blockquote{margin:0}',
            'ul,ol{margin:0;padding:0;list-style:none}',
            'img{display:block;max-width:100%;height:auto}',
            'a.figma-link{display:contents;color:inherit;text-decoration:inherit}',
            '.figma-vector-asset{display:block;width:100%;height:100%;object-fit:fill}',
            '.figma-image-asset{display:block;width:100%;height:100%;max-width:none;object-fit:cover;object-position:center}',
        );
        if ( $renderTextGlyphPaths ) {
            $rules[] = '.figma-text-glyphs{display:block;width:100%;height:100%;overflow:visible}';
        }

        return $rules;
    }

    /**
     * @param array<int, string> $cssRules
     * @param array<int, string> $mediaBlocks
     */
    public function stylesheet(string $fontCss, string $designSystemCss, array $cssRules, array $mediaBlocks = array(), bool $dedupeRules = false): string
    {
        if ( $dedupeRules ) {
            $cssRules = array_values(array_unique($cssRules));
        }

        $css = ('' !== $fontCss ? $fontCss . "\n" : '')
            . ('' !== $designSystemCss ? $designSystemCss : '')
            . implode("\n", $cssRules) . "\n";
        if ( ! empty($mediaBlocks) ) {
            // Responsive overrides cascade after the widest-first base rules so
            // narrower breakpoints win at their own viewport width.
            $css .= implode("\n", $mediaBlocks) . "\n";
        }

        return $css;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function htmlDocument(string $title, string $stylesheetHref, string $body, array $metadata = array()): string
    {
        $head = array(
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>' . $title . '</title>',
        );

        $description = $this->metadataValue($metadata, 'description');
        if ( null !== $description ) {
            $head[] = '<meta name="description" content="' . $this->sanitizeAttribute($description) . '">';
        }

        $canonicalUrl = $this->metadataValue($metadata, 'canonical_url');
        if ( null !== $canonicalUrl ) {
            $head[] = '<link rel="canonical" href="' . $this->sanitizeAttribute($canonicalUrl) . '">';
        }

        $faviconHref = $this->metadataValue($metadata, 'favicon_href');
        if ( null !== $faviconHref ) {
            $head[] = '<link rel="icon" href="' . $this->sanitizeAttribute($faviconHref) . '">';
        }

        foreach ( array(
            'og_title' => array('property', 'og:title'),
            'og_description' => array('property', 'og:description'),
            'og_image' => array('property', 'og:image'),
            'twitter_card' => array('name', 'twitter:card'),
            'twitter_title' => array('name', 'twitter:title'),
            'twitter_description' => array('name', 'twitter:description'),
            'twitter_image' => array('name', 'twitter:image'),
        ) as $key => $tag ) {
            $value = $this->metadataValue($metadata, $key);
            if ( null !== $value ) {
                $head[] = '<meta ' . $tag[0] . '="' . $tag[1] . '" content="' . $this->sanitizeAttribute($value) . '">';
            }
        }

        $head[] = '<link rel="stylesheet" href="' . $this->sanitizeAttribute($stylesheetHref) . '">';

        $mainAttributes = array(
            'class="figma-root"',
            'data-figma-root="true"',
            'data-static-artifact-capture="ignore"',
            'data-page-title="' . $this->sanitizeAttribute(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '"',
            'aria-label="' . $this->sanitizeAttribute(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '"',
        );

        $pagePath = $this->metadataValue($metadata, 'page_path');
        if ( null !== $pagePath ) {
            $mainAttributes[] = 'data-page-path="' . $this->sanitizeAttribute($pagePath) . '"';
        }

        $templateType = $this->metadataValue($metadata, 'template_type');
        if ( null !== $templateType ) {
            $mainAttributes[] = 'data-template-type="' . $this->sanitizeAttribute($templateType) . '"';
        }

        $templateSlug = $this->metadataValue($metadata, 'template_slug');
        if ( null !== $templateSlug ) {
            $mainAttributes[] = 'data-template-slug="' . $this->sanitizeAttribute($templateSlug) . '"';
        }

        return "<!doctype html>\n<html lang=\"en\">\n<head>\n" . implode("\n", $head) . "\n</head>\n<body>\n<main " . implode(' ', $mainAttributes) . ">\n" . $body . "</main>\n</body>\n</html>\n";
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    public function htmlFilesContent(array $files): string
    {
        $html = '';
        foreach ( $files as $file ) {
            if ( is_array($file) && 'text/html' === ($file['mime_type'] ?? null) && isset($file['content']) && is_scalar($file['content']) ) {
                $html .= "\n" . (string) $file['content'];
            }
        }

        return $html;
    }

    private function sanitizeAttribute(string $value): string
    {
        $sanitizeAttribute = $this->attributeSanitizer;
        return $sanitizeAttribute($value);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function metadataValue(array $metadata, string $key): ?string
    {
        if ( ! isset($metadata[$key]) || ! is_scalar($metadata[$key]) ) {
            return null;
        }

        $value = trim((string) $metadata[$key]);
        return '' === $value ? null : $value;
    }
}
