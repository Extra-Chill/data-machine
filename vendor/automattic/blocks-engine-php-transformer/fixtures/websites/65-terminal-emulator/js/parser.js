/* =========================================================
   Tideglass Shell — Command-line parser
   Tokenizes a line honoring single/double quotes, splits on
   pipes, extracts redirections, and expands env vars + $?.
   ---------------------------------------------------------
   parse(line, env) -> {
     pipeline: [ { argv:[...], redirects:[{op:'>'|'>>'|'<', target}] } ],
     error: null | string
   }
   ========================================================= */
'use strict';

window.TG = window.TG || {};

TG.Parser = (function () {

  // Expand $VAR, ${VAR}, $? against the env map.
  function expandVars(text, env) {
    return text.replace(/\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)|\$\?/g,
      (m, braced, bare) => {
        if (m === '$?') return env.__status !== undefined ? String(env.__status) : '0';
        const name = braced || bare;
        return env[name] !== undefined ? env[name] : '';
      });
  }

  /* Tokenize one line into typed tokens:
       {t:'word', v}  {t:'pipe'}  {t:'redir', op}
     Quoting rules:
       'single'  -> literal, no expansion
       "double"  -> expansion happens, but no word splitting inside
     Returns {tokens} or throws on unterminated quote. */
  function tokenize(line, env) {
    const tokens = [];
    let i = 0;
    const n = line.length;

    function pushWord(parts) {
      // parts: array of {raw, expand}. We keep the parts so the value can
      // be (re)materialized against the *current* env at execution time —
      // this is what makes `false; echo $?` see the right status.
      let value = '';
      for (const p of parts) value += p.expand ? expandVars(p.raw, env) : p.raw;
      tokens.push({ t: 'word', v: value, parts });
    }

    while (i < n) {
      const c = line[i];

      if (c === ' ' || c === '\t') { i++; continue; }

      if (c === '|') {
        if (line[i + 1] === '|') { tokens.push({ t: 'op', op: '||' }); i += 2; }
        else { tokens.push({ t: 'pipe' }); i++; }
        continue;
      }
      if (c === '&' && line[i + 1] === '&') { tokens.push({ t: 'op', op: '&&' }); i += 2; continue; }
      if (c === ';') { tokens.push({ t: 'op', op: ';' }); i++; continue; }

      if (c === '>' ) {
        if (line[i + 1] === '>') { tokens.push({ t: 'redir', op: '>>' }); i += 2; }
        else { tokens.push({ t: 'redir', op: '>' }); i++; }
        continue;
      }
      if (c === '<') { tokens.push({ t: 'redir', op: '<' }); i++; continue; }

      if (c === '#') break; // comment to end of line

      // Read a word made of possibly-mixed quoted/unquoted segments
      const parts = [];
      while (i < n) {
        const ch = line[i];
        if (ch === ' ' || ch === '\t' || ch === '|' || ch === '>' || ch === '<' || ch === ';' || ch === '&') break;

        if (ch === "'") {
          i++;
          let buf = '';
          while (i < n && line[i] !== "'") { buf += line[i]; i++; }
          if (i >= n) throw new Error('unexpected EOF while looking for matching `\'`');
          i++; // closing quote
          parts.push({ raw: buf, expand: false });
        } else if (ch === '"') {
          i++;
          let buf = '';
          while (i < n && line[i] !== '"') {
            if (line[i] === '\\' && (line[i + 1] === '"' || line[i + 1] === '\\' || line[i + 1] === '$')) {
              buf += line[i + 1]; i += 2;
            } else { buf += line[i]; i++; }
          }
          if (i >= n) throw new Error('unexpected EOF while looking for matching `"`');
          i++; // closing quote
          parts.push({ raw: buf, expand: true });
        } else if (ch === '\\') {
          if (i + 1 < n) { parts.push({ raw: line[i + 1], expand: false }); i += 2; }
          else { i++; }
        } else {
          let buf = '';
          while (i < n) {
            const cc = line[i];
            if (cc === ' ' || cc === '\t' || cc === '|' || cc === '>' || cc === '<' ||
                cc === ';' || cc === '&' || cc === "'" || cc === '"' || cc === '\\') break;
            buf += cc; i++;
          }
          parts.push({ raw: buf, expand: true });
        }
      }
      pushWord(parts);
    }
    return tokens;
  }

  function parse(line, env) {
    // result.segments = [ { pipeline:[cmd,...], joiner:';'|'&&'|'||'|null } ]
    // joiner describes how THIS segment connects to the NEXT one.
    const result = { segments: [], error: null };
    let tokens;
    try { tokens = tokenize(line, env || {}); }
    catch (e) { result.error = e.message; return result; }
    if (!tokens.length) return result;

    let pipeline = [];
    let current = { argv: [], argvParts: [], redirects: [] };

    function flushCmd() {
      if (current.argv.length || current.redirects.length) pipeline.push(current);
      current = { argv: [], argvParts: [], redirects: [] };
    }
    function flushSegment(joiner) {
      flushCmd();
      if (pipeline.length) result.segments.push({ pipeline, joiner });
      pipeline = [];
    }

    for (let k = 0; k < tokens.length; k++) {
      const tok = tokens[k];
      if (tok.t === 'op') {
        if (!current.argv.length && !pipeline.length) { result.error = `syntax error near \`${tok.op}\``; return result; }
        flushSegment(tok.op);
      } else if (tok.t === 'pipe') {
        if (!current.argv.length) { result.error = 'syntax error near `|`'; return result; }
        flushCmd();
      } else if (tok.t === 'redir') {
        const next = tokens[k + 1];
        if (!next || next.t !== 'word') { result.error = `syntax error: expected filename after \`${tok.op}\``; return result; }
        current.redirects.push({ op: tok.op, target: next.v });
        k++;
      } else {
        current.argv.push(tok.v);
        current.argvParts.push(tok.parts);
      }
    }
    flushSegment(null);

    for (const seg of result.segments) {
      if (seg.pipeline.some(c => !c.argv.length)) {
        result.error = 'syntax error: empty command in pipeline';
        result.segments = [];
        break;
      }
    }
    return result;
  }

  // Re-materialize a word's parts against the current env (used so $VARS
  // and $? in a later `;`/`&&` segment reflect up-to-date state).
  function materialize(parts, env) {
    if (!parts) return null;
    let value = '';
    for (const p of parts) value += p.expand ? expandVars(p.raw, env) : p.raw;
    return value;
  }

  return { parse, tokenize, expandVars, materialize };
})();
