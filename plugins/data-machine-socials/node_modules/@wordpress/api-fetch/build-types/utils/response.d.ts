/**
 * Parses a response, throwing an error if parsing the response fails.
 *
 * @param {Response} response
 * @param {boolean}  shouldParseResponse
 * @return {Promise<any>} Parsed response.
 */
export function parseAndThrowError(response: Response, shouldParseResponse?: boolean): Promise<any>;
export function parseResponseAndNormalizeError(response: Response, shouldParseResponse?: boolean): Promise<any>;
//# sourceMappingURL=response.d.ts.map