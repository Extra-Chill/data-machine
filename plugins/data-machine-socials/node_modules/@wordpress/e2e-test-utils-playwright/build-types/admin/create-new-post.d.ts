/**
 * Internal dependencies
 */
import type { Admin } from './';
interface NewPostOptions {
    postType?: string;
    title?: string;
    content?: string;
    excerpt?: string;
    showWelcomeGuide?: boolean;
    fullscreenMode?: boolean;
}
/**
 * Creates new post.
 *
 * @param this
 * @param options Options to create new post.
 */
export declare function createNewPost(this: Admin, options?: NewPostOptions): Promise<void>;
export {};
//# sourceMappingURL=create-new-post.d.ts.map