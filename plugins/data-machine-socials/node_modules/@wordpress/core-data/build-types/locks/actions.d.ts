export default function createLocksActions(): {
    __unstableAcquireStoreLock: (store: any, path: any, { exclusive }: {
        exclusive: any;
    }) => () => Promise<any>;
    __unstableReleaseStoreLock: (lock: any) => () => void;
};
//# sourceMappingURL=actions.d.ts.map