<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\StaticSite;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use InvalidArgumentException;

final class MaterializationView
{
    public const SCHEMA = 'blocks-engine/php-transformer/materialization-view/v1';

    /**
     * @return array<string,mixed>
     */
    public function fromResult(array|object $result): array
    {
        $data = $this->resultArray($result);
        TransformerResult::assertCanonicalEnvelope($data);

        $sourceReports = $data['source_reports'];
        $materializationPlan = ( new MaterializationPlanBuilder() )->fromResult($data);

        return array(
            'schema'               => self::SCHEMA,
            'result_schema'        => $data['schema'],
            'status'               => $data['status'],
            'artifact_summary'     => $this->arrayValue($sourceReports, 'artifact'),
            'materialization_plan' => $materializationPlan,
            'compiled_site'        => $this->arrayValue($sourceReports, 'compiled_site'),
            'assets'               => $data['assets'],
            'documents'            => $data['documents'],
            'block_markup'         => $data['serialized_blocks'],
            'blocks'               => $data['blocks'],
            'block_types'          => $data['block_types'],
            'components'           => $data['components'],
            'diagnostics'          => $data['diagnostics'],
            'provenance'           => $data['provenance'],
            'conversion_report'    => $this->arrayValue($sourceReports, 'conversion_report'),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function resultArray(array|object $result): array
    {
        if ( $result instanceof TransformerResult ) {
            return $result->toArray();
        }

        if ( is_array($result) ) {
            return $result;
        }

        if ( is_callable(array($result, 'toArray')) ) {
            $data = $result->toArray();
            if ( is_array($data) ) {
                return $data;
            }
        }

        throw new InvalidArgumentException('Materialization view expects a TransformerResult, result array, or object with toArray().');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function arrayValue(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : array();
    }
}
