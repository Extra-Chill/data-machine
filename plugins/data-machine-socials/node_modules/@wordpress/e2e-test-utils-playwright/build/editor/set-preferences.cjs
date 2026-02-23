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

// packages/e2e-test-utils-playwright/src/editor/set-preferences.ts
var set_preferences_exports = {};
__export(set_preferences_exports, {
  setPreferences: () => setPreferences
});
module.exports = __toCommonJS(set_preferences_exports);
async function setPreferences(context, preferences) {
  await this.page.waitForFunction(() => window?.wp?.data);
  await this.page.evaluate(
    async (props) => {
      for (const [key, value] of Object.entries(
        props.preferences
      )) {
        await window.wp.data.dispatch("core/preferences").set(props.context, key, value);
      }
    },
    { context, preferences }
  );
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  setPreferences
});
//# sourceMappingURL=set-preferences.cjs.map
