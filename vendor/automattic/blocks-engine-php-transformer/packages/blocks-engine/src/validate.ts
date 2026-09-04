export function scanForInjection(markup: string): string[] {
  const violations: string[] = [];
  if (/<\?/.test(markup)) {
    violations.push('raw PHP tag in markup (not allowed)');
  }
  if (/<\s*script/i.test(markup)) {
    violations.push('raw <script> tag in markup (not allowed)');
  }
  if (/[\s"'/]on[a-z]+\s*=/i.test(markup)) {
    violations.push('inline event handler attribute (on*=) in markup (not allowed)');
  }
  return violations;
}

export function blockMarkupRoundtrips(
  markup: string,
): { ok: true } | { ok: false; reason: string } {
  if (!markup || !markup.trim()) {
    return { ok: false, reason: 'empty markup' };
  }

  const stack: string[] = [];
  const re = /<!--\s*(\/?)wp:([a-zA-Z0-9/_-]+)([^]*?)(\/)?\s*-->/g;
  let match: RegExpExecArray | null;
  while ((match = re.exec(markup)) !== null) {
    const isClose = match[1] === '/';
    const name = match[2];
    const isSelfClose = match[4] === '/';
    if (isSelfClose) continue;
    if (isClose) {
      const top = stack.pop();
      if (top !== name) {
        return {
          ok: false,
          reason: `mismatched block close: expected /wp:${top ?? '(empty)'}, got /wp:${name}`,
        };
      }
    } else {
      stack.push(name);
    }
  }
  if (stack.length > 0) {
    return { ok: false, reason: `unclosed blocks: ${stack.join(', ')}` };
  }
  return { ok: true };
}
