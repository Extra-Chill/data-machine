export const DEFAULT_ENTITY_KEY: "id";
export const rootEntitiesConfig: ({
    label: string;
    kind: string;
    name: string;
    baseURL: string;
    baseURLParams: {
        _fields: string;
        context?: undefined;
    };
    plural: string;
    syncConfig: {
        fetch: () => Promise<any>;
        applyChangesToDoc: (doc: any, changes: any) => void;
        fromCRDTDoc: (doc: any) => any;
    };
    syncObjectType: string;
    getSyncObjectId: () => string;
    key?: undefined;
    rawAttributes?: undefined;
    supportsPagination?: undefined;
    transientEdits?: undefined;
    getTitle?: undefined;
    getRevisionsUrl?: undefined;
} | {
    label: string;
    name: string;
    kind: string;
    key: string;
    baseURL: string;
    baseURLParams: {
        context: string;
        _fields?: undefined;
    };
    plural: string;
    syncConfig: {
        fetch: (id: any) => Promise<any>;
        applyChangesToDoc: (doc: any, changes: any) => void;
        fromCRDTDoc: (doc: any) => any;
    };
    syncObjectType: string;
    getSyncObjectId: (id: any) => any;
    rawAttributes?: undefined;
    supportsPagination?: undefined;
    transientEdits?: undefined;
    getTitle?: undefined;
    getRevisionsUrl?: undefined;
} | {
    name: string;
    kind: string;
    baseURL: string;
    baseURLParams: {
        context: string;
        _fields?: undefined;
    };
    plural: string;
    label: string;
    rawAttributes: string[];
    supportsPagination: boolean;
    syncConfig?: undefined;
    syncObjectType?: undefined;
    getSyncObjectId?: undefined;
    key?: undefined;
    transientEdits?: undefined;
    getTitle?: undefined;
    getRevisionsUrl?: undefined;
} | {
    name: string;
    kind: string;
    key: string;
    baseURL: string;
    baseURLParams: {
        context: string;
        _fields?: undefined;
    };
    plural: string;
    label: string;
    syncConfig?: undefined;
    syncObjectType?: undefined;
    getSyncObjectId?: undefined;
    rawAttributes?: undefined;
    supportsPagination?: undefined;
    transientEdits?: undefined;
    getTitle?: undefined;
    getRevisionsUrl?: undefined;
} | {
    name: string;
    kind: string;
    baseURL: string;
    baseURLParams: {
        context: string;
        _fields?: undefined;
    };
    plural: string;
    transientEdits: {
        blocks: boolean;
    };
    label: string;
    syncConfig?: undefined;
    syncObjectType?: undefined;
    getSyncObjectId?: undefined;
    key?: undefined;
    rawAttributes?: undefined;
    supportsPagination?: undefined;
    getTitle?: undefined;
    getRevisionsUrl?: undefined;
} | {
    name: string;
    kind: string;
    baseURL: string;
    baseURLParams: {
        context: string;
        _fields?: undefined;
    };
    plural: string;
    label: string;
    syncConfig?: undefined;
    syncObjectType?: undefined;
    getSyncObjectId?: undefined;
    key?: undefined;
    rawAttributes?: undefined;
    supportsPagination?: undefined;
    transientEdits?: undefined;
    getTitle?: undefined;
    getRevisionsUrl?: undefined;
} | {
    name: string;
    kind: string;
    baseURL: string;
    baseURLParams: {
        context: string;
        _fields?: undefined;
    };
    plural: string;
    label: string;
    rawAttributes: string[];
    syncConfig?: undefined;
    syncObjectType?: undefined;
    getSyncObjectId?: undefined;
    key?: undefined;
    supportsPagination?: undefined;
    transientEdits?: undefined;
    getTitle?: undefined;
    getRevisionsUrl?: undefined;
} | {
    label: string;
    name: string;
    kind: string;
    baseURL: string;
    baseURLParams: {
        context: string;
        _fields?: undefined;
    };
    plural: string;
    getTitle: (record: any) => any;
    getRevisionsUrl: (parentId: any, revisionId: any) => string;
    supportsPagination: boolean;
    syncConfig?: undefined;
    syncObjectType?: undefined;
    getSyncObjectId?: undefined;
    key?: undefined;
    rawAttributes?: undefined;
    transientEdits?: undefined;
})[];
export const additionalEntityConfigLoaders: ({
    kind: string;
    loadEntities: typeof loadPostTypeEntities;
    name?: undefined;
    plural?: undefined;
} | {
    kind: string;
    name: string;
    plural: string;
    loadEntities: typeof loadSiteEntity;
})[];
export function prePersistPostType(persistedRecord: any, edits: any): any;
export function getMethodName(kind: string, name: string, prefix?: string): string;
export function getOrLoadEntitiesConfig(kind: string, name: string): (thunkArgs: object) => Promise<any[]>;
/**
 * Returns the list of post type entities.
 *
 * @return {Promise} Entities promise
 */
declare function loadPostTypeEntities(): Promise<any>;
/**
 * Returns the Site entity.
 *
 * @return {Promise} Entity promise
 */
declare function loadSiteEntity(): Promise<any>;
export {};
//# sourceMappingURL=entities.d.ts.map