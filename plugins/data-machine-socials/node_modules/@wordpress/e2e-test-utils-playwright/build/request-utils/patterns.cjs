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

// packages/e2e-test-utils-playwright/src/request-utils/patterns.ts
var patterns_exports = {};
__export(patterns_exports, {
  deleteAllPatternCategories: () => deleteAllPatternCategories
});
module.exports = __toCommonJS(patterns_exports);
async function deleteAllPatternCategories() {
  const categories = await this.rest({
    path: "/wp/v2/wp_pattern_category",
    params: {
      per_page: 100
    }
  });
  await this.batchRest(
    categories.map((category) => ({
      method: "DELETE",
      path: `/wp/v2/wp_pattern_category/${category.id}?force=true`
    }))
  );
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  deleteAllPatternCategories
});
//# sourceMappingURL=patterns.cjs.map
