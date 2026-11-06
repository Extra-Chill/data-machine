<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use InvalidArgumentException;

/** Maps browser-visible asset URLs to declared plan tokens. */
final class AssetReferenceCanonicalizer
{
    /** @var array<string,string> */
    private array $tokensBySource = array();

    private string $siteRoot;

    /** @param array<int,array<string,string>> $tokens */
    public function __construct(array $tokens, string $siteRoot = '')
    {
        $this->siteRoot = self::identity($siteRoot);
        foreach ($tokens as $token) {
            $source = self::identity($token['source_path'] ?? '');
            if ('' === $source || !is_string($token['token'] ?? null) || isset($this->tokensBySource[$source])) {
                throw new InvalidArgumentException('WordPress site plan has colliding declared asset source identities.');
            }
            $this->tokensBySource[$source] = WordPressSitePlan::TOKEN_PREFIX . $token['token'] . '}}';
        }
    }

    public function reference(string $reference, string $origin): ?string
    {
        if ('' === trim($reference) || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $reference)) {
            return null;
        }
        preg_match('/^([^?#]*)(.*)$/s', $reference, $parts);
        $path = $parts[1] ?? '';
        $suffix = $parts[2] ?? '';
        if ('' === $path || preg_match('~%2f|%5c|%2e~i', $path)) {
            return null;
        }
        $normalizedPath = str_replace('\\', '/', $path);
        $externalPath = ltrim($normalizedPath, '/');
        $external = str_starts_with($externalPath, '_external/');
        if (str_starts_with($normalizedPath, '/')) {
            // The entry document's directory is the website root when an
            // artifact is packaged beneath a wrapper such as `website/`.
            $identity = self::rootedIdentity($path, $this->siteRoot);
            // Keep bare artifact-root references working for standalone assets
            // and transport-only `_external/` paths.
            if ('' !== $identity && !isset($this->tokensBySource[$identity])) $identity = self::identity(ltrim($path, '/'));
        } elseif ($external) {
            // Downloaded remote assets retain this artifact-root staging namespace.
            $identity = self::identity($path);
            if (!isset($this->tokensBySource[$identity])) {
                $relativeIdentity = self::relativeIdentity($path, $origin);
                if (isset($this->tokensBySource[$relativeIdentity])) {
                    $identity = $relativeIdentity;
                }
            }
        } else {
            $identity = self::relativeIdentity($path, $origin);
            // Compiler block markup may already carry an artifact-relative identity.
            if (!isset($this->tokensBySource[$identity])) $identity = self::identity($path);
        }
        if ('' !== $identity && isset($this->tokensBySource[$identity])) {
            return $this->tokensBySource[$identity] . $suffix;
        }
        // `_external/` is a transport-only prefix used by downloaded remote
        // assets; match its declared artifact-relative identity when present.
        if ($external) {
            $relativeIdentity = self::relativeIdentity($externalPath, $origin);
            if (isset($this->tokensBySource[$relativeIdentity])) {
                return $this->tokensBySource[$relativeIdentity] . $suffix;
            }
            $stagedIdentity = substr($externalPath, strlen('_external/'));
            if (isset($this->tokensBySource[$stagedIdentity])) {
                return $this->tokensBySource[$stagedIdentity] . $suffix;
            }
        }
        return null;
    }

    public function content(string $content, string $origin): string
    {
        $replace = fn(string $reference): string => $this->reference($reference, $origin) ?? $reference;
        if (str_ends_with(strtolower($origin), '.css')) return self::css($content, $replace);
        $content = preg_replace_callback('~<\s*[A-Za-z][A-Za-z0-9:-]*(?:\s+(?:"[^"]*"|\'[^\']*\'|[^\'"<>])*)?/?>~s', static fn(array $match): string => self::tag($match[0], $replace), $content) ?? $content;
        // RichText image attributes inside serialized block JSON use escaped
        // quotes, so they are not parsed as ordinary HTML tags above.
        if (str_contains($content, '\\"')) {
            $content = preg_replace_callback('~(\b(?:src|href|poster)\s*=\s*\\\\")([^"\\\\]*)(\\\\")~is', static fn(array $match): string => $match[1] . $replace($match[2]) . $match[3], $content) ?? $content;
            $content = preg_replace_callback('~(\bsrcset\s*=\s*\\\\")([^"\\\\]*)(\\\\")~is', static fn(array $match): string => $match[1] . self::srcset($match[2], $replace) . $match[3], $content) ?? $content;
        }
        if (str_contains($content, '\\u0022')) {
            $content = self::replaceWhenChanged('~(\b(?:src|href|poster)\s*=\s*\\\\u0022)(.*?)(\\\\u0022)~is', $content, static fn(array $match): string => $match[1] . $replace($match[2]) . $match[3]);
            $content = self::replaceWhenChanged('~(\bsrcset\s*=\s*\\\\u0022)(.*?)(\\\\u0022)~is', $content, static fn(array $match): string => $match[1] . self::srcset($match[2], $replace) . $match[3]);
        }
        if (false !== stripos($content, '<style')) {
            $content = preg_replace_callback('~<style\b[^>]*>(.*?)</style\s*>~is', static function (array $match) use ($replace): string {
                return str_replace($match[1], self::css($match[1], $replace), $match[0]);
            }, $content) ?? $content;
        }
        // Block comments are the supported serialized JSON transport. Restricting
        // rewrites to them avoids treating arbitrary text or SVG data as markup.
        return str_contains($content, '<!--') ? self::replaceWhenChanged('~<!--\s*wp:.*?-->~is', $content, static fn(array $match): string => self::json($match[0], $replace)) : $content;
    }

    /** @param callable(array<int|string,string>):string $replace */
    private static function replaceWhenChanged(string $pattern, string $content, callable $replace): string
    {
        $offset = 0;
        while (preg_match($pattern, $content, $captures, PREG_OFFSET_CAPTURE, $offset)) {
            $match = array();
            foreach ($captures as $key => $capture) $match[$key] = $capture[0];
            if ($replace($match) !== $match[0]) return preg_replace_callback($pattern, $replace, $content) ?? $content;
            $offset = $captures[0][1] + strlen($captures[0][0]);
        }
        return $content;
    }

    /** @param callable(string):string $replace */
    private static function tag(string $tag, callable $replace): string
    {
        return preg_replace_callback('~(?<![A-Za-z0-9:_-])(xlink:href|srcset|src|href|poster|action|style)\s*=\s*(["\'])(.*?)\2~is', static function (array $match) use ($replace): string {
            $name = strtolower($match[1]);
            $value = 'style' === $name ? self::css($match[3], $replace) : ('srcset' === $name ? self::srcset($match[3], $replace) : $replace($match[3]));
            return $match[1] . '=' . $match[2] . $value . $match[2];
        }, $tag) ?? $tag;
    }

    /** @param callable(string):string $replace */
    private static function css(string $css, callable $replace): string
    {
        $css = CssUrlRewriter::rewrite($css, $replace);
        return preg_replace_callback('~(@import\s+)(["\'])([^"\']+)\2~i', static fn(array $match): string => $match[1] . $match[2] . $replace($match[3]) . $match[2], $css) ?? $css;
    }

    /** @param callable(string):string $replace */
    private static function json(string $comment, callable $replace): string
    {
        return preg_replace_callback('~((?:"|\\\\u0022)(url|src|href|poster|action|srcset)(?:"|\\\\u0022)\s*:\s*(?:"|\\\\u0022))(.*?)(?:"|\\\\u0022)~is', static function (array $match) use ($replace): string {
            $jsonReplace = static function (string $reference) use ($replace): string {
                $normalized = str_replace('\\/', '/', $reference);
                $value = $replace($normalized);
                return $normalized === $value ? $reference : $value;
            };
            $value = 'srcset' === strtolower($match[2]) ? self::srcset($match[3], $jsonReplace) : $jsonReplace($match[3]);
            return $match[1] . $value . (str_contains($match[0], '\\u0022') ? '\\u0022' : '"');
        }, $comment) ?? $comment;
    }

    /** @param callable(string):string $replace */
    private static function srcset(string $value, callable $replace): string
    {
        return implode(',', array_map(static function (string $candidate) use ($replace): string {
            if (!preg_match('/^(\s*)(\S+)(.*)$/s', $candidate, $parts)) return $candidate;
            return $parts[1] . $replace($parts[2]) . $parts[3];
        }, explode(',', $value)));
    }

    private static function relativeIdentity(string $reference, string $origin): string
    {
        $base = '' === $origin || !str_contains($origin, '/') ? array() : explode('/', dirname($origin));
        return self::segments(array_merge($base, explode('/', str_replace('\\', '/', $reference))));
    }

    private static function identity(string $path): string
    {
        return self::segments(explode('/', str_replace('\\', '/', $path)));
    }

    private static function rootedIdentity(string $reference, string $siteRoot): string
    {
        $root = '' === $siteRoot ? array() : explode('/', $siteRoot);
        $segments = $root;
        foreach (explode('/', ltrim(str_replace('\\', '/', $reference), '/')) as $segment) {
            $segment = rawurldecode($segment);
            if (str_contains($segment, '/') || str_contains($segment, '\\')) return '';
            if ('' === $segment || '.' === $segment) continue;
            if ('..' === $segment) {
                if (count($segments) <= count($root)) return '';
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }

    /** @param array<int,string> $segments */
    private static function segments(array $segments): string
    {
        $normalized = array();
        foreach ($segments as $segment) {
			$segment = rawurldecode($segment);
			if (str_contains($segment, '/') || str_contains($segment, '\\')) return '';
            if ('' === $segment || '.' === $segment) continue;
            if ('..' === $segment) {
                if (array() === $normalized) return '';
                array_pop($normalized);
                continue;
            }
            $normalized[] = $segment;
        }
        return implode('/', $normalized);
    }
}
