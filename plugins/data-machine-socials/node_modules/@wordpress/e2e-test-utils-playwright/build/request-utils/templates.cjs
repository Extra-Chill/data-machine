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

// packages/e2e-test-utils-playwright/src/request-utils/templates.ts
var templates_exports = {};
__export(templates_exports, {
  createTemplate: () => createTemplate,
  deleteAllTemplates: () => deleteAllTemplates
});
module.exports = __toCommonJS(templates_exports);
var PATH_MAPPING = {
  wp_template: "/wp/v2/templates",
  wp_template_part: "/wp/v2/template-parts"
};
async function deleteAllTemplates(type) {
  const path = PATH_MAPPING[type];
  if (!path) {
    throw new Error(`Unsupported template type: ${type}.`);
  }
  const templates = await this.rest({ path });
  for (const template of templates) {
    if (!template?.id || !template?.wp_id) {
      continue;
    }
    try {
      await this.rest({
        method: "DELETE",
        path: `${path}/${template.id}`,
        params: { force: true }
      });
    } catch (responseError) {
      console.warn(
        `deleteAllTemplates failed to delete template (id: ${template.wp_id}) with the following error`,
        responseError
      );
    }
  }
}
async function createTemplate(type, payload) {
  const template = await this.rest({
    method: "POST",
    path: PATH_MAPPING[type],
    params: { ...payload, type, status: "publish", is_wp_suggestion: true }
  });
  return template;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  createTemplate,
  deleteAllTemplates
});
//# sourceMappingURL=templates.cjs.map
