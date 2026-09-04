/** Remove script/style/comment blocks, inline event handlers, and PHP tags. */
export function sanitize(html: string): string {
  return html
    // Paired script/style including their contents.
    .replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>/gi, '')
    // Any residual/unclosed script/style tags.
    .replace(/<\/?(?:script|style)\b[^>]*>/gi, '')
    // PHP (incl. short tags).
    .replace(/<\?[\s\S]*?\?>/g, '')
    .replace(/<\?/g, '')
    // HTML comments, including literal block-comment lookalikes.
    .replace(/<!--[\s\S]*?-->/g, '')
    // Inline event handlers.
    .replace(/\son[a-z]+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '');
}
