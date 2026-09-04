/**
 * CodePreview — renders a live component above its source code.
 *
 * The `preview` slot is the rendered output; the `code` prop is the source
 * shown in a copyable code panel below it. Used on every component page.
 *
 * Usage in MDX:
 *   import { CodePreview } from '@components/CodePreview'
 *   <CodePreview code={`<Button>Save changes</Button>`}>
 *     <Button>Save changes</Button>
 *   </CodePreview>
 */
import { useState } from 'react';

export function CodePreview({ code, lang = 'tsx', children }) {
  const [copied, setCopied] = useState(false);

  function copy() {
    navigator.clipboard.writeText(code).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  }

  return (
    <div className="code-preview">
      <div className="code-preview__stage" data-preview>
        {children}
      </div>
      <div className="code-preview__panel">
        <div className="code-preview__bar">
          <span className="code-preview__lang">{lang}</span>
          <button className="code-preview__copy" onClick={copy} type="button">
            {copied ? 'Copied' : 'Copy'}
          </button>
        </div>
        <pre className="code-preview__code"><code>{code}</code></pre>
      </div>
    </div>
  );
}

export default CodePreview;
