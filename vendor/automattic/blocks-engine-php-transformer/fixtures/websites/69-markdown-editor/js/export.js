/* ============================================================
   Inkwell — exporters
   Download as .md, export rendered standalone .html, and a
   print-friendly view for "Print to PDF".
   ============================================================ */
(function (global) {
  'use strict';

  function download(filename, content, mime) {
    var blob = new Blob([content], { type: mime || 'text/plain;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(url); }, 100);
  }

  function safeName(title) {
    return (title || 'document').toLowerCase()
      .replace(/[^\w\s-]/g, '').trim().replace(/\s+/g, '-').slice(0, 60) || 'document';
  }

  function exportMarkdown(doc) {
    download(safeName(doc.title) + '.md', doc.body, 'text/markdown;charset=utf-8');
  }

  /* Build a fully self-contained HTML file (inlined CSS, no external deps). */
  function buildStandaloneHtml(doc) {
    var rendered = global.Inkwell.render(doc.body);
    var css = STANDALONE_CSS;
    return '<!DOCTYPE html>\n<html lang="en">\n<head>\n' +
      '<meta charset="UTF-8">\n' +
      '<meta name="viewport" content="width=device-width, initial-scale=1.0">\n' +
      '<title>' + global.Inkwell.escapeHtml(doc.title) + '</title>\n' +
      '<meta name="generator" content="Inkwell">\n' +
      '<style>\n' + css + '\n</style>\n</head>\n<body>\n' +
      '<main class="doc">\n' + rendered.html + '\n</main>\n' +
      '<footer class="doc-foot">Rendered with Inkwell · ' +
        new Date().toISOString().slice(0, 10) + '</footer>\n' +
      '</body>\n</html>\n';
  }

  function exportHtml(doc) {
    download(safeName(doc.title) + '.html', buildStandaloneHtml(doc), 'text/html;charset=utf-8');
  }

  /* Open a print-friendly window and trigger the print dialog (-> PDF). */
  function printDocument(doc) {
    var html = buildStandaloneHtml(doc);
    var w = global.open('', '_blank');
    if (!w) return false;
    w.document.open();
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(function () { try { w.print(); } catch (e) {} }, 350);
    return true;
  }

  var STANDALONE_CSS =
    ':root{--ink:#1f2430;--soft:#5a6172;--bg:#ffffff;--accent:#5b53d6;--line:#e6e8ef;--code-bg:#f4f5f9}' +
    '*{box-sizing:border-box}' +
    'body{margin:0;background:var(--bg);color:var(--ink);' +
    'font:17px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}' +
    '.doc{max-width:780px;margin:0 auto;padding:64px 28px 40px}' +
    'h1,h2,h3,h4,h5,h6{line-height:1.25;margin:1.8em 0 .6em;font-weight:700;letter-spacing:-.01em}' +
    'h1{font-size:2.1em;margin-top:0}h2{font-size:1.6em;border-bottom:1px solid var(--line);padding-bottom:.25em}' +
    'h3{font-size:1.3em}h4{font-size:1.1em}' +
    '.md-anchor{display:none}' +
    'p{margin:0 0 1.1em}a{color:var(--accent)}' +
    'code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.88em}' +
    '.md-code-inline{background:var(--code-bg);padding:.12em .4em;border-radius:5px}' +
    'pre{background:var(--code-bg);padding:16px 18px;border-radius:10px;overflow:auto;border:1px solid var(--line);position:relative}' +
    'pre code{display:block;line-height:1.55}' +
    '.md-code-lang{position:absolute;top:8px;right:12px;font-size:11px;color:var(--soft);text-transform:uppercase;letter-spacing:.08em}' +
    '.t-kw{color:#9a3fd6}.t-str{color:#2e8b57}.t-num{color:#c2410c}.t-com{color:#94a0b8;font-style:italic}' +
    'blockquote{margin:1.2em 0;padding:.4em 1.1em;border-left:4px solid var(--accent);color:var(--soft);background:#f7f7fc;border-radius:0 8px 8px 0}' +
    'ul,ol{margin:0 0 1.1em;padding-left:1.5em}li{margin:.3em 0}' +
    '.md-tasklist{list-style:none;padding-left:.2em}.md-task{margin-right:.5em;transform:translateY(1px)}' +
    'hr{border:none;border-top:2px solid var(--line);margin:2em 0}' +
    'img{max-width:100%;border-radius:10px;display:block;margin:1.2em 0}' +
    'table{border-collapse:collapse;width:100%;margin:1.2em 0;font-size:.95em}' +
    'th,td{border:1px solid var(--line);padding:.5em .8em;text-align:left}' +
    'th{background:#f4f5f9;font-weight:700}tr:nth-child(even) td{background:#fafbfd}' +
    '.doc-foot{max-width:780px;margin:0 auto;padding:24px 28px 60px;color:var(--soft);font-size:13px;border-top:1px solid var(--line)}' +
    '@media print{.doc{padding:0;max-width:none}.doc-foot{display:none}pre,blockquote,img,table{break-inside:avoid}}';

  global.InkwellExport = {
    exportMarkdown: exportMarkdown,
    exportHtml: exportHtml,
    printDocument: printDocument,
    buildStandaloneHtml: buildStandaloneHtml,
    download: download
  };

})(typeof window !== 'undefined' ? window : this);
