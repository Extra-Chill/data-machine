# WESTRIDGE LONGWAVE COOPERATIVE — Intended Solution Path

A "cursed"/haunted ARG fixture. A defunct shortwave-listening collective whose
auto-generated site is subtly aware of the visitor and hides a three-lock puzzle
that opens a hidden final page (`carrier.html`).

Everything is vanilla HTML/CSS/JS and works from `file://`. Open `index.html`.

---

## Files

```
51-cursed-arg-site/
├── index.html        Home / "Transmission" (entry, console breadcrumb, terminal)
├── manifesto.html    "The Charter" (7 principles + base64 reinforcement clue)
├── archive.html      "Field Logs" (5 logs: acrostic + ROT13 cipher = puzzle core)
├── join.html         "Join the Watch" (contact form + clue in SVG alt text)
├── carrier.html      HIDDEN final page — gated, only reachable by solving the puzzle
├── SOLUTION.md       this file
├── css/
│   └── style.css     shared CRT/phosphor stylesheet (reduced-motion safe)
└── js/
    ├── static.js     ambient canvas static (subtle, gated)
    ├── awareness.js  reactive "the site notices you" (whisper, idle, clock, glitch,
    │                 console breadcrumb, localStorage progress store)
    ├── terminal.js   hidden operator terminal + 3-stage puzzle validation
    ├── join.js       application-form eerie auto-reply
    └── carrier.js    gate logic for the hidden final page
```

Shared header/footer/terminal/whisper markup is inlined per page (file:// cannot
reliably fetch HTML partials).

---

## The "awareness" layer (atmosphere, not required to solve)

- First-visit vs return-visit whisper toasts addressed to "you" (localStorage `wlc.seen`).
- Idle detection: stop interacting and the site speaks (~32s).
- Witching-hour line if the local clock is 00:00–03:59.
- Time-on-page makes glitch bursts and canvas static slowly intensify.
- Tab-away changes the `<title>` to "[ are you still tuned in? ]".
- All strobing/animation is disabled under `prefers-reduced-motion`.

---

## THE PUZZLE — three locks, opened in the relay terminal

### Opening the terminal
On any page: press the **`~`** (tilde/backtick) key, **type the word `OPEN`**,
or enter the **Konami code** (↑↑↓↓←→←→ B A). Type `HINT` at any stage for help,
`EXIT` to close.

Progress persists in `localStorage` (`wlc.progress.v1`); the three "beacon" dots
in the terminal light up as each lock falls.

---

### Step 0 — Find the way in (breadcrumb)
Open the browser **console** on the home page. The operator log prints:
> FIELD LOG fragments are kept at /archive.html — five of them survived.
> one log is "corrupted." it is not. read it through a 13-place shift.
> when you know the operator's name, open the TERMINAL (press ~, or type OPEN).

The same hints exist as **HTML comments** in `index.html` and `archive.html`.

---

### Step 1 — STAGE 1: the operator's name  →  `HOLLOWAY`
On **archive.html**, LOG 03 is flagged `[FLAGGED: CORRUPT]` and carries a
`data-cipher="rot13"` block of "garbled" text. It is **ROT13** (Caesar shift 13).

Ciphertext:
```
vs lbh ner ernqvat guvf, gur jngpu vf lbhef abj. gur bcrengbe ba qhgl gbavtug jnf zr.
gur anzr lbh jnag, gur anzr gung bcraf gur grezvany, vf ZL anzr: ubyybjnl.
gur erprvire vgfrys vf anzrq va gur gvgyrf nobir — svefg yrggref, gbc gb obggbz.
```
Decoded (ROT13):
```
if you are reading this, the watch is yours now. the operator on duty tonight was me.
the name you want, the name that opens the terminal, is MY name: holloway.
the receiver itself is named in the titles above — first letters, top to bottom.
```

➡ In the terminal at **STAGE 1 (IDENTIFY THE OPERATOR)**, enter: **`HOLLOWAY`**
(case-insensitive). Lock 1 falls; Holloway tells you to read the log titles.

---

### Step 2 — STAGE 2: the receiver's name  →  `NIGHT`
The five Field Log titles, in shift order, are:

1. **N**orthwind, and a number that was not random
2. **I**nterlace patterns over the dead band
3. **G**roundwave bleed, transcription unreadable
4. **H**alflight transmission, repeating
5. **T**idewater silence, and the last shift

First letters top-to-bottom = **N I G H T** → **NIGHT** (the receiver is called
"the Night Glass"; `NIGHT`, `NIGHTGLASS`, or `THE NIGHT GLASS` all accept).

➡ In the terminal at **STAGE 2 (NAME THE RECEIVER)**, enter: **`NIGHT`**.
Lock 2 falls; Holloway points you to the frequency.

---

### Step 3 — STAGE 3: the frequency  →  `14770`
The frequency **14.770 kHz** is printed in the footer of every page
("last carrier 14.770 kHz"), on the home hero dial, in the ticker, and inside the
SVG receiver on join.html. It is also confirmed by the **base64** line on
manifesto.html:
```
dGhlIGZyZXF1ZW5jeSBpcyAxNDc3MA==   ->   "the frequency is 14770"
```

➡ In the terminal at **STAGE 3 (TUNE TO FREQUENCY)**, enter the digits: **`14770`**
(`14.770` / `147.70` also accepted). Lock 3 falls.

---

### Step 4 — The revelation
On the third unlock the terminal auto-redirects to **`carrier.html`** after ~5s
(or type `CARRIER` / `GO`). There is **no link to carrier.html anywhere on the
surface site** — it is gated by `carrier.js`, which checks all three localStorage
flags. If you reach it without solving, you see the "CARRIER SEALED" locked view.

The unlocked page is the ending: Holloway's direct address to the new operator,
the reveal that the cooperative never truly disbanded and that the carrier was
*found already running*, and the induction of the visitor as "chair five."

A "surrender the watch" link on carrier.html clears localStorage to reset the ARG.

---

## Quick verification checklist
1. Open `index.html`; check console breadcrumb prints.
2. Press `~` → terminal opens. Type `HOLLOWAY` → stage 1 clears (beacon 1 lit).
3. Type `NIGHT` → stage 2 clears (beacon 2 lit).
4. Type `14770` → stage 3 clears → auto-redirect to `carrier.html` (unlocked).
5. Visit `carrier.html` directly in a fresh profile → shows "CARRIER SEALED".
6. Toggle OS "reduce motion" → static calms, glitch/animation stop.
```
ROT13:  HOLLOWAY  <->  ubyybjnl
base64: 14770     <->  dGhlIGZyZXF1ZW5jeSBpcyAxNDc3MA==
```
