import type { SurfaceVariant, SurfaceProps } from './types';
export declare const Surface: import("@emotion/utils").SerializedStyles;
export declare const background: import("@emotion/utils").SerializedStyles;
export declare function getBorders({ borderBottom, borderLeft, borderRight, borderTop, }: Pick<SurfaceProps, 'borderBottom' | 'borderLeft' | 'borderRight' | 'borderTop'>): import("@emotion/utils").SerializedStyles;
export declare const primary: import("@emotion/utils").SerializedStyles;
export declare const secondary: import("@emotion/utils").SerializedStyles;
export declare const tertiary: import("@emotion/utils").SerializedStyles;
export declare const getDotted: (surfaceBackgroundSize: string, surfaceBackgroundSizeDotted: string) => import("@emotion/utils").SerializedStyles;
export declare const getGrid: (surfaceBackgroundSize: string) => import("@emotion/utils").SerializedStyles;
export declare const getVariant: (variant: SurfaceVariant, surfaceBackgroundSize: string, surfaceBackgroundSizeDotted: string) => import("@emotion/utils").SerializedStyles;
//# sourceMappingURL=styles.d.ts.map