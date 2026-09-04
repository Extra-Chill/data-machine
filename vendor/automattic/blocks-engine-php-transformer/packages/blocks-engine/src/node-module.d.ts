declare module 'node:module' {
  export interface NodeRequire {
    (id: string): unknown;
  }

  export function createRequire(filename: string): NodeRequire;
}

declare const __filename: string | undefined;
