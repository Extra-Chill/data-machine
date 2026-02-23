"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createClassicMenu = createClassicMenu;
exports.createNavigationMenu = createNavigationMenu;
exports.deleteAllMenus = deleteAllMenus;
exports.getNavigationMenus = getNavigationMenus;
/**
 * Create a classic menu
 *
 * @param name Menu name.
 * @return Menu content.
 */
async function createClassicMenu(name) {
    const menuItems = [
        {
            title: 'Custom link',
            url: 'http://localhost:8889/',
            type: 'custom',
            menu_order: 1,
        },
    ];
    const menu = await this.rest({
        method: 'POST',
        path: `/wp/v2/menus/`,
        data: {
            name,
        },
    });
    await this.batchRest(menuItems.map((menuItem) => ({
        method: 'POST',
        path: `/wp/v2/menu-items`,
        body: {
            menus: menu.id,
            object_id: undefined,
            ...menuItem,
            parent: undefined,
        },
    })));
    return menu;
}
/**
 * Create a navigation menu
 *
 * @param menuData navigation menu post data.
 * @return Menu content.
 */
async function createNavigationMenu(menuData) {
    return this.rest({
        method: 'POST',
        path: `/wp/v2/navigation/`,
        data: {
            status: 'publish',
            ...menuData,
        },
    });
}
/**
 * Delete all navigation and classic menus
 *
 */
async function deleteAllMenus() {
    const navMenus = await this.rest({
        path: `/wp/v2/navigation/`,
        data: {
            status: [
                'publish',
                'pending',
                'draft',
                'auto-draft',
                'future',
                'private',
                'inherit',
                'trash',
            ],
        },
    });
    if (navMenus.length) {
        await this.batchRest(navMenus.map((menu) => ({
            method: 'DELETE',
            path: `/wp/v2/navigation/${menu.id}?force=true`,
        })));
    }
    const classicMenus = await this.rest({
        path: `/wp/v2/menus/`,
        data: {
            status: [
                'publish',
                'pending',
                'draft',
                'auto-draft',
                'future',
                'private',
                'inherit',
                'trash',
            ],
        },
    });
    if (classicMenus.length) {
        await this.batchRest(classicMenus.map((menu) => ({
            method: 'DELETE',
            path: `/wp/v2/menus/${menu.id}?force=true`,
        })));
    }
}
/**
 * Get latest navigation menus
 *
 * @param  args
 * @param  args.status
 * @return {string} Menu content.
 */
async function getNavigationMenus(args) {
    const navigationMenus = await this.rest({
        method: 'GET',
        path: `/wp/v2/navigation/`,
        data: args,
    });
    return navigationMenus;
}
//# sourceMappingURL=menus.js.map