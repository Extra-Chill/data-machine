import { describe, expect, it } from 'vitest';
import { analyzeRuntimeRegionEffects } from './region-effect-manifest.js';

describe('analyzeRuntimeRegionEffects', () => {
  it('splits fixture87-style carousel and reveal effects without dropping either', () => {
    const manifest = analyzeRuntimeRegionEffects(`document.querySelectorAll('.carousel .next').forEach((button) => button.addEventListener('click', () => button.closest('.carousel').classList.add('active')));\ndocument.querySelectorAll('.reveal').forEach((item) => item.addEventListener('scroll', () => item.classList.add('visible')));`);
    expect(manifest.units.map((unit) => unit.status)).toEqual(['independently_suppressible', 'independently_suppressible']);
    expect(manifest.units[0].targets).toEqual(['.carousel .next']);
    expect(manifest.units[1].targets).toEqual(['.reveal']);
  });

  it('fails closed for shared state and dynamic selectors', () => {
    const shared = analyzeRuntimeRegionEffects(`let active = 0;\ndocument.querySelector('.carousel').addEventListener('click', () => active++);`);
    expect(shared.units.every((unit) => unit.status === 'shared_or_unsplittable')).toBe(true);
    const dynamic = analyzeRuntimeRegionEffects(`document.querySelector(selector).addEventListener('click', () => {});`);
    expect(dynamic.units[0].reason).toBe('dynamic_selector');
  });

  it('fails closed on unparseable source instead of reporting it effect-free', () => {
    const broken = analyzeRuntimeRegionEffects(`document.querySelector('.carousel'`);
    expect(broken.units).toHaveLength(1);
    expect(broken.units[0].status).toBe('shared_or_unsplittable');
    expect(broken.units[0].reason).toBe('parse_failed');
    expect(broken.units[0].source).toMatchObject({ start: 0, end: broken.units[0].source.end });
    const inert = analyzeRuntimeRegionEffects(`/* nothing to do */`);
    expect(inert.units).toEqual([]);
  });

  it('fails closed for state shared through destructuring, function declarations, and loop heads', () => {
    const destructured = analyzeRuntimeRegionEffects(`const { active } = window.state;\ndocument.querySelector('.carousel').addEventListener('click', () => active.toggle());`);
    expect(destructured.units[1].reason).toBe('shared_state');
    const arrayPattern = analyzeRuntimeRegionEffects(`let [first] = window.items;\ndocument.querySelector('.reveal').addEventListener('click', () => first.show());`);
    expect(arrayPattern.units[1].reason).toBe('shared_state');
    const declaredFunction = analyzeRuntimeRegionEffects(`function advance() {}\ndocument.querySelector('.carousel').addEventListener('click', () => advance());`);
    expect(declaredFunction.units[1].reason).toBe('shared_state');
    const loopHead = analyzeRuntimeRegionEffects(`for (const step of window.steps) { window.register(step); }\ndocument.querySelector('.carousel').addEventListener('click', () => window.play(step));`);
    expect(loopHead.units[1].reason).toBe('shared_state');
  });

  it('escapes getElementById targets that are not plain CSS identifiers', () => {
    const manifest = analyzeRuntimeRegionEffects(`document.getElementById('hero').addEventListener('click', () => {});\ndocument.getElementById('2col "grid"').addEventListener('click', () => {});`);
    expect(manifest.units[0].targets).toEqual(['#hero']);
    expect(manifest.units[1].targets).toEqual(['[id="2col \\"grid\\""]']);
  });
});
