import type { APIRequestContext, Cookie } from '@playwright/test';
import type { User } from './login';
import { login } from './login';
import { listMedia, uploadMedia, deleteMedia, deleteAllMedia } from './media';
import { createUser, deleteAllUsers } from './users';
import { setupRest, rest, getMaxBatchSize, batchRest } from './rest';
import { getPluginsMap, activatePlugin, deactivatePlugin } from './plugins';
import { deleteAllTemplates, createTemplate } from './templates';
import { activateTheme, getCurrentThemeGlobalStylesPostId, getThemeGlobalStylesRevisions } from './themes';
import { createBlock } from './blocks';
import { createComment, deleteAllComments } from './comments';
import { createPost, deleteAllPosts } from './posts';
import { createClassicMenu, createNavigationMenu, deleteAllMenus, getNavigationMenus } from './menus';
import { deleteAllPages, createPage } from './pages';
import { resetPreferences } from './preferences';
import { getSiteSettings, updateSiteSettings } from './site-settings';
import { deleteAllWidgets, addWidgetBlock } from './widgets';
import { setGutenbergExperiments } from './gutenberg-experiments';
interface StorageState {
    cookies: Cookie[];
    nonce: string;
    rootURL: string;
}
declare class RequestUtils {
    request: APIRequestContext;
    user: User;
    maxBatchSize?: number;
    storageState?: StorageState;
    storageStatePath?: string;
    baseURL?: string;
    pluginsMap: Record<string, string> | null;
    static setup({ user, storageStatePath, baseURL, }: {
        user?: User;
        storageStatePath?: string;
        baseURL?: string;
    }): Promise<RequestUtils>;
    constructor(requestContext: APIRequestContext, { user, storageState, storageStatePath, baseURL, }?: {
        user?: User;
        storageState?: StorageState;
        storageStatePath?: string;
        baseURL?: string;
    });
    /** @borrows login as this.login */
    login: typeof login;
    /** @borrows setupRest as this.setupRest */
    setupRest: typeof setupRest;
    rest: typeof rest;
    /** @borrows getMaxBatchSize as this.getMaxBatchSize */
    getMaxBatchSize: typeof getMaxBatchSize;
    batchRest: typeof batchRest;
    /** @borrows getPluginsMap as this.getPluginsMap */
    getPluginsMap: typeof getPluginsMap;
    /** @borrows activatePlugin as this.activatePlugin */
    activatePlugin: typeof activatePlugin;
    /** @borrows deactivatePlugin as this.deactivatePlugin */
    deactivatePlugin: typeof deactivatePlugin;
    /** @borrows activateTheme as this.activateTheme */
    activateTheme: typeof activateTheme;
    /** @borrows createBlock as this.createBlock */
    createBlock: typeof createBlock;
    /** @borrows deleteAllBlocks as this.deleteAllBlocks */
    deleteAllBlocks: () => Promise<void>;
    /** @borrows createPost as this.createPost */
    createPost: typeof createPost;
    /** @borrows deleteAllPosts as this.deleteAllPosts */
    deleteAllPosts: typeof deleteAllPosts;
    /** @borrows createClassicMenu as this.createClassicMenu */
    createClassicMenu: typeof createClassicMenu;
    /** @borrows createNavigationMenu as this.createNavigationMenu */
    createNavigationMenu: typeof createNavigationMenu;
    /** @borrows deleteAllMenus as this.deleteAllMenus */
    deleteAllMenus: typeof deleteAllMenus;
    /** @borrows getNavigationMenus as this.getNavigationMenus */
    getNavigationMenus: typeof getNavigationMenus;
    /** @borrows createComment as this.createComment */
    createComment: typeof createComment;
    /** @borrows deleteAllComments as this.deleteAllComments */
    deleteAllComments: typeof deleteAllComments;
    /** @borrows deleteAllWidgets as this.deleteAllWidgets */
    deleteAllWidgets: typeof deleteAllWidgets;
    /** @borrows addWidgetBlock as this.addWidgetBlock */
    addWidgetBlock: typeof addWidgetBlock;
    /** @borrows deleteAllTemplates as this.deleteAllTemplates */
    deleteAllTemplates: typeof deleteAllTemplates;
    /** @borrows createTemplate as this.createTemplate */
    createTemplate: typeof createTemplate;
    /** @borrows resetPreferences as this.resetPreferences */
    resetPreferences: typeof resetPreferences;
    /** @borrows listMedia as this.listMedia */
    listMedia: typeof listMedia;
    /** @borrows uploadMedia as this.uploadMedia */
    uploadMedia: typeof uploadMedia;
    /** @borrows deleteMedia as this.deleteMedia */
    deleteMedia: typeof deleteMedia;
    /** @borrows deleteAllMedia as this.deleteAllMedia */
    deleteAllMedia: typeof deleteAllMedia;
    /** @borrows createUser as this.createUser */
    createUser: typeof createUser;
    /** @borrows deleteAllUsers as this.deleteAllUsers */
    deleteAllUsers: typeof deleteAllUsers;
    /** @borrows getSiteSettings as this.getSiteSettings */
    getSiteSettings: typeof getSiteSettings;
    /** @borrows updateSiteSettings as this.updateSiteSettings */
    updateSiteSettings: typeof updateSiteSettings;
    /** @borrows deleteAllPages as this.deleteAllPages */
    deleteAllPages: typeof deleteAllPages;
    /** @borrows createPage as this.createPage */
    createPage: typeof createPage;
    /** @borrows getCurrentThemeGlobalStylesPostId as this.getCurrentThemeGlobalStylesPostId */
    getCurrentThemeGlobalStylesPostId: typeof getCurrentThemeGlobalStylesPostId;
    /** @borrows getThemeGlobalStylesRevisions as this.getThemeGlobalStylesRevisions */
    getThemeGlobalStylesRevisions: typeof getThemeGlobalStylesRevisions;
    /** @borrows deleteAllPatternCategories as this.deleteAllPatternCategories */
    deleteAllPatternCategories: () => Promise<void>;
    /** @borrows setGutenbergExperiments as this.setGutenbergExperiments */
    setGutenbergExperiments: typeof setGutenbergExperiments;
}
export type { StorageState };
export { RequestUtils };
//# sourceMappingURL=index.d.ts.map