export namespace Sentry {
    export { init };
    export { noop as captureMessage };
    export { noop as captureBreadcrumb };
    export { noop as getContext };
    export let captureException: (error: Error, options: {
        level?: string;
        tags?: {
            [key: string]: any;
        };
        extra?: {
            [key: string]: any;
        };
    }) => Promise<void>;
    export function _shouldSample(): boolean;
}
export type Breadcrumb = import("@sentry/node").Breadcrumb;
export type NodeClient = import("@sentry/node").NodeClient;
export type NodeOptions = import("@sentry/node").NodeOptions;
export type SeverityLevel = import("@sentry/node").SeverityLevel;
/**
 * When called, replaces noops with actual Sentry implementation.
 * @param {{url: string, flags: LH.CliFlags, config?: LH.Config, environmentData: NodeOptions}} opts
 */
declare function init(opts: {
    url: string;
    flags: LH.CliFlags;
    config?: LH.Config;
    environmentData: NodeOptions;
}): Promise<void>;
declare function noop(): void;
export {};
//# sourceMappingURL=sentry.d.ts.map