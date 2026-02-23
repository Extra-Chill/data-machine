export default ThirdPartyCookies;
declare class ThirdPartyCookies extends Audit {
    /**
     * https://source.chromium.org/chromium/chromium/src/+/d2fcd4ba302baeabf4b96d8fa9fdb7a215736c31:third_party/devtools-frontend/src/front_end/models/issues_manager/CookieIssue.ts;l=62-69
     * @param {LH.Crdp.Audits.CookieIssueDetails} cookieIssue
     * @return {string}
     */
    static getCookieId(cookieIssue: LH.Crdp.Audits.CookieIssueDetails): string;
    /**
     * @param {LH.Artifacts} artifacts
     * @return {Promise<LH.Audit.Product>}
     */
    static audit(artifacts: LH.Artifacts): Promise<LH.Audit.Product>;
}
export namespace UIStrings {
    let title: string;
    let failureTitle: string;
    let description: string;
    let displayValue: string;
}
import { Audit } from './audit.js';
//# sourceMappingURL=third-party-cookies.d.ts.map