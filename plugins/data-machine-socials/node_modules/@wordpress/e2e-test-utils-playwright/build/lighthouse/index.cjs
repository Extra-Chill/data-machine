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

// packages/e2e-test-utils-playwright/src/lighthouse/index.ts
var lighthouse_exports = {};
__export(lighthouse_exports, {
  Lighthouse: () => Lighthouse
});
module.exports = __toCommonJS(lighthouse_exports);
var import_core = __toESM(require("lighthouse/core/index.cjs"));
var Lighthouse = class {
  page;
  port;
  constructor({ page, port }) {
    this.page = page;
    this.port = port;
  }
  /**
   * Returns the Lighthouse report for the current URL.
   *
   * Runs several Lighthouse audits in a separate browser window and returns
   * the summary.
   */
  async getReport() {
    const audits = {
      "largest-contentful-paint": "LCP",
      "total-blocking-time": "TBT",
      interactive: "TTI",
      "cumulative-layout-shift": "CLS",
      "interaction-to-next-paint": "INP"
    };
    const report = await (0, import_core.default)(
      this.page.url(),
      { port: this.port },
      {
        extends: "lighthouse:default",
        settings: {
          // "provided" means no throttling.
          // TODO: Make configurable.
          throttlingMethod: "provided",
          // Default is "mobile".
          // See https://github.com/GoogleChrome/lighthouse/blob/main/docs/emulation.md
          // TODO: Make configurable.
          formFactor: "desktop",
          screenEmulation: {
            disabled: true
          },
          // Speeds up the report.
          disableFullPageScreenshot: true,
          // Only run certain audits to speed things up.
          onlyAudits: Object.keys(audits)
        }
      }
    );
    const result = {};
    if (!report) {
      return result;
    }
    const { lhr } = report;
    for (const [audit, acronym] of Object.entries(audits)) {
      result[acronym] = lhr.audits[audit]?.numericValue || 0;
    }
    return result;
  }
};
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  Lighthouse
});
//# sourceMappingURL=index.cjs.map
