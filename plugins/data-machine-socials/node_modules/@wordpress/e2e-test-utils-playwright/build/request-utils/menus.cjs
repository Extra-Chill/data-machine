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

// packages/e2e-test-utils-playwright/src/request-utils/menus.ts
var menus_exports = {};
__export(menus_exports, {
  createClassicMenu: () => createClassicMenu,
  createNavigationMenu: () => createNavigationMenu,
  deleteAllMenus: () => deleteAllMenus,
  getNavigationMenus: () => getNavigationMenus
});
module.exports = __toCommonJS(menus_exports);
async function createClassicMenu(name) {
  const menuItems = [
    {
      title: "Custom link",
      url: "http://localhost:8889/",
      type: "custom",
      menu_order: 1
    }
  ];
  const menu = await this.rest({
    method: "POST",
    path: `/wp/v2/menus/`,
    data: {
      name
    }
  });
  await this.batchRest(
    menuItems.map((menuItem) => ({
      method: "POST",
      path: `/wp/v2/menu-items`,
      body: {
        menus: menu.id,
        object_id: void 0,
        ...menuItem,
        parent: void 0
      }
    }))
  );
  return menu;
}
async function createNavigationMenu(menuData) {
  return this.rest({
    method: "POST",
    path: `/wp/v2/navigation/`,
    data: {
      status: "publish",
      ...menuData
    }
  });
}
async function deleteAllMenus() {
  const navMenus = await this.rest({
    path: `/wp/v2/navigation/`,
    data: {
      status: [
        "publish",
        "pending",
        "draft",
        "auto-draft",
        "future",
        "private",
        "inherit",
        "trash"
      ]
    }
  });
  if (navMenus.length) {
    await this.batchRest(
      navMenus.map((menu) => ({
        method: "DELETE",
        path: `/wp/v2/navigation/${menu.id}?force=true`
      }))
    );
  }
  const classicMenus = await this.rest({
    path: `/wp/v2/menus/`,
    data: {
      status: [
        "publish",
        "pending",
        "draft",
        "auto-draft",
        "future",
        "private",
        "inherit",
        "trash"
      ]
    }
  });
  if (classicMenus.length) {
    await this.batchRest(
      classicMenus.map((menu) => ({
        method: "DELETE",
        path: `/wp/v2/menus/${menu.id}?force=true`
      }))
    );
  }
}
async function getNavigationMenus(args) {
  const navigationMenus = await this.rest({
    method: "GET",
    path: `/wp/v2/navigation/`,
    data: args
  });
  return navigationMenus;
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  createClassicMenu,
  createNavigationMenu,
  deleteAllMenus,
  getNavigationMenus
});
//# sourceMappingURL=menus.cjs.map
