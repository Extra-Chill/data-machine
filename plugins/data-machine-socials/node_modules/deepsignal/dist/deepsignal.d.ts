import { Signal } from "@preact/signals-core";
export declare const deepSignal: <T extends object>(obj: T) => DeepSignal<T>;
export declare const peek: <T extends DeepSignalObject<object>, K extends FilterSignals<keyof T>>(obj: T, key: K) => RevertDeepSignal<RevertDeepSignalObject<T>[K]>;
declare const isShallow: unique symbol;
export declare function shallow<T extends object>(obj: T): Shallow<T>;
/** TYPES **/
export declare type DeepSignal<T> = T extends Function ? T : T extends {
    [isShallow]: true;
} ? T : T extends Array<unknown> ? DeepSignalArray<T> : T extends object ? DeepSignalObject<T> : T;
declare type DeepSignalObject<T extends object> = {
    [P in keyof T & string as `$${P}`]?: T[P] extends Function ? never : Signal<T[P]>;
} & {
    [P in keyof T]: DeepSignal<T[P]>;
};
/** @ts-expect-error **/
interface DeepArray<T> extends Array<T> {
    map: <U>(callbackfn: (value: DeepSignal<T>, index: number, array: DeepSignalArray<T[]>) => U, thisArg?: any) => U[];
    forEach: (callbackfn: (value: DeepSignal<T>, index: number, array: DeepSignalArray<T[]>) => void, thisArg?: any) => void;
    concat(...items: ConcatArray<T>[]): DeepSignalArray<T[]>;
    concat(...items: (T | ConcatArray<T>)[]): DeepSignalArray<T[]>;
    reverse(): DeepSignalArray<T[]>;
    shift(): DeepSignal<T> | undefined;
    slice(start?: number, end?: number): DeepSignalArray<T[]>;
    splice(start: number, deleteCount?: number): DeepSignalArray<T[]>;
    splice(start: number, deleteCount: number, ...items: T[]): DeepSignalArray<T[]>;
    filter<S extends T>(predicate: (value: DeepSignal<T>, index: number, array: DeepSignalArray<T[]>) => value is DeepSignal<S>, thisArg?: any): DeepSignalArray<S[]>;
    filter(predicate: (value: DeepSignal<T>, index: number, array: DeepSignalArray<T[]>) => unknown, thisArg?: any): DeepSignalArray<T[]>;
    reduce(callbackfn: (previousValue: DeepSignal<T>, currentValue: DeepSignal<T>, currentIndex: number, array: DeepSignalArray<T[]>) => T): DeepSignal<T>;
    reduce(callbackfn: (previousValue: DeepSignal<T>, currentValue: DeepSignal<T>, currentIndex: number, array: DeepSignalArray<T[]>) => DeepSignal<T>, initialValue: T): DeepSignal<T>;
    reduce<U>(callbackfn: (previousValue: U, currentValue: DeepSignal<T>, currentIndex: number, array: DeepSignalArray<T[]>) => U, initialValue: U): U;
    reduceRight(callbackfn: (previousValue: DeepSignal<T>, currentValue: DeepSignal<T>, currentIndex: number, array: DeepSignalArray<T[]>) => T): DeepSignal<T>;
    reduceRight(callbackfn: (previousValue: DeepSignal<T>, currentValue: DeepSignal<T>, currentIndex: number, array: DeepSignalArray<T[]>) => DeepSignal<T>, initialValue: T): DeepSignal<T>;
    reduceRight<U>(callbackfn: (previousValue: U, currentValue: DeepSignal<T>, currentIndex: number, array: DeepSignalArray<T[]>) => U, initialValue: U): U;
}
declare type ArrayType<T> = T extends Array<infer I> ? I : T;
declare type DeepSignalArray<T> = DeepArray<ArrayType<T>> & {
    [key: number]: DeepSignal<ArrayType<T>>;
    $?: {
        [key: number]: Signal<ArrayType<T>>;
    };
    $length?: Signal<number>;
};
export declare type Shallow<T extends object> = T & {
    [isShallow]: true;
};
export declare const useDeepSignal: <T extends object>(obj: T) => DeepSignal<T>;
declare type FilterSignals<K> = K extends `$${infer P}` ? never : K;
declare type RevertDeepSignalObject<T> = Pick<T, FilterSignals<keyof T>>;
declare type RevertDeepSignalArray<T> = Omit<T, "$" | "$length">;
export declare type RevertDeepSignal<T> = T extends Array<unknown> ? RevertDeepSignalArray<T> : T extends object ? RevertDeepSignalObject<T> : T;
export {};
