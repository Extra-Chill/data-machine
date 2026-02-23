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

// packages/e2e-test-utils-playwright/src/page-utils/press-keys.ts
var press_keys_exports = {};
__export(press_keys_exports, {
  pressKeys: () => pressKeys,
  setClipboardData: () => setClipboardData
});
module.exports = __toCommonJS(press_keys_exports);
var import_change_case = require("change-case");
var import_keycodes = require("./keycodes.cjs");
var clipboardDataHolder = {
  "text/plain": "",
  "text/html": "",
  "rich-text": ""
};
function setClipboardData({ plainText = "", html = "" }) {
  clipboardDataHolder = {
    "text/plain": plainText,
    "text/html": html,
    "rich-text": ""
  };
}
async function emulateClipboard(page, type) {
  const output = await page.evaluate(
    ([_type, _clipboardData]) => {
      const canvasDoc = (
        // @ts-ignore
        document.activeElement?.contentDocument ?? document
      );
      const event = new ClipboardEvent(_type, {
        bubbles: true,
        cancelable: true,
        clipboardData: new DataTransfer()
      });
      if (!event.clipboardData) {
        throw new Error("ClipboardEvent.clipboardData is null");
      }
      if (_type === "paste") {
        event.clipboardData.setData(
          "text/plain",
          _clipboardData["text/plain"]
        );
        event.clipboardData.setData(
          "text/html",
          _clipboardData["text/html"]
        );
        event.clipboardData.setData(
          "rich-text",
          _clipboardData["rich-text"]
        );
      } else {
        const selection = canvasDoc.defaultView.getSelection();
        const plainText = selection.toString();
        let html = plainText;
        if (selection.rangeCount) {
          const range = selection.getRangeAt(0);
          const fragment = range.cloneContents();
          html = Array.from(fragment.childNodes).map(
            (node) => node.outerHTML ?? node.nodeValue
          ).join("");
        }
        event.clipboardData.setData("text/plain", plainText);
        event.clipboardData.setData("text/html", html);
      }
      canvasDoc.activeElement.dispatchEvent(event);
      if (_type === "paste") {
        return event.defaultPrevented;
      }
      return {
        "text/plain": event.clipboardData.getData("text/plain"),
        "text/html": event.clipboardData.getData("text/html"),
        "rich-text": event.clipboardData.getData("rich-text")
      };
    },
    [type, clipboardDataHolder]
  );
  if (typeof output === "object") {
    clipboardDataHolder = output;
  }
  if (output === false) {
    await page.keyboard.type(clipboardDataHolder["text/plain"]);
  }
}
var isAppleOS = () => process.platform === "darwin";
var isWebkit = (page) => page.context().browser().browserType().name() === "webkit";
var browserCache = /* @__PURE__ */ new WeakMap();
var getHasNaturalTabNavigation = async (page) => {
  if (!isAppleOS() || !isWebkit(page)) {
    return true;
  }
  if (browserCache.has(page.context().browser())) {
    return browserCache.get(page.context().browser());
  }
  const testPage = await page.context().newPage();
  await testPage.setContent(`<button>1</button><button>2</button>`);
  await testPage.getByText("1").focus();
  await testPage.keyboard.press("Tab");
  const featureDetected = await testPage.getByText("2").evaluate((node) => node === document.activeElement);
  browserCache.set(page.context().browser(), featureDetected);
  await testPage.close();
  return featureDetected;
};
var modifiers = {
  ...import_keycodes.modifiers,
  shiftAlt: (_isApple) => _isApple() ? [import_keycodes.SHIFT, import_keycodes.ALT] : [import_keycodes.SHIFT, import_keycodes.CTRL]
};
async function pressKeys(key, { times, ...pressOptions } = {}) {
  const hasNaturalTabNavigation = await getHasNaturalTabNavigation(
    this.page
  );
  let command;
  if (key.toLowerCase() === "primary+c") {
    command = () => emulateClipboard(this.page, "copy");
  } else if (key.toLowerCase() === "primary+x") {
    command = () => emulateClipboard(this.page, "cut");
  } else if (key.toLowerCase() === "primary+v") {
    command = () => emulateClipboard(this.page, "paste");
  } else {
    const keys = key.split("+").flatMap((keyCode) => {
      if (Object.prototype.hasOwnProperty.call(modifiers, keyCode)) {
        return modifiers[keyCode](
          isAppleOS
        ).map(
          (modifier) => modifier === import_keycodes.CTRL ? "Control" : (0, import_change_case.capitalCase)(modifier)
        );
      } else if (keyCode === "Tab" && !hasNaturalTabNavigation) {
        return ["Alt", "Tab"];
      }
      return keyCode;
    });
    const normalizedKeys = keys.join("+");
    command = () => this.page.keyboard.press(normalizedKeys);
  }
  times = times ?? 1;
  for (let i = 0; i < times; i += 1) {
    await command();
    if (times > 1 && pressOptions.delay) {
      await this.page.waitForTimeout(pressOptions.delay);
    }
  }
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  pressKeys,
  setClipboardData
});
//# sourceMappingURL=press-keys.cjs.map
