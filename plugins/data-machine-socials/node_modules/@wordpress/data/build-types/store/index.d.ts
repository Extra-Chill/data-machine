export default coreDataStore;
declare namespace coreDataStore {
    let name: string;
    function instantiate(registry: any): {
        getSelectors(): {
            [k: string]: (key: any, ...args: any[]) => any;
        };
        getActions(): {
            [k: string]: (key: any, ...args: any[]) => any;
        };
        subscribe(): () => () => void;
    };
}
//# sourceMappingURL=index.d.ts.map