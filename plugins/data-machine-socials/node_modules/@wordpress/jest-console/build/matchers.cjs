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

// packages/jest-console/src/matchers.ts
var import_jest_matcher_utils = require("jest-matcher-utils");
var import_supported_matchers = __toESM(require("./supported-matchers.cjs"));
var createErrorMessage = (spyInfo) => {
  const { spy, pass, calls, matcherName, methodName, expected } = spyInfo;
  const hint = pass ? `.not${matcherName}` : matcherName;
  const message = pass ? `Expected mock function not to be called but it was called with:
${calls.map(
    import_jest_matcher_utils.printReceived
  )}` : `Expected mock function to be called${expected ? ` with:
${(0, import_jest_matcher_utils.printExpected)(expected)}
` : "."}
but it was called with:
${calls.map(import_jest_matcher_utils.printReceived)}`;
  return () => `${(0, import_jest_matcher_utils.matcherHint)(hint, spy.getMockName())}

` + message + `

console.${methodName}() should not be used unless explicitly expected
See https://www.npmjs.com/package/@wordpress/jest-console for details.`;
};
var createSpyInfo = (spy, matcherName, methodName, expected) => {
  const calls = spy.mock.calls;
  const pass = expected ? JSON.stringify(calls).includes(JSON.stringify(expected)) : calls.length > 0;
  const message = createErrorMessage({
    spy,
    pass,
    calls,
    matcherName,
    methodName,
    expected
  });
  return {
    pass,
    message
  };
};
var createToHaveBeenCalledMatcher = (matcherName, methodName) => (received) => {
  const spy = received[methodName];
  const spyInfo = createSpyInfo(spy, matcherName, methodName);
  spy.assertionsNumber += 1;
  return spyInfo;
};
var createToHaveBeenCalledWith = (matcherName, methodName) => function(received, ...expected) {
  const spy = received[methodName];
  const spyInfo = createSpyInfo(spy, matcherName, methodName, expected);
  spy.assertionsNumber += 1;
  return spyInfo;
};
expect.extend(
  Object.entries(import_supported_matchers.default).reduce(
    (result, [methodName, matcherName]) => {
      const matcherNameWith = `${matcherName}With`;
      return {
        ...result,
        [matcherName]: createToHaveBeenCalledMatcher(
          `.${matcherName}`,
          methodName
        ),
        [matcherNameWith]: createToHaveBeenCalledWith(
          `.${matcherNameWith}`,
          methodName
        )
      };
    },
    {}
  )
);
//# sourceMappingURL=matchers.cjs.map
