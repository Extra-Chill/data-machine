/**
 * External dependencies
 */
import type { CSSProperties } from 'react';
/**
 * Internal dependencies
 */
import './color-ramps/lib/register-color-spaces';
import type { ThemeProviderProps } from './types';
export declare function useThemeProviderStyles({ color, }?: {
    color?: ThemeProviderProps['color'];
}): {
    resolvedSettings: {
        color: {
            primary: string;
            bg: string;
        };
    };
    themeProviderStyles: CSSProperties;
};
//# sourceMappingURL=use-theme-provider-styles.d.ts.map