import type { Ref } from 'react';
import type { Axis, Position } from './utils';
import { useResizeLabel } from './utils';
import { Root } from './styles/resize-tooltip.styles';
type ResizeTooltipProps = React.ComponentProps<typeof Root> & {
    'aria-hidden'?: boolean;
    axis?: Axis;
    className?: string;
    fadeTimeout?: number;
    isVisible?: boolean;
    labelRef?: Ref<HTMLDivElement>;
    onResize?: Parameters<typeof useResizeLabel>[0]['onResize'];
    position?: Position;
    showPx?: boolean;
    zIndex?: number;
};
declare const ForwardedComponent: import("react").ForwardRefExoticComponent<Omit<ResizeTooltipProps, "ref"> & import("react").RefAttributes<HTMLDivElement>>;
export default ForwardedComponent;
//# sourceMappingURL=index.d.ts.map