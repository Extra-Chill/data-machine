/**
 * Internal dependencies
 */
import type { PageUtils } from './';
interface NetworkConditions {
    /**
     * True to emulate internet disconnection.
     */
    offline: boolean;
    /**
     * Minimum latency from request sent to response headers received (ms).
     */
    latency: number;
    /**
     * Maximal aggregated download throughput (bytes/sec). -1 disables download throttling.
     */
    downloadThroughput: number;
    /**
     * Maximal aggregated upload throughput (bytes/sec).  -1 disables upload throttling.
     */
    uploadThroughput: number;
}
declare const PredefinedNetworkConditions: Record<string, NetworkConditions>;
export declare function emulateNetworkConditions(this: PageUtils, condition: keyof typeof PredefinedNetworkConditions | NetworkConditions): Promise<void>;
export {};
//# sourceMappingURL=emulate-network-conditions.d.ts.map