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

// packages/element/src/react-platform.ts
var react_platform_exports = {};
__export(react_platform_exports, {
  createPortal: () => import_react_dom.createPortal,
  createRoot: () => import_client.createRoot,
  findDOMNode: () => import_react_dom.findDOMNode,
  flushSync: () => import_react_dom.flushSync,
  hydrate: () => import_react_dom.hydrate,
  hydrateRoot: () => import_client.hydrateRoot,
  render: () => import_react_dom.render,
  unmountComponentAtNode: () => import_react_dom.unmountComponentAtNode
});
module.exports = __toCommonJS(react_platform_exports);
var import_react_dom = require("react-dom");
var import_client = require("react-dom/client");
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  createPortal,
  createRoot,
  findDOMNode,
  flushSync,
  hydrate,
  hydrateRoot,
  render,
  unmountComponentAtNode
});
//# sourceMappingURL=react-platform.cjs.map
