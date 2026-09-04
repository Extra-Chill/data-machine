/* =========================================================
   Tideglass Shell — Command implementations
   Every command is a function (ctx) -> { out, err, status }
   ctx = {
     argv,        // ['ls','-l',...]
     stdin,       // string piped in (may be '')
     env,         // env map (mutable for export)
     shell,       // back-reference for cwd, history, special actions
     fs           // TG.FS
   }
   Commands write strings; the runner handles redirection,
   pipes, and rendering. Keep them pure-ish: no DOM here
   except via shell.* hooks for screen-affecting commands.
   ========================================================= */
'use strict';

window.TG = window.TG || {};

TG.Commands = (function () {
  const FS = TG.FS;

  const ok  = (out = '') => ({ out, err: '', status: 0 });
  const fail = (err, status = 1) => ({ out: '', err, status });

  // ---- small helpers ------------------------------------------------
  function splitFlags(argv) {
    const flags = new Set();
    const positional = [];
    for (let i = 1; i < argv.length; i++) {
      const a = argv[i];
      if (a === '--') { positional.push(...argv.slice(i + 1)); break; }
      if (a.length > 1 && a[0] === '-' && a !== '-') {
        for (const ch of a.slice(1)) flags.add(ch);
      } else positional.push(a);
    }
    return { flags, positional };
  }

  function lines(s) { return s.length ? s.replace(/\n$/, '').split('\n') : []; }

  function humanMode(node) {
    return node.type === 'dir' ? 'drwxr-xr-x' : '-rw-r--r--';
  }
  function sizeOf(node) {
    return node.type === 'file' ? node.content.length
      : Object.keys(node.children).length;
  }
  function fmtTime(mtime) {
    const d = new Date(mtime * 1000);
    const mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
    const day = String(d.getDate()).padStart(2, ' ');
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    return `${mon} ${day} ${hh}:${mm}`;
  }
  function pad(s, n) { s = String(s); return s.length >= n ? s : ' '.repeat(n - s.length) + s; }

  // Resolve positional args into nodes; expand globs upstream in runner.
  function nodeAt(ctx, p) {
    return FS.getNode(FS.resolve(ctx.shell.cwd, p));
  }

  // ===================================================================
  //  THE COMMAND TABLE
  // ===================================================================
  const CMD = {};

  CMD.help = {
    desc: 'list available commands',
    run(ctx) {
      const groups = [
        ['Filesystem', ['ls','cd','pwd','cat','tree','find','stat','du']],
        ['Edit',       ['mkdir','touch','rm','mv','cp','echo']],
        ['Text',       ['head','tail','wc','grep','sort','uniq','rev','tac']],
        ['Shell',      ['help','man','history','clear','env','export','alias','which','date','whoami','uname']],
        ['Fun',        ['neofetch','cowsay','fortune','tour','sl','matrix']],
      ];
      let out = 'Tideglass shell — built-in commands\n\n';
      for (const [g, cmds] of groups) {
        out += g.padEnd(12) + cmds.join('  ') + '\n';
      }
      out += '\nPipes ( | ), redirects ( > >> < ), and $VARS work.\n';
      out += "Try:  ls -l | grep .md | wc -l\n";
      out += "Type 'man <cmd>' for details, or 'tour' for a guided walkthrough.\n";
      return ok(out);
    }
  };

  CMD.pwd = { desc: 'print working directory', run(ctx) { return ok(ctx.shell.cwd + '\n'); } };

  CMD.whoami = { desc: 'print current user', run(ctx) { return ok((ctx.env.USER || 'mara') + '\n'); } };

  CMD.uname = {
    desc: 'print system information',
    run(ctx) {
      const { flags } = splitFlags(ctx.argv);
      if (flags.has('a')) return ok('Tideglass harborlight 1.4.0-glass x86_64 GNU/Browser\n');
      return ok('Tideglass\n');
    }
  };

  CMD.date = {
    desc: 'print the current date and time',
    run() {
      const d = new Date();
      const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
      const mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      const z = n => String(n).padStart(2, '0');
      return ok(`${days[d.getDay()]} ${mon[d.getMonth()]} ${z(d.getDate())} ${z(d.getHours())}:${z(d.getMinutes())}:${z(d.getSeconds())} ${d.getFullYear()}\n`);
    }
  };

  CMD.echo = {
    desc: 'write arguments to standard output',
    run(ctx) {
      const { flags, positional } = splitFlags(ctx.argv);
      const text = positional.join(' ');
      return ok(text + (flags.has('n') ? '' : '\n'));
    }
  };

  CMD.clear = { desc: 'clear the terminal screen', run(ctx) { ctx.shell.clearScreen(); return ok(''); } };

  CMD.true  = { desc: 'do nothing, successfully', run() { return ok(''); } };
  CMD.false = { desc: 'do nothing, unsuccessfully', run() { return fail('', 1); } };
  CMD.yes   = {
    desc: 'output a string repeatedly (capped)',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      const s = positional.join(' ') || 'y';
      return ok((s + '\n').repeat(20));
    }
  };

  CMD.ls = {
    desc: 'list directory contents',
    run(ctx) {
      const { flags, positional } = splitFlags(ctx.argv);
      const showAll = flags.has('a');
      const long = flags.has('l');
      const targets = positional.length ? positional : ['.'];
      let out = '';
      let status = 0;
      const blocks = [];
      for (const tp of targets) {
        const abs = FS.resolve(ctx.shell.cwd, tp);
        const node = FS.getNode(abs);
        if (!node) { blocks.push({ err: `ls: cannot access '${tp}': No such file or directory` }); status = 2; continue; }
        let entries;
        if (node.type === 'file') {
          entries = [{ name: tp, node }];
        } else {
          const names = Object.keys(node.children).sort();
          entries = names
            .filter(n => showAll || !n.startsWith('.'))
            .map(n => ({ name: n, node: node.children[n] }));
          if (showAll) entries = [{ name: '.', node }, { name: '..', node }].concat(entries);
        }
        let body;
        if (long) {
          body = entries.map(e => {
            const cls = e.node.type === 'dir' ? 'class="t-dir"' : '';
            return `${humanMode(e.node)} ${pad(sizeOf(e.node), 5)} ${fmtTime(e.node.mtime)} <span ${cls}>${esc(e.name)}${e.node.type==='dir'?'/':''}</span>`;
          }).join('\n');
        } else {
          body = entries.map(e => {
            const cls = e.node.type === 'dir' ? 'class="t-dir"' : '';
            return `<span ${cls}>${esc(e.name)}${e.node.type==='dir'?'/':''}</span>`;
          }).join('  ');
        }
        blocks.push({ header: targets.length > 1 ? `${tp}:` : '', body });
      }
      const errs = blocks.filter(b => b.err).map(b => b.err).join('\n');
      const goods = blocks.filter(b => !b.err);
      out = goods.map(b => (b.header ? b.header + '\n' : '') + b.body).join('\n\n');
      if (out) out += '\n';
      return { out, err: errs ? errs + '\n' : '', status };
    }
  };

  CMD.cd = {
    desc: 'change the working directory',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      let target = positional[0];
      if (!target || target === '~') target = ctx.env.HOME || '/home/mara';
      if (target === '-') target = ctx.shell.prevCwd || ctx.shell.cwd;
      const abs = FS.resolve(ctx.shell.cwd, target);
      const node = FS.getNode(abs);
      if (!node) return fail(`cd: no such file or directory: ${target}\n`);
      if (node.type !== 'dir') return fail(`cd: not a directory: ${target}\n`);
      ctx.shell.setCwd(abs || '/');
      return ok('');
    }
  };

  CMD.cat = {
    desc: 'concatenate and print files',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      if (!positional.length) return ok(ctx.stdin); // cat with no args echoes stdin
      let out = '', err = '', status = 0;
      for (const p of positional) {
        const node = nodeAt(ctx, p);
        if (!node) { err += `cat: ${p}: No such file or directory\n`; status = 1; continue; }
        if (node.type === 'dir') { err += `cat: ${p}: Is a directory\n`; status = 1; continue; }
        out += node.content;
        if (out && !out.endsWith('\n')) out += '\n';
      }
      return { out, err, status };
    }
  };

  // Shared option parser for head/tail: returns {n, files}.
  // Supports "-n N", "-N", and a positional count is treated as a file.
  function headTailArgs(argv) {
    let n = 10;
    const files = [];
    for (let i = 1; i < argv.length; i++) {
      const a = argv[i];
      if (a === '-n') { n = parseInt(argv[++i], 10) || 10; }
      else if (/^-\d+$/.test(a)) { n = parseInt(a.slice(1), 10); }
      else files.push(a);
    }
    return { n, files };
  }

  CMD.head = {
    desc: 'output the first part of files',
    run(ctx) {
      const { n, files } = headTailArgs(ctx.argv);
      const src = files.length ? joinFiles(ctx, files) : { out: ctx.stdin, err: '', status: 0 };
      const ls = lines(src.out);
      return { out: ls.slice(0, n).join('\n') + (ls.length ? '\n' : ''), err: src.err, status: src.status };
    }
  };

  CMD.tail = {
    desc: 'output the last part of files',
    run(ctx) {
      const { n, files } = headTailArgs(ctx.argv);
      const src = files.length ? joinFiles(ctx, files) : { out: ctx.stdin, err: '', status: 0 };
      const ls = lines(src.out);
      return { out: ls.slice(Math.max(0, ls.length - n)).join('\n') + (ls.length ? '\n' : ''), err: src.err, status: src.status };
    }
  };

  function joinFiles(ctx, files) {
    let out = '', err = '', status = 0;
    for (const p of files) {
      const node = nodeAt(ctx, p);
      if (!node) { err += `${ctx.argv[0]}: cannot open '${p}'\n`; status = 1; continue; }
      if (node.type === 'dir') { err += `${ctx.argv[0]}: ${p}: Is a directory\n`; status = 1; continue; }
      out += node.content;
      if (out && !out.endsWith('\n')) out += '\n';
    }
    return { out, err, status };
  }

  CMD.wc = {
    desc: 'count lines, words, and bytes',
    run(ctx) {
      const { flags, positional } = splitFlags(ctx.argv);
      const want = { l: flags.has('l'), w: flags.has('w'), c: flags.has('c') };
      const any = want.l || want.w || want.c;
      function count(text) {
        const l = text === '' ? 0 : text.replace(/\n$/, '').split('\n').length;
        const w = (text.match(/\S+/g) || []).length;
        const c = text.length;
        return { l, w, c };
      }
      function fmt(cnt, label) {
        const cols = [];
        if (!any || want.l) cols.push(pad(cnt.l, 7));
        if (!any || want.w) cols.push(pad(cnt.w, 7));
        if (!any || want.c) cols.push(pad(cnt.c, 7));
        return cols.join('') + (label ? ' ' + label : '');
      }
      if (!positional.length) {
        return ok(fmt(count(ctx.stdin), '') + '\n');
      }
      let out = '', err = '', status = 0;
      const total = { l: 0, w: 0, c: 0 };
      for (const p of positional) {
        const node = nodeAt(ctx, p);
        if (!node || node.type === 'dir') { err += `wc: ${p}: No such file\n`; status = 1; continue; }
        const cnt = count(node.content);
        total.l += cnt.l; total.w += cnt.w; total.c += cnt.c;
        out += fmt(cnt, p) + '\n';
      }
      if (positional.length > 1) out += fmt(total, 'total') + '\n';
      return { out, err, status };
    }
  };

  CMD.grep = {
    desc: 'search text for a pattern',
    run(ctx) {
      const { flags, positional } = splitFlags(ctx.argv);
      if (!positional.length) return fail('usage: grep [-in v c] PATTERN [FILE...]\n', 2);
      const pat = positional[0];
      let rx;
      try {
        rx = new RegExp(pat, flags.has('i') ? 'i' : '');
      } catch (e) {
        // treat as literal
        rx = new RegExp(pat.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), flags.has('i') ? 'i' : '');
      }
      const invert = flags.has('v');
      const countOnly = flags.has('c');
      const withNum = flags.has('n');
      const files = positional.slice(1);
      const sources = [];
      if (files.length) {
        for (const p of files) {
          const node = nodeAt(ctx, p);
          if (!node || node.type === 'dir') { sources.push({ name: p, err: true }); continue; }
          sources.push({ name: p, text: node.content });
        }
      } else {
        sources.push({ name: null, text: ctx.stdin });
      }
      let out = '', err = '', status = 1;
      const multi = files.length > 1;
      for (const s of sources) {
        if (s.err) { err += `grep: ${s.name}: No such file or directory\n`; continue; }
        const ls = lines(s.text);
        let hits = 0;
        ls.forEach((ln, idx) => {
          const m = rx.test(ln);
          if (m !== invert) {
            hits++;
            if (!countOnly) {
              let prefix = '';
              if (multi) prefix += s.name + ':';
              if (withNum) prefix += (idx + 1) + ':';
              out += prefix + highlight(ln, rx, invert) + '\n';
            }
          }
        });
        if (countOnly) out += (multi ? s.name + ':' : '') + hits + '\n';
        if (hits) status = 0;
      }
      return { out, err, status };
    }
  };

  function highlight(line, rx, invert) {
    if (invert) return esc(line);
    const g = new RegExp(rx.source, rx.flags.includes('g') ? rx.flags : rx.flags + 'g');
    return esc(line).replace(g, m => `<span class="t-match">${m}</span>`);
  }

  CMD.sort = {
    desc: 'sort lines of text',
    run(ctx) {
      const { flags, positional } = splitFlags(ctx.argv);
      const src = positional.length ? joinFiles(ctx, positional) : { out: ctx.stdin, err: '', status: 0 };
      let ls = lines(src.out);
      if (flags.has('n')) ls.sort((a, b) => parseFloat(a) - parseFloat(b) || a.localeCompare(b));
      else ls.sort((a, b) => a.localeCompare(b));
      if (flags.has('r')) ls.reverse();
      if (flags.has('u')) ls = ls.filter((x, i) => i === 0 || x !== ls[i - 1]);
      return { out: ls.join('\n') + (ls.length ? '\n' : ''), err: src.err, status: src.status };
    }
  };

  CMD.uniq = {
    desc: 'report or filter repeated lines',
    run(ctx) {
      const { flags, positional } = splitFlags(ctx.argv);
      const src = positional.length ? joinFiles(ctx, positional) : { out: ctx.stdin, err: '', status: 0 };
      const ls = lines(src.out);
      const out = [];
      let prev = null, run = 0;
      for (const ln of ls) {
        if (ln === prev) { run++; continue; }
        if (prev !== null) out.push(flags.has('c') ? `${pad(run, 7)} ${prev}` : prev);
        prev = ln; run = 1;
      }
      if (prev !== null) out.push(flags.has('c') ? `${pad(run, 7)} ${prev}` : prev);
      let res = out;
      if (flags.has('d')) res = res.filter(l => (flags.has('c') ? parseInt(l) > 1 : false));
      return { out: res.join('\n') + (res.length ? '\n' : ''), err: src.err, status: src.status };
    }
  };

  CMD.rev = {
    desc: 'reverse characters of each line',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      const src = positional.length ? joinFiles(ctx, positional) : { out: ctx.stdin, err: '', status: 0 };
      const out = lines(src.out).map(l => l.split('').reverse().join('')).join('\n');
      return { out: out + (out ? '\n' : ''), err: src.err, status: src.status };
    }
  };

  CMD.tac = {
    desc: 'print files line by line in reverse',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      const src = positional.length ? joinFiles(ctx, positional) : { out: ctx.stdin, err: '', status: 0 };
      const out = lines(src.out).reverse().join('\n');
      return { out: out + (out ? '\n' : ''), err: src.err, status: src.status };
    }
  };

  CMD.mkdir = {
    desc: 'make directories',
    run(ctx) {
      const { flags, positional } = splitFlags(ctx.argv);
      if (!positional.length) return fail('mkdir: missing operand\n', 1);
      let err = '', status = 0;
      for (const p of positional) {
        const r = FS.mkdir(ctx.shell.cwd, p, flags.has('p'));
        if (!r.ok) { err += `mkdir: ${r.err}\n`; status = 1; }
      }
      return { out: '', err, status };
    }
  };

  CMD.touch = {
    desc: 'create empty files or update timestamps',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      if (!positional.length) return fail('touch: missing file operand\n', 1);
      let err = '', status = 0;
      for (const p of positional) {
        const r = FS.touch(ctx.shell.cwd, p);
        if (!r.ok) { err += `touch: ${r.err}\n`; status = 1; }
      }
      return { out: '', err, status };
    }
  };

  CMD.rm = {
    desc: 'remove files or directories',
    run(ctx) {
      const { flags, positional } = splitFlags(ctx.argv);
      if (!positional.length) return fail('rm: missing operand\n', 1);
      const recursive = flags.has('r') || flags.has('R') || flags.has('f');
      let err = '', status = 0;
      // The fun easter egg: rm -rf / on the fake FS
      const abs0 = FS.resolve(ctx.shell.cwd, positional[0]);
      if ((flags.has('r') || flags.has('f')) && abs0 === '/' ) {
        return ok("rm: it's all fake anyway — but nice try.  (use the reset button if you really want a clean slate)\n");
      }
      for (const p of positional) {
        const r = FS.remove(ctx.shell.cwd, p, recursive);
        if (!r.ok) { err += `rm: ${r.err}\n`; status = 1; }
      }
      return { out: '', err, status };
    }
  };

  CMD.mv = {
    desc: 'move or rename files',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      if (positional.length < 2) return fail('mv: missing destination operand\n', 1);
      const dst = positional.pop();
      let err = '', status = 0;
      for (const src of positional) {
        const r = FS.move(ctx.shell.cwd, src, dst, false);
        if (!r.ok) { err += `mv: ${r.err}\n`; status = 1; }
      }
      return { out: '', err, status };
    }
  };

  CMD.cp = {
    desc: 'copy files',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      if (positional.length < 2) return fail('cp: missing destination operand\n', 1);
      const dst = positional.pop();
      let err = '', status = 0;
      for (const src of positional) {
        const r = FS.move(ctx.shell.cwd, src, dst, true);
        if (!r.ok) { err += `cp: ${r.err}\n`; status = 1; }
      }
      return { out: '', err, status };
    }
  };

  CMD.stat = {
    desc: 'display file status',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      if (!positional.length) return fail('stat: missing operand\n', 1);
      let out = '', err = '', status = 0;
      for (const p of positional) {
        const node = nodeAt(ctx, p);
        if (!node) { err += `stat: cannot stat '${p}': No such file or directory\n`; status = 1; continue; }
        const abs = FS.resolve(ctx.shell.cwd, p);
        out += `  File: ${abs}\n`;
        out += `  Size: ${pad(sizeOf(node), 8)}   ${node.type === 'dir' ? 'directory' : 'regular file'}\n`;
        out += `Access: (${humanMode(node)})\n`;
        out += `Modify: ${new Date(node.mtime * 1000).toISOString()}\n`;
      }
      return { out, err, status };
    }
  };

  CMD.du = {
    desc: 'estimate file space usage',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      const start = positional[0] || '.';
      const node = nodeAt(ctx, start);
      if (!node) return fail(`du: cannot access '${start}': No such file or directory\n`);
      let out = '';
      function walk(n, path) {
        if (n.type === 'file') { out += `${pad(Math.ceil(n.content.length / 1024) || 1, 6)}  ${path}\n`; return n.content.length; }
        let total = 0;
        for (const k of Object.keys(n.children).sort()) total += walk(n.children[k], path + '/' + k);
        out += `${pad(Math.ceil(total / 1024) || 1, 6)}  ${path}\n`;
        return total;
      }
      walk(node, start === '.' ? ctx.shell.cwd : FS.resolve(ctx.shell.cwd, start));
      return ok(out);
    }
  };

  CMD.tree = {
    desc: 'display directories as a tree',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      const start = positional[0] || '.';
      const node = nodeAt(ctx, start);
      if (!node) return fail(`tree: ${start}: No such file or directory\n`);
      let dirs = 0, files = 0;
      let out = `<span class="t-dir">${esc(start)}</span>\n`;
      function walk(n, prefix) {
        if (n.type !== 'dir') return;
        const keys = Object.keys(n.children).filter(k => !k.startsWith('.')).sort();
        keys.forEach((k, i) => {
          const child = n.children[k];
          const last = i === keys.length - 1;
          const branch = last ? '└── ' : '├── ';
          const isd = child.type === 'dir';
          if (isd) dirs++; else files++;
          out += prefix + branch + (isd ? `<span class="t-dir">${esc(k)}</span>` : esc(k)) + '\n';
          if (isd) walk(child, prefix + (last ? '    ' : '│   '));
        });
      }
      walk(node, '');
      out += `\n${dirs} directories, ${files} files\n`;
      return ok(out);
    }
  };

  CMD.find = {
    desc: 'search for files in a directory tree',
    run(ctx) {
      const argv = ctx.argv;
      const start = (argv[1] && !argv[1].startsWith('-')) ? argv[1] : '.';
      let namePat = null, typeFilter = null;
      const nameIdx = argv.indexOf('-name');
      if (nameIdx >= 0) namePat = argv[nameIdx + 1];
      const typeIdx = argv.indexOf('-type');
      if (typeIdx >= 0) typeFilter = argv[typeIdx + 1];
      const startNode = nodeAt(ctx, start);
      if (!startNode) return fail(`find: '${start}': No such file or directory\n`);
      const rx = namePat ? globRe(namePat) : null;
      let out = '';
      function walk(n, path) {
        const base = FS.baseName(path) || path;
        const typeMatch = !typeFilter || (typeFilter === 'd' && n.type === 'dir') || (typeFilter === 'f' && n.type === 'file');
        const nameMatch = !rx || rx.test(base);
        if (typeMatch && nameMatch) out += (n.type === 'dir' ? `<span class="t-dir">${esc(path)}</span>` : esc(path)) + '\n';
        if (n.type === 'dir') for (const k of Object.keys(n.children).sort()) walk(n.children[k], path === '/' ? '/' + k : path + '/' + k);
      }
      walk(startNode, start);
      return ok(out);
    }
  };

  function globRe(g) {
    let re = '^';
    for (const ch of g) {
      if (ch === '*') re += '.*';
      else if (ch === '?') re += '.';
      else re += ch.replace(/[.+^${}()|[\]\\]/g, '\\$&');
    }
    return new RegExp(re + '$');
  }

  CMD.history = {
    desc: 'show command history',
    run(ctx) {
      const h = ctx.shell.history;
      let out = '';
      h.forEach((cmd, i) => { out += `${pad(i + 1, 5)}  ${esc(cmd)}\n`; });
      return ok(out);
    }
  };

  CMD.env = {
    desc: 'print environment variables',
    run(ctx) {
      let out = '';
      for (const k of Object.keys(ctx.env).sort()) {
        if (k.startsWith('__')) continue;
        out += `${k}=${ctx.env[k]}\n`;
      }
      return ok(out);
    }
  };

  CMD.export = {
    desc: 'set an environment variable',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      if (!positional.length) return CMD.env.run(ctx);
      for (const a of positional) {
        const eq = a.indexOf('=');
        if (eq < 0) continue;
        ctx.env[a.slice(0, eq)] = a.slice(eq + 1);
      }
      ctx.shell.saveEnv();
      return ok('');
    }
  };

  CMD.alias = {
    desc: 'show command aliases (display only)',
    run(ctx) {
      return ok("alias ll='ls -l'\nalias la='ls -a'\nalias ..='cd ..'\n");
    }
  };

  CMD.which = {
    desc: 'locate a command',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      let out = '', status = 0;
      for (const p of positional) {
        if (CMD[p]) out += `/bin/${p}\n`;
        else { status = 1; }
      }
      return { out, err: '', status };
    }
  };

  CMD.man = {
    desc: 'display the manual for a command',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      const name = positional[0];
      if (!name) return ok("What manual page do you want?\nTry: man ls   (or open docs.html for the full reference)\n");
      const m = TG.MAN && TG.MAN[name];
      if (!m) return fail(`No manual entry for ${name}\n`);
      let out = `<span class="t-bold">${name.toUpperCase()}(1)</span>${' '.repeat(40)}Tideglass Manual\n\n`;
      out += `<span class="t-bold">NAME</span>\n       ${name} — ${m.summary}\n\n`;
      out += `<span class="t-bold">SYNOPSIS</span>\n       ${esc(m.synopsis)}\n\n`;
      out += `<span class="t-bold">DESCRIPTION</span>\n${m.description.split('\n').map(l => '       ' + l).join('\n')}\n`;
      if (m.examples) out += `\n<span class="t-bold">EXAMPLES</span>\n${m.examples.split('\n').map(l => '       ' + l).join('\n')}\n`;
      return ok(out);
    }
  };

  // ---- Fun commands -------------------------------------------------
  CMD.fortune = {
    desc: 'print a random adage',
    run(ctx) {
      const node = FS.getNode('/usr/share/fortunes');
      const pool = node ? lines(node.content) : ['No fortunes installed.'];
      const pick = pool[Math.floor(Math.random() * pool.length)];
      return ok(pick + '\n');
    }
  };

  CMD.cowsay = {
    desc: 'a cow says something',
    run(ctx) {
      const { positional } = splitFlags(ctx.argv);
      let text = positional.join(' ') || ctx.stdin.trim();
      if (!text) text = 'Moo.';
      const width = Math.min(40, Math.max(...text.split('\n').map(l => l.length), 4));
      const wrapped = wrap(text, width);
      const top = ' ' + '_'.repeat(width + 2);
      const bot = ' ' + '-'.repeat(width + 2);
      let bubble = top + '\n';
      if (wrapped.length === 1) {
        bubble += `< ${wrapped[0].padEnd(width)} >\n`;
      } else {
        wrapped.forEach((l, i) => {
          const lb = i === 0 ? '/' : i === wrapped.length - 1 ? '\\' : '|';
          const rb = i === 0 ? '\\' : i === wrapped.length - 1 ? '/' : '|';
          bubble += `${lb} ${l.padEnd(width)} ${rb}\n`;
        });
      }
      bubble += bot + '\n';
      const cow =
`        \\   ^__^
         \\  (oo)\\_______
            (__)\\       )\\/\\
                ||----w |
                ||     ||`;
      return ok(bubble + cow + '\n');
    }
  };

  function wrap(text, width) {
    const words = text.split(/\s+/);
    const out = [];
    let cur = '';
    for (const w of words) {
      if ((cur + ' ' + w).trim().length > width && cur) { out.push(cur); cur = w; }
      else cur = (cur + ' ' + w).trim();
    }
    if (cur) out.push(cur);
    return out.length ? out : [''];
  }

  CMD.neofetch = {
    desc: 'show system info with an ASCII logo',
    run(ctx) {
      const up = ctx.shell.uptime();
      const fileCount = countFiles(FS.root);
      const logo = [
        '<span class="t-accent">      .--.      </span>',
        '<span class="t-accent">    .\'    \'.    </span>',
        '<span class="t-accent">   /  ~~~~  \\   </span>',
        '<span class="t-accent">  |  /\\__/\\  |  </span>',
        '<span class="t-accent">  |  \\____/  |  </span>',
        '<span class="t-accent">   \\  ~~~~  /   </span>',
        '<span class="t-accent">    \'.____.\'    </span>',
        '<span class="t-accent">   ~~~~~~~~~~   </span>',
      ];
      const info = [
        `<span class="t-accent t-bold">${ctx.env.USER || 'mara'}@harborlight</span>`,
        `-------------------`,
        `<span class="t-bold">OS</span>:        Tideglass 1.4.0 (browser)`,
        `<span class="t-bold">Host</span>:      Lighthouse Workstation`,
        `<span class="t-bold">Kernel</span>:    glass 1.4.0-web`,
        `<span class="t-bold">Uptime</span>:    ${up}`,
        `<span class="t-bold">Shell</span>:     glass 1.4`,
        `<span class="t-bold">Files</span>:     ${fileCount} in virtual FS`,
        `<span class="t-bold">Theme</span>:     ${ctx.shell.scheme}`,
        `<span class="t-bold">CPU</span>:       Imaginary 6-core @ 0.0GHz`,
        `<span class="t-bold">Memory</span>:    1 browser tab`,
        ``,
        `<span class="sw0">███</span><span class="sw1">███</span><span class="sw2">███</span><span class="sw3">███</span><span class="sw4">███</span><span class="sw5">███</span>`,
      ];
      let out = '';
      const rows = Math.max(logo.length, info.length);
      for (let i = 0; i < rows; i++) {
        out += (logo[i] || ' '.repeat(16)) + '  ' + (info[i] || '') + '\n';
      }
      return ok(out);
    }
  };

  function countFiles(node) {
    if (node.type === 'file') return 1;
    let c = 0;
    for (const k of Object.keys(node.children)) c += countFiles(node.children[k]);
    return c;
  }

  CMD.sl = {
    desc: 'a steam locomotive (you meant ls)',
    run(ctx) {
      const train =
`      ====        ________                ___________
  _D _|  |_______/        \\__I_I_____===__|_________|
   |(_)---  |   H\\________/ |   |        =|___ ___|
   /     |  |   H  |  |     |   |         ||_| |_||
  |      |  |   H  |__--------------------| [___] |
  | ________|___H__/__|_____/[][]~\\_______|       |
  |/ |   |-----------I_____I [][] []  D   |=======|__
__/ =| o |=-O=====O=====O=====O \\ ____Y___________|__
 |/-=|___|=    ||    ||    ||    |_____/~\\___/
  \\_/      \\O=====O=====O=====O_/      \\_/`;
      return ok(train + '\n\n(you probably meant `ls`)\n');
    }
  };

  CMD.matrix = {
    desc: 'a tiny taste of the matrix',
    run(ctx) {
      ctx.shell.runMatrix && ctx.shell.runMatrix();
      return ok('');
    }
  };

  CMD.tour = {
    desc: 'a guided walkthrough of the shell',
    run(ctx) {
      ctx.shell.startTour && ctx.shell.startTour();
      return ok('');
    }
  };

  // ---- man pages used by `man` and docs.html ------------------------
  TG.MAN = {
    help:  { summary: 'list available commands', synopsis: 'help', description: 'Print every built-in command, grouped by purpose, plus a couple of\nexample pipelines. For details on one command use "man <name>".' },
    ls:    { summary: 'list directory contents', synopsis: 'ls [-l] [-a] [FILE...]', description: 'List information about the FILEs (the current directory by default).\nEntries are sorted alphabetically.\n  -l   use a long listing format (mode, size, time, name)\n  -a   include entries starting with .', examples: 'ls\nls -la projects\nls -l | grep .md' },
    cd:    { summary: 'change the working directory', synopsis: 'cd [DIR]', description: 'Change the current directory to DIR. With no argument, change to\n$HOME. "cd -" returns to the previous directory; ".." goes up.', examples: 'cd projects/tidewatch\ncd ..\ncd' },
    pwd:   { summary: 'print working directory', synopsis: 'pwd', description: 'Print the full path of the current working directory.' },
    cat:   { summary: 'concatenate and print files', synopsis: 'cat [FILE...]', description: 'Concatenate FILE(s) to standard output. With no FILE, copy stdin\n(useful at the end of a pipe).', examples: 'cat README.txt\ncat notes/*.md | wc -l' },
    echo:  { summary: 'write arguments to output', synopsis: 'echo [-n] [STRING...]', description: 'Print STRING(s) separated by spaces, followed by a newline.\n  -n   do not output the trailing newline\n$VARS are expanded before echo runs.', examples: 'echo hello world\necho "home is $HOME"\necho saved > note.txt' },
    grep:  { summary: 'search text for a pattern', synopsis: 'grep [-i] [-v] [-n] [-c] PATTERN [FILE...]', description: 'Search for lines matching PATTERN (a regular expression).\n  -i   case-insensitive       -v   invert match\n  -n   show line numbers      -c   count matches only\nReads stdin when no FILE is given.', examples: 'grep WARN logs/tidewatch.log\ncat logs/access.log | grep 401 | wc -l' },
    wc:    { summary: 'count lines, words, bytes', synopsis: 'wc [-l] [-w] [-c] [FILE...]', description: 'Print newline, word, and byte counts for each FILE, and a total\nline if more than one FILE is given. Reads stdin when no FILE.', examples: 'wc -l README.txt\nls | wc -l' },
    head:  { summary: 'first lines of a file', synopsis: 'head [-n N] [FILE...]', description: 'Print the first N lines (default 10) of each FILE or of stdin.', examples: 'head -n 3 logs/syslog\nsort names.txt | head -5' },
    tail:  { summary: 'last lines of a file', synopsis: 'tail [-n N] [FILE...]', description: 'Print the last N lines (default 10) of each FILE or of stdin.', examples: 'tail -n 4 logs/tidewatch.log' },
    sort:  { summary: 'sort lines of text', synopsis: 'sort [-n] [-r] [-u] [FILE...]', description: 'Sort lines.\n  -n numeric    -r reverse    -u unique', examples: 'sort -u names.txt\nsort -nr scores.txt' },
    uniq:  { summary: 'filter adjacent repeated lines', synopsis: 'uniq [-c] [-d] [FILE...]', description: 'Collapse adjacent identical lines.\n  -c prefix counts   -d only duplicated lines\nUsually combined with sort.', examples: 'sort log | uniq -c | sort -nr' },
    mkdir: { summary: 'make directories', synopsis: 'mkdir [-p] DIR...', description: 'Create directories.\n  -p   create parent directories as needed', examples: 'mkdir build\nmkdir -p a/b/c' },
    touch: { summary: 'create or update files', synopsis: 'touch FILE...', description: 'Create empty FILEs or update their modification time.' },
    rm:    { summary: 'remove files or directories', synopsis: 'rm [-r] [-f] FILE...', description: 'Remove files.\n  -r   remove directories and their contents recursively\n  -f   force (ignore nonexistent)', examples: 'rm note.txt\nrm -r build' },
    mv:    { summary: 'move or rename files', synopsis: 'mv SRC... DEST', description: 'Rename SRC to DEST, or move SRC(s) into directory DEST.', examples: 'mv old.txt new.txt\nmv *.log logs/' },
    cp:    { summary: 'copy files', synopsis: 'cp SRC... DEST', description: 'Copy SRC to DEST, or into directory DEST. Copies directories\nrecursively as well.', examples: 'cp README.txt README.bak' },
    echo2: null,
    find:  { summary: 'search a directory tree', synopsis: 'find [PATH] [-name PATTERN] [-type f|d]', description: 'Walk the tree under PATH (default .) printing matching paths.\n  -name   glob to match the basename (e.g. "*.md")\n  -type   restrict to files (f) or directories (d)', examples: 'find . -name "*.md"\nfind /home -type d' },
    tree:  { summary: 'show a directory tree', synopsis: 'tree [PATH]', description: 'Print PATH (default .) as an indented tree, then a summary count.' },
    wc2: null,
    history:{ summary: 'show command history', synopsis: 'history', description: 'List previously entered commands with their index. Use Up/Down to\nrecall them, or Ctrl+R to reverse-search.' },
    env:   { summary: 'show environment', synopsis: 'env', description: 'Print all environment variables in NAME=value form.' },
    export:{ summary: 'set an environment variable', synopsis: 'export NAME=VALUE', description: 'Define an environment variable visible to $expansions and env.', examples: 'export GREETING=hi\necho $GREETING' },
    man:   { summary: 'display a manual page', synopsis: 'man COMMAND', description: 'Show the manual page for COMMAND. See docs.html for everything.' },
    clear: { summary: 'clear the screen', synopsis: 'clear', description: 'Clear the terminal scrollback (Ctrl+L does the same).' },
    whoami:{ summary: 'print current user', synopsis: 'whoami', description: 'Print the effective user name ($USER).' },
    date:  { summary: 'print date and time', synopsis: 'date', description: 'Print the current local date and time.' },
    uname: { summary: 'print system info', synopsis: 'uname [-a]', description: 'Print system information. -a prints everything.' },
    neofetch:{ summary: 'system info + ASCII logo', synopsis: 'neofetch', description: 'Display a summary of the (imaginary) system next to an ASCII logo.' },
    cowsay:{ summary: 'a cow says something', synopsis: 'cowsay [TEXT]', description: 'An ASCII cow says TEXT (or stdin). Pure whimsy.', examples: 'cowsay hello\nfortune | cowsay' },
    fortune:{ summary: 'print a random adage', synopsis: 'fortune', description: 'Print a random line from the fortune file.' },
    tour:  { summary: 'guided walkthrough', synopsis: 'tour', description: 'Run a short, interactive walkthrough of the shell features.' },
    stat:  { summary: 'display file status', synopsis: 'stat FILE...', description: 'Show size, type, mode and modification time for each FILE.' },
    du:    { summary: 'estimate disk usage', synopsis: 'du [PATH]', description: 'Print the size of each file and directory under PATH (in KB).' },
    rev:   { summary: 'reverse each line', synopsis: 'rev [FILE...]', description: 'Reverse the characters of every input line.' },
    tac:   { summary: 'print lines in reverse', synopsis: 'tac [FILE...]', description: 'Like cat, but print lines from last to first.' },
    which: { summary: 'locate a command', synopsis: 'which NAME...', description: 'Print the (pretend) path of each built-in command.' },
    alias: { summary: 'list aliases', synopsis: 'alias', description: 'Show the defined aliases. (Display only in this shell.)' },
    true:  { summary: 'do nothing, successfully', synopsis: 'true', description: 'Exit with status 0. Handy on the left of || in examples.' },
    false: { summary: 'do nothing, unsuccessfully', synopsis: 'false', description: 'Exit with status 1. Handy on the left of && in examples.', examples: 'false || echo "ran the fallback"' },
    yes:   { summary: 'repeat a string', synopsis: 'yes [STRING]', description: 'Print STRING (default "y") repeatedly. Capped at 20 lines here so\nyour browser tab survives.' },
    sl:    { summary: 'steam locomotive', synopsis: 'sl', description: 'You meant ls. A train arrives instead.' },
    matrix:{ summary: 'matrix rain', synopsis: 'matrix', description: 'A brief column of falling characters. Press any key to stop.' },
  };
  delete TG.MAN.echo2; delete TG.MAN.wc2;

  return {
    table: CMD,
    has(name) { return Object.prototype.hasOwnProperty.call(CMD, name); },
    run(name, ctx) { return CMD[name].run(ctx); },
    names() { return Object.keys(CMD).sort(); }
  };
})();
