<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use DOMElement;

final class TableClassificationPolicy
{
    public const DATA = 'data';
    public const PARAMETER = 'parameter';
    public const LAYOUT_SIMPLE = 'layout_simple';
    public const COMPLEX_NESTED = 'complex_nested';
    public const COMPLEX_SPANNING = 'complex_spanning';

    /**
     * @return array{classification: string, representable: bool, signals: array<string, mixed>}
     */
    public function classify(DOMElement $element): array
    {
        if ( 'table' !== strtolower($element->tagName) ) {
            return array(
                'classification' => self::LAYOUT_SIMPLE,
                'representable'  => false,
                'signals'        => array(),
            );
        }

        $signals = $this->tableSignals($element);
        if ( true === $signals['has_descendant_table'] ) {
            return array(
                'classification' => self::COMPLEX_NESTED,
                'representable'  => false,
                'signals'        => $signals,
            );
        }

        if ( true === $signals['has_colspan'] || true === $signals['has_rowspan'] || false === $signals['rectangular'] ) {
            return array(
                'classification' => self::COMPLEX_SPANNING,
                'representable'  => false,
                'signals'        => $signals,
            );
        }

        return array(
            'classification' => true === $signals['data_signals'] ? self::DATA : self::LAYOUT_SIMPLE,
            'representable'  => true,
            'signals'        => $signals,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function tableSignals(DOMElement $table): array
    {
        $rows = $this->rowsForTable($table);
        $columnCounts = array();
        $hasColspan = false;
        $hasRowspan = false;
        $hasHeaderCell = false;

        foreach ( $rows as $row ) {
            $columnCount = 0;
            foreach ( $this->cellsForRow($row) as $cell ) {
                ++$columnCount;
                $tagName = strtolower($cell->tagName);
                $hasHeaderCell = $hasHeaderCell || 'th' === $tagName;
                $hasColspan = $hasColspan || $cell->hasAttribute('colspan');
                $hasRowspan = $hasRowspan || $cell->hasAttribute('rowspan');
            }
            $columnCounts[] = $columnCount;
        }

        $nonEmptyColumnCounts = array_values(array_filter($columnCounts, static fn (int $count): bool => $count > 0));
        $rectangular = array() !== $nonEmptyColumnCounts && 1 === count(array_unique($nonEmptyColumnCounts));
        $hasCaption = null !== $this->firstDirectChild($table, 'caption');
        $hasSection = null !== $this->firstDirectChild($table, 'thead') || null !== $this->firstDirectChild($table, 'tfoot');

        return array(
            'has_descendant_table' => $this->hasDescendantTable($table),
            'has_colspan'          => $hasColspan,
            'has_rowspan'          => $hasRowspan,
            'row_count'            => count($rows),
            'column_counts'        => $columnCounts,
            'rectangular'          => $rectangular,
            'data_signals'         => $hasHeaderCell || $hasCaption || $hasSection,
        );
    }

    /**
     * @return array<int, DOMElement>
     */
    private function rowsForTable(DOMElement $table): array
    {
        $rows = array();
        foreach ( $table->getElementsByTagName('tr') as $row ) {
            if ( $row instanceof DOMElement && $this->belongsToTable($row, $table) ) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function cellsForRow(DOMElement $row): array
    {
        $cells = array();
        foreach ( $row->childNodes as $cell ) {
            if ( $cell instanceof DOMElement && in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                $cells[] = $cell;
            }
        }

        return $cells;
    }

    private function belongsToTable(DOMElement $element, DOMElement $table): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'table' !== strtolower($node->tagName) ) {
                continue;
            }

            return $node->isSameNode($table);
        }

        return false;
    }

    private function hasDescendantTable(DOMElement $table): bool
    {
        foreach ( $table->getElementsByTagName('table') as $descendant ) {
            if ( $descendant instanceof DOMElement && ! $descendant->isSameNode($table) ) {
                return true;
            }
        }

        return false;
    }

    private function firstDirectChild(DOMElement $element, string $tagName): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $tagName === strtolower($child->tagName) ) {
                return $child;
            }
        }

        return null;
    }
}
