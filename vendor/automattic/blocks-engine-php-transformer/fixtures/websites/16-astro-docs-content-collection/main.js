/* =============================================
   SIGNALFORGE DOCS — main.js
   Nav, search, copy buttons, TOC tracking
   ============================================= */

// ─── SEARCH INDEX ──────────────────────────────
const SEARCH_INDEX = [
  {
    title: 'SignalForge Documentation',
    page: 'index.html',
    section: '',
    excerpt: 'Monitor API reliability and respond to incidents before customers start filing support tickets.',
    tags: ['home', 'docs', 'overview', 'getting started']
  },
  {
    title: 'Product Overview',
    page: 'overview.html',
    section: '',
    excerpt: 'Architecture overview, core concepts, and how SignalForge integrates with your platform stack.',
    tags: ['overview', 'architecture', 'concepts', 'signals', 'monitors']
  },
  {
    title: 'Signals',
    page: 'overview.html',
    section: '#signals',
    excerpt: 'A Signal is a typed observation emitted when a monitor check completes. Signals carry severity, value, threshold, and metadata.',
    tags: ['signal', 'observation', 'type']
  },
  {
    title: 'Monitors',
    page: 'overview.html',
    section: '#monitors',
    excerpt: 'A Monitor is a scheduled check definition. It runs on a configurable interval against an HTTP endpoint, TCP port, or custom probe.',
    tags: ['monitor', 'check', 'http', 'tcp', 'interval']
  },
  {
    title: 'Alert Rules',
    page: 'overview.html',
    section: '#alert-rules',
    excerpt: 'Alert Rules define the conditions under which a Signal triggers a notification. You can compose rules from multiple signal types.',
    tags: ['alert', 'rule', 'notification', 'condition', 'severity']
  },
  {
    title: 'Getting Started',
    page: 'getting-started.html',
    section: '',
    excerpt: 'Install the SignalForge CLI, configure your first monitor, and receive your first signal in under 10 minutes.',
    tags: ['install', 'setup', 'cli', 'npm', 'brew', 'quickstart']
  },
  {
    title: 'Installation',
    page: 'getting-started.html',
    section: '#installation',
    excerpt: 'Install SignalForge via npm: npm install -g @signalforge/cli or via Homebrew: brew install signalforge/tap/signalforge',
    tags: ['install', 'npm', 'brew', 'homebrew', 'cli']
  },
  {
    title: 'First Monitor',
    page: 'getting-started.html',
    section: '#first-monitor',
    excerpt: 'Define your first monitor in signalforge.yml and push it to your workspace with signalforge monitor sync.',
    tags: ['monitor', 'yaml', 'signalforge.yml', 'sync', 'first signal']
  },
  {
    title: 'Incident Workflow',
    page: 'incident-workflow.html',
    section: '',
    excerpt: 'A step-by-step runbook for detecting, triaging, escalating, and resolving API incidents with SignalForge.',
    tags: ['incident', 'runbook', 'triage', 'escalation', 'resolve', 'postmortem']
  },
  {
    title: 'Alert Triage',
    page: 'incident-workflow.html',
    section: '#triage',
    excerpt: 'When a critical alert fires, confirm signal breadth, check correlated monitors, and assign an incident commander within 5 minutes.',
    tags: ['triage', 'alert', 'commander', 'incident', 'confirm']
  },
  {
    title: 'Escalation',
    page: 'incident-workflow.html',
    section: '#escalation',
    excerpt: 'Escalation paths: auto-escalate after 10 minutes of no acknowledgment. Route by severity: critical → on-call, warning → Slack channel.',
    tags: ['escalation', 'on-call', 'pagerduty', 'severity']
  },
  {
    title: 'Post-Mortem',
    page: 'incident-workflow.html',
    section: '#postmortem',
    excerpt: 'File a post-mortem within 48 hours. SignalForge auto-generates a timeline from signal history.',
    tags: ['postmortem', 'post-mortem', 'retrospective', 'timeline', 'blameless']
  },
  {
    title: 'API Reference',
    page: 'api-reference.html',
    section: '',
    excerpt: 'Full REST API reference for SignalForge. Endpoints for monitors, signals, alert rules, and webhooks.',
    tags: ['api', 'rest', 'reference', 'endpoints', 'curl', 'json']
  },
  {
    title: 'GET /v1/monitors',
    page: 'api-reference.html',
    section: '#get-monitors',
    excerpt: 'List all monitors in your workspace. Returns a paginated array of monitor objects.',
    tags: ['api', 'get', 'monitors', 'list']
  },
  {
    title: 'POST /v1/monitors',
    page: 'api-reference.html',
    section: '#create-monitor',
    excerpt: 'Create a new monitor. Accepts a monitor definition object with url, interval, timeout, and alert configuration.',
    tags: ['api', 'post', 'create', 'monitor']
  },
  {
    title: 'GET /v1/signals',
    page: 'api-reference.html',
    section: '#get-signals',
    excerpt: 'List recent signals, optionally filtered by monitor_id, severity, or time range.',
    tags: ['api', 'get', 'signals', 'list', 'filter']
  },
  {
    title: 'Webhooks',
    page: 'api-reference.html',
    section: '#webhooks',
    excerpt: 'Configure webhook endpoints to receive real-time signal payloads via HTTP POST.',
    tags: ['webhook', 'payload', 'notification', 'http', 'event']
  },
  {
    title: 'Authentication',
    page: 'api-reference.html',
    section: '#authentication',
    excerpt: 'Authenticate API requests using Bearer token. Generate tokens from your workspace settings.',
    tags: ['auth', 'token', 'bearer', 'api key', 'authentication']
  },
  {
    title: 'Changelog',
    page: 'changelog.html',
    section: '',
    excerpt: 'Release notes and version history for SignalForge CLI and API.',
    tags: ['changelog', 'release', 'version', 'notes', 'history']
  },
  {
    title: 'v0.9.2 — Webhook Retry Logic',
    page: 'changelog.html',
    section: '#v092',
    excerpt: 'v0.9.2 adds exponential backoff for webhook delivery failures, and fixes a signal deduplication edge case.',
    tags: ['v0.9.2', 'webhook', 'retry', 'fix']
  },
  {
    title: 'v0.9.0 — Alert Rules Engine',
    page: 'changelog.html',
    section: '#v090',
    excerpt: 'v0.9.0 introduces the composable Alert Rules Engine with support for AND/OR logic across signal types.',
    tags: ['v0.9.0', 'alert rules', 'engine', 'feature']
  },
  {
    title: 'Support',
    page: 'support.html',
    section: '',
    excerpt: 'Community Discord, GitHub issues, documentation feedback, and enterprise support contacts.',
    tags: ['support', 'help', 'discord', 'github', 'contact', 'enterprise']
  }
];

