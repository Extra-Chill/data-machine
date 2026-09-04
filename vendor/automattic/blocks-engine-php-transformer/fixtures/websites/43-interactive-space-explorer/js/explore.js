/* =========================================================
   AETHELON — interactive system explorer (explore.html)
   Click a body in the Saturn system map → the detail panel
   updates with real(istic) physical data. Keyboard accessible.
   ========================================================= */
'use strict';

(function () {
  const map = document.querySelector('.explorer-map');
  const panel = document.querySelector('.explorer-panel');
  if (!map || !panel) return;

  /* Plausible figures for the Saturn system + the AETHELON target. */
  const BODIES = {
    saturn: {
      name: 'Saturn', type: 'Gas giant · ringed planet', tag: 'Primary',
      color: '#e3c98a', glow: 'rgba(255,212,121,.45)',
      desc: 'The sixth planet and the gravitational anchor of AETHELON’s destination. Its vast ring system and 146 confirmed moons make it the richest planetary laboratory in the outer Solar System.',
      stats: {
        'Mean radius': '58,232 km', 'Mass': '5.68 × 10²⁶ kg',
        'Day length': '10.7 hours', 'Year': '29.4 Earth years',
        'Known moons': '146', 'Surface gravity': '10.4 m/s²'
      }
    },
    titan: {
      name: 'Titan', type: 'Moon · hydrocarbon world', tag: 'Flyby target',
      color: '#d8a24a', glow: 'rgba(255,168,74,.4)',
      desc: 'Saturn’s largest moon and the only one with a dense atmosphere — thicker than Earth’s. Lakes of liquid methane and ethane pool across its surface. AETHELON performs two gravity-assist flybys here.',
      stats: {
        'Mean radius': '2,575 km', 'Orbital period': '15.9 days',
        'Distance from Saturn': '1,221,870 km', 'Surface temp': '−90 °C... −179 °C',
        'Atmosphere': 'N₂ + CH₄', 'Surface gravity': '1.35 m/s²'
      }
    },
    enceladus: {
      name: 'Enceladus', type: 'Ice moon · ocean world', tag: 'Prime objective',
      color: '#bfeaff', glow: 'rgba(127,231,255,.6)',
      desc: 'AETHELON’s prime objective. Beneath a fractured ice shell lies a global liquid-water ocean that vents into space through the south-polar “tiger stripes.” Cassini detected silica, salts and organics in the plumes — the ingredients for habitability.',
      stats: {
        'Mean radius': '252 km', 'Orbital period': '1.37 days',
        'Distance from Saturn': '237,948 km', 'Surface temp': '−201 °C',
        'Ocean depth': '~10 km (est.)', 'Surface gravity': '0.11 m/s²'
      }
    },
    dione: {
      name: 'Dione', type: 'Moon · icy body', tag: 'Survey target',
      color: '#cfd6e0', glow: 'rgba(200,210,230,.35)',
      desc: 'A dense, heavily cratered ice moon laced with bright “wispy” ice cliffs. A tenuous oxygen exosphere and possible subsurface ocean make Dione a secondary AETHELON survey target during cruise.',
      stats: {
        'Mean radius': '561 km', 'Orbital period': '2.74 days',
        'Distance from Saturn': '377,396 km', 'Surface temp': '−186 °C',
        'Density': '1.48 g/cm³', 'Surface gravity': '0.23 m/s²'
      }
    },
    tethys: {
      name: 'Tethys', type: 'Moon · ice & rock', tag: 'Survey target',
      color: '#e6ecf2', glow: 'rgba(230,236,242,.35)',
      desc: 'An extraordinarily reflective moon of nearly pure water ice, scarred by the giant Odysseus impact crater and the 2,000-km Ithaca Chasma rift. AETHELON images it on inbound approach.',
      stats: {
        'Mean radius': '531 km', 'Orbital period': '1.89 days',
        'Distance from Saturn': '294,619 km', 'Surface temp': '−187 °C',
        'Density': '0.98 g/cm³', 'Surface gravity': '0.15 m/s²'
      }
    }
  };

  const nodes = map.querySelectorAll('.body-node');

  function render(key) {
    const b = BODIES[key];
    if (!b) return;
    panel.querySelector('[data-ptag]').textContent = b.tag;
    panel.querySelector('[data-pname]').textContent = b.name;
    panel.querySelector('[data-ptype]').textContent = b.type;
    panel.querySelector('[data-pdesc]').textContent = b.desc;
    const orb = panel.querySelector('.orb');
    orb.style.background = `radial-gradient(circle at 34% 30%, #fff6, ${b.color} 38%, ${shade(b.color, -40)})`;
    orb.style.setProperty('--bodyglow', b.glow);
    const grid = panel.querySelector('.pstats');
    grid.innerHTML = '';
    Object.entries(b.stats).forEach(([k, v]) => {
      const row = document.createElement('div');
      row.className = 'row';
      row.innerHTML = `<span class="k">${k}</span><span class="v">${v}</span>`;
      grid.appendChild(row);
    });

    nodes.forEach(n => {
      const on = n.dataset.body === key;
      n.classList.toggle('selected', on);
      n.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  // darken a hex colour by an amount
  function shade(hex, amt) {
    const n = parseInt(hex.slice(1), 16);
    let r = (n >> 16) + amt, g = ((n >> 8) & 255) + amt, b = (n & 255) + amt;
    r = Math.max(0, Math.min(255, r)); g = Math.max(0, Math.min(255, g)); b = Math.max(0, Math.min(255, b));
    return `rgb(${r},${g},${b})`;
  }

  nodes.forEach(n => {
    n.setAttribute('role', 'button');
    n.setAttribute('tabindex', '0');
    n.setAttribute('aria-pressed', 'false');
    n.addEventListener('click', () => render(n.dataset.body));
    n.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); render(n.dataset.body); }
    });
  });

  render('enceladus'); // default selection
})();
