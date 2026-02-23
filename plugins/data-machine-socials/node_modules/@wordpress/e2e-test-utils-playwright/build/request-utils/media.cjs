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

// packages/e2e-test-utils-playwright/src/request-utils/media.ts
var media_exports = {};
__export(media_exports, {
  deleteAllMedia: () => deleteAllMedia,
  deleteMedia: () => deleteMedia,
  listMedia: () => listMedia,
  uploadMedia: () => uploadMedia
});
module.exports = __toCommonJS(media_exports);
var fs = __toESM(require("fs"));
async function listMedia() {
  const response = await this.rest({
    method: "GET",
    path: "/wp/v2/media",
    params: {
      per_page: 100
    }
  });
  return response;
}
async function uploadMedia(filePathOrData) {
  const file = typeof filePathOrData === "string" ? fs.createReadStream(filePathOrData) : filePathOrData;
  const response = await this.rest({
    method: "POST",
    path: "/wp/v2/media",
    multipart: {
      file
    }
  });
  return response;
}
async function deleteMedia(mediaId) {
  const response = await this.rest({
    method: "DELETE",
    path: `/wp/v2/media/${mediaId}`,
    params: { force: true }
  });
  return response;
}
async function deleteAllMedia() {
  const files = await this.listMedia();
  const responses = await Promise.all(
    files.map((media) => this.deleteMedia(media.id))
  );
  return responses;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  deleteAllMedia,
  deleteMedia,
  listMedia,
  uploadMedia
});
//# sourceMappingURL=media.cjs.map
