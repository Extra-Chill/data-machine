export type CrdpEventMessageEmitter = LH.Protocol.StrictEventEmitterClass<LH.CrdpEvents>;
declare const ProtocolSession_base: CrdpEventMessageEmitter;
/** @implements {LH.Gatherer.ProtocolSession} */
export class ProtocolSession extends ProtocolSession_base implements LH.Gatherer.ProtocolSession {
    /**
     * @param {LH.Puppeteer.CDPSession} cdpSession
     */
    constructor(cdpSession: LH.Puppeteer.CDPSession);
    _cdpSession: import("../index.js").Puppeteer.CDPSession;
    /** @type {LH.Crdp.Target.TargetInfo|undefined} */
    _targetInfo: LH.Crdp.Target.TargetInfo | undefined;
    /** @type {number|undefined} */
    _nextProtocolTimeout: number | undefined;
    /**
     * Re-emit protocol events from the underlying CDPSession.
     * @template {keyof LH.CrdpEvents} E
     * @param {E} method
     * @param {LH.CrdpEvents[E]} params
     */
    _handleProtocolEvent<E extends keyof LH.CrdpEvents>(method: E, ...params: LH.CrdpEvents[E]): void;
    _targetCrashedPromise: Promise<never>;
    id(): string;
    /** @param {LH.Crdp.Target.TargetInfo} targetInfo */
    setTargetInfo(targetInfo: LH.Crdp.Target.TargetInfo): void;
    /**
     * @return {boolean}
     */
    hasNextProtocolTimeout(): boolean;
    /**
     * @return {number}
     */
    getNextProtocolTimeout(): number;
    /**
     * @param {number} ms
     */
    setNextProtocolTimeout(ms: number): void;
    /**
     * @template {keyof LH.CrdpCommands} C
     * @param {C} method
     * @param {LH.CrdpCommands[C]['paramsType']} params
     * @return {Promise<LH.CrdpCommands[C]['returnType']>}
     */
    sendCommand<C extends keyof LH.CrdpCommands>(method: C, ...params: LH.CrdpCommands[C]["paramsType"]): Promise<LH.CrdpCommands[C]["returnType"]>;
    /**
     * Send and if there's an error response, do not reject.
     * @template {keyof LH.CrdpCommands} C
     * @param {C} method
     * @param {LH.CrdpCommands[C]['paramsType']} params
     * @return {Promise<void>}
     */
    sendCommandAndIgnore<C extends keyof LH.CrdpCommands>(method: C, ...params: LH.CrdpCommands[C]["paramsType"]): Promise<void>;
    /**
     * Disposes of a session so that it can no longer talk to Chrome.
     * @return {Promise<void>}
     */
    dispose(): Promise<void>;
    onCrashPromise(): Promise<never>;
}
export {};
//# sourceMappingURL=session.d.ts.map