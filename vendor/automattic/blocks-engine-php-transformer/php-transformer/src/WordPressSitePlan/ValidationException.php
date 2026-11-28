<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use InvalidArgumentException;

/** A compatibility-safe validation failure with bounded compiler diagnostics. */
final class ValidationException extends InvalidArgumentException
{
    /** @param array<string,mixed> $context */
    public function __construct(string $message, private array $context)
    {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function diagnostic(): array
    {
        $fields = is_array($this->context['fields'] ?? null) ? $this->context['fields'] : array();
        ksort($fields);
        $boundedFields = array();
        $truncated = (int) ($this->context['fields_truncated'] ?? 0);
        foreach ($fields as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && null !== $value) || 20 === count($boundedFields)) { ++$truncated; continue; }
            $key = substr($key, 0, 64);
            if (isset($boundedFields[$key])) { ++$truncated; continue; }
            $boundedFields[$key] = is_string($value) ? substr($value, 0, 256) : $value;
        }
        $index = $this->context['declaration_index'] ?? 0;
        $index = is_int($index) ? $index : (is_string($index) && ctype_digit($index) ? (int) $index : 0);
        $diagnostic = array('code' => 'wordpress_site_plan_invalid_declaration', 'message' => substr($this->getMessage(), 0, 256), 'source_path' => substr((string) ($this->context['source_path'] ?? ''), 0, 256), 'document_kind' => substr((string) ($this->context['document_kind'] ?? ''), 0, 64), 'declaration_kind' => substr((string) ($this->context['declaration_kind'] ?? ''), 0, 64), 'declaration_index' => max(0, $index), 'reason' => substr((string) ($this->context['reason'] ?? ''), 0, 64), 'fields' => $boundedFields);
        if (0 < $truncated) $diagnostic['fields_truncated'] = $truncated;
        return $diagnostic;
    }
}
