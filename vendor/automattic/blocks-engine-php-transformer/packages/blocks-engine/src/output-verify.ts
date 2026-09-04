export interface VerifyResult {
  valid: boolean;
  hallucinated: string[];
}

function stripBlockComments(markup: string): string {
  return markup
    .replace(/<!--\s*\/?wp:[^>]*-->/g, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ');
}

function htmlToPlainText(html: string): string {
  if (!html) return '';
  return html
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&apos;/g, "'")
    .replace(/\s+/g, ' ')
    .trim();
}

function extractTextNodes(markup: string): string[] {
  const stripped = stripBlockComments(markup);
  const nodes: string[] = [];
  const parts = stripped.split(/<[^>]+>/);
  for (const raw of parts) {
    const text = htmlToPlainText(raw);
    if (!text) continue;
    const alnum = text.replace(/[^a-zA-Z0-9]/g, '');
    if (alnum.length < 3) continue;
    nodes.push(text);
  }
  return nodes;
}

function normalize(text: string): string {
  return text.toLowerCase().replace(/\s+/g, ' ').trim();
}

export function verifyComposedOutput(
  blocksMarkup: string,
  sourceHtmlPlainText: string,
): VerifyResult {
  const sourceText = normalize(htmlToPlainText(sourceHtmlPlainText));
  const nodes = extractTextNodes(blocksMarkup);
  const hallucinated: string[] = [];

  for (const node of nodes) {
    const needle = normalize(node);
    if (!needle) continue;
    if (!sourceText.includes(needle)) hallucinated.push(node);
  }

  return {
    valid: hallucinated.length === 0,
    hallucinated,
  };
}
