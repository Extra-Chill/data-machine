import * as Types from '../types/types.js';
declare const schedulerToRunEntryPoints: Map<Types.Events.Event, Types.Events.Event[]>;
declare const asyncCallToScheduler: Map<Types.Events.SyntheticProfileCall, {
    taskName: string;
    scheduler: Types.Events.Event;
}>;
declare const runEntryPointToScheduler: Map<Types.Events.Event, {
    taskName: string;
    scheduler: Types.Events.Event;
}>;
export declare function reset(): void;
export declare function handleEvent(_: Types.Events.Event): void;
export declare function finalize(): Promise<void>;
export declare function data(): {
    schedulerToRunEntryPoints: typeof schedulerToRunEntryPoints;
    asyncCallToScheduler: typeof asyncCallToScheduler;
    runEntryPointToScheduler: typeof runEntryPointToScheduler;
};
export declare function deps(): ['Renderer', 'Flows'];
export {};
