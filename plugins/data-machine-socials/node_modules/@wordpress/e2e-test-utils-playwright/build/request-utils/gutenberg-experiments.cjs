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

// packages/e2e-test-utils-playwright/src/request-utils/gutenberg-experiments.ts
var gutenberg_experiments_exports = {};
__export(gutenberg_experiments_exports, {
  setGutenbergExperiments: () => setGutenbergExperiments
});
module.exports = __toCommonJS(gutenberg_experiments_exports);
async function setGutenbergExperiments(experiments) {
  const response = await this.request.get(
    "/wp-admin/admin.php?page=gutenberg-experiments"
  );
  const html = await response.text();
  const nonce = html.match(/name="_wpnonce" value="([^"]+)"/)[1];
  const formData = {
    option_page: "gutenberg-experiments",
    action: "update",
    _wpnonce: nonce,
    _wp_http_referer: "/wp-admin/admin.php?page=gutenberg-experiments",
    submit: "Save Changes"
  };
  const regularExperiments = experiments.filter(
    (exp) => exp !== "active_templates"
  );
  const hasActiveTemplates = experiments.includes("active_templates");
  if (regularExperiments.length > 0) {
    Object.assign(
      formData,
      Object.fromEntries(
        regularExperiments.map((experiment) => [
          `gutenberg-experiments[${experiment}]`,
          1
        ])
      )
    );
  }
  if (hasActiveTemplates) {
    formData.active_templates = 1;
  }
  await this.request.post("/wp-admin/options.php", {
    form: formData,
    failOnStatusCode: true
  });
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  setGutenbergExperiments
});
//# sourceMappingURL=gutenberg-experiments.cjs.map
