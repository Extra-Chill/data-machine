/* =========================================================
   Tideglass Shell — Virtual Filesystem
   An in-memory tree of directories and files, seeded with
   believable content, persisted to localStorage.
   ---------------------------------------------------------
   Node shape:
     dir:  { type:'dir',  name, children:{ name -> node }, mtime }
     file: { type:'file', name, content:'',                mtime }
   ========================================================= */
'use strict';

window.TG = window.TG || {};

TG.FS = (function () {

  const LS_KEY = 'tideglass.fs.v1';
  const SECONDS = (d) => Math.floor(new Date(d).getTime() / 1000);

  /* ---- The seed filesystem -----------------------------------------
     Authored as a nested literal, then expanded into real nodes.
     Strings become files; objects become directories. -------------- */
  const SEED = {
    home: {
      mara: {
        'README.txt':
`Welcome to Tideglass — a shell that lives entirely in your browser.

Nothing here touches a server. The filesystem you're poking at is a
tree of JavaScript objects, mutated by real commands and saved to
localStorage so it survives a refresh. Break it however you like;
'reset' (the button, top-right) puts everything back.

Quick start:
  help            list every command
  tour            a guided 90-second walkthrough
  ls -l           look around
  cat notes/*     read the seeded notes
  neofetch        show off

You are 'mara', home directory /home/mara. Have fun.`,

        '.profile':
`# ~/.profile — read at login
export EDITOR=glass
export PAGER=less
export PROJECT_ROOT=/home/mara/projects
export PS1_STYLE=lambda
# aliases the shell pretends to honor
alias ll='ls -l'
alias la='ls -a'
echo "welcome back, $USER — $(date)"`,

        '.shellrc':
`# things tideglass loads but mostly ignores, for flavor
set -o vi
HISTSIZE=2000
HISTCONTROL=ignoredups
# color scheme can be changed in the toolbar, not here`,

        notes: {
          'todo.md':
`# Todo

- [x] Rewrite the tide-gauge parser to stream, not buffer
- [x] Email the harbormaster about gauge #7 drift
- [ ] Add a 'why is the water like that' FAQ to the docs
- [ ] Figure out the 3am pressure spike (gauge #4 again)
- [ ] Replace the cron job that nobody remembers writing
- [ ] Backup the lighthouse photos before the SD card dies

priority is the pressure spike. it's not the tide.`,

          'reading-list.md':
`# Reading list

The Soul of a New Machine — Tracy Kidder        (reread)
The Mythical Man-Month — Fred Brooks
Tidal: The Science and Stories of the Greatest Tides
Programming Pearls — Jon Bentley
Where Wizards Stay Up Late — Hafner & Lyon

abandoned:
  a 900-page book about lighthouses that was 60% lens diagrams`,

          'standup.txt':
`mon  caught the parser bug, it was an off-by-one in the ring buffer
tue  gauge #7 still drifting ~4cm/day, replaced the desiccant
wed  paired with theo on the alert thresholds
thu  wrote docs, drank too much coffee, no spike investigation (sorry)
fri  spike again at 03:14. it is SO not the tide.`
        },

        projects: {
          'tidewatch': {
            'README.md':
`# tidewatch

A small daemon that reads serial data from the harbor tide gauges,
normalizes it, and writes a tidy stream other tools can grep.

## Why
The gauges speak a crusty fixed-width protocol from 1994. tidewatch
turns it into newline-delimited JSON so the rest of the world can
stop suffering.

## Run
    tidewatch --port /dev/ttyUSB0 --gauge 7

## Status
Works. The 03:14 pressure spike on gauge #4 is NOT a tidewatch bug.
Probably. See notes/standup.txt.`,

            'main.glass':
`# pseudo-source, not a real language, just vibes
import serial
import json

gauges = open_config("gauges.toml")

loop every 1s:
    raw = serial.read_frame()
    if raw.checksum_ok():
        reading = parse_fixed_width(raw)
        emit(json.line(reading))
    else:
        log.warn("bad frame from", raw.gauge_id)`,

            'gauges.toml':
`# harbor tide-gauge registry
[gauge.4]
name   = "North Mole"
serial = "/dev/ttyUSB0"
depth  = 11.2
note   = "the 03:14 spike one. watch this space."

[gauge.7]
name   = "Inner Harbor"
serial = "/dev/ttyUSB1"
depth  = 6.4
note   = "drifting ~4cm/day, replaced desiccant 2026-06-09"

[gauge.12]
name   = "Breakwater"
serial = "/dev/ttyUSB2"
depth  = 18.0`,

            'CHANGELOG.md':
`# Changelog

## 2.3.1 — 2026-06-12
- Stream the parser instead of buffering whole frames (big memory win)
- Fix off-by-one in the ring buffer that ate every 256th reading

## 2.3.0 — 2026-05-30
- Add gauge #12 (Breakwater)
- Emit JSON lines instead of CSV

## 2.0.0 — 2026-03-01
- Rewrite from the bash script that started all this`
          },

          'glasscharts': {
            'README.md':
`# glasscharts

Tiny client-side charting for tide data. No dependencies, draws to
canvas, refuses to phone home. Powers the harbor dashboard.

Supported: line, band (min/max), and the dreaded "spike annotator"
that points an arrow at every anomaly and labels it. Guess which
gauge generated the most arrows.`,

            'demo.html':
`<!-- a minimal embed, kept for reference -->
<canvas id="tide" width="640" height="240"></canvas>
<script src="glasscharts.js"></script>
<script>
  Glasscharts.line('#tide', fetchTideSeries('gauge-7'));
</script>`
          }
        },

        logs: {
          'tidewatch.log':
`2026-06-25T03:11:02Z INFO  gauge=4 reading depth=11.18 ok
2026-06-25T03:12:02Z INFO  gauge=4 reading depth=11.19 ok
2026-06-25T03:13:02Z INFO  gauge=4 reading depth=11.20 ok
2026-06-25T03:14:02Z WARN  gauge=4 pressure spike +0.42 (anomaly)
2026-06-25T03:14:03Z WARN  gauge=4 frame checksum mismatch, retrying
2026-06-25T03:14:05Z INFO  gauge=4 reading depth=11.21 ok
2026-06-25T03:15:02Z INFO  gauge=4 reading depth=11.20 ok
2026-06-25T06:02:11Z INFO  gauge=7 reading depth=6.39 ok
2026-06-25T06:02:11Z WARN  gauge=7 drift detected vs baseline 4.1cm
2026-06-25T08:30:00Z INFO  daemon healthy, 3 gauges connected`,

          'access.log':
`192.168.1.20 - - [25/Jun/2026:08:31:10] "GET /dashboard HTTP/1.1" 200 4821
192.168.1.20 - - [25/Jun/2026:08:31:10] "GET /api/tide/7 HTTP/1.1" 200 1190
192.168.1.44 - - [25/Jun/2026:08:33:02] "GET /api/tide/4 HTTP/1.1" 200 1188
192.168.1.44 - - [25/Jun/2026:08:33:09] "GET /api/anomaly HTTP/1.1" 200 612
10.0.0.5 - - [25/Jun/2026:08:40:55] "GET /dashboard HTTP/1.1" 200 4821
192.168.1.20 - - [25/Jun/2026:09:01:18] "GET /api/tide/12 HTTP/1.1" 200 1192
192.168.1.99 - - [25/Jun/2026:09:02:40] "POST /api/login HTTP/1.1" 401 39
192.168.1.99 - - [25/Jun/2026:09:02:48] "POST /api/login HTTP/1.1" 401 39
192.168.1.99 - - [25/Jun/2026:09:02:55] "POST /api/login HTTP/1.1" 200 88`
        },

        'lighthouse.txt':
`The lighthouse at the harbor mouth was automated in 1989, which is
the polite way of saying nobody lives there anymore. The lens is a
third-order Fresnel, hand-ground, and on a clear night you can see
its glow translate the fog into something almost solid.

I keep meaning to back up the photos. I keep not doing it. See todo.md.`
      },
      guest: {
        'README.txt':
`This is the guest account. You're logged in as 'mara', not guest,
but feel free to 'cat /home/guest/welcome' and snoop.`,
        'welcome':
`Hello, stranger. Make yourself at home. Try 'fortune'.`
      }
    },

    etc: {
      'motd':
`  ~ Tideglass ~  the shell that runs on nothing but a browser tab.
  Tip of the day: pipe things. 'ls -l | grep .md | wc -l' counts files.`,
      'hostname': 'harborlight',
      'shells':
`# valid login shells
/bin/sh
/bin/bash
/bin/glass`,
      'tideglass.conf':
`# system-wide tideglass config
default_scheme = harbor
crt_default    = off
font_size      = 15
motd           = on`
    },

    usr: {
      share: {
        'fortunes':
`Any sufficiently advanced bug is indistinguishable from a feature.
The tide does not negotiate.
There are two hard problems in computing: cache invalidation, naming things, and off-by-one errors.
A clean filesystem is a sign of a wasted afternoon.
The 03:14 spike is not the tide. It is never the tide.
Backup your photos. Today. You know which photos.
A pipe is just a tiny river for your data.
'rm -rf /' is a wonderful way to ruin a Tuesday. (Don't worry, ours is fake.)
The best error message is the one that never shows up.
Lighthouses are just very dramatic status indicators.`
      },
      bin: {
        // populated lazily as command "binaries" for tab completion flavor
      }
    },

    bin: {},

    var: {
      log: {
        'syslog':
`Jun 25 03:14:02 harborlight tidewatch[412]: anomaly on gauge 4
Jun 25 08:30:00 harborlight kernel: it's all made of objects in here
Jun 25 08:31:00 harborlight glassd[88]: shell session started for mara
Jun 25 09:02:55 harborlight sshd[771]: accepted login after 2 failures`
      },
      'tmp': {}
    },

    tmp: {}
  };

  /* ---- Build a live tree from the seed literal --------------------- */
  function buildNode(name, val, mtime) {
    if (typeof val === 'string') {
      return { type: 'file', name, content: val, mtime };
    }
    const dir = { type: 'dir', name, children: {}, mtime };
    for (const k of Object.keys(val)) {
      dir.children[k] = buildNode(k, val[k], mtime);
    }
    return dir;
  }

  function freshRoot() {
    const t = SECONDS('2026-06-25T08:31:00Z');
    const root = { type: 'dir', name: '', children: {}, mtime: t };
    for (const k of Object.keys(SEED)) {
      root.children[k] = buildNode(k, SEED[k], t);
    }
    return root;
  }

  let root = freshRoot();

  /* ---- Persistence ------------------------------------------------- */
  function save() {
    try { localStorage.setItem(LS_KEY, JSON.stringify(root)); }
    catch (e) { /* private mode / quota; in-memory still works */ }
  }
  function load() {
    try {
      const raw = localStorage.getItem(LS_KEY);
      if (raw) { root = JSON.parse(raw); return true; }
    } catch (e) { /* corrupt; fall through to fresh */ }
    return false;
  }
  function reset() {
    root = freshRoot();
    save();
  }

  /* ---- Path utilities ---------------------------------------------- */
  function splitPath(p) {
    return p.split('/').filter(Boolean);
  }

  // Resolve a (possibly relative) path against cwd into an absolute,
  // normalized path string. Handles . and ..
  function resolve(cwd, p) {
    let parts;
    if (p.startsWith('/')) parts = splitPath(p);
    else parts = splitPath(cwd).concat(splitPath(p));
    const out = [];
    for (const seg of parts) {
      if (seg === '.') continue;
      if (seg === '..') { out.pop(); continue; }
      out.push(seg);
    }
    return '/' + out.join('/');
  }

  function getNode(absPath) {
    const parts = splitPath(absPath);
    let node = root;
    for (const seg of parts) {
      if (node.type !== 'dir' || !node.children[seg]) return null;
      node = node.children[seg];
    }
    return node;
  }

  function parentPath(absPath) {
    const parts = splitPath(absPath);
    parts.pop();
    return '/' + parts.join('/');
  }
  function baseName(absPath) {
    const parts = splitPath(absPath);
    return parts.length ? parts[parts.length - 1] : '';
  }

  function isDir(node) { return node && node.type === 'dir'; }
  function isFile(node) { return node && node.type === 'file'; }

  /* ---- Mutations (return {ok, err}) -------------------------------- */
  function touchTime(node) { node.mtime = Math.floor(Date.now() / 1000); }

  function mkdir(cwd, p, recursive) {
    const abs = resolve(cwd, p);
    if (getNode(abs)) return { ok: false, err: `cannot create directory '${p}': File exists` };
    const parts = splitPath(abs);
    let node = root;
    for (let i = 0; i < parts.length; i++) {
      const seg = parts[i];
      const last = i === parts.length - 1;
      if (!node.children[seg]) {
        if (!recursive && !last) return { ok: false, err: `cannot create directory '${p}': No such file or directory` };
        node.children[seg] = { type: 'dir', name: seg, children: {}, mtime: Math.floor(Date.now()/1000) };
      } else if (node.children[seg].type !== 'dir') {
        return { ok: false, err: `cannot create directory '${p}': Not a directory` };
      }
      node = node.children[seg];
    }
    save();
    return { ok: true };
  }

  function writeFile(cwd, p, content) {
    const abs = resolve(cwd, p);
    const parent = getNode(parentPath(abs));
    if (!isDir(parent)) return { ok: false, err: `cannot write '${p}': No such directory` };
    const name = baseName(abs);
    const existing = parent.children[name];
    if (existing && existing.type === 'dir') return { ok: false, err: `'${p}': Is a directory` };
    parent.children[name] = { type: 'file', name, content, mtime: Math.floor(Date.now()/1000) };
    save();
    return { ok: true };
  }

  function appendFile(cwd, p, content) {
    const abs = resolve(cwd, p);
    const node = getNode(abs);
    if (node && node.type === 'file') {
      node.content += content;
      touchTime(node);
      save();
      return { ok: true };
    }
    return writeFile(cwd, p, content);
  }

  function touch(cwd, p) {
    const abs = resolve(cwd, p);
    const node = getNode(abs);
    if (node) { touchTime(node); save(); return { ok: true }; }
    return writeFile(cwd, p, '');
  }

  function remove(cwd, p, recursive) {
    const abs = resolve(cwd, p);
    if (abs === '/' ) return { ok: false, err: `refusing to remove '/'` };
    const node = getNode(abs);
    if (!node) return { ok: false, err: `cannot remove '${p}': No such file or directory` };
    if (node.type === 'dir' && Object.keys(node.children).length && !recursive)
      return { ok: false, err: `cannot remove '${p}': Is a directory` };
    const parent = getNode(parentPath(abs));
    delete parent.children[baseName(abs)];
    save();
    return { ok: true };
  }

  function move(cwd, src, dst, copy) {
    const sAbs = resolve(cwd, src);
    const sNode = getNode(sAbs);
    if (!sNode) return { ok: false, err: `cannot stat '${src}': No such file or directory` };
    let dAbs = resolve(cwd, dst);
    let dNode = getNode(dAbs);
    // moving into an existing directory keeps the source basename
    if (isDir(dNode)) dAbs = resolve(dAbs, baseName(sAbs));
    const dParent = getNode(parentPath(dAbs));
    if (!isDir(dParent)) return { ok: false, err: `target '${dst}': No such directory` };
    const clone = copy ? deepClone(sNode) : sNode;
    clone.name = baseName(dAbs);
    dParent.children[clone.name] = clone;
    if (!copy) {
      const sParent = getNode(parentPath(sAbs));
      delete sParent.children[baseName(sAbs)];
    }
    save();
    return { ok: true };
  }

  function deepClone(node) {
    if (node.type === 'file') return { type: 'file', name: node.name, content: node.content, mtime: node.mtime };
    const d = { type: 'dir', name: node.name, children: {}, mtime: node.mtime };
    for (const k of Object.keys(node.children)) d.children[k] = deepClone(node.children[k]);
    return d;
  }

  /* ---- Glob: expand a single token that may contain * or ? --------- */
  function glob(cwd, pattern) {
    if (!/[*?[]/.test(pattern)) return [pattern];
    const abs = resolve(cwd, pattern);
    const dirPart = parentPath(abs) || '/';
    const namePart = baseName(abs);
    const dirNode = getNode(dirPart);
    if (!isDir(dirNode)) return [pattern];
    const rx = globToRegex(namePart);
    const matches = Object.keys(dirNode.children)
      .filter(n => rx.test(n) && (namePart.startsWith('.') || !n.startsWith('.')))
      .sort();
    if (!matches.length) return [pattern];
    // Return paths in the same style (relative vs absolute) as input
    const prefix = pattern.includes('/') ? pattern.slice(0, pattern.lastIndexOf('/') + 1) : '';
    return matches.map(m => prefix + m);
  }

  function globToRegex(glob) {
    let re = '^';
    for (const ch of glob) {
      if (ch === '*') re += '[^/]*';
      else if (ch === '?') re += '[^/]';
      else re += ch.replace(/[.+^${}()|[\]\\]/g, '\\$&');
    }
    return new RegExp(re + '$');
  }

  /* ---- Public API -------------------------------------------------- */
  return {
    LS_KEY,
    get root() { return root; },
    load, save, reset,
    resolve, getNode, parentPath, baseName, splitPath,
    isDir, isFile,
    mkdir, writeFile, appendFile, touch, remove, move, glob,
    freshRoot
  };
})();