// ─── SEARCH LOGIC ──────────────────────────────
function searchDocs(query) {
  if (!query || query.length < 2) return [];
  const q = query.toLowerCase();
  return SEARCH_INDEX
    .map(item => {
      let score = 0;
      const titleLower = item.title.toLowerCase();
      const excerptLower = item.excerpt.toLowerCase();

      if (titleLower.startsWith(q)) score += 10;
      else if (titleLower.includes(q)) score += 6;
      if (excerptLower.includes(q)) score += 3;
      item.tags.forEach(tag => {
        if (tag.includes(q)) score += 4;
        if (tag === q) score += 8;
      });

      return { ...item, score };
    })
    .filter(i => i.score > 0)
    .sort((a, b) => b.score - a.score)
    .slice(0, 6);
}

// ─── RENDER SEARCH RESULTS ──────────────────────
function renderResults(results, container) {
  if (results.length === 0) {
    container.innerHTML = '<div class="search-no-results">No results found</div>';
    return;
  }

  container.innerHTML = results.map(r => `
    <a class="search-result-item" href="${r.page}${r.section}">
      <div class="search-result-title">${r.title}</div>
      <div class="search-result-excerpt">${r.excerpt.slice(0, 90)}…</div>
      <div class="search-result-page">${r.page.replace('.html', '')}</div>
    </a>
  `).join('');
}

