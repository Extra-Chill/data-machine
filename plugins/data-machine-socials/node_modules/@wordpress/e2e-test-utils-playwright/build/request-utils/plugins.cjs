"use strict";
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
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
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// packages/e2e-test-utils-playwright/src/request-utils/plugins.ts
var plugins_exports = {};
__export(plugins_exports, {
  activatePlugin: () => activatePlugin,
  deactivatePlugin: () => deactivatePlugin,
  getPluginsMap: () => getPluginsMap
});
module.exports = __toCommonJS(plugins_exports);
var import_change_case = require("change-case");
async function getPluginsMap(forceRefetch = false) {
  if (!forceRefetch && this.pluginsMap) {
    return this.pluginsMap;
  }
  const plugins = await this.rest({
    path: "/wp/v2/plugins"
  });
  this.pluginsMap = {};
  for (const plugin of plugins) {
    const slug = (0, import_change_case.paramCase)(plugin.name.toLowerCase());
    this.pluginsMap[slug] = plugin.plugin;
  }
  return this.pluginsMap;
}
function getPluginFromMap(slug, pluginsMap) {
  const plugin = pluginsMap[slug];
  if (!plugin) {
    for (const key of Object.keys(pluginsMap)) {
      if (key.toLowerCase().replace(/-/g, "") === slug.toLowerCase().replace(/-/g, "")) {
        throw new Error(
          `The plugin "${slug}" isn't installed. Did you perhaps mean "${key}"?`
        );
      }
    }
    throw new Error(`The plugin "${slug}" isn't installed`);
  }
  return plugin;
}
async function activatePlugin(slug) {
  const pluginsMap = await this.getPluginsMap();
  const plugin = getPluginFromMap(slug, pluginsMap);
  await this.rest({
    method: "PUT",
    path: `/wp/v2/plugins/${plugin}`,
    data: { status: "active" }
  });
}
async function deactivatePlugin(slug) {
  const pluginsMap = await this.getPluginsMap();
  const plugin = getPluginFromMap(slug, pluginsMap);
  await this.rest({
    method: "PUT",
    path: `/wp/v2/plugins/${plugin}`,
    data: { status: "inactive" }
  });
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  activatePlugin,
  deactivatePlugin,
  getPluginsMap
});
//# sourceMappingURL=plugins.cjs.map
