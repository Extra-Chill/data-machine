export default fetchAllMiddleware;
/**
 * The REST API enforces an upper limit on the per_page option. To handle large
 * collections, apiFetch consumers can pass `per_page=-1`; this middleware will
 * then recursively assemble a full response array from all available pages.
 *
 * @type {import('../types').APIFetchMiddleware}
 */
declare const fetchAllMiddleware: import('../types').APIFetchMiddleware;
//# sourceMappingURL=fetch-all-middleware.d.ts.map