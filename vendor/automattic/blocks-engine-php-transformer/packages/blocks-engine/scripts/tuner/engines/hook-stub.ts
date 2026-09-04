/**
 * Tier-B seam — deterministic stub hook (v1 wiring only, NOT ratcheted).
 *
 * Proves the propose/realize/cache contract the real DLA hook will plug into,
 * with no model call and no network:
 *   - propose(specs): the costly, non-deterministic step in a real hook (an LLM
 *     polish/repair pass). Here it is a deterministic stand-in, cached by
 *     (engine, model, effort, input, promptHash) so the second call replays from
 *     cache instead of recomputing.
 *   - realize(raw): the deterministic tail — validate the cached artifact.
 *
 * A real DLA-hook capture lands as a fast follow by populating the cache with
 * actual hook output; nothing in this file's shape changes.
 */
import { reconstructEngine } from './reconstruct.js';
import { cacheKey, readCache, writeCache, type CacheKeyParts } from '../cache.js';
import type { SectionRenderOptions } from '../../../src/theme/native-reconstruct-types.js';
import type { SectionSpec } from '../../../src/theme/section-spec.js';
import { validateBlockMarkup } from '../../../src/validate-block-markup.js';

export const HOOK_STUB_PROMPT_HASH = 'stub-1';

export interface ProposeResult {
  raw: string;
  promptHash: string;
  fromCache: boolean;
}

export interface HookRealizeResult {
  output: string;
  valid: boolean;
}

function keyParts(specs: SectionSpec[]): CacheKeyParts {
  return {
    engineLabel: hookStubEngine.label,
    model: hookStubEngine.model,
    effort: hookStubEngine.effort,
    inputHtml: JSON.stringify(specs),
    promptHash: HOOK_STUB_PROMPT_HASH,
  };
}

export const hookStubEngine = {
  label: 'hook-stub',
  model: 'deterministic',
  effort: 'none',
  promptHash: HOOK_STUB_PROMPT_HASH,

  /** Costly step stand-in. Cached: first call computes, later calls replay. */
  propose(specs: SectionSpec[], cacheDir: string, now: string, options: SectionRenderOptions = {}): ProposeResult {
    const parts = keyParts(specs);
    const cached = readCache(cacheDir, parts);
    if (cached) {
      return { raw: cached.raw, promptHash: parts.promptHash, fromCache: true };
    }
    // Stands in for an LLM polish/repair pass over the deterministic reconstruct.
    const raw = reconstructEngine.realize(specs, options).output;
    writeCache(cacheDir, parts, 'stub', raw, now);
    return { raw, promptHash: parts.promptHash, fromCache: false };
  },

  /** Deterministic tail: validate the cached artifact. */
  realize(raw: string): HookRealizeResult {
    return { output: raw, valid: validateBlockMarkup(raw).length === 0 };
  },

  cacheKey(specs: SectionSpec[]): string {
    return cacheKey(keyParts(specs));
  },
};
