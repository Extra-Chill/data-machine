<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Path;

final class ArtifactPath
{
    public static function safeRelativePath(string $path): string
    {
        $path = self::cleanInput($path);
        if ( '' === $path || str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:/#', $path) ) {
            return '';
        }

        $parts = array();
        foreach ( explode('/', $path) as $part ) {
            if ( '' === $part || '.' === $part ) {
                continue;
            }
            if ( '..' === $part ) {
                return '';
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    public static function resolveRelativePath(string $reference, string $sourcePath = '', bool $sanitizeSegments = false): string
    {
        $reference = self::stripQueryAndFragment($reference);
        $reference = self::cleanInput($reference);
        if ( '' === $reference || self::isAbsoluteReference($reference) ) {
            return '';
        }

        $base = '' === $sourcePath || ! str_contains($sourcePath, '/') ? '' : dirname($sourcePath) . '/';
        $parts = array();
        foreach ( explode('/', $base . $reference) as $part ) {
			$part = rawurldecode($part);
			if ( str_contains($part, '/') || str_contains($part, '\\') ) {
				return '';
			}
            if ( '' === $part || '.' === $part ) {
                continue;
            }
            if ( '..' === $part ) {
                if ( array() === $parts ) {
                    return '';
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $sanitizeSegments ? (preg_replace('/[^A-Za-z0-9._-]/', '-', $part) ?? '') : $part;
        }

        return self::safeRelativePath(implode('/', $parts));
    }

    public static function stripQueryAndFragment(string $reference): string
    {
        return strtok($reference, '?#') ?: '';
    }

    private static function cleanInput(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    private static function isAbsoluteReference(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $path);
    }
}
