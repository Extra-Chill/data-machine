import type { Item, Menu } from './types';
export declare const useCreateNavigationTree: () => {
    items: Record<string, Omit<Item, "children">>;
    getItem: (key: string) => Omit<Item, "children">;
    addItem: (key: string, value: Item) => void;
    removeItem: (key: string) => void;
    menus: Record<string, Omit<Menu, "children">>;
    getMenu: (key: string) => Omit<Menu, "children">;
    addMenu: (key: string, value: Menu) => void;
    removeMenu: (key: string) => void;
    childMenu: Record<string, string[]>;
    traverseMenu: (startMenu: string, callback: (menuObject: Menu) => boolean | undefined) => void;
    isMenuEmpty: (menuToCheck: string) => boolean;
};
//# sourceMappingURL=use-create-navigation-tree.d.ts.map