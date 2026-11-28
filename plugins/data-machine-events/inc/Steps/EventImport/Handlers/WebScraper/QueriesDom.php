<?php
/**
 * Guarded DOMXPath query helpers.
 *
 * DOMXPath::query() returns DOMNodeList|false and its items are typed
 * DOMNode|DOMNameSpaceNode; scrapers only ever want DOMElements. These
 * helpers guarantee element-only results so callers never handle the
 * false or non-element cases.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait QueriesDom {

	/**
	 * Run an XPath query and return the matching element nodes.
	 *
	 * A false query result (invalid expression) yields an empty array and
	 * non-element nodes are filtered out.
	 *
	 * @param \DOMXPath     $xpath        XPath query object.
	 * @param string        $expression   XPath expression.
	 * @param \DOMNode|null $context_node Optional context node.
	 * @return list<\DOMElement>
	 */
	protected function queryElements( \DOMXPath $xpath, string $expression, ?\DOMNode $context_node = null ): array {
		$list = $xpath->query( $expression, $context_node );

		if ( false === $list ) {
			return array();
		}

		$elements = array();
		foreach ( $list as $node ) {
			if ( $node instanceof \DOMElement ) {
				$elements[] = $node;
			}
		}

		return $elements;
	}

	/**
	 * Run an XPath query and return the first matching element, or null.
	 *
	 * @param \DOMXPath     $xpath        XPath query object.
	 * @param string        $expression   XPath expression.
	 * @param \DOMNode|null $context_node Optional context node.
	 * @return \DOMElement|null
	 */
	protected function queryFirstElement( \DOMXPath $xpath, string $expression, ?\DOMNode $context_node = null ): ?\DOMElement {
		$elements = $this->queryElements( $xpath, $expression, $context_node );

		return $elements[0] ?? null;
	}
}
