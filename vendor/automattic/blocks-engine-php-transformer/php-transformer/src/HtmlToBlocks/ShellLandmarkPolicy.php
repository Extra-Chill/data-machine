<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/**
 * Centralizes the distinction between document shell landmarks and content-local
 * semantic containers so transformer/materialization decisions stay aligned.
 */
final class ShellLandmarkPolicy
{
    /** @var array<int, string> */
    private const GLOBAL_SHELL_LANDMARK_TAGS = array( 'header', 'footer', 'nav' );

    /** @var array<int, string> */
    private const SEMANTIC_GROUP_TAGS = array( 'header', 'nav', 'section', 'article', 'aside', 'footer', 'main' );

    /** @var array<int, string> */
    private const FLOW_CONTAINER_TAGS = array( 'article', 'aside', 'body', 'center', 'div', 'footer', 'header', 'main', 'nav', 'section' );

    /** @var array<int, string> */
    private const WRAPPER_PRESERVING_TAGS = array( 'article', 'aside', 'div', 'footer', 'header', 'main', 'nav', 'section' );

    /** @var array<int, string> */
    private const INLINE_TOKEN_CONTAINER_TAGS = array( 'div', 'footer', 'header', 'main', 'nav', 'section' );

    /** @var array<int, string> */
    private const INLINE_CONTENT_WRAPPER_TAGS = array( 'article', 'div', 'footer', 'header', 'main', 'section' );

    public static function isGlobalShellLandmarkTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::GLOBAL_SHELL_LANDMARK_TAGS, true);
    }

    public static function isSemanticGroupTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::SEMANTIC_GROUP_TAGS, true);
    }

    public static function isFlowContainerTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::FLOW_CONTAINER_TAGS, true);
    }

    public static function isWrapperPreservingTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::WRAPPER_PRESERVING_TAGS, true);
    }

    public static function isInlineTokenContainerTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::INLINE_TOKEN_CONTAINER_TAGS, true);
    }

    public static function isInlineContentWrapperTag(string $tagName): bool
    {
        return in_array(strtolower($tagName), self::INLINE_CONTENT_WRAPPER_TAGS, true);
    }

    public static function landmarkKind(string $tagName, string $role = '', bool $insideContentLocalCitation = false): string
    {
        $tagName = strtolower($tagName);
        if ( 'footer' === $tagName && $insideContentLocalCitation ) {
            return '';
        }

        if ( in_array($tagName, array( 'header', 'nav', 'main', 'footer' ), true) ) {
            return 'nav' === $tagName ? 'nav' : $tagName;
        }

        return match ( strtolower($role) ) {
            'banner' => 'header',
            'navigation' => 'nav',
            'main' => 'main',
            'contentinfo' => 'footer',
            default => '',
        };
    }

    public static function templatePartArea(string $path, string $role): string
    {
        if ( preg_match('/\b(header|footer|sidebar|navigation)\b/i', $path . ' ' . $role, $match) ) {
            return strtolower($match[1]);
        }

        return 'uncategorized';
    }
}
