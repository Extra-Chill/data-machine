/* =========================================================
   DRIFTLANE — Board data, seed content & persistence
   Pure data layer. No DOM. Exposed on window.Driftlane.data
   ========================================================= */
(function () {
  'use strict';

  const STORE_KEY = 'driftlane.board.v1';
  const PREFS_KEY = 'driftlane.prefs.v1';

  /* ---- team members (avatars generated from initials) ---- */
  const PEOPLE = [
    { id: 'u-amara',  name: 'Amara Okafor',    role: 'Eng Lead',   color: '#6c8cff' },
    { id: 'u-river',  name: 'River Bennett',   role: 'Frontend',   color: '#2bbf9f' },
    { id: 'u-soraya', name: 'Soraya Haddad',   role: 'Design',     color: '#f0883e' },
    { id: 'u-deniz',  name: 'Deniz Yılmaz',    role: 'Backend',    color: '#b57bff' },
    { id: 'u-mateo',  name: 'Mateo Reyes',     role: 'QA',         color: '#ff6b8a' },
    { id: 'u-junko',  name: 'Junko Watanabe',  role: 'Product',    color: '#3fb6ff' },
    { id: 'u-omar',   name: 'Omar Castellano', role: 'DevOps',     color: '#f2c14e' }
  ];

  /* ---- label palette ---- */
  const LABELS = [
    { id: 'l-bug',     name: 'bug',           color: '#ff5d6c' },
    { id: 'l-feature', name: 'feature',       color: '#2bbf9f' },
    { id: 'l-design',  name: 'design',        color: '#b57bff' },
    { id: 'l-infra',   name: 'infra',         color: '#f2c14e' },
    { id: 'l-docs',    name: 'docs',          color: '#6c8cff' },
    { id: 'l-perf',    name: 'performance',   color: '#f0883e' },
    { id: 'l-research', name: 'research',     color: '#3fb6ff' },
    { id: 'l-blocked', name: 'blocked',       color: '#ff8a3d' }
  ];

  let _seq = 1000;
  const uid = (p) => p + '-' + (++_seq).toString(36) + '-' + Math.random().toString(36).slice(2, 6);

  /* ---- helper to build a card without typing every field ---- */
  function card(o) {
    return {
      id: o.id || uid('c'),
      title: o.title,
      desc: o.desc || '',
      labels: o.labels || [],
      assignee: o.assignee || null,
      due: o.due || null,
      priority: o.priority || 'medium',   // low | medium | high | urgent
      checklist: (o.checklist || []).map((t, i) =>
        typeof t === 'string'
          ? { id: uid('t'), text: t, done: i < (o.doneCount || 0) }
          : { id: uid('t'), text: t.text, done: !!t.done }),
      comments: o.comments || 0,
      created: o.created || Date.now()
    };
  }

  /* =======================================================
     SEED BOARD — "Atlas Mobile — Q3 Sprint 14"
     A believable software product sprint board.
     ======================================================= */
  function seedBoard() {
    return {
      id: 'board-atlas',
      name: 'Atlas Mobile — Q3 Sprint 14',
      created: Date.now(),
      columns: [
        {
          id: 'col-backlog', name: 'Backlog', wip: 0, cards: [
            card({
              title: 'Offline sync conflict resolution',
              desc: 'Design a last-write-wins + manual-merge strategy for records edited on two devices while offline. Needs a vector-clock prototype before estimation.',
              labels: ['l-feature', 'l-research'], assignee: 'u-deniz', priority: 'high',
              due: '2026-07-18', comments: 4,
              checklist: ['Survey CRDT vs. OT', 'Spike vector clocks', 'Write design doc', 'Review with Amara']
            }),
            card({
              title: 'Dark mode for settings screens',
              desc: 'The settings stack still hardcodes light tokens. Migrate to semantic color roles.',
              labels: ['l-design'], assignee: 'u-soraya', priority: 'low', comments: 1,
              checklist: ['Audit hardcoded colors', 'Map to tokens'], doneCount: 1
            }),
            card({
              title: 'Push notification batching',
              desc: 'Group notifications by thread to avoid the "12 new pings" spam users complained about in the App Store reviews.',
              labels: ['l-feature', 'l-perf'], assignee: 'u-omar', priority: 'medium', comments: 2
            }),
            card({
              title: 'Investigate Android 14 keyboard jump',
              desc: 'On Pixel 8, the compose field jumps ~40px when the IME opens. Likely a window-inset regression.',
              labels: ['l-bug', 'l-research'], assignee: 'u-river', priority: 'medium', comments: 0
            })
          ]
        },
        {
          id: 'col-todo', name: 'To Do', wip: 5, cards: [
            card({
              title: 'Add biometric unlock (Face ID / fingerprint)',
              desc: 'Wrap the keychain access behind LocalAuthentication with a graceful PIN fallback.',
              labels: ['l-feature'], assignee: 'u-deniz', priority: 'high',
              due: '2026-07-02', comments: 3,
              checklist: ['iOS LAContext flow', 'Android BiometricPrompt', 'PIN fallback UI', 'Lockout after 5 fails', 'QA on 4 devices'],
              doneCount: 1
            }),
            card({
              title: 'Empty-state illustrations for inbox',
              desc: 'Three states: no messages, no search results, offline. SVG, theme-aware.',
              labels: ['l-design'], assignee: 'u-soraya', priority: 'medium', due: '2026-06-30', comments: 2,
              checklist: ['No messages', 'No results', 'Offline'], doneCount: 0
            }),
            card({
              title: 'Migrate analytics to v3 schema',
              desc: 'Old event names are ambiguous. Roll out the new naming with a dual-write window.',
              labels: ['l-infra'], assignee: 'u-omar', priority: 'medium', comments: 1
            })
          ]
        },
        {
          id: 'col-progress', name: 'In Progress', wip: 4, cards: [
            card({
              title: 'Image upload pipeline — resumable',
              desc: 'Switch to tus resumable uploads so a dropped connection on cellular does not restart a 12MB photo.',
              labels: ['l-feature', 'l-perf'], assignee: 'u-amara', priority: 'urgent',
              due: '2026-06-26', comments: 7,
              checklist: ['Client chunker', 'tus server endpoint', 'Retry/backoff', 'Progress UI', 'Cancel + resume', 'Load test 200 uploads'],
              doneCount: 4
            }),
            card({
              title: 'Fix crash on rotate during video playback',
              desc: 'AVPlayer layer is not retained across the configuration change → EXC_BAD_ACCESS. Repro 3/5 times on iPad.',
              labels: ['l-bug'], assignee: 'u-river', priority: 'urgent', due: '2026-06-25', comments: 5,
              checklist: ['Repro reliably', 'Retain player layer', 'Regression test'], doneCount: 2
            }),
            card({
              title: 'Onboarding A/B test instrumentation',
              desc: 'Variant B drops the email step. Wire exposure + completion events behind the existing flag.',
              labels: ['l-feature'], assignee: 'u-junko', priority: 'medium', comments: 2
            })
          ]
        },
        {
          id: 'col-review', name: 'Review', wip: 3, cards: [
            card({
              title: 'Search results pagination + skeletons',
              desc: 'PR #1482. Infinite scroll with skeleton placeholders. Waiting on Mateo to sign off on the loading states.',
              labels: ['l-feature', 'l-perf'], assignee: 'u-mateo', priority: 'high', due: '2026-06-27', comments: 6,
              checklist: ['Code review', 'QA pass', 'Accessibility check'], doneCount: 2
            }),
            card({
              title: 'Localize date/time formatting',
              desc: 'PR #1479. Switch from hand-rolled formatters to ICU. Needs a screenshot diff review for RTL.',
              labels: ['l-docs', 'l-feature'], assignee: 'u-junko', priority: 'medium', comments: 3,
              checklist: ['RTL screenshots', 'Sign off'], doneCount: 1
            })
          ]
        },
        {
          id: 'col-done', name: 'Done', wip: 0, cards: [
            card({
              title: 'Crash-free rate dashboard',
              desc: 'Grafana board now tracks crash-free sessions by version. Shipped in 4.6.0.',
              labels: ['l-infra'], assignee: 'u-omar', priority: 'medium', comments: 1,
              checklist: ['Build board', 'Alert thresholds'], doneCount: 2
            }),
            card({
              title: 'Replace deprecated map SDK',
              desc: 'Migrated off the EOL map provider; tiles + clustering verified.',
              labels: ['l-infra', 'l-feature'], assignee: 'u-deniz', priority: 'high', comments: 4,
              checklist: ['Swap SDK', 'Clustering', 'Verify offline tiles'], doneCount: 3
            }),
            card({
              title: 'Accessibility audit — VoiceOver labels',
              desc: '47 unlabeled controls fixed across the main tab bar and message composer.',
              labels: ['l-docs', 'l-design'], assignee: 'u-mateo', priority: 'medium', comments: 2,
              checklist: ['Tab bar', 'Composer', 'Settings'], doneCount: 3
            })
          ]
        }
      ]
    };
  }

  /* =======================================================
     TEMPLATES — starter boards loaded from templates.html
     ======================================================= */
  const TEMPLATES = {
    sprint: {
      key: 'sprint',
      name: 'Software Sprint',
      tagline: 'Backlog → To Do → In Progress → Review → Done. The classic engineering flow with WIP limits.',
      build: () => seedBoard()
    },

    content: {
      key: 'content',
      name: 'Content Calendar',
      tagline: 'Plan an editorial pipeline from idea to published, with writers, editors and channels.',
      build: () => ({
        id: 'board-content', name: 'Editorial Calendar — Summer', created: Date.now(),
        columns: [
          { id: 'c-ideas', name: 'Ideas', wip: 0, cards: [
            card({ title: 'The state of resumable uploads in 2026', labels: ['l-research'], assignee: 'u-junko', priority: 'low', desc: 'Trend piece. Interview two infra leads.', comments: 1 }),
            card({ title: '"How we cut cold start by 60%" deep dive', labels: ['l-perf'], assignee: 'u-amara', priority: 'medium', desc: 'Engineering blog. Charts from the perf sprint.' }),
            card({ title: 'Designer Q&A: building empty states people love', labels: ['l-design'], assignee: 'u-soraya', priority: 'low' })
          ]},
          { id: 'c-draft', name: 'Drafting', wip: 3, cards: [
            card({ title: 'Offline-first: a field guide', labels: ['l-docs'], assignee: 'u-deniz', priority: 'high', due: '2026-07-05', desc: 'Long-form. 2000 words.', comments: 3,
              checklist: ['Outline', 'First draft', 'Code samples', 'Diagrams'], doneCount: 2 }),
            card({ title: 'Release notes — 4.7.0', labels: ['l-docs'], assignee: 'u-junko', priority: 'medium', due: '2026-06-29' })
          ]},
          { id: 'c-edit', name: 'Editing', wip: 2, cards: [
            card({ title: 'Accessibility wins from Q2', labels: ['l-docs', 'l-design'], assignee: 'u-mateo', priority: 'medium', comments: 2,
              checklist: ['Copy edit', 'Fact check', 'Alt text'], doneCount: 1 })
          ]},
          { id: 'c-review', name: 'Review', wip: 2, cards: [
            card({ title: 'Brand refresh announcement', labels: ['l-design'], assignee: 'u-soraya', priority: 'high', due: '2026-06-28', comments: 4 })
          ]},
          { id: 'c-pub', name: 'Published', wip: 0, cards: [
            card({ title: 'Why we moved to a monorepo', labels: ['l-infra'], assignee: 'u-omar', priority: 'medium', comments: 5 }),
            card({ title: 'Designing for one-handed reach', labels: ['l-design'], assignee: 'u-soraya', priority: 'low', comments: 2 })
          ]}
        ]
      })
    },

    personal: {
      key: 'personal',
      name: 'Personal To-Do',
      tagline: 'A lightweight life board: This Week, Today, Doing, Done. Just you.',
      build: () => ({
        id: 'board-personal', name: 'My Week', created: Date.now(),
        columns: [
          { id: 'p-week', name: 'This Week', wip: 0, cards: [
            card({ title: 'Renew passport', priority: 'high', due: '2026-07-10', desc: 'Photos done. Need to book the appointment.', checklist: ['Photos', 'Form', 'Book slot'], doneCount: 1 }),
            card({ title: 'Plan trip to Lisbon', priority: 'low', desc: 'Flights + 3 nights. Compare neighborhoods.' , checklist: ['Flights', 'Hotel', 'Day trips'] }),
            card({ title: 'Read "The Pragmatic Programmer"', priority: 'low', desc: 'Chapter 6 onward.' })
          ]},
          { id: 'p-today', name: 'Today', wip: 5, cards: [
            card({ title: 'Grocery run', priority: 'medium', due: '2026-06-25', checklist: ['Coffee', 'Oat milk', 'Spinach', 'Pasta'], doneCount: 2 }),
            card({ title: 'Reply to landlord about lease', priority: 'high', due: '2026-06-25' }),
            card({ title: '30-min run', priority: 'medium' })
          ]},
          { id: 'p-doing', name: 'Doing', wip: 2, cards: [
            card({ title: 'Tidy the home office', priority: 'low', checklist: ['Cables', 'Desk', 'Bookshelf'], doneCount: 1 })
          ]},
          { id: 'p-done', name: 'Done', wip: 0, cards: [
            card({ title: 'Pay electricity bill', priority: 'high' }),
            card({ title: 'Call mom', priority: 'medium' })
          ]}
        ]
      })
    }
  };

  /* =======================================================
     Persistence
     ======================================================= */
  function load() {
    try {
      const raw = localStorage.getItem(STORE_KEY);
      if (!raw) return null;
      const b = JSON.parse(raw);
      if (!b || !Array.isArray(b.columns)) return null;
      return b;
    } catch (e) { return null; }
  }

  function save(board) {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(board)); return true; }
    catch (e) { return false; }
  }

  function clear() {
    try { localStorage.removeItem(STORE_KEY); } catch (e) {}
  }

  function loadPrefs() {
    try { return JSON.parse(localStorage.getItem(PREFS_KEY)) || {}; }
    catch (e) { return {}; }
  }
  function savePrefs(p) {
    try { localStorage.setItem(PREFS_KEY, JSON.stringify(p)); } catch (e) {}
  }

  /* ---- lookups ---- */
  function person(id) { return PEOPLE.find(p => p.id === id) || null; }
  function label(id)  { return LABELS.find(l => l.id === id) || null; }

  function initials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }

  window.Driftlane = window.Driftlane || {};
  window.Driftlane.data = {
    STORE_KEY, PREFS_KEY,
    PEOPLE, LABELS, TEMPLATES,
    uid, card, seedBoard,
    load, save, clear, loadPrefs, savePrefs,
    person, label, initials
  };
})();
