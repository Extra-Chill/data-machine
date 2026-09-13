<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

final class LinkUrlSanitizer
{
    private const ALLOWED_PROTOCOLS = array(
        'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'ircs', 'gopher',
        'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'svn', 'tel', 'fax', 'xmpp',
        'webcal', 'urn',
    );

    public static function sanitize(string $url): string
    {
        $trimmed = preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $url);
        $url = is_string($trimmed) ? $trimmed : trim($url);

        if ( '' === $url || 1 !== preg_match('//u', $url) || 1 === preg_match('/[\x00-\x20\x7f-\x9f\p{Z}\x{FEFF}]/u', $url) ) {
            return '';
        }

        if ( self::isBareEmail($url) ) {
            return 'mailto:' . $url;
        }

        if ( self::isBareWebHost($url) ) {
            return 'https://' . $url;
        }

        if ( 1 === preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches) && ! in_array(strtolower($matches[1]), self::ALLOWED_PROTOCOLS, true) ) {
            return '';
        }

        return $url;
    }

    private static function isBareEmail(string $url): bool
    {
        $atom = '[\p{L}\p{N}!#$%&\'*+=?^_`{|}~-]+';
        $quoted = '"(?:\\\\[\x21-\x7e]|[\x21\x23-\x5b\x5d-\x7e])+"';
        $label = '[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?';

        return 1 === preg_match('/^(?:' . $atom . '(?:\.' . $atom . ')*|' . $quoted . ')@' . $label . '(?:\.' . $label . ')+$/u', $url);
    }

    private static function isBareWebHost(string $url): bool
    {
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        $fileExtensions = array('asp', 'aspx', 'avif', 'css', 'gif', 'htm', 'html', 'jpeg', 'jpg', 'js', 'json', 'markdown', 'md', 'mdown', 'mkd', 'pdf', 'php', 'png', 'svg', 'txt', 'webp', 'xml', 'zip');

        if (1 !== preg_match('/^(?:' . $label . '\.)+([a-z]{2,63})$/i', $url, $matches)) {
            return false;
        }

        return ! in_array(strtolower($matches[1]), $fileExtensions, true);
    }
}
