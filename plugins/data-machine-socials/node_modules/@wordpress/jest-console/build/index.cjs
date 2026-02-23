"use strict";
var __create = Object.create;
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __getProtoOf = Object.getPrototypeOf;
var __hasOwnProp = Object.prototype.hasOwnProperty;
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

// packages/jest-console/src/index.ts
var import_matchers = require("./matchers.cjs");
var import_supported_matchers = __toESM(require("./supported-matchers.cjs"));
var setConsoleMethodSpy = (args) => {
  const [methodName, matcherName] = args;
  const spy = jest.spyOn(console, methodName).mockName(`console.${methodName}`);
  function resetSpy() {
    spy.mockReset();
    spy.assertionsNumber = 0;
  }
  function assertExpectedCalls() {
    if (spy.assertionsNumber === 0 && spy.mock.calls.length > 0) {
      const name = matcherName;
      expect(console).not[name]();
    }
  }
  beforeAll(resetSpy);
  beforeEach(() => {
    assertExpectedCalls();
    resetSpy();
  });
  afterEach(assertExpectedCalls);
};
Object.entries(import_supported_matchers.default).forEach(setConsoleMethodSpy);
//# sourceMappingURL=index.cjs.map
