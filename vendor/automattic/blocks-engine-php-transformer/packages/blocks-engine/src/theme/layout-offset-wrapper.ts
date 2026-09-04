import * as cheerio from 'cheerio';
import type { AnyNode, Element } from 'domhandler';

const OUT_OF_FLOW_POSITION_RE = /\bposition\s*:\s*(fixed|absolute|sticky)\b/i;
const HORIZONTAL_OFFSET_PROPS = ['margin-left', 'margin-right', 'padding-left', 'padding-right'] as const;
const NON_CONTENT_TAGS = new Set(['header', 'footer', 'nav', 'aside', 'script', 'style', 'template']);

interface CssRule {
  selector: string;
  declarations: string;
}

export function detectLayoutOffsetWrapper(homeHtml: string, css: string): string | undefined {
  const cleanCss = stripCssComments(css);
  if (!OUT_OF_FLOW_POSITION_RE.test(cleanCss)) return undefined;

  const $ = cheerio.load(homeHtml);
  const target = primaryMainContentElement($);
  if (!target) return undefined;

  const rules = parseCssRules(cleanCss);
  const rootVars = parseRootVars(rules);

  let current: Element | null = target.includeTarget ? target.element : parentElement(target.element);
  while (current) {
    const tag = tagName(current);
    if (tag === 'html' || tag === 'body') break;

    const classAttr = current.attribs?.class ?? '';
    for (const token of classAttr.split(/\s+/).filter(Boolean)) {
      if (classHasHorizontalOffset(token, rules, rootVars)) return token;
    }

    current = parentElement(current);
  }

  return undefined;
}

function primaryMainContentElement($: cheerio.CheerioAPI): { element: Element; includeTarget: boolean } | null {
  const main = $('main').first().get(0);
  if (isElement(main)) return { element: main, includeTarget: false };

  const bodyChildren = $('body').children().toArray();
  const bodyContent = bodyChildren.find(isContentElement);
  if (isElement(bodyContent)) return { element: bodyContent, includeTarget: true };

  const rootContent = $.root().children().toArray().find(isContentElement);
  return isElement(rootContent) ? { element: rootContent, includeTarget: true } : null;
}

function isContentElement(node: AnyNode | undefined): node is Element {
  return isElement(node) && !NON_CONTENT_TAGS.has(tagName(node));
}

function isElement(node: AnyNode | undefined): node is Element {
  return !!node && node.type === 'tag';
}

function parentElement(node: Element): Element | null {
  const parent = node.parent as AnyNode | undefined;
  return isElement(parent) ? parent : null;
}

function tagName(node: Element): string {
  return (node.tagName || node.name || '').toLowerCase();
}

function classHasHorizontalOffset(token: string, rules: CssRule[], rootVars: Map<string, string>): boolean {
  return rules.some(
    (rule) => selectorContainsClass(rule.selector, token) && hasNonTrivialHorizontalOffset(rule.declarations, rootVars)
  );
}

function hasNonTrivialHorizontalOffset(declarations: string, rootVars: Map<string, string>): boolean {
  for (const prop of HORIZONTAL_OFFSET_PROPS) {
    const match = new RegExp(`\\b${prop}\\s*:\\s*([^;]+)`, 'i').exec(declarations);
    if (!match) continue;
    if (isNonTrivialOffsetValue(match[1], rootVars)) return true;
  }
  return false;
}

function isNonTrivialOffsetValue(rawValue: string, rootVars: Map<string, string>): boolean {
  const value = rawValue.trim();
  const varMatch = /var\(\s*(--[a-zA-Z0-9_-]+)\s*(?:,[^)]+)?\)/.exec(value);
  if (varMatch) {
    const resolved = rootVars.get(varMatch[1]);
    return resolved ? isNonTrivialOffsetValue(resolved, rootVars) : true;
  }

  const pxMatch = /(-?\d+(?:\.\d+)?)px\b/i.exec(value);
  return pxMatch ? Number.parseFloat(pxMatch[1]) >= 100 : false;
}

function selectorContainsClass(selector: string, token: string): boolean {
  return new RegExp(`(^|[^_a-zA-Z0-9-])\\.${escapeRegExp(token)}(?![_a-zA-Z0-9-])`).test(selector);
}

function parseCssRules(css: string): CssRule[] {
  const out: CssRule[] = [];
  const ruleRe = /([^{}]+)\{([^{}]*)\}/g;
  let match: RegExpExecArray | null;
  while ((match = ruleRe.exec(css))) {
    out.push({ selector: match[1].trim(), declarations: match[2] });
  }
  return out;
}

function parseRootVars(rules: CssRule[]): Map<string, string> {
  const vars = new Map<string, string>();
  for (const rule of rules) {
    if (!/(^|,)\s*:root\s*(,|$)/.test(rule.selector)) continue;
    for (const match of rule.declarations.matchAll(/(--[a-zA-Z0-9_-]+)\s*:\s*([^;]+)/g)) {
      vars.set(match[1], match[2].trim());
    }
  }
  return vars;
}

function stripCssComments(css: string): string {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
