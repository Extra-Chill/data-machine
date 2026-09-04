/**
 * The propose/realize cache — makes the Tier-B inner loop free.
 *
 * Exactly one step in a hook engine is slow + non-deterministic (the model call,
 * "propose"); everything after it is instant + deterministic ("realize"). Cache
 * the costly artifact keyed by everything that could change it, and replay the
 * deterministic tail for free.
 *
 * Key = sha(engineLabel + model + effort + inputHtml + promptHash). An input edit
 * changes inputHtml; a prompt/schema edit changes promptHash — either moves the
 * key, so a stale artifact simply misses (never replayed silently).
 */
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

export interface CacheKeyParts {
  engineLabel: string;
  model: string;
  effort: string;
  inputHtml: string;
  promptHash: string;
}

export interface CacheEntry {
  engineLabel: string;
  model: string;
  effort: string;
  label: string;
  promptHash: string;
  raw: string;
  proposeMs?: number;
  cachedAt: string;
}

export function cacheKey(parts: CacheKeyParts): string {
  const hash = createHash('sha256');
  for (const field of [parts.engineLabel, parts.model, parts.effort, parts.inputHtml, parts.promptHash]) {
    hash.update(field);
    hash.update('\0');
  }
  return hash.digest('hex').slice(0, 16);
}

function cachePath(dir: string, key: string): string {
  return path.join(dir, `${key}.json`);
}

export function readCache(dir: string, parts: CacheKeyParts): CacheEntry | undefined {
  const file = cachePath(dir, cacheKey(parts));
  if (!existsSync(file)) return undefined;
  try {
    return JSON.parse(readFileSync(file, 'utf8')) as CacheEntry;
  } catch {
    return undefined;
  }
}

export function writeCache(
  dir: string,
  parts: CacheKeyParts,
  label: string,
  raw: string,
  cachedAt: string,
  proposeMs?: number,
): void {
  mkdirSync(dir, { recursive: true });
  const entry: CacheEntry = {
    engineLabel: parts.engineLabel,
    model: parts.model,
    effort: parts.effort,
    label,
    promptHash: parts.promptHash,
    raw,
    ...(proposeMs !== undefined ? { proposeMs } : {}),
    cachedAt,
  };
  writeFileSync(cachePath(dir, cacheKey(parts)), `${JSON.stringify(entry, null, 2)}\n`, 'utf8');
}
