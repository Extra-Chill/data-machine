/* =========================================================
   AuroraOS 88 — shared document content
   Used by the deep-link entry pages (about.html, projects.html)
   so the long-form copy lives in exactly one place. On the
   main index.html the content lives inline in <template>s;
   here we inject it into any empty <template data-content>.
   ========================================================= */
'use strict';

AOS.CONTENT = {
  'readme': `
    <article class="doc">
      <h1>readme.txt — about Vega Sato</h1>
      <p class="doc-lead">Synthwave producer by night, indie game developer by the other part of the night. This whole website is a tiny operating system because a plain scrolling page felt like a waste of a perfectly good computer.</p>
      <h2>// who</h2>
      <p>I'm <b>Vega Sato</b>, based in a converted radio-repair shop in Kanazawa. I make music under the name <b>NEON COASTLINE</b> and ship small, strange games under the studio <b>Tidewave Interactive</b>. Most of my work lives somewhere between an arcade cabinet and a 2 a.m. drive along an empty coastal highway.</p>
      <h2>// the short version</h2>
      <ul>
        <li>🎹 Three albums + a stack of singles, all self-produced.</li>
        <li>🕹️ Two released games, one in early access, one that will never ship (on purpose).</li>
        <li>📼 Composed soundtracks for four other indie titles.</li>
        <li>🎛️ I build my own synth patches and most of my own tools — including this OS.</li>
      </ul>
      <h2>// philosophy</h2>
      <p>Constraints are instruments. I write music on a broken cassette 4-track because the wow-and-flutter does half the arranging for me. I prototype games in a weekend or not at all. And I believe a personal site should feel like <em>somewhere</em>, not just something you scroll past.</p>
      <h2>// what's on this disk</h2>
      <p>Open the <b>Projects</b> drive for games and records, run the <b>Synth</b> to make some noise, sign the <b>Guestbook</b>, or just type <code>help</code> in the <b>Terminal</b>. Everything here actually works. Poke at it.</p>
      <p class="doc-sign">— V. Sato, somewhere past midnight</p>
    </article>`,
  'file-coastline': `
    <article class="doc">
      <h1>NEON COASTLINE — discography</h1>
      <p class="doc-lead">Four releases of widescreen, slightly-rusted synthwave. All written, played, and mixed in the shop.</p>
      <h2>Headlight Country (LP, 2025)</h2>
      <p>Eleven tracks about driving away from things. Recorded mostly between 1 and 4 a.m. The title track has a 90-second outro that's just a tape loop slowly eating itself — that was an accident I decided to keep.</p>
      <h2>Saltwater Arcade (EP, 2024)</h2>
      <p>Five upbeat ones for the part of the night that still feels hopeful. Heavy on FM bells and a drum machine I found in a flooded basement (it survived; I cleaned it with rice).</p>
      <h2>Low Tide Transmissions (LP, 2023)</h2>
      <p>The slow record. Ambient-leaning, lots of field recordings from the Sea of Japan stitched under the pads. Best heard on headphones during rain.</p>
      <h2>Ghost in the FM (single, 2022)</h2>
      <p>The first thing I ever released as NEON COASTLINE. Three chords and a lot of reverb. People still ask for it live, which is humbling and slightly annoying.</p>
      <p class="doc-note">▸ Tip: open the <b>Synth</b> app and the bassline preset is straight off <i>Headlight Country</i>.</p>
    </article>`,
  'file-tidewave': `
    <article class="doc">
      <h1>Tidewave Interactive — games</h1>
      <p class="doc-lead">Small games with a long aftertaste. I make the kind of thing that fits on a floppy in spirit, if not in megabytes.</p>
      <h2>★ LIGHTHOUSE-KEEPER (2025, PC / Switch)</h2>
      <p>A slow horror-management game. You run a lighthouse, ration your oil, and decide which ships to warn. The fog is procedural and it lies to you. My most-played title and the reason I can afford synthesizers.</p>
      <h2>★ CASSETTE PILOT (2023, PC)</h2>
      <p>An auto-runner where the level <i>is</i> the music — the track is generated from the cassette you load, and the obstacles fall on the beat. Built the audio engine first, the game second.</p>
      <h2>◐ DEEP STATIC (early access, 2026)</h2>
      <p>Submarine signals-intelligence roguelike. You translate enemy radio chatter to survive. Currently 40% built and 100% over-scoped, as is tradition.</p>
      <h2>✕ THE LAST ARCADE (will never ship)</h2>
      <p>A game about a game that doesn't exist. I work on it one night a year, on the winter solstice, and I will never finish it. That's the whole point.</p>
    </article>`,
  'file-rig': `
    <article class="doc">
      <h1>the_rig.txt — gear &amp; tools</h1>
      <h2>// sound</h2>
      <ul>
        <li>Roland Juno-106 (the heart of everything)</li>
        <li>Yamaha DX7 mk1 — all the bells, none of the patience</li>
        <li>Tascam Portastudio 414 (the broken 4-track)</li>
        <li>A drum machine of unknown origin, est. 1986, flood survivor</li>
        <li>One very good spring reverb tank</li>
      </ul>
      <h2>// code</h2>
      <ul>
        <li>Games in a hand-rolled C engine + a Lua scripting layer</li>
        <li>Tools (like AuroraOS) in vanilla HTML/CSS/JS — no frameworks, ever</li>
        <li>A 12-year-old text editor I refuse to replace</li>
      </ul>
      <h2>// rules</h2>
      <ol>
        <li>If it can't fail interestingly, it's not worth shipping.</li>
        <li>Finish the loop before you polish the corners.</li>
        <li>Keep one project you'll never finish.</li>
      </ol>
    </article>`,
  'now': `
    <article class="doc">
      <h1>now.txt</h1>
      <p class="doc-lead">A snapshot of what's currently spinning. Last touched June 2026.</p>
      <ul class="now-list">
        <li><b>Building:</b> DEEP STATIC's signal-translation minigame (the fun part, finally).</li>
        <li><b>Writing:</b> album #4, working title <i>Harbor Lights Off</i>. Slower than the last one.</li>
        <li><b>Reading:</b> a 1970s manual for a synthesizer I will probably never own.</li>
        <li><b>Listening:</b> a lot of city-pop and the sound of rain on a tin roof.</li>
        <li><b>Avoiding:</b> THE LAST ARCADE, until the solstice.</li>
      </ul>
    </article>`
};

/* fill any empty <template data-content="..."> on the page */
(function () {
  document.querySelectorAll('#content-store template[data-content]').forEach(tpl => {
    const key = tpl.getAttribute('data-content');
    if (!tpl.innerHTML.trim() && AOS.CONTENT[key]) tpl.innerHTML = AOS.CONTENT[key];
  });
})();