// ─── INIT SEARCH ───────────────────────────────
function initSearch() {
  const inputs = document.querySelectorAll('.search-input');
  inputs.forEach(input => {
    const container = input.closest('.search-container');
    if (!container) return;
    let resultsEl = container.querySelector('.search-results');
    if (!resultsEl) {
      resultsEl = document.createElement('div');
      resultsEl.className = 'search-results';
      container.appendChild(resultsEl);
    }

    let debounceTimer;
    input.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        const q = input.value.trim();
        if (q.length < 2) {
          resultsEl.classList.remove('active');
          return;
        }
        const results = searchDocs(q);
        renderResults(results, resultsEl);
        resultsEl.classList.add('active');
      }, 120);
    });

    input.addEventListener('focus', () => {
      if (input.value.trim().length >= 2) {
        resultsEl.classList.add('active');
      }
    });

    document.addEventListener('click', e => {
      if (!container.contains(e.target)) {
        resultsEl.classList.remove('active');
      }
    });

    input.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        resultsEl.classList.remove('active');
        input.blur();
      }
    });
  });

  // Topbar search button triggers focus on nearby search input
  const searchBtn = document.querySelector('.topbar-search-btn');
  if (searchBtn) {
    searchBtn.addEventListener('click', () => {
      const heroInput = document.querySelector('.search-input');
      if (heroInput) {
        heroInput.focus();
        heroInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }
}

// ─── MOBILE NAV ─────────────────────────────────
function initMobileNav() {
  const toggle = document.getElementById('navToggle');
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');

  if (!toggle || !sidebar) return;

  function openNav() {
    sidebar.classList.add('open');
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  function closeNav() {
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  toggle.addEventListener('click', () => {
    if (sidebar.classList.contains('open')) closeNav();
    else openNav();
  });

  overlay.addEventListener('click', closeNav);

  // Close on nav link click (mobile)
  sidebar.querySelectorAll('.nav-link').forEach(link => {
    link.addEventListener('click', () => {
      if (window.innerWidth < 768) closeNav();
    });
  });
}

// ─── ACTIVE NAV LINK ────────────────────────────
function initActiveNav() {
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-link').forEach(link => {
    const href = link.getAttribute('href');
    if (href === currentPage || (currentPage === '' && href === 'index.html')) {
      link.classList.add('active');
    }
  });
}

// ─── COPY BUTTONS ───────────────────────────────
function initCopyButtons() {
  document.querySelectorAll('.copy-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const block = btn.closest('.code-block, .terminal');
      if (!block) return;
      const pre = block.querySelector('pre');
      if (!pre) return;
      const text = pre.textContent || '';
      navigator.clipboard.writeText(text).then(() => {
        btn.textContent = 'copied';
        btn.classList.add('copied');
        setTimeout(() => {
          btn.textContent = 'copy';
          btn.classList.remove('copied');
        }, 2000);
      }).catch(() => {
        btn.textContent = 'failed';
        setTimeout(() => { btn.textContent = 'copy'; }, 2000);
      });
    });
  });
}

// ─── TOC SCROLL TRACKING ────────────────────────
function initTOC() {
  const tocLinks = document.querySelectorAll('.toc-list a');
  if (tocLinks.length === 0) return;

  const headings = Array.from(document.querySelectorAll('h2[id], h3[id]'));

  function updateActive() {
    let current = '';
    const topOffset = 80;

    headings.forEach(h => {
      if (h.getBoundingClientRect().top <= topOffset) {
        current = '#' + h.id;
      }
    });

    tocLinks.forEach(link => {
      link.classList.toggle('active', link.getAttribute('href') === current);
    });
  }

  window.addEventListener('scroll', updateActive, { passive: true });
  updateActive();
}

// ─── INIT ────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initSearch();
  initMobileNav();
  initActiveNav();
  initCopyButtons();
  initTOC();
});
