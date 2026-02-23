/**
 * @typedef SchemaItem
 * @property {string[]}                            [attributes] Attributes.
 * @property {(string | RegExp)[]}                 [classes]    Classnames or RegExp to test against.
 * @property {'*' | { [tag: string]: SchemaItem }} [children]   Child schemas.
 * @property {string[]}                            [require]    Selectors to test required children against. Leave empty or undefined if there are no requirements.
 * @property {boolean}                             allowEmpty   Whether to allow nodes without children.
 * @property {(node: Node) => boolean}             [isMatch]    Function to test whether a node is a match. If left undefined any node will be assumed to match.
 */
/** @typedef {{ [tag: string]: SchemaItem }} Schema */
/**
 * Given a schema, unwraps or removes nodes, attributes and classes on a node
 * list.
 *
 * @param {NodeList} nodeList The nodeList to filter.
 * @param {Document} doc      The document of the nodeList.
 * @param {Schema}   schema   An array of functions that can mutate with the provided node.
 * @param {boolean}  inline   Whether to clean for inline mode.
 */
export default function cleanNodeList(nodeList: NodeList, doc: Document, schema: Schema, inline: boolean): void;
export type SchemaItem = {
    /**
     * Attributes.
     */
    attributes?: string[] | undefined;
    /**
     * Classnames or RegExp to test against.
     */
    classes?: (string | RegExp)[] | undefined;
    /**
     * Child schemas.
     */
    children?: "*" | {
        [tag: string]: SchemaItem;
    } | undefined;
    /**
     * Selectors to test required children against. Leave empty or undefined if there are no requirements.
     */
    require?: string[] | undefined;
    /**
     * Whether to allow nodes without children.
     */
    allowEmpty: boolean;
    /**
     * Function to test whether a node is a match. If left undefined any node will be assumed to match.
     */
    isMatch?: ((node: Node) => boolean) | undefined;
};
export type Schema = {
    [tag: string]: SchemaItem;
};
//# sourceMappingURL=clean-node-list.d.ts.map