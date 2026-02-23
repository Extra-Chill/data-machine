"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.RequestUtils = void 0;
/**
 * External dependencies
 */
const fs = require("fs/promises");
const path = require("path");
const test_1 = require("@playwright/test");
/**
 * Internal dependencies
 */
const config_1 = require("../config");
const login_1 = require("./login");
const media_1 = require("./media");
const users_1 = require("./users");
const rest_1 = require("./rest");
const plugins_1 = require("./plugins");
const templates_1 = require("./templates");
const themes_1 = require("./themes");
const blocks_1 = require("./blocks");
const comments_1 = require("./comments");
const posts_1 = require("./posts");
const menus_1 = require("./menus");
const pages_1 = require("./pages");
const preferences_1 = require("./preferences");
const site_settings_1 = require("./site-settings");
const widgets_1 = require("./widgets");
const patterns_1 = require("./patterns");
const gutenberg_experiments_1 = require("./gutenberg-experiments");
class RequestUtils {
    request;
    user;
    maxBatchSize;
    storageState;
    storageStatePath;
    baseURL;
    pluginsMap = null;
    static async setup({ user, storageStatePath, baseURL = config_1.WP_BASE_URL, }) {
        let storageState;
        if (storageStatePath) {
            await fs.mkdir(path.dirname(storageStatePath), {
                recursive: true,
            });
            try {
                storageState = JSON.parse(await fs.readFile(storageStatePath, 'utf-8'));
            }
            catch (error) {
                if (error instanceof Error &&
                    error.code === 'ENOENT') {
                    // Ignore errors if the state is not found.
                }
                else {
                    throw error;
                }
            }
        }
        const requestContext = await test_1.request.newContext({
            baseURL,
            storageState: storageState && {
                cookies: storageState.cookies,
                origins: [],
            },
        });
        const requestUtils = new this(requestContext, {
            user,
            storageState,
            storageStatePath,
            baseURL,
        });
        return requestUtils;
    }
    constructor(requestContext, { user = config_1.WP_ADMIN_USER, storageState, storageStatePath, baseURL = config_1.WP_BASE_URL, } = {}) {
        this.user = user;
        this.request = requestContext;
        this.storageStatePath = storageStatePath;
        this.storageState = storageState;
        this.baseURL = baseURL;
    }
    /** @borrows login as this.login */
    login = login_1.login.bind(this);
    /** @borrows setupRest as this.setupRest */
    setupRest = rest_1.setupRest.bind(this);
    // .bind() drops the generic types. Re-casting it to keep the type signature.
    rest = rest_1.rest.bind(this);
    /** @borrows getMaxBatchSize as this.getMaxBatchSize */
    getMaxBatchSize = rest_1.getMaxBatchSize.bind(this);
    // .bind() drops the generic types. Re-casting it to keep the type signature.
    batchRest = rest_1.batchRest.bind(this);
    /** @borrows getPluginsMap as this.getPluginsMap */
    getPluginsMap = plugins_1.getPluginsMap.bind(this);
    /** @borrows activatePlugin as this.activatePlugin */
    activatePlugin = plugins_1.activatePlugin.bind(this);
    /** @borrows deactivatePlugin as this.deactivatePlugin */
    deactivatePlugin = plugins_1.deactivatePlugin.bind(this);
    /** @borrows activateTheme as this.activateTheme */
    activateTheme = themes_1.activateTheme.bind(this);
    /** @borrows createBlock as this.createBlock */
    createBlock = blocks_1.createBlock.bind(this);
    /** @borrows deleteAllBlocks as this.deleteAllBlocks */
    deleteAllBlocks = blocks_1.deleteAllBlocks.bind(this);
    /** @borrows createPost as this.createPost */
    createPost = posts_1.createPost.bind(this);
    /** @borrows deleteAllPosts as this.deleteAllPosts */
    deleteAllPosts = posts_1.deleteAllPosts.bind(this);
    /** @borrows createClassicMenu as this.createClassicMenu */
    createClassicMenu = menus_1.createClassicMenu.bind(this);
    /** @borrows createNavigationMenu as this.createNavigationMenu */
    createNavigationMenu = menus_1.createNavigationMenu.bind(this);
    /** @borrows deleteAllMenus as this.deleteAllMenus */
    deleteAllMenus = menus_1.deleteAllMenus.bind(this);
    /** @borrows getNavigationMenus as this.getNavigationMenus */
    getNavigationMenus = menus_1.getNavigationMenus.bind(this);
    /** @borrows createComment as this.createComment */
    createComment = comments_1.createComment.bind(this);
    /** @borrows deleteAllComments as this.deleteAllComments */
    deleteAllComments = comments_1.deleteAllComments.bind(this);
    /** @borrows deleteAllWidgets as this.deleteAllWidgets */
    deleteAllWidgets = widgets_1.deleteAllWidgets.bind(this);
    /** @borrows addWidgetBlock as this.addWidgetBlock */
    addWidgetBlock = widgets_1.addWidgetBlock.bind(this);
    /** @borrows deleteAllTemplates as this.deleteAllTemplates */
    deleteAllTemplates = templates_1.deleteAllTemplates.bind(this);
    /** @borrows createTemplate as this.createTemplate */
    createTemplate = templates_1.createTemplate.bind(this);
    /** @borrows resetPreferences as this.resetPreferences */
    resetPreferences = preferences_1.resetPreferences.bind(this);
    /** @borrows listMedia as this.listMedia */
    listMedia = media_1.listMedia.bind(this);
    /** @borrows uploadMedia as this.uploadMedia */
    uploadMedia = media_1.uploadMedia.bind(this);
    /** @borrows deleteMedia as this.deleteMedia */
    deleteMedia = media_1.deleteMedia.bind(this);
    /** @borrows deleteAllMedia as this.deleteAllMedia */
    deleteAllMedia = media_1.deleteAllMedia.bind(this);
    /** @borrows createUser as this.createUser */
    createUser = users_1.createUser.bind(this);
    /** @borrows deleteAllUsers as this.deleteAllUsers */
    deleteAllUsers = users_1.deleteAllUsers.bind(this);
    /** @borrows getSiteSettings as this.getSiteSettings */
    getSiteSettings = site_settings_1.getSiteSettings.bind(this);
    /** @borrows updateSiteSettings as this.updateSiteSettings */
    updateSiteSettings = site_settings_1.updateSiteSettings.bind(this);
    /** @borrows deleteAllPages as this.deleteAllPages */
    deleteAllPages = pages_1.deleteAllPages.bind(this);
    /** @borrows createPage as this.createPage */
    createPage = pages_1.createPage.bind(this);
    /** @borrows getCurrentThemeGlobalStylesPostId as this.getCurrentThemeGlobalStylesPostId */
    getCurrentThemeGlobalStylesPostId = themes_1.getCurrentThemeGlobalStylesPostId.bind(this);
    /** @borrows getThemeGlobalStylesRevisions as this.getThemeGlobalStylesRevisions */
    getThemeGlobalStylesRevisions = themes_1.getThemeGlobalStylesRevisions.bind(this);
    /** @borrows deleteAllPatternCategories as this.deleteAllPatternCategories */
    deleteAllPatternCategories = patterns_1.deleteAllPatternCategories.bind(this);
    /** @borrows setGutenbergExperiments as this.setGutenbergExperiments */
    setGutenbergExperiments = gutenberg_experiments_1.setGutenbergExperiments.bind(this);
}
exports.RequestUtils = RequestUtils;
//# sourceMappingURL=index.js.map