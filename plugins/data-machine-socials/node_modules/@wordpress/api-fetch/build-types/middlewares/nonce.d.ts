export default createNonceMiddleware;
/**
 * @param {string} nonce
 * @return {import('../types').APIFetchMiddleware & { nonce: string }} A middleware to enhance a request with a nonce.
 */
declare function createNonceMiddleware(nonce: string): import('../types').APIFetchMiddleware & {
    nonce: string;
};
//# sourceMappingURL=nonce.d.ts.map