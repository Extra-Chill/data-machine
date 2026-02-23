/**
 * Internal dependencies
 */
import type { PageUtils } from './';
/**
 * Sets the clipboard data that can be pasted with
 * `pressKeys( 'primary+v' )`.
 *
 * @param this
 * @param clipboardData
 * @param clipboardData.plainText
 * @param clipboardData.html
 */
export declare function setClipboardData(this: PageUtils, { plainText, html }: {
    plainText?: string | undefined;
    html?: string | undefined;
}): void;
type Options = {
    times?: number;
    delay?: number;
};
export declare function pressKeys(this: PageUtils, key: string, { times, ...pressOptions }?: Options): Promise<void>;
export {};
//# sourceMappingURL=press-keys.d.ts.map