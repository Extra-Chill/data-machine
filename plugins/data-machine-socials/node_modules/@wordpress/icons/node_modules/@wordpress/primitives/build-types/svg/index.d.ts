export function Circle(props: React.ComponentPropsWithoutRef<"circle">): React.JSX.Element;
export function G(props: React.ComponentPropsWithoutRef<"g">): React.JSX.Element;
export function Line(props: React.ComponentPropsWithoutRef<"line">): React.JSX.Element;
export function Path(props: React.ComponentPropsWithoutRef<"path">): React.JSX.Element;
export function Polygon(props: React.ComponentPropsWithoutRef<"polygon">): React.JSX.Element;
export function Rect(props: React.ComponentPropsWithoutRef<"rect">): React.JSX.Element;
export function Defs(props: React.ComponentPropsWithoutRef<"defs">): React.JSX.Element;
export function RadialGradient(props: React.ComponentPropsWithoutRef<"radialGradient">): React.JSX.Element;
export function LinearGradient(props: React.ComponentPropsWithoutRef<"linearGradient">): React.JSX.Element;
export function Stop(props: React.ComponentPropsWithoutRef<"stop">): React.JSX.Element;
export const SVG: import("react").ForwardRefExoticComponent<{
    isPressed?: boolean;
} & Omit<import("react").SVGProps<SVGSVGElement>, "ref"> & import("react").RefAttributes<SVGSVGElement>>;
export type SVGProps = {
    isPressed?: boolean;
} & React.ComponentPropsWithoutRef<"svg">;
//# sourceMappingURL=index.d.ts.map