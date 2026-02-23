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

// packages/e2e-test-utils-playwright/src/request-utils/rest.ts
var rest_exports = {};
__export(rest_exports, {
  batchRest: () => batchRest,
  getMaxBatchSize: () => getMaxBatchSize,
  rest: () => rest,
  setupRest: () => setupRest
});
module.exports = __toCommonJS(rest_exports);
var fs = __toESM(require("fs/promises"));
var import_path = require("path");
var import_test = require("@playwright/test");
var import_config = require("../config.cjs");
function splitRequestsToChunks(requests, chunkSize) {
  const arr = [...requests];
  const cache = [];
  while (arr.length) {
    cache.push(arr.splice(0, chunkSize));
  }
  return cache;
}
async function getAPIRootURL(request) {
  const response = await request.head(import_config.WP_BASE_URL);
  const links = response.headers().link;
  const restLink = links?.match(/<([^>]+)>; rel="https:\/\/api\.w\.org\/"/);
  if (!restLink) {
    throw new Error(`Failed to discover REST API endpoint.
 Link header: ${links}`);
  }
  const [, rootURL] = restLink;
  return rootURL;
}
async function setupRest() {
  let nonce = "";
  let rootURL = "";
  await import_test.expect.poll(
    async () => {
      try {
        [nonce, rootURL] = await Promise.all([
          this.login(),
          getAPIRootURL(this.request)
        ]);
      } catch (error) {
        return error;
      }
      return !!(nonce && rootURL);
    },
    {
      message: "Failed to setup REST API.",
      timeout: 6e4
      // 1 minute.
    }
  ).toBe(true);
  const { cookies } = await this.request.storageState();
  const storageState = {
    cookies,
    nonce,
    rootURL
  };
  if (this.storageStatePath) {
    await fs.mkdir((0, import_path.dirname)(this.storageStatePath), { recursive: true });
    await fs.writeFile(
      this.storageStatePath,
      JSON.stringify(storageState),
      "utf-8"
    );
  }
  this.storageState = storageState;
  return storageState;
}
async function rest(options) {
  const { path, ...fetchOptions } = options;
  if (!path) {
    throw new Error('"path" is required to make a REST call');
  }
  if (!this.storageState?.nonce || !this.storageState?.rootURL) {
    await this.setupRest();
  }
  const relativePath = path.startsWith("/") ? path.slice(1) : path;
  const url = this.storageState.rootURL + relativePath;
  try {
    const response = await this.request.fetch(url, {
      ...fetchOptions,
      failOnStatusCode: false,
      headers: {
        "X-WP-Nonce": this.storageState.nonce,
        ...fetchOptions.headers || {}
      }
    });
    const json = await response.json();
    if (!response.ok()) {
      throw json;
    }
    return json;
  } catch (error) {
    if (typeof error === "object" && error !== null && Object.prototype.hasOwnProperty.call(error, "code") && error.code === "rest_cookie_invalid_nonce") {
      await this.setupRest();
      return this.rest(options);
    }
    throw error;
  }
}
async function getMaxBatchSize(forceRefetch = false) {
  if (!forceRefetch && this.maxBatchSize) {
    return this.maxBatchSize;
  }
  const response = await this.rest({
    method: "OPTIONS",
    path: "/batch/v1"
  });
  this.maxBatchSize = response.endpoints[0].args.requests.maxItems;
  return this.maxBatchSize;
}
async function batchRest(requests) {
  const maxBatchSize = await this.getMaxBatchSize();
  if (requests.length > maxBatchSize) {
    const chunks = splitRequestsToChunks(requests, maxBatchSize);
    const chunkResponses = await Promise.all(
      chunks.map(
        (chunkRequests) => this.batchRest(chunkRequests)
      )
    );
    return chunkResponses.flat();
  }
  const batchResponses = await this.rest({
    method: "POST",
    path: "/batch/v1",
    data: {
      requests,
      validation: "require-all-validate"
    }
  });
  if (batchResponses.failed) {
    throw batchResponses;
  }
  return batchResponses.responses;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  batchRest,
  getMaxBatchSize,
  rest,
  setupRest
});
//# sourceMappingURL=rest.cjs.map
