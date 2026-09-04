import { createHash } from 'node:crypto';
import { parse } from 'acorn';
import type { AnyNode, Pattern, Program } from 'acorn';
import { cssIdSelector } from '../escape.js';

export type RuntimeEffectUnit = {
  id: string;
  source: { start: number; end: number; hash: string };
  targets: string[];
  events: string[];
  mutations: string[];
  dependencies: string[];
  status: 'independently_suppressible' | 'shared_or_unsplittable';
  reason?: 'dynamic_selector' | 'shared_state' | 'unrecognized_pattern' | 'parse_failed';
};

export type RegionEffectManifest = {
  schema: 'blocks-engine/runtime-region-effects/v1';
  sourceHash: string;
  units: RuntimeEffectUnit[];
};

const SCHEMA = 'blocks-engine/runtime-region-effects/v1' as const;

const CLASS_MUTATORS = new Set(['add', 'remove', 'toggle', 'replace']);

const hash = (value: string) => createHash('sha256').update(value).digest('hex');

/**
 * Produces an ownership manifest from top-level DOM-effect statements. This is
 * deliberately bounded: unsupported AST shapes remain retained as a whole, and
 * unparseable source yields a single whole-source unretirable unit rather than
 * an empty (effect-free-looking) manifest.
 */
export function analyzeRuntimeRegionEffects(source: string): RegionEffectManifest {
  const sourceHash = hash(source);
  let program: Program;
  try {
    program = parse(source, { ecmaVersion: 'latest', sourceType: 'script' });
  } catch {
    return {
      schema: SCHEMA,
      sourceHash,
      units: [
        {
          id: `effect_1_${sourceHash.slice(0, 12)}`,
          source: { start: 0, end: source.length, hash: sourceHash },
          targets: [],
          events: [],
          mutations: [],
          dependencies: [],
          status: 'shared_or_unsplittable',
          reason: 'parse_failed',
        },
      ],
    };
  }

  const declared = new Set<string>();
  for (const statement of program.body) collectSharedBindings(statement, declared);

  return {
    schema: SCHEMA,
    sourceHash,
    units: program.body.map((statement, index) => unitFor(statement, index, source, declared)),
  };
}

/**
 * Registers every binding a top-level statement can contribute to program
 * scope: declarations in nested blocks and loop heads included, function
 * bodies excluded (their bindings are local). Block-scoped bindings in nested
 * blocks over-collect, which only fails closed.
 */
function collectSharedBindings(value: unknown, names: Set<string>): void {
  if (Array.isArray(value)) {
    for (const child of value) collectSharedBindings(child, names);
    return;
  }
  if (!isAstNode(value)) return;
  if ((value.type === 'FunctionDeclaration' || value.type === 'ClassDeclaration') && value.id) names.add(value.id.name);
  if (value.type === 'FunctionDeclaration' || value.type === 'FunctionExpression' || value.type === 'ArrowFunctionExpression') return;
  if (value.type === 'VariableDeclaration') {
    for (const declaration of value.declarations) collectPatternNames(declaration.id, names);
  }
  for (const child of Object.values(value)) collectSharedBindings(child, names);
}

function collectPatternNames(pattern: Pattern, names: Set<string>): void {
  switch (pattern.type) {
    case 'Identifier':
      names.add(pattern.name);
      break;
    case 'ObjectPattern':
      for (const property of pattern.properties) {
        collectPatternNames(property.type === 'RestElement' ? property.argument : property.value, names);
      }
      break;
    case 'ArrayPattern':
      for (const element of pattern.elements) {
        if (element) collectPatternNames(element, names);
      }
      break;
    case 'RestElement':
      collectPatternNames(pattern.argument, names);
      break;
    case 'AssignmentPattern':
      collectPatternNames(pattern.left, names);
      break;
  }
}

function unitFor(statement: AnyNode, index: number, source: string, declared: Set<string>): RuntimeEffectUnit {
  const slice = source.slice(statement.start, statement.end);
  const sliceHash = hash(slice);
  const targets = new Set<string>();
  const events = new Set<string>();
  const mutations = new Set<string>();
  const identifiers = new Set<string>();
  let dynamicSelector = false;
  let recognized = false;
  walk(statement, (node) => {
    if (node.type === 'Identifier') identifiers.add(node.name);
    if (node.type !== 'CallExpression' || node.callee.type !== 'MemberExpression') return;
    const name = node.callee.property.type === 'Identifier' ? node.callee.property.name : '';
    if ((name === 'querySelector' || name === 'querySelectorAll' || name === 'getElementById') && node.arguments.length) {
      recognized = true;
      const argument = node.arguments[0];
      if (argument.type !== 'Literal' || typeof argument.value !== 'string') dynamicSelector = true;
      else targets.add(name === 'getElementById' ? cssIdSelector(argument.value) : argument.value);
    }
    if (name === 'addEventListener' && node.arguments[0]?.type === 'Literal' && typeof node.arguments[0].value === 'string') {
      recognized = true;
      events.add(node.arguments[0].value);
    }
    if (CLASS_MUTATORS.has(name) && node.callee.object.type === 'MemberExpression' && node.callee.object.property.type === 'Identifier' && node.callee.object.property.name === 'classList') mutations.add('class');
    if (name === 'setAttribute' && node.arguments[0]?.type === 'Literal' && typeof node.arguments[0].value === 'string') mutations.add(`attribute:${node.arguments[0].value}`);
  });
  const dependencies = [...identifiers].filter((name) => declared.has(name)).sort();
  const reason = dynamicSelector ? 'dynamic_selector' : dependencies.length ? 'shared_state' : !recognized || !targets.size ? 'unrecognized_pattern' : undefined;
  const unit: RuntimeEffectUnit = {
    id: `effect_${index + 1}_${sliceHash.slice(0, 12)}`,
    source: { start: statement.start, end: statement.end, hash: sliceHash },
    targets: [...targets].sort(), events: [...events].sort(), mutations: [...mutations].sort(),
    dependencies,
    status: reason ? 'shared_or_unsplittable' : 'independently_suppressible',
  };
  return reason ? { ...unit, reason } : unit;
}

function isAstNode(value: unknown): value is AnyNode {
  return !!value && typeof value === 'object' && typeof (value as { type?: unknown }).type === 'string';
}

function walk(value: unknown, visit: (node: AnyNode) => void): void {
  if (Array.isArray(value)) {
    for (const child of value) walk(child, visit);
    return;
  }
  if (!isAstNode(value)) return;
  visit(value);
  for (const child of Object.values(value)) walk(child, visit);
}
