/* =========================================================
   RELAY — Library data
   Example patterns + sample texts that load into the lab.
   All sample text is real-world-shaped (logs, CSV, emails,
   code, dates) — never lorem ipsum.
   ========================================================= */

(function (global) {
  'use strict';

  var SAMPLE_LOG =
"2026-06-21T08:14:02Z INFO  auth: user chris.huber@a8c.com signed in from 192.168.1.42\n" +
"2026-06-21T08:14:09Z WARN  cache: key \"session:8f2a\" evicted after 3600s ttl\n" +
"2026-06-21T08:15:31Z ERROR db: connection to 10.0.3.17:5432 refused (timeout 250ms)\n" +
"2026-06-21T08:15:33Z INFO  retry: backoff 2s, attempt 2 of 5\n" +
"2026-06-21T08:16:01Z ERROR db: connection to 10.0.3.17:5432 refused (timeout 250ms)\n" +
"2026-06-21T08:16:48Z INFO  auth: user a.lovelace@example.org signed in from 203.0.113.9\n" +
"2026-06-21T08:17:12Z DEBUG http: GET /api/v2/orders?status=open 200 in 41ms\n" +
"2026-06-21T08:17:55Z WARN  rate: client 198.51.100.23 throttled (120 req/min)\n";

  var SAMPLE_CONTACTS =
"name,email,phone,signed_up\n" +
"Ada Lovelace,a.lovelace@example.org,+1 (415) 555-0148,2025-11-03\n" +
"Grace Hopper,grace@navy.mil,+1 415-555-0192,2025-12-17\n" +
"Linus T.,linus@kernel.org,(503) 555-2231,2026-01-09\n" +
"Margaret H.,margaret.hamilton@nasa.gov,415.555.7781,2026-02-28\n" +
"chris,chris.huber@a8c.com,+1 (415) 555-9000,2026-06-24\n";

  var SAMPLE_MARKDOWN =
"# Release notes — Relay 3.2\n\n" +
"Visit https://relay.example.dev/changelog for the full log.\n" +
"- Fixed #1428: hex colors like #1a1f2e and #FFF now parse.\n" +
"- New: share links at https://relay.example.dev/s/9f2A?flags=gim\n" +
"- See the [docs](https://relay.example.dev/docs) and email us at hello@relay.example.dev.\n" +
"- Brand colors: #6cf2c8, #ff7a90, #8a7bff.\n";

  var SAMPLE_CODE =
"function debounce(fn, wait) {\n" +
"  let t;\n" +
"  return function (...args) {\n" +
"    clearTimeout(t);\n" +
"    t = setTimeout(() => fn.apply(this, args), wait);\n" +
"  };\n" +
"}\n\n" +
"// TODO(chris): handle leading edge\n" +
"// FIXME: cancel on unmount\n" +
"const onResize = debounce(render, 120); // 120ms\n";

  var EXAMPLES = [
    {
      id: 'email', name: 'Email address', tag: 'common',
      pattern: "([a-zA-Z0-9._%+-]+)@([a-zA-Z0-9.-]+\\.[a-zA-Z]{2,})",
      flags: 'gm', replace: "$1 AT $2", sample: SAMPLE_CONTACTS,
      note: "Captures the local part and the domain separately."
    },
    {
      id: 'url', name: 'HTTP/HTTPS URL', tag: 'common',
      pattern: "https?:\\/\\/[^\\s)\\]]+",
      flags: 'g', replace: "<link>", sample: SAMPLE_MARKDOWN,
      note: "Greedy run of non-space characters after the scheme."
    },
    {
      id: 'ipv4', name: 'IPv4 address', tag: 'network',
      pattern: "\\b(\\d{1,3})\\.(\\d{1,3})\\.(\\d{1,3})\\.(\\d{1,3})\\b",
      flags: 'g', replace: "$1.$2.x.x", sample: SAMPLE_LOG,
      note: "Four dotted octets. (Doesn't validate 0–255 — try the strict version.)"
    },
    {
      id: 'ipv4-strict', name: 'IPv4 (strict 0–255)', tag: 'network',
      pattern: "\\b(?:(?:25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)\\.){3}(?:25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)\\b",
      flags: 'g', replace: "[ip]", sample: SAMPLE_LOG,
      note: "Each octet constrained to 0–255 via alternation."
    },
    {
      id: 'iso-date', name: 'ISO date with named groups', tag: 'dates',
      pattern: "(?<year>\\d{4})-(?<month>\\d{2})-(?<day>\\d{2})",
      flags: 'g', replace: "$<day>/$<month>/$<year>", sample: SAMPLE_CONTACTS,
      note: "Named groups year / month / day; replace reorders to D/M/Y."
    },
    {
      id: 'timestamp', name: 'ISO-8601 timestamp', tag: 'dates',
      pattern: "(\\d{4}-\\d{2}-\\d{2})T(\\d{2}:\\d{2}:\\d{2})Z",
      flags: 'g', replace: "$1 $2", sample: SAMPLE_LOG,
      note: "Splits the date and time halves of a Zulu timestamp."
    },
    {
      id: 'hex', name: 'Hex color', tag: 'common',
      pattern: "#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{3})\\b",
      flags: 'gi', replace: "▉", sample: SAMPLE_MARKDOWN,
      note: "Matches #RGB or #RRGGBB (alternation, longest first)."
    },
    {
      id: 'loglevel', name: 'Log level + message', tag: 'logs',
      pattern: "^\\S+\\s+(INFO|WARN|ERROR|DEBUG)\\s+(\\w+):\\s+(.*)$",
      flags: 'gm', replace: "[$1] $2 → $3", sample: SAMPLE_LOG,
      note: "Anchored per line with the m flag; captures level, subsystem, message."
    },
    {
      id: 'phone', name: 'US phone number', tag: 'common',
      pattern: "\\+?1?[\\s.-]*\\(?(\\d{3})\\)?[\\s.-]*(\\d{3})[\\s.-]*(\\d{4})",
      flags: 'g', replace: "($1) $2-$3", sample: SAMPLE_CONTACTS,
      note: "Tolerates spaces, dots, dashes and optional parens/country code."
    },
    {
      id: 'todo', name: 'TODO / FIXME comments', tag: 'code',
      pattern: "\\/\\/\\s*(TODO|FIXME)(?:\\((\\w+)\\))?:\\s*(.*)",
      flags: 'g', replace: "⚑ $1 [$2]: $3", sample: SAMPLE_CODE,
      note: "Optional non-capturing assignee in parens, then the note."
    },
    {
      id: 'word-dup', name: 'Doubled word (backref)', tag: 'tricks',
      pattern: "\\b(\\w+)\\s+\\1\\b",
      flags: 'gi', replace: "$1", sample: "this this is a a common typo where the the word repeats",
      note: "\\1 backreferences group 1 — finds an immediately repeated word."
    },
    {
      id: 'lookahead-pw', name: 'Password rule (lookahead)', tag: 'tricks',
      pattern: "^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[^\\w\\s]).{8,}$",
      flags: 'gm', replace: "ok", sample: "hunter2\nCorrectHorse9!\npassword\nS0lar-Fl@re\nabc",
      note: "Four positive lookaheads assert lower, upper, digit and symbol."
    }
  ];

  var SAMPLES = [
    { id: 'log', name: 'Server log', text: SAMPLE_LOG },
    { id: 'csv', name: 'Contacts CSV', text: SAMPLE_CONTACTS },
    { id: 'md', name: 'Markdown release notes', text: SAMPLE_MARKDOWN },
    { id: 'code', name: 'JavaScript source', text: SAMPLE_CODE }
  ];

  var DEFAULT = {
    pattern: "(?<year>\\d{4})-(?<month>\\d{2})-(?<day>\\d{2})T(\\d{2}:\\d{2}:\\d{2})Z\\s+(INFO|WARN|ERROR|DEBUG)",
    flags: 'gm',
    text: SAMPLE_LOG,
    replace: "$<day>/$<month> [$5]"
  };

  global.RelayLibrary = { EXAMPLES: EXAMPLES, SAMPLES: SAMPLES, DEFAULT: DEFAULT };

})(typeof window !== 'undefined' ? window : this);
