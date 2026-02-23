/** @implements {LH.Gatherer.Driver} */
export class Driver implements LH.Gatherer.Driver {
    /**
     * @param {LH.Puppeteer.Page} page
     */
    constructor(page: LH.Puppeteer.Page);
    _page: import("../index.js").Puppeteer.Page;
    /** @type {TargetManager|undefined} */
    _targetManager: TargetManager | undefined;
    /** @type {NetworkMonitor|undefined} */
    _networkMonitor: NetworkMonitor | undefined;
    /** @type {ExecutionContext|undefined} */
    _executionContext: ExecutionContext | undefined;
    /** @type {Fetcher|undefined} */
    _fetcher: Fetcher | undefined;
    defaultSession: import("../../types/gatherer.js").default.ProtocolSession;
    /** @return {LH.Gatherer.Driver['executionContext']} */
    get executionContext(): LH.Gatherer.Driver["executionContext"];
    get fetcher(): any;
    get targetManager(): any;
    get networkMonitor(): any;
    /** @return {Promise<string>} */
    url(): Promise<string>;
    /** @return {Promise<void>} */
    connect(): Promise<void>;
    /** @return {Promise<void>} */
    disconnect(): Promise<void>;
}
import { TargetManager } from './driver/target-manager.js';
import { NetworkMonitor } from './driver/network-monitor.js';
import { ExecutionContext } from './driver/execution-context.js';
import { Fetcher } from './fetcher.js';
//# sourceMappingURL=driver.d.ts.map