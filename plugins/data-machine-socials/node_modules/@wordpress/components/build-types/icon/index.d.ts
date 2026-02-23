/**
 * External dependencies
 */
import type { ComponentType, SVGProps } from 'react';
import type { IconKey as DashiconIconKey } from '../dashicon/types';
export type IconType = DashiconIconKey | ComponentType<{
    size?: number;
}> | ((props: {
    size?: number;
}) => JSX.Element) | JSX.Element;
interface BaseProps {
    /**
     * The icon to render. Supported values are: Dashicons (specified as
     * strings), functions, Component instances and `null`.
     *
     * @default null
     */
    icon?: IconType | null;
    /**
     * The size (width and height) of the icon.
     *
     * @default `20` when a Dashicon is rendered, `24` for all other icons.
     */
    size?: number;
}
type AdditionalProps<T> = T extends ComponentType<infer U> ? U : T extends DashiconIconKey ? SVGProps<SVGSVGElement> : {};
export type Props = BaseProps & AdditionalProps<IconType>;
declare function Icon({ icon, size, ...additionalProps }: Props): import("react").JSX.Element | null;
export default Icon;
//# sourceMappingURL=index.d.ts.map