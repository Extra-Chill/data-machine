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

// packages/e2e-test-utils-playwright/src/index.ts
var index_exports = {};
__export(index_exports, {
  Admin: () => import_admin.Admin,
  Editor: () => import_editor.Editor,
  Lighthouse: () => import_lighthouse.Lighthouse,
  Metrics: () => import_metrics.Metrics,
  PageUtils: () => import_page_utils.PageUtils,
  RequestUtils: () => import_request_utils.RequestUtils,
  expect: () => import_test.expect,
  test: () => import_test.test
});
module.exports = __toCommonJS(index_exports);
var import_admin = require("./admin/index.cjs");
var import_editor = require("./editor/index.cjs");
var import_page_utils = require("./page-utils/index.cjs");
var import_request_utils = require("./request-utils/index.cjs");
var import_metrics = require("./metrics/index.cjs");
var import_lighthouse = require("./lighthouse/index.cjs");
var import_test = require("./test.cjs");
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  Admin,
  Editor,
  Lighthouse,
  Metrics,
  PageUtils,
  RequestUtils,
  expect,
  test
});
//# sourceMappingURL=index.cjs.map
