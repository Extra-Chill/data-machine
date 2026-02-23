/**
 * Internal dependencies
 */
import type { PageUtils } from './index';
import type { Locator } from '@playwright/test';
type FileObject = {
    name: string;
    mimeType?: string;
    buffer: Buffer;
};
type Options = {
    position?: {
        x: number;
        y: number;
    };
};
/**
 * Simulate dragging files from outside the current page.
 *
 * @param this
 * @param files The files to be dragged.
 * @return The methods of the drag operation.
 */
declare function dragFiles(this: PageUtils, files: string | string[] | FileObject | FileObject[]): Promise<{
    /**
     * Drag the files over an element (fires `dragenter` and `dragover` events).
     *
     * @param selectorOrLocator A selector or a locator to search for an element.
     * @param options           The optional options.
     * @param options.position  A point to use relative to the top-left corner of element padding box. If not specified, uses some visible point of the element.
     */
    dragOver: (selectorOrLocator: string | Locator, options?: Options) => Promise<void>;
    /**
     * Drop the files at the current position.
     */
    drop: () => Promise<void>;
}>;
export { dragFiles };
//# sourceMappingURL=drag-files.d.ts.map