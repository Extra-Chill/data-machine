<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Adds the emitted stylesheet content to HTML artifacts for self-contained previews.
 */
final class InlineCssFileInjector
{
    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    public function inject(array $files, string $css): array
    {
        if ( '' === $css ) {
            return $files;
        }

        foreach ( $files as $index => $file ) {
            if ( ! is_array($file) || 'text/html' !== ($file['mime_type'] ?? null) || ! isset($file['content']) || ! is_scalar($file['content']) ) {
                continue;
            }

            $file['content'] = $this->injectIntoHtml((string) $file['content'], $css);
            $files[$index] = $file;
        }

        return $files;
    }

    private function injectIntoHtml(string $html, string $css): string
    {
        if ( '' === $css || str_contains($html, '<style data-figma-transformer-css="true">') ) {
            return $html;
        }

        $style = '<style data-figma-transformer-css="true">' . str_replace('</style', '<\/style', $css) . '</style>';
        if ( str_contains($html, '</head>') ) {
            return str_replace('</head>', $style . "\n</head>", $html);
        }

        return $style . "\n" . $html;
    }
}
