export function deriveKey(secret: string, roomName: string): PromiseLike<CryptoKey>;
export function encrypt(data: Uint8Array, key: CryptoKey | null): PromiseLike<Uint8Array>;
export function encryptJson(data: any, key: CryptoKey | null): PromiseLike<Uint8Array>;
export function decrypt(data: Uint8Array, key: CryptoKey | null): PromiseLike<Uint8Array>;
export function decryptJson(data: Uint8Array, key: CryptoKey | null): PromiseLike<any>;
