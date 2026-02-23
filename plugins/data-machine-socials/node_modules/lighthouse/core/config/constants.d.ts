export const throttling: {
    DEVTOOLS_RTT_ADJUSTMENT_FACTOR: number;
    DEVTOOLS_THROUGHPUT_ADJUSTMENT_FACTOR: number;
    mobileSlow4G: {
        rttMs: number;
        throughputKbps: number;
        requestLatencyMs: number;
        downloadThroughputKbps: number;
        uploadThroughputKbps: number;
        cpuSlowdownMultiplier: number;
    };
    mobileRegular3G: {
        rttMs: number;
        throughputKbps: number;
        requestLatencyMs: number;
        downloadThroughputKbps: number;
        uploadThroughputKbps: number;
        cpuSlowdownMultiplier: number;
    };
    desktopDense4G: {
        rttMs: number;
        throughputKbps: number;
        cpuSlowdownMultiplier: number;
        requestLatencyMs: number;
        downloadThroughputKbps: number;
        uploadThroughputKbps: number;
    };
};
export namespace screenEmulationMetrics {
    export { MOTOGPOWER_EMULATION_METRICS as mobile };
    export { DESKTOP_EMULATION_METRICS as desktop };
}
export namespace userAgents {
    export { MOTOG4_USERAGENT as mobile };
    export { DESKTOP_USERAGENT as desktop };
}
/** @type {LH.Config.Settings} */
export const defaultSettings: LH.Config.Settings;
export namespace nonSimulatedSettingsOverrides {
    let pauseAfterFcpMs: number;
    let pauseAfterLoadMs: number;
    let networkQuietThresholdMs: number;
    let cpuQuietThresholdMs: number;
}
/**
 * @type {Required<LH.SharedFlagsSettings['screenEmulation']>}
 */
declare const MOTOGPOWER_EMULATION_METRICS: Required<LH.SharedFlagsSettings["screenEmulation"]>;
/**
 * Desktop metrics adapted from emulated_devices/module.json
 * @type {Required<LH.SharedFlagsSettings['screenEmulation']>}
 */
declare const DESKTOP_EMULATION_METRICS: Required<LH.SharedFlagsSettings["screenEmulation"]>;
declare const MOTOG4_USERAGENT: "Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Mobile Safari/537.36";
declare const DESKTOP_USERAGENT: "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36";
export {};
//# sourceMappingURL=constants.d.ts.map