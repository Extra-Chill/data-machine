<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\FigFile;

/**
 * Normalized accessors for raw Kiwi schema field metadata.
 */
final class FigKiwiSchemaFields
{
    public const PRIMITIVE_TYPES = array('bool', 'byte', 'int', 'uint', 'float', 'string', 'int64', 'uint64');

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $fieldsByValueCache = array();

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    public function definitionsByName(array $schema): array
    {
        $definitions = array();
        foreach ( $schema['definitions'] ?? array() as $definition ) {
            if ( is_array($definition) && isset($definition['name']) ) {
                $definitions[(string) $definition['name']] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, array<string, mixed>>
     */
    public function fieldsByValue(array $definition): array
    {
        $cacheKey = $this->definitionCacheKey($definition);
        if ( isset($this->fieldsByValueCache[$cacheKey]) ) {
            return $this->fieldsByValueCache[$cacheKey];
        }

        $fields = array();
        foreach ( $definition['fields'] ?? array() as $field ) {
            if ( is_array($field) ) {
                $fields[$this->fieldNumber($field)] = $field;
            }
        }

        $this->fieldsByValueCache[$cacheKey] = $fields;
        return $fields;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function definitionCacheKey(array $definition): string
    {
        $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : array();
        $first = $fields[0] ?? array();
        $last = $fields[array_key_last($fields)] ?? array();

        return implode('|', array(
            (string) ($definition['name'] ?? ''),
            (string) ($definition['kind'] ?? ''),
            (string) count($fields),
            is_array($first) ? $this->fieldCacheKeyPart($first) : '',
            is_array($last) ? $this->fieldCacheKeyPart($last) : '',
        ));
    }

    /**
     * @param array<string, mixed> $field
     */
    private function fieldCacheKeyPart(array $field): string
    {
        return implode(':', array(
            (string) ($field['value'] ?? ''),
            (string) ($field['name'] ?? ''),
            (string) ($field['type'] ?? ''),
            true === ($field['is_array'] ?? false) ? '1' : '0',
            true === ($field['is_deprecated'] ?? false) ? '1' : '0',
        ));
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, array<string, mixed>>
     */
    public function fields(array $definition): array
    {
        $fields = array();
        foreach ( $definition['fields'] ?? array() as $field ) {
            if ( is_array($field) ) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $field
     */
    public function fieldName(array $field): string
    {
        return (string) ($field['name'] ?? '');
    }

    /**
     * @param array<string, mixed> $field
     */
    public function fieldType(array $field): string
    {
        return (string) ($field['type'] ?? '');
    }

    /**
     * @param array<string, mixed> $field
     */
    public function isArrayField(array $field): bool
    {
        return true === ($field['is_array'] ?? false);
    }

    /**
     * @param array<string, mixed> $field
     */
    public function isDeprecatedField(array $field): bool
    {
        return true === ($field['is_deprecated'] ?? false);
    }

    /**
     * @param array<string, mixed> $field
     */
    public function fieldNumber(array $field): int
    {
        return (int) ($field['value'] ?? 0);
    }

    public function fieldPath(string $parentPath, string $fieldName): string
    {
        return $parentPath . '.' . $fieldName;
    }

    public function inventoryKey(string $parentType, string $path, string $fieldName, string $type): string
    {
        return $parentType . '|' . $path . '|' . $fieldName . '|' . $type;
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     */
    public function wireType(array $field, array $definitions): string
    {
        $type = $this->fieldType($field);
        if ( $this->isArrayField($field) ) {
            return 'length_delimited_array';
        }

        if ( in_array($type, array('bool', 'byte', 'int', 'uint', 'int64', 'uint64'), true) ) {
            return 'varint';
        }
        if ( 'float' === $type ) {
            return 'varfloat';
        }
        if ( 'string' === $type ) {
            return 'null_terminated_string';
        }

        $definition = $definitions[$type] ?? null;
        if ( ! is_array($definition) ) {
            return 'unknown';
        }

        return match ( $definition['kind'] ?? null ) {
            'ENUM' => 'varint_enum',
            'STRUCT' => 'kiwi_struct',
            'MESSAGE' => 'kiwi_message',
            default => 'unknown',
        };
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @return array<string, mixed>
     */
    public function typeDefinition(string $type, array $definitions): array
    {
        if ( in_array($type, self::PRIMITIVE_TYPES, true) ) {
            return array('name' => $type, 'kind' => 'PRIMITIVE');
        }

        $definition = $definitions[$type] ?? null;
        return is_array($definition) ? $this->normalizeDefinition($definition) : array('name' => $type, 'kind' => 'UNKNOWN');
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    public function schemaDefinitionInventory(array $schema): array
    {
        $inventory = array();
        foreach ( $schema['definitions'] ?? array() as $definition ) {
            if ( is_array($definition) && isset($definition['name']) ) {
                $inventory[(string) $definition['name']] = $this->normalizeDefinition($definition);
            }
        }
        ksort($inventory);
        return $inventory;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function normalizeDefinition(array $definition): array
    {
        $fields = array();
        foreach ( $this->fields($definition) as $field ) {
            $fields[] = array(
                'name'       => $this->fieldName($field),
                'type'       => $this->fieldType($field),
                'is_array'   => $this->isArrayField($field),
                'number'     => $this->fieldNumber($field),
                'deprecated' => $this->isDeprecatedField($field),
            );
        }

        return array(
            'name'   => (string) ($definition['name'] ?? ''),
            'kind'   => (string) ($definition['kind'] ?? 'UNKNOWN'),
            'fields' => $fields,
        );
    }
}
