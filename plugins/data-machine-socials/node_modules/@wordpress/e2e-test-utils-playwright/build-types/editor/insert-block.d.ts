/**
 * Internal dependencies
 */
import type { Editor } from './index';
interface BlockRepresentation {
    name: string;
    attributes?: Object;
    innerBlocks?: BlockRepresentation[];
}
/**
 * Insert a block.
 *
 * @param this
 * @param blockRepresentation Inserted block representation.
 * @param options
 * @param options.clientId    Client ID of the parent block to insert into.
 */
declare function insertBlock(this: Editor, blockRepresentation: BlockRepresentation, { clientId }?: {
    clientId?: string;
}): Promise<void>;
export type { BlockRepresentation };
export { insertBlock };
//# sourceMappingURL=insert-block.d.ts.map