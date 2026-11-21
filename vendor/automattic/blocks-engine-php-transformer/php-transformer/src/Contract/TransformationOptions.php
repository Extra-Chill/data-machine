<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

final class TransformationOptions
{
    /**
     * @param array<string, mixed> $options
     * @return array{strict: bool, allow_fallbacks: bool}
     */
    public static function context(array $options): array
    {
        $context = isset($options['context']) && is_array($options['context']) ? $options['context'] : array();

        return array(
            'strict'          => self::optionBoolean($options, $context, 'strict', false),
            'allow_fallbacks' => self::optionBoolean($options, $context, 'allow_fallbacks', true),
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    public static function provenance(array $options): array
    {
        $provenance = isset($options['provenance']) && is_array($options['provenance']) ? $options['provenance'] : array();
        $metadata   = array();

        foreach ( array( 'source', 'scope' ) as $key ) {
            $value = $provenance[$key] ?? $options[$key] ?? ( 'scope' === $key ? ( $options['source_scope'] ?? null ) : null );
            if ( is_string($value) && '' !== $value ) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $context
     */
    private static function optionBoolean(array $options, array $context, string $key, bool $default): bool
    {
        if ( array_key_exists($key, $options) ) {
            return (bool) $options[$key];
        }

        if ( array_key_exists($key, $context) ) {
            return (bool) $context[$key];
        }

        return $default;
    }
}
