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

// packages/jest-console/src/supported-matchers.ts
var supported_matchers_exports = {};
__export(supported_matchers_exports, {
  default: () => supported_matchers_default
});
module.exports = __toCommonJS(supported_matchers_exports);
var supportedMatchers = {
  error: "toHaveErrored",
  info: "toHaveInformed",
  log: "toHaveLogged",
  warn: "toHaveWarned"
};
var supported_matchers_default = supportedMatchers;
//# sourceMappingURL=supported-matchers.cjs.map
