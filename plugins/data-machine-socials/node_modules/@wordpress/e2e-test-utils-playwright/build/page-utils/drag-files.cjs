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

// packages/e2e-test-utils-playwright/src/page-utils/drag-files.ts
var drag_files_exports = {};
__export(drag_files_exports, {
  dragFiles: () => dragFiles
});
module.exports = __toCommonJS(drag_files_exports);
var import_promises = require("fs/promises");
var import_path = require("path");
var import_mime = require("mime");
async function dragFiles(files) {
  const filesList = Array.isArray(files) ? files : [files];
  const fileObjects = await Promise.all(
    filesList.map(async (filePathOrObject) => {
      if (typeof filePathOrObject !== "string") {
        return {
          name: filePathOrObject.name,
          mimeType: filePathOrObject.mimeType || (0, import_mime.getType)(filePathOrObject.name),
          base64: filePathOrObject.buffer.toString("base64")
        };
      }
      const base64 = await (0, import_promises.readFile)(filePathOrObject, "base64");
      const name = (0, import_path.basename)(filePathOrObject);
      return {
        name,
        mimeType: (0, import_mime.getType)(filePathOrObject),
        base64
      };
    })
  );
  const dragData = {
    items: fileObjects.map((fileObject) => ({
      mimeType: fileObject.mimeType ?? "File",
      data: fileObject.base64
    })),
    files: fileObjects.map((fileObject) => fileObject.name),
    // Copy = 1, Link = 2, Move = 16.
    dragOperationsMask: 1
  };
  const cdpSession = await this.context.newCDPSession(this.page);
  const position = {
    x: 0,
    y: 0
  };
  return {
    /**
     * Drag the files over an element (fires `dragenter` and `dragover` events).
     *
     * @param selectorOrLocator A selector or a locator to search for an element.
     * @param options           The optional options.
     * @param options.position  A point to use relative to the top-left corner of element padding box. If not specified, uses some visible point of the element.
     */
    dragOver: async (selectorOrLocator, options = {}) => {
      const locator = typeof selectorOrLocator === "string" ? this.page.locator(selectorOrLocator) : selectorOrLocator;
      const boundingBox = await locator.boundingBox();
      if (!boundingBox) {
        throw new Error(
          "Cannot find the element or the element is not visible on the viewport."
        );
      }
      position.x = boundingBox.x + (options.position?.x ?? boundingBox.width / 2);
      position.y = boundingBox.y + (options.position?.y ?? boundingBox.height / 2);
      await cdpSession.send("Input.dispatchDragEvent", {
        type: "dragEnter",
        ...position,
        data: dragData
      });
      await cdpSession.send("Input.dispatchDragEvent", {
        type: "dragOver",
        ...position,
        data: dragData
      });
    },
    /**
     * Drop the files at the current position.
     */
    drop: async () => {
      const topMostElement = await this.page.evaluateHandle(
        ({ x, y }) => {
          const element = document.elementFromPoint(x, y);
          if (element instanceof HTMLIFrameElement) {
            const offsetBox = element.getBoundingClientRect();
            return element.contentDocument.elementFromPoint(
              x - offsetBox.x,
              y - offsetBox.y
            );
          }
          return element;
        },
        position
      );
      const elementHandle = topMostElement.asElement();
      if (!elementHandle) {
        throw new Error("Element not found.");
      }
      const dataTransfer = await elementHandle.evaluateHandle(
        async (_node, _fileObjects) => {
          const dt = new DataTransfer();
          const fileInstances = await Promise.all(
            _fileObjects.map(async (fileObject) => {
              const blob = await fetch(
                `data:${fileObject.mimeType};base64,${fileObject.base64}`
              ).then((res) => res.blob());
              return new File([blob], fileObject.name, {
                type: fileObject.mimeType ?? void 0
              });
            })
          );
          fileInstances.forEach((file) => {
            dt.items.add(file);
          });
          return dt;
        },
        fileObjects
      );
      await elementHandle.dispatchEvent("drop", { dataTransfer });
      await cdpSession.detach();
    }
  };
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  dragFiles
});
//# sourceMappingURL=drag-files.cjs.map
