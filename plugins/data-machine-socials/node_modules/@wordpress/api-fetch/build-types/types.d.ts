export interface APIFetchOptions extends RequestInit {
    headers?: Record<string, string>;
    path?: string;
    url?: string;
    /**
     * @default true
     */
    parse?: boolean;
    data?: any;
    namespace?: string;
    endpoint?: string;
}
export type APIFetchMiddleware = (options: APIFetchOptions, next: (nextOptions: APIFetchOptions) => Promise<any>) => Promise<any>;
//# sourceMappingURL=types.d.ts.map