export type LighthouseErrorDefinition = {
    code: string;
    message: string;
    pattern?: RegExp | undefined;
    /**
     * True if it should appear in the top-level LHR.runtimeError property.
     */
    lhrRuntimeError?: boolean | undefined;
};
export type SerializedLighthouseError = {
    sentinel: "__LighthouseErrorSentinel";
    code: string;
    stack?: string;
    cause?: unknown;
    properties?: {
        [p: string]: string | undefined;
    };
};
export type SerializedBaseError = {
    sentinel: "__ErrorSentinel";
    message: string;
    code?: string;
    stack?: string;
    cause?: unknown;
};
/**
 * The {@link ErrorOptions} type wasn't added until es2022 (Node 16), so we recreate it here to support ts targets before es2022.
 * TODO: Just use `ErrorOptions` if we can't support targets before es2022 in the docs test.
 */
export type LHErrorOptions = {
    cause: unknown;
};
/**
 * @typedef {{sentinel: '__LighthouseErrorSentinel', code: string, stack?: string, cause?: unknown, properties?: {[p: string]: string|undefined}}} SerializedLighthouseError
 * @typedef {{sentinel: '__ErrorSentinel', message: string, code?: string, stack?: string, cause?: unknown}} SerializedBaseError
 */
/**
 * The {@link ErrorOptions} type wasn't added until es2022 (Node 16), so we recreate it here to support ts targets before es2022.
 * TODO: Just use `ErrorOptions` if we can't support targets before es2022 in the docs test.
 * @typedef {{cause: unknown}} LHErrorOptions
 */
