/**
 * Internal dependencies
 */
import type { Editor } from './index';
type Block = {
    name: string;
    attributes: Record<string, unknown>;
    innerBlocks: Block[];
};
/**
 * Returns the edited blocks.
 *
 * @param this
 * @param options
 * @param options.clientId Limit the results to be only under a partial tree of the specified clientId.
 * @param options.full     Whether to return the full block data or just the name and attributes.
 *
 * @return  The blocks.
 */
export declare function getBlocks(this: Editor, { clientId, full }?: {
    clientId?: string;
    full?: boolean;
}): Promise<Block[]>;
export {};
//# sourceMappingURL=get-blocks.d.ts.map