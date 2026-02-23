/**
 * External dependencies
 */
import { type ComponentChild } from 'preact';
export declare const hydratedIslands: WeakSet<WeakKey>;
/**
 * Recursive function that transforms a DOM tree into vDOM.
 *
 * @param root The root element or node to start traversing on.
 * @return The resulting vDOM tree.
 */
export declare function toVdom(root: Node): Array<ComponentChild>;
//# sourceMappingURL=vdom.d.ts.map