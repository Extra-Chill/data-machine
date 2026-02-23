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

// packages/e2e-test-utils-playwright/src/request-utils/widgets.js
var widgets_exports = {};
__export(widgets_exports, {
  addWidgetBlock: () => addWidgetBlock,
  deleteAllWidgets: () => deleteAllWidgets
});
module.exports = __toCommonJS(widgets_exports);
async function deleteAllWidgets() {
  const [widgets, sidebars] = await Promise.all([
    this.rest({ path: "/wp/v2/widgets" }),
    this.rest({ path: "/wp/v2/sidebars" })
  ]);
  await this.batchRest(
    widgets.map((widget) => ({
      method: "DELETE",
      path: `/wp/v2/widgets/${widget.id}?force=true`
    }))
  );
  await Promise.all(
    sidebars.map(
      (sidebar) => this.rest({
        method: "POST",
        path: `/wp/v2/sidebars/${sidebar.id}`,
        data: { id: sidebar.id, widgets: [] }
      })
    )
  );
}
async function addWidgetBlock(serializedBlock, widgetAreaId) {
  const { id: blockId } = await this.rest({
    method: "POST",
    path: "/wp/v2/widgets",
    data: {
      id_base: "block",
      sidebar: widgetAreaId,
      instance: {
        raw: { content: serializedBlock }
      }
    }
  });
  const { widgets } = await this.rest({
    path: `/wp/v2/sidebars/${widgetAreaId}`
  });
  const updatedWidgets = new Set(widgets);
  updatedWidgets.delete(blockId);
  updatedWidgets.add(blockId);
  await this.rest({
    method: "PUT",
    path: `/wp/v2/sidebars/${widgetAreaId}`,
    data: {
      widgets: [...updatedWidgets]
    }
  });
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  addWidgetBlock,
  deleteAllWidgets
});
//# sourceMappingURL=widgets.cjs.map