export class LighthouseError extends Error {
    /**
     * @param {string} method
     * @param {{message: string, data?: string|undefined}} protocolError
     * @return {Error|LighthouseError}
     */
    static fromProtocolMessage(method: string, protocolError: {
        message: string;
        data?: string | undefined;
    }): Error | LighthouseError;
    /**
     * A JSON.stringify replacer to serialize LighthouseErrors and (as a fallback) Errors.
     * Returns a simplified version of the error object that can be reconstituted
     * as a copy of the original error at parse time.
     * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/JSON/stringify#The_replacer_parameter
     * @param {Error|LighthouseError} err
     * @return {SerializedBaseError|SerializedLighthouseError}
     */
    static stringifyReplacer(err: Error | LighthouseError): SerializedBaseError | SerializedLighthouseError;
    /**
     * A JSON.parse reviver. If any value passed in is a serialized Error or
     * LighthouseError, the error is recreated as the original object. Otherwise, the
     * value is passed through unchanged.
     * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/JSON/parse#Using_the_reviver_parameter
     * @param {string} key
     * @param {any} possibleError
     * @return {any}
     */
    static parseReviver(key: string, possibleError: any): any;
    /**
     * @param {LighthouseErrorDefinition} errorDefinition
     * @param {Record<string, string|undefined>=} properties
     * @param {LHErrorOptions=} options
     */
    constructor(errorDefinition: LighthouseErrorDefinition, properties?: Record<string, string | undefined> | undefined, options?: LHErrorOptions | undefined);
    code: string;
    friendlyMessage: import("../index.js").IcuMessage;
    lhrRuntimeError: boolean;
}
export namespace LighthouseError {
    export { ERRORS as errors };
    export let NO_ERROR: string;
    export let UNKNOWN_ERROR: string;
}
export namespace UIStrings {
    let didntCollectScreenshots: string;
    let badTraceRecording: string;
    let noFcp: string;
    let noLcp: string;
    let pageLoadTookTooLong: string;
    let pageLoadFailed: string;
    let pageLoadFailedWithStatusCode: string;
    let pageLoadFailedWithDetails: string;
    let pageLoadFailedInsecure: string;
    let pageLoadFailedInterstitial: string;
    let internalChromeError: string;
    let requestContentTimeout: string;
    let notHtml: string;
    let urlInvalid: string;
    let protocolTimeout: string;
    let dnsFailure: string;
    let pageLoadFailedHung: string;
    let criTimeout: string;
    let missingRequiredArtifact: string;
    let erroredRequiredArtifact: string;
    let oldChromeDoesNotSupportFeature: string;
    let targetCrashed: string;
}
declare namespace ERRORS {
    namespace NO_SPEEDLINE_FRAMES {
        export let code: string;
        import message = UIStrings.didntCollectScreenshots;
        export { message };
        export let lhrRuntimeError: boolean;
    }
    namespace SPEEDINDEX_OF_ZERO {
        let code_1: string;
        export { code_1 as code };
        import message_1 = UIStrings.didntCollectScreenshots;
        export { message_1 as message };
        let lhrRuntimeError_1: boolean;
        export { lhrRuntimeError_1 as lhrRuntimeError };
    }
    namespace NO_SCREENSHOTS {
        let code_2: string;
        export { code_2 as code };
        import message_2 = UIStrings.didntCollectScreenshots;
        export { message_2 as message };
        let lhrRuntimeError_2: boolean;
        export { lhrRuntimeError_2 as lhrRuntimeError };
    }
    namespace INVALID_SPEEDLINE {
        let code_3: string;
        export { code_3 as code };
        import message_3 = UIStrings.didntCollectScreenshots;
        export { message_3 as message };
        let lhrRuntimeError_3: boolean;
        export { lhrRuntimeError_3 as lhrRuntimeError };
    }
    namespace NO_TRACING_STARTED {
        let code_4: string;
        export { code_4 as code };
        import message_4 = UIStrings.badTraceRecording;
        export { message_4 as message };
        let lhrRuntimeError_4: boolean;
        export { lhrRuntimeError_4 as lhrRuntimeError };
    }
    namespace NO_RESOURCE_REQUEST {
        let code_5: string;
        export { code_5 as code };
        import message_5 = UIStrings.badTraceRecording;
        export { message_5 as message };
        let lhrRuntimeError_5: boolean;
        export { lhrRuntimeError_5 as lhrRuntimeError };
    }
    namespace NO_NAVSTART {
        let code_6: string;
        export { code_6 as code };
        import message_6 = UIStrings.badTraceRecording;
        export { message_6 as message };
        let lhrRuntimeError_6: boolean;
        export { lhrRuntimeError_6 as lhrRuntimeError };
    }
    namespace NO_FCP {
        let code_7: string;
        export { code_7 as code };
        import message_7 = UIStrings.noFcp;
        export { message_7 as message };
        let lhrRuntimeError_7: boolean;
        export { lhrRuntimeError_7 as lhrRuntimeError };
    }
    namespace NO_DCL {
        let code_8: string;
        export { code_8 as code };
        import message_8 = UIStrings.badTraceRecording;
        export { message_8 as message };
        let lhrRuntimeError_8: boolean;
        export { lhrRuntimeError_8 as lhrRuntimeError };
    }
    namespace NO_FMP {
        let code_9: string;
        export { code_9 as code };
        import message_9 = UIStrings.badTraceRecording;
        export { message_9 as message };
    }
    namespace NO_LCP {
        let code_10: string;
        export { code_10 as code };
        import message_10 = UIStrings.noLcp;
        export { message_10 as message };
    }
    namespace NO_LCP_ALL_FRAMES {
        let code_11: string;
        export { code_11 as code };
        import message_11 = UIStrings.noLcp;
        export { message_11 as message };
    }
    namespace UNSUPPORTED_OLD_CHROME {
        let code_12: string;
        export { code_12 as code };
        import message_12 = UIStrings.oldChromeDoesNotSupportFeature;
        export { message_12 as message };
    }
    namespace NO_TTI_CPU_IDLE_PERIOD {
        let code_13: string;
        export { code_13 as code };
        import message_13 = UIStrings.pageLoadTookTooLong;
        export { message_13 as message };
    }
    namespace NO_TTI_NETWORK_IDLE_PERIOD {
        let code_14: string;
        export { code_14 as code };
        import message_14 = UIStrings.pageLoadTookTooLong;
        export { message_14 as message };
    }
    namespace NO_DOCUMENT_REQUEST {
        let code_15: string;
        export { code_15 as code };
        import message_15 = UIStrings.pageLoadFailed;
        export { message_15 as message };
        let lhrRuntimeError_9: boolean;
        export { lhrRuntimeError_9 as lhrRuntimeError };
    }
    namespace FAILED_DOCUMENT_REQUEST {
        let code_16: string;
        export { code_16 as code };
        import message_16 = UIStrings.pageLoadFailedWithDetails;
        export { message_16 as message };
        let lhrRuntimeError_10: boolean;
        export { lhrRuntimeError_10 as lhrRuntimeError };
    }
    namespace ERRORED_DOCUMENT_REQUEST {
        let code_17: string;
        export { code_17 as code };
        import message_17 = UIStrings.pageLoadFailedWithStatusCode;
        export { message_17 as message };
        let lhrRuntimeError_11: boolean;
        export { lhrRuntimeError_11 as lhrRuntimeError };
    }
    namespace INSECURE_DOCUMENT_REQUEST {
        let code_18: string;
        export { code_18 as code };
        import message_18 = UIStrings.pageLoadFailedInsecure;
        export { message_18 as message };
        let lhrRuntimeError_12: boolean;
        export { lhrRuntimeError_12 as lhrRuntimeError };
    }
    namespace CHROME_INTERSTITIAL_ERROR {
        let code_19: string;
        export { code_19 as code };
        import message_19 = UIStrings.pageLoadFailedInterstitial;
        export { message_19 as message };
        let lhrRuntimeError_13: boolean;
        export { lhrRuntimeError_13 as lhrRuntimeError };
    }
    namespace PAGE_HUNG {
        let code_20: string;
        export { code_20 as code };
        import message_20 = UIStrings.pageLoadFailedHung;
        export { message_20 as message };
        let lhrRuntimeError_14: boolean;
        export { lhrRuntimeError_14 as lhrRuntimeError };
    }
    namespace NOT_HTML {
        let code_21: string;
        export { code_21 as code };
        import message_21 = UIStrings.notHtml;
        export { message_21 as message };
        let lhrRuntimeError_15: boolean;
        export { lhrRuntimeError_15 as lhrRuntimeError };
    }
    namespace TRACING_ALREADY_STARTED {
        let code_22: string;
        export { code_22 as code };
        import message_22 = UIStrings.internalChromeError;
        export { message_22 as message };
        export let pattern: RegExp;
        let lhrRuntimeError_16: boolean;
        export { lhrRuntimeError_16 as lhrRuntimeError };
    }
    namespace PARSING_PROBLEM {
        let code_23: string;
        export { code_23 as code };
        import message_23 = UIStrings.internalChromeError;
        export { message_23 as message };
        let pattern_1: RegExp;
        export { pattern_1 as pattern };
        let lhrRuntimeError_17: boolean;
        export { lhrRuntimeError_17 as lhrRuntimeError };
    }
    namespace READ_FAILED {
        let code_24: string;
        export { code_24 as code };
        import message_24 = UIStrings.internalChromeError;
        export { message_24 as message };
        let pattern_2: RegExp;
        export { pattern_2 as pattern };
        let lhrRuntimeError_18: boolean;
        export { lhrRuntimeError_18 as lhrRuntimeError };
    }
    namespace INVALID_URL {
        let code_25: string;
        export { code_25 as code };
        import message_25 = UIStrings.urlInvalid;
        export { message_25 as message };
    }
    namespace PROTOCOL_TIMEOUT {
        let code_26: string;
        export { code_26 as code };
        import message_26 = UIStrings.protocolTimeout;
        export { message_26 as message };
        let lhrRuntimeError_19: boolean;
        export { lhrRuntimeError_19 as lhrRuntimeError };
    }
    namespace DNS_FAILURE {
        let code_27: string;
        export { code_27 as code };
        import message_27 = UIStrings.dnsFailure;
        export { message_27 as message };
        let lhrRuntimeError_20: boolean;
        export { lhrRuntimeError_20 as lhrRuntimeError };
    }
    namespace CRI_TIMEOUT {
        let code_28: string;
        export { code_28 as code };
        import message_28 = UIStrings.criTimeout;
        export { message_28 as message };
        let lhrRuntimeError_21: boolean;
        export { lhrRuntimeError_21 as lhrRuntimeError };
    }
    namespace MISSING_REQUIRED_ARTIFACT {
        let code_29: string;
        export { code_29 as code };
        import message_29 = UIStrings.missingRequiredArtifact;
        export { message_29 as message };
    }
    namespace ERRORED_REQUIRED_ARTIFACT {
        let code_30: string;
        export { code_30 as code };
        import message_30 = UIStrings.erroredRequiredArtifact;
        export { message_30 as message };
    }
    namespace TARGET_CRASHED {
        let code_31: string;
        export { code_31 as code };
        import message_31 = UIStrings.targetCrashed;
        export { message_31 as message };
        let lhrRuntimeError_22: boolean;
        export { lhrRuntimeError_22 as lhrRuntimeError };
    }
}
export {};
//# sourceMappingURL=lh-error.d.ts.map