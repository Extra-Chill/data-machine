<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\AssetAnalysis;

/** Rewrites bounded CSS url() values without accepting malformed functions. */
final class CssUrlRewriter
{
    /** @param callable(string):string $replace */
    public static function rewrite(string $content, callable $replace): string
    {
        $offset = 0;
        while (false !== ($start = stripos($content, 'url(', $offset))) {
            if ($start > 0 && preg_match('/[A-Za-z0-9_-]/', $content[$start - 1])) {
                $offset = $start + 4;
                continue;
            }
            $cursor = $start + 4;
            while (isset($content[$cursor]) && str_contains(" \t\r\n\f", $content[$cursor])) ++$cursor;
            $quote = $content[$cursor] ?? '';
            $quoted = '"' === $quote || "'" === $quote;
            $valueStart = $quoted ? ++$cursor : $cursor;
            $value = '';
            $valid = false;
            while (isset($content[$cursor])) {
                $character = $content[$cursor];
                if ('\\' === $character && isset($content[$cursor + 1])) {
                    $value .= $character . $content[++$cursor];
                    ++$cursor;
                    continue;
                }
                if ($quoted && $character === $quote) {
                    ++$cursor;
                    while (isset($content[$cursor]) && str_contains(" \t\r\n\f", $content[$cursor])) ++$cursor;
                    $valid = ')' === ($content[$cursor] ?? '');
                    break;
                }
                if (!$quoted && ')' === $character) {
                    $valid = true;
                    break;
                }
                if (!$quoted && (str_contains(" \t\r\n\f\"'(", $character) || ord($character) < 0x20)) break;
                $value .= $character;
                ++$cursor;
            }
            if (!$valid || '' === $value) {
                $offset = $start + 4;
                continue;
            }
            $reference = self::unescape($value);
            $replacement = $replace($reference);
            if ($replacement !== $reference) {
                $content = substr($content, 0, $valueStart) . $replacement . substr($content, $valueStart + strlen($value));
                $cursor += strlen($replacement) - strlen($value);
            }
            $offset = $cursor + 1;
        }
        return $content;
    }

    private static function unescape(string $value): string
    {
        return preg_replace_callback('/\\\\([0-9a-fA-F]{1,6}\s?|.)/s', static function (array $match): string {
            $escape = $match[1];
            if (preg_match('/^[0-9a-fA-F]{1,6}\s?$/', $escape)) {
                return html_entity_decode('&#x' . trim($escape) . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return $escape;
        }, $value) ?? $value;
    }
}
