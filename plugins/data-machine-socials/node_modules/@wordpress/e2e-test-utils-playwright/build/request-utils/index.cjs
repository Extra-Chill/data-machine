"use strict";
var __create = Object.create;
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __getProtoOf = Object.getPrototypeOf;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toESM = (mod, isNodeMode, target) => (target = mod != null ? __create(__getProtoOf(mod)) : {}, __copyProps(
  // If the importer is in node compatibility mode or this is not an ESM
  // file that has been converted to a CommonJS file using a Babel-
  // compatible transform (i.e. "__esModule" has not been set), then set
  // "default" to the CommonJS "module.exports" for node compatibility.
  isNodeMode || !mod || !mod.__esModule ? __defProp(target, "default", { value: mod, enumerable: true }) : target,
  mod
));
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// packages/e2e-test-utils-playwright/src/request-utils/index.ts
var request_utils_exports = {};
__export(request_utils_exports, {
  RequestUtils: () => RequestUtils
});
module.exports = __toCommonJS(request_utils_exports);
var fs = __toESM(require("fs/promises"));
var path = __toESM(require("path"));
var import_test = require("@playwright/test");
var import_config = require("../config.cjs");
var import_login = require("./login.cjs");
var import_media = require("./media.cjs");
var import_users = require("./users.cjs");
var import_rest = require("./rest.cjs");
var import_plugins = require("./plugins.cjs");
var import_templates = require("./templates.cjs");
var import_themes = require("./themes.cjs");
var import_blocks = require("./blocks.cjs");
var import_comments = require("./comments.cjs");
var import_posts = require("./posts.cjs");
var import_menus = require("./menus.cjs");
var import_pages = require("./pages.cjs");
var import_preferences = require("./preferences.cjs");
var import_site_settings = require("./site-settings.cjs");
var import_widgets = require("./widgets.cjs");
var import_patterns = require("./patterns.cjs");
var import_gutenberg_experiments = require("./gutenberg-experiments.cjs");
var RequestUtils = class {
  request;
  user;
  maxBatchSize;
  storageState;
  storageStatePath;
  baseURL;
  pluginsMap = null;
  static async setup({
    user,
    storageStatePath,
    baseURL = import_config.WP_BASE_URL
  }) {
    let storageState;
    if (storageStatePath) {
      await fs.mkdir(path.dirname(storageStatePath), {
        recursive: true
      });
      try {
        storageState = JSON.parse(
          await fs.readFile(storageStatePath, "utf-8")
        );
      } catch (error) {
        if (error instanceof Error && error.code === "ENOENT") {
        } else {
          throw error;
        }
      }
    }
    const requestContext = await import_test.request.newContext({
      baseURL,
      storageState: storageState && {
        cookies: storageState.cookies,
        origins: []
      }
    });
    const requestUtils = new this(requestContext, {
      user,
      storageState,
      storageStatePath,
      baseURL
    });
    return requestUtils;
  }
  constructor(requestContext, {
    user = import_config.WP_ADMIN_USER,
    storageState,
    storageStatePath,
    baseURL = import_config.WP_BASE_URL
  } = {}) {
    this.user = user;
    this.request = requestContext;
    this.storageStatePath = storageStatePath;
    this.storageState = storageState;
    this.baseURL = baseURL;
  }
  /** @borrows login as this.login */
  login = import_login.login.bind(this);
  /** @borrows setupRest as this.setupRest */
  setupRest = import_rest.setupRest.bind(this);
  // .bind() drops the generic types. Re-casting it to keep the type signature.
  rest = import_rest.rest.bind(this);
  /** @borrows getMaxBatchSize as this.getMaxBatchSize */
  getMaxBatchSize = import_rest.getMaxBatchSize.bind(this);
  // .bind() drops the generic types. Re-casting it to keep the type signature.
  batchRest = import_rest.batchRest.bind(this);
  /** @borrows getPluginsMap as this.getPluginsMap */
  getPluginsMap = import_plugins.getPluginsMap.bind(this);
  /** @borrows activatePlugin as this.activatePlugin */
  activatePlugin = import_plugins.activatePlugin.bind(this);
  /** @borrows deactivatePlugin as this.deactivatePlugin */
  deactivatePlugin = import_plugins.deactivatePlugin.bind(this);
  /** @borrows activateTheme as this.activateTheme */
  activateTheme = import_themes.activateTheme.bind(this);
  /** @borrows createBlock as this.createBlock */
  createBlock = import_blocks.createBlock.bind(this);
  /** @borrows deleteAllBlocks as this.deleteAllBlocks */
  deleteAllBlocks = import_blocks.deleteAllBlocks.bind(this);
  /** @borrows createPost as this.createPost */
  createPost = import_posts.createPost.bind(this);
  /** @borrows deleteAllPosts as this.deleteAllPosts */
  deleteAllPosts = import_posts.deleteAllPosts.bind(this);
  /** @borrows createClassicMenu as this.createClassicMenu */
  createClassicMenu = import_menus.createClassicMenu.bind(this);
  /** @borrows createNavigationMenu as this.createNavigationMenu */
  createNavigationMenu = import_menus.createNavigationMenu.bind(this);
  /** @borrows deleteAllMenus as this.deleteAllMenus */
  deleteAllMenus = import_menus.deleteAllMenus.bind(this);
  /** @borrows getNavigationMenus as this.getNavigationMenus */
  getNavigationMenus = import_menus.getNavigationMenus.bind(this);
  /** @borrows createComment as this.createComment */
  createComment = import_comments.createComment.bind(this);
  /** @borrows deleteAllComments as this.deleteAllComments */
  deleteAllComments = import_comments.deleteAllComments.bind(this);
  /** @borrows deleteAllWidgets as this.deleteAllWidgets */
  deleteAllWidgets = import_widgets.deleteAllWidgets.bind(this);
  /** @borrows addWidgetBlock as this.addWidgetBlock */
  addWidgetBlock = import_widgets.addWidgetBlock.bind(this);
  /** @borrows deleteAllTemplates as this.deleteAllTemplates */
  deleteAllTemplates = import_templates.deleteAllTemplates.bind(this);
  /** @borrows createTemplate as this.createTemplate */
  createTemplate = import_templates.createTemplate.bind(this);
  /** @borrows resetPreferences as this.resetPreferences */
  resetPreferences = import_preferences.resetPreferences.bind(this);
  /** @borrows listMedia as this.listMedia */
  listMedia = import_media.listMedia.bind(this);
  /** @borrows uploadMedia as this.uploadMedia */
  uploadMedia = import_media.uploadMedia.bind(this);
  /** @borrows deleteMedia as this.deleteMedia */
  deleteMedia = import_media.deleteMedia.bind(this);
  /** @borrows deleteAllMedia as this.deleteAllMedia */
  deleteAllMedia = import_media.deleteAllMedia.bind(this);
  /** @borrows createUser as this.createUser */
  createUser = import_users.createUser.bind(this);
  /** @borrows deleteAllUsers as this.deleteAllUsers */
  deleteAllUsers = import_users.deleteAllUsers.bind(this);
  /** @borrows getSiteSettings as this.getSiteSettings */
  getSiteSettings = import_site_settings.getSiteSettings.bind(this);
  /** @borrows updateSiteSettings as this.updateSiteSettings */
  updateSiteSettings = import_site_settings.updateSiteSettings.bind(this);
  /** @borrows deleteAllPages as this.deleteAllPages */
  deleteAllPages = import_pages.deleteAllPages.bind(this);
  /** @borrows createPage as this.createPage */
  createPage = import_pages.createPage.bind(this);
  /** @borrows getCurrentThemeGlobalStylesPostId as this.getCurrentThemeGlobalStylesPostId */
  getCurrentThemeGlobalStylesPostId = import_themes.getCurrentThemeGlobalStylesPostId.bind(this);
  /** @borrows getThemeGlobalStylesRevisions as this.getThemeGlobalStylesRevisions */
  getThemeGlobalStylesRevisions = import_themes.getThemeGlobalStylesRevisions.bind(this);
  /** @borrows deleteAllPatternCategories as this.deleteAllPatternCategories */
  deleteAllPatternCategories = import_patterns.deleteAllPatternCategories.bind(this);
  /** @borrows setGutenbergExperiments as this.setGutenbergExperiments */
  setGutenbergExperiments = import_gutenberg_experiments.setGutenbergExperiments.bind(this);
};
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  RequestUtils
});
//# sourceMappingURL=index.cjs.map
