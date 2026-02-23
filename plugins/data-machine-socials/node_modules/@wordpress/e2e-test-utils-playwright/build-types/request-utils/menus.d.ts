/**
 * Internal dependencies
 */
import type { RequestUtils } from './index';
export interface MenuData {
    title: string;
    content: string;
}
export interface NavigationMenu {
    id: number;
    content: string;
    status: 'publish' | 'future' | 'draft' | 'pending' | 'private';
}
/**
 * Create a classic menu
 *
 * @param name Menu name.
 * @return Menu content.
 */
export declare function createClassicMenu(this: RequestUtils, name: string): Promise<NavigationMenu>;
/**
 * Create a navigation menu
 *
 * @param menuData navigation menu post data.
 * @return Menu content.
 */
export declare function createNavigationMenu(this: RequestUtils, menuData: MenuData): Promise<any>;
/**
 * Delete all navigation and classic menus
 *
 */
export declare function deleteAllMenus(this: RequestUtils): Promise<void>;
/**
 * Get latest navigation menus
 *
 * @param  args
 * @param  args.status
 * @return {string} Menu content.
 */
export declare function getNavigationMenus(this: RequestUtils, args: {
    status: 'publish';
}): Promise<NavigationMenu[]>;
//# sourceMappingURL=menus.d.ts.map