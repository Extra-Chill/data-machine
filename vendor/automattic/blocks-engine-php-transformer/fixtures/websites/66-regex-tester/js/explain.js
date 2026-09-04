/* =========================================================
   RELAY — Explain
   Walks the AST from parser.js and produces a flat list of
   explanation rows: { raw, desc, depth, kind }.
   Rendered as a nested, plain-English breakdown of the regex.
   ========================================================= */

(function (global) {
  'use strict';

  function charName(ch) {
    var map = {
      ' ': 'a space', '\n': 'a newline (\\n)', '\r': 'a carriage return (\\r)',
      '\t': 'a tab (\\t)', '\f': 'a form feed (\\f)', '\v': 'a vertical tab (\\v)',
      '\0': 'a NUL character', '\b': 'a backspace'
    };
    if (map[ch]) return map[ch];
    if (ch === undefined) return 'nothing';
    return '"' + ch + '"';
  }

  function quant(min, max, greedy) {
    var base;
    if (min === 0 && max === Infinity) base = 'zero or more times';
    else if (min === 1 && max === Infinity) base = 'one or more times';
    else if (min === 0 && max === 1) base = 'optional (zero or one time)';
    else if (max === Infinity) base = 'at least ' + min + ' times';
    else if (min === max) base = 'exactly ' + min + ' time' + (min === 1 ? '' : 's');
    else base = 'between ' + min + ' and ' + max + ' times';
    base += greedy ? ', as many as possible (greedy)' : ', as few as possible (lazy)';
    return base;
  }

  var ANCHOR = {
    start: 'Start of the string (or start of a line with the m flag)',
    end: 'End of the string (or end of a line with the m flag)',
    wordB: 'A word boundary — the edge between a word char (\\w) and a non-word char',
    nonWordB: 'A position that is NOT a word boundary'
  };

  var GROUP_LABEL = {
    capturing: 'Capturing group',
    noncapturing: 'Non-capturing group — groups without saving the match',
    named: 'Named capturing group',
    lookahead: 'Positive lookahead — must be followed by, but does not consume',
    neglookahead: 'Negative lookahead — must NOT be followed by',
    lookbehind: 'Positive lookbehind — must be preceded by',
    neglookbehind: 'Negative lookbehind — must NOT be preceded by'
  };

  function explain(ast) {
    var rows = [];
    walk(ast, 0, rows);
    return rows;
  }

  function push(rows, raw, desc, depth, kind) {
    rows.push({ raw: raw, desc: desc, depth: depth, kind: kind || 'token' });
  }

  function walk(node, depth, rows) {
    switch (node.type) {
      case 'sequence':
        node.items.forEach(function (it) { walk(it, depth, rows); });
        break;

      case 'alternation':
        push(rows, '|', 'Alternation — match any ONE of the following ' + node.options.length + ' branches:', depth, 'group');
        node.options.forEach(function (opt, i) {
          push(rows, 'branch ' + (i + 1), 'Option ' + (i + 1), depth + 1, 'branch');
          walk(opt, depth + 2, rows);
        });
        break;

      case 'literal':
        push(rows, node.raw, 'The character ' + charName(node.value) + (node.escaped ? ' (escaped)' : ''), depth, 'literal');
        break;

      case 'any':
        push(rows, '.', 'Any single character except a line break (matches line breaks too with the s flag)', depth, 'meta');
        break;

      case 'anchor':
        push(rows, node.raw, ANCHOR[node.kind], depth, 'anchor');
        break;

      case 'escape':
        if (node.kind === 'class') push(rows, node.raw, 'Any ' + node.label + (node.shorthand === 'w' ? ' ([A-Za-z0-9_])' : node.shorthand === 'd' ? ' ([0-9])' : ''), depth, 'meta');
        else if (node.kind === 'control') push(rows, node.raw, 'Matches ' + charName(node.value), depth, 'meta');
        else if (node.kind === 'unicodeprop') push(rows, node.raw, (node.negated ? 'Any character WITHOUT' : 'Any character with') + ' the Unicode property ' + node.prop, depth, 'meta');
        else push(rows, node.raw, 'Escape sequence', depth, 'meta');
        break;

      case 'backref':
        if (node.byName) push(rows, node.raw, 'Backreference to the text captured by named group "' + node.ref + '"', depth, 'backref');
        else push(rows, node.raw, 'Backreference to the text captured by group #' + node.ref, depth, 'backref');
        break;

      case 'charclass':
        push(rows, node.raw, describeClass(node), depth, 'class');
        break;

      case 'group':
        var label = GROUP_LABEL[node.kind];
        if (node.kind === 'capturing') label += ' #' + node.captureNum + ' — saves the matched text';
        else if (node.kind === 'named') label += ' "' + node.name + '" (#' + node.captureNum + ')';
        push(rows, node.kind === 'named' ? '(?<' + node.name + '>…)' : node.raw.slice(0, node.raw.indexOf(')') === -1 ? 3 : undefined), label, depth, 'group');
        // re-set raw to a tidy opener
        rows[rows.length - 1].raw = groupOpener(node);
        walk(node.body, depth + 1, rows);
        break;

      case 'quantifier':
        // Describe the child first, then attach the repetition note.
        walk(node.child, depth, rows);
        push(rows, node.raw.replace(node.child.raw, '').trim() || node.raw, '↳ Repeat the previous token ' + quant(node.min, node.max, node.greedy), depth, 'quant');
        break;

      default:
        push(rows, node.raw || '?', 'Unknown construct', depth, 'token');
    }
  }

  function groupOpener(node) {
    switch (node.kind) {
      case 'capturing': return '( … )';
      case 'noncapturing': return '(?: … )';
      case 'named': return '(?<' + node.name + '> … )';
      case 'lookahead': return '(?= … )';
      case 'neglookahead': return '(?! … )';
      case 'lookbehind': return '(?<= … )';
      case 'neglookbehind': return '(?<! … )';
      default: return node.raw;
    }
  }

  function describeClass(node) {
    var bits = node.parts.map(function (p) {
      if (p.kind === 'range') return '"' + p.from + '" to "' + p.to + '"';
      if (p.kind === 'class') return p.label;
      return charName(p.value);
    });
    var inner = bits.join(', ');
    if (node.negated) return 'Any single character that is NOT one of: ' + inner;
    return 'Any single character from the set: ' + inner;
  }

  global.RelayExplain = { explain: explain };

})(typeof window !== 'undefined' ? window : this);
