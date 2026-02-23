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

// packages/e2e-test-utils-playwright/src/test.ts
var test_exports = {};
__export(test_exports, {
  expect: () => import_test.expect,
  test: () => test
});
module.exports = __toCommonJS(test_exports);
var path = __toESM(require("path"));
var import_test = require("@playwright/test");
var import_get_port = __toESM(require("get-port"));
var import_index = require("./index.cjs");
var STORAGE_STATE_PATH = process.env.STORAGE_STATE_PATH || path.join(process.cwd(), "artifacts/storage-states/admin.json");
var OBSERVED_CONSOLE_MESSAGE_TYPES = ["warn", "error"];
function observeConsoleLogging(message) {
  const type = message.type();
  if (!OBSERVED_CONSOLE_MESSAGE_TYPES.includes(
    type
  )) {
    return;
  }
  const text = message.text();
  if (text.includes("This is a global warning")) {
    return;
  }
  if (text.includes("A cookie associated with a cross-site resource") || text.includes(
    "https://developer.mozilla.org/docs/Web/HTTP/Headers/Set-Cookie/SameSite"
  )) {
    return;
  }
  if (text.includes("net::ERR_UNKNOWN_URL_SCHEME")) {
    return;
  }
  if (text.includes("elements with non-unique id #_wpnonce")) {
    return;
  }
  if (text.includes("JQMIGRATE")) {
    return;
  }
  if (text.includes("Layout was forced before the page was fully loaded")) {
    return;
  }
  if (text.includes("MouseEvent.moz")) {
    return;
  }
  const logFunction = type;
  console[logFunction](text);
}
var test = import_test.test.extend({
  admin: async ({ page, pageUtils, editor }, use) => {
    await use(new import_index.Admin({ page, pageUtils, editor }));
  },
  editor: async ({ page }, use) => {
    await use(new import_index.Editor({ page }));
  },
  page: async ({ page }, use) => {
    page.on("console", observeConsoleLogging);
    await use(page);
    try {
      await page.evaluate(() => {
        window.localStorage.clear();
      });
    } catch (error) {
    }
    await page.close();
  },
  pageUtils: async ({ page, browserName }, use) => {
    await use(new import_index.PageUtils({ page, browserName }));
  },
  requestUtils: [
    async ({}, use, workerInfo) => {
      const requestUtils = await import_index.RequestUtils.setup({
        baseURL: workerInfo.project.use.baseURL,
        storageStatePath: STORAGE_STATE_PATH
      });
      await use(requestUtils);
    },
    { scope: "worker", auto: true }
  ],
  // Spins up a new browser for use by the Lighthouse fixture
  // so that Lighthouse can connect to the debugging port.
  // As a worker-scoped fixture, this will only launch 1
  // instance for the whole test worker, so multiple tests
  // will share the same instance with the same port.
  lighthousePort: [
    async ({}, use) => {
      const port = await (0, import_get_port.default)();
      const browser = await import_test.chromium.launch({
        args: [`--remote-debugging-port=${port}`]
      });
      await use(port);
      await browser.close();
    },
    { scope: "worker" }
  ],
  lighthouse: async ({ page, lighthousePort }, use) => {
    await use(new import_index.Lighthouse({ page, port: lighthousePort }));
  },
  metrics: async ({ page }, use) => {
    await use(new import_index.Metrics({ page }));
  }
});
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  expect,
  test
});
//# sourceMappingURL=test.cjs.map
