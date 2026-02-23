export default function createLocks(): {
    acquire: (store: any, path: any, exclusive: any) => Promise<any>;
    release: (lock: any) => void;
};
//# sourceMappingURL=engine.d.ts.map