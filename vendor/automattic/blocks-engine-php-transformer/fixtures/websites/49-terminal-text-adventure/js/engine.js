/* ============================================================================
   THE WAKING DEPTH  —  game engine
   A parser-based interactive-fiction engine. Vanilla JS, no dependencies.

   Responsibilities:
     - hold mutable game state (room, inventory, item locations, flags, score)
     - parse a typed command into { verb, noun, prep, second }
     - resolve nouns against items + scenery (with synonyms)
     - execute verbs and return printable text
     - serialize / restore state for save/load

   The engine is UI-agnostic: it returns strings (or arrays of lines). The
   terminal in main.js decides how to render them (typewriter, etc.).
   ============================================================================ */

(function (global) {
  "use strict";

  const W = global.WAKING;

  function Engine() {
    this.reset();
  }

  /* ---- lifecycle --------------------------------------------------- */

  Engine.prototype.reset = function () {
    this.room = W.START_ROOM;
    this.moves = 0;
    this.score = 0;
    this.maxScore = 6;
    this.flags = Object.assign({}, W.INITIAL_FLAGS);
    this.gameOver = false;
    this.visited = {};

    // mutable copy of item locations
    this.itemLoc = {};
    for (const id in W.ITEMS) {
      this.itemLoc[id] = W.ITEMS[id].loc;
    }
    // place contained items inside their containers (loc = container id)
    for (const id in W.ITEMS) {
      const c = W.ITEMS[id].contains;
      if (c) c.forEach((childId) => { this.itemLoc[childId] = id; });
    }
    // mutable container state
    this.containerOpen = {};
    for (const id in W.ITEMS) {
      if (W.ITEMS[id].closed) this.containerOpen[id] = false;
    }
    this.visited[this.room] = true;
  };

  /* ---- serialization ----------------------------------------------- */

  Engine.prototype.serialize = function () {
    return {
      v: 1,
      room: this.room,
      moves: this.moves,
      score: this.score,
      flags: this.flags,
      itemLoc: this.itemLoc,
      containerOpen: this.containerOpen,
      gameOver: this.gameOver,
      visited: this.visited
    };
  };

  Engine.prototype.restore = function (s) {
    if (!s || s.v !== 1) return false;
    this.room = s.room;
    this.moves = s.moves;
    this.score = s.score;
    this.flags = s.flags;
    this.itemLoc = s.itemLoc;
    this.containerOpen = s.containerOpen;
    this.gameOver = s.gameOver;
    this.visited = s.visited || {};
    return true;
  };

  /* ---- helpers ----------------------------------------------------- */

  Engine.prototype.itemsIn = function (loc) {
    const out = [];
    for (const id in this.itemLoc) {
      if (this.itemLoc[id] === loc) out.push(id);
    }
    return out;
  };

  Engine.prototype.has = function (id) {
    return this.itemLoc[id] === "inventory";
  };

  Engine.prototype.here = function (id) {
    // item is takeable/usable from the current scene: in room or inventory
    return this.itemLoc[id] === this.room || this.itemLoc[id] === "inventory";
  };

  // Resolve a typed noun into an item id (searching scene + inventory),
  // matching against name + aliases. Returns id or null.
  Engine.prototype.resolveItem = function (noun) {
    if (!noun) return null;
    noun = noun.toLowerCase().trim();
    let best = null;
    for (const id in W.ITEMS) {
      const it = W.ITEMS[id];
      const names = [it.short, ...(it.aliases || [])].map((s) => s.toLowerCase());
      if (names.includes(noun)) {
        // prefer items in scene/inventory
        if (this.here(id)) return id;
        best = best || id;
      }
    }
    // partial contains match as fallback, scene-first
    for (const id in W.ITEMS) {
      const it = W.ITEMS[id];
      const names = [it.short, ...(it.aliases || [])].map((s) => s.toLowerCase());
      if (names.some((n) => n.includes(noun) || noun.includes(n))) {
        if (this.here(id)) return id;
        best = best || id;
      }
    }
    return best;
  };

  // Resolve scenery in the current room. Returns description string or null.
  Engine.prototype.resolveScenery = function (noun) {
    if (!noun) return null;
    noun = noun.toLowerCase().trim();
    const sc = W.SCENERY[this.room];
    if (!sc) return null;
    if (sc[noun]) return sc[noun];
    for (const key in sc) {
      if (key.includes(noun) || noun.includes(key)) return sc[key];
    }
    return null;
  };

  /* ---- room rendering ---------------------------------------------- */

  Engine.prototype.describeRoom = function (brief) {
    const r = W.ROOMS[this.room];
    let out = "== " + r.name.toUpperCase() + " ==\n";
    if (!brief) out += r.desc + "\n";

    // dynamic notes per puzzle state
    if (this.room === "lightDoor" && this.flags.doorPried) {
      out += "\nThe boards lie splintered at your feet. The door stands open " +
        "to the NORTH, breathing out cold lighthouse air.\n";
    }

    // items resting in the room
    const visible = this.itemsIn(this.room);
    if (visible.length) {
      out += "\nYou can see here: " + this.listNames(visible) + ".";
    }

    out += "\n" + this.exitLine();
    return out;
  };

  Engine.prototype.exitLine = function () {
    const exits = this.currentExits();
    const dirs = Object.keys(exits);
    if (!dirs.length) return "There are no obvious exits.";
    return "Exits: " + dirs.map((d) => d.toUpperCase()).join(", ") + ".";
  };

  // exits, accounting for the pried door opening north into the foyer
  Engine.prototype.currentExits = function () {
    const base = Object.assign({}, W.ROOMS[this.room].exits);
    if (this.room === "lightDoor" && this.flags.doorPried) {
      base.north = "foyer";
    }
    return base;
  };

  Engine.prototype.listNames = function (ids) {
    const names = ids.map((id) => W.ITEMS[id].name);
    if (names.length === 1) return names[0];
    return names.slice(0, -1).join(", ") + " and " + names[names.length - 1];
  };

  /* ---- the main entry point ---------------------------------------- */
  // Returns { text: string, moved: bool, win: bool }
  Engine.prototype.execute = function (raw) {
    const cmd = this.parse(raw);
    if (!cmd.verb) {
      return { text: "I didn't catch that. Type HELP if you're lost." };
    }
    if (this.gameOver && !["restart", "look", "score", "help", "about"].includes(cmd.verb)) {
      return { text: "The night is over. Type RESTART to begin again." };
    }
    const handler = this.verbs[cmd.verb];
    if (!handler) {
      return { text: this.unknownVerb(cmd.verb) };
    }
    const before = this.room;
    const result = handler.call(this, cmd);
    // moves only count for world-affecting commands
    if (result && result.tick !== false && !result.win && !this.gameOver) {
      this.moves++;
    }
    if (result && result.win) this.gameOver = true;
    return Object.assign({ moved: before !== this.room }, result);
  };

  /* ---- parser ------------------------------------------------------ */

  const DIRECTIONS = {
    n: "north", north: "north",
    s: "south", south: "south",
    e: "east", east: "east",
    w: "west", west: "west",
    u: "up", up: "up", upstairs: "up", climb: "up",
    d: "down", down: "down", downstairs: "down"
  };

  const VERB_SYNONYMS = {
    look: "look", l: "look", examine: "examine", x: "examine", inspect: "examine",
    check: "examine", read: "read", peruse: "read",
    take: "take", get: "take", grab: "take", pick: "take", pickup: "take",
    drop: "drop", put: "drop", discard: "drop",
    go: "go", move: "go", walk: "go", head: "go",
    open: "open", unlock: "unlock", close: "close",
    use: "use", apply: "use", fit: "use", insert: "use", fill: "use", feed: "use", pour: "use",
    pry: "pry", prise: "pry", lever: "pry", force: "pry", wrench: "pry",
    light: "light", ignite: "light", strike: "light", kindle: "light",
    inventory: "inventory", i: "inventory", inv: "inventory",
    help: "help", "?": "help", commands: "help",
    save: "save", load: "load", restart: "restart", reset: "restart",
    score: "score", map: "map", wait: "wait", z: "wait",
    about: "about", credits: "about",
    turn: "use", combine: "use"
  };

  const STOPWORDS = ["the", "a", "an", "at", "to", "into", "my", "some", "of"];

  Engine.prototype.parse = function (raw) {
    const tokens = (raw || "").toLowerCase().trim().split(/\s+/).filter(Boolean);
    if (!tokens.length) return {};

    // bare direction ("north", "n", "go north")
    if (DIRECTIONS[tokens[0]] && tokens.length === 1) {
      return { verb: "go", noun: DIRECTIONS[tokens[0]] };
    }

    let verb = VERB_SYNONYMS[tokens[0]] || null;

    // "go north" / "walk to kitchen"
    if (verb === "go") {
      const rest = tokens.slice(1).filter((t) => !STOPWORDS.includes(t));
      const dir = rest.length ? DIRECTIONS[rest[0]] || rest[0] : null;
      return { verb: "go", noun: dir };
    }

    if (!verb) {
      // maybe it's "look at X" handled below; otherwise unknown
      // also allow "x door"
      return { verb: tokens[0], noun: tokens.slice(1).join(" "), unknown: true };
    }

    // split remaining into noun [prep second]
    let rest = tokens.slice(1).filter((t) => !STOPWORDS.includes(t) || t === "on" || t === "with" || t === "in");
    // find a preposition to separate two objects (use X on Y)
    const preps = ["on", "with", "in", "from", "into"];
    let prepIdx = -1, prep = null;
    for (let i = 0; i < rest.length; i++) {
      if (preps.includes(rest[i])) { prepIdx = i; prep = rest[i]; break; }
    }
    let noun, second;
    if (prepIdx >= 0) {
      noun = rest.slice(0, prepIdx).filter((t) => !STOPWORDS.includes(t)).join(" ");
      second = rest.slice(prepIdx + 1).filter((t) => !STOPWORDS.includes(t)).join(" ");
    } else {
      noun = rest.filter((t) => !STOPWORDS.includes(t)).join(" ");
    }
    return { verb: verb, noun: noun || null, prep: prep, second: second || null };
  };

  /* ---- unknown verb, in character ---------------------------------- */

  Engine.prototype.unknownVerb = function (verb) {
    const lines = [
      "The word \"" + verb + "\" means nothing to the cold logic of this place.",
      "You can't \"" + verb + "\" here. The lighthouse does not understand you.",
      "Nothing happens. The dark seems almost to be waiting for you to make sense.",
      "That's not a thing you can do tonight. (Type HELP for what you can.)"
    ];
    return lines[this.moves % lines.length];
  };

  /* ============================================================== */
  /*  VERBS                                                          */
  /* ============================================================== */

  Engine.prototype.verbs = {

    look: function (cmd) {
      if (cmd.noun) return this.verbs.examine.call(this, cmd);
      return { text: this.describeRoom(false), tick: false };
    },

    examine: function (cmd) {
      if (!cmd.noun) return { text: "Examine what?", tick: false };
      // self
      if (["me", "self", "myself", "hands"].includes(cmd.noun)) {
        return { text: "You're cold to the bone and your hands won't quite stop " +
          "shaking. You came out here to bury a question. The question has other plans.", tick: false };
      }
      const id = this.resolveItem(cmd.noun);
      if (id && this.here(id)) {
        let t = W.ITEMS[id].look;
        if (W.ITEMS[id].closed) {
          t += this.containerOpen[id] ? " It is open." : " It is closed.";
          if (this.containerOpen[id]) {
            const inside = this.itemsIn(id);
            if (inside.length) t += " Inside: " + this.listNames(inside) + ".";
          }
        }
        return { text: t, tick: false };
      }
      const sc = this.resolveScenery(cmd.noun);
      if (sc) return { text: sc, tick: false };
      if (id) return { text: "You don't see any " + cmd.noun + " here.", tick: false };
      return { text: "There's no " + cmd.noun + " here worth your attention.", tick: false };
    },

    read: function (cmd) {
      if (!cmd.noun) return { text: "Read what?", tick: false };
      const id = this.resolveItem(cmd.noun);
      if (!id || !this.here(id)) {
        const sc = this.resolveScenery(cmd.noun);
        if (sc) return { text: "There's nothing written there to read.", tick: false };
        return { text: "There's no " + cmd.noun + " here to read.", tick: false };
      }
      const it = W.ITEMS[id];
      if (it.read) {
        // reading the chart/logbook is a story beat
        if (id === "chart" && !this.flags.gotYear) {
          this.flags.gotYear = true;
        }
        return { text: it.read, tick: false };
      }
      return { text: "There's nothing to read on " + it.name + ".", tick: false };
    },

    take: function (cmd) {
      if (!cmd.noun) return { text: "Take what?" };
      if (cmd.noun === "all" || cmd.noun === "everything") {
        const ids = this.itemsIn(this.room).filter((id) => W.ITEMS[id].portable);
        if (!ids.length) return { text: "There's nothing here to take." };
        ids.forEach((id) => { this.itemLoc[id] = "inventory"; });
        return { text: "Taken: " + this.listNames(ids) + "." };
      }
      const id = this.resolveItem(cmd.noun);
      if (!id) return { text: "You don't see any " + cmd.noun + " here." };
      if (this.has(id)) return { text: "You're already carrying " + W.ITEMS[id].name + "." };
      if (this.itemLoc[id] !== this.room) {
        // maybe it's inside an open container in the room
        const cont = this.itemLoc[id];
        if (this.containerOpen[cont] && this.itemLoc[cont] === "inventory") {
          this.itemLoc[id] = "inventory";
          return { text: "You take " + W.ITEMS[id].name + " out of " + W.ITEMS[cont].name + "." };
        }
        if (this.containerOpen[cont] && this.itemLoc[cont] === this.room) {
          this.itemLoc[id] = "inventory";
          return { text: "You take " + W.ITEMS[id].name + " from " + W.ITEMS[cont].name + "." };
        }
        return { text: "You don't see any " + cmd.noun + " here." };
      }
      if (W.ITEMS[id].portable === false) {
        return { text: W.ITEMS[id].fixed || "That won't come loose, however you pull." };
      }
      this.itemLoc[id] = "inventory";
      return { text: "You take " + W.ITEMS[id].name + "." };
    },

    drop: function (cmd) {
      if (!cmd.noun) return { text: "Drop what?" };
      const id = this.resolveItem(cmd.noun);
      if (!id || !this.has(id)) return { text: "You aren't carrying any " + cmd.noun + "." };
      this.itemLoc[id] = this.room;
      return { text: "You set down " + W.ITEMS[id].name + "." };
    },

    inventory: function () {
      const ids = this.itemsIn("inventory");
      if (!ids.length) {
        return { text: "Your hands are empty. You feel underprepared for a haunted lighthouse.", tick: false };
      }
      let t = "You are carrying:";
      ids.forEach((id) => {
        t += "\n  - " + W.ITEMS[id].name;
        if (W.ITEMS[id].closed && this.containerOpen[id]) {
          const inside = this.itemsIn(id);
          if (inside.length) t += " (open, containing " + this.listNames(inside) + ")";
          else t += " (open, empty)";
        } else if (W.ITEMS[id].closed) {
          t += " (closed)";
        }
      });
      return { text: t, tick: false };
    },

    go: function (cmd) {
      if (!cmd.noun) return { text: "Go where? Try a direction: N, S, E, W, UP, DOWN." };
      const dir = DIRECTIONS[cmd.noun] || cmd.noun;
      const exits = this.currentExits();
      if (!exits[dir]) {
        // blocked-door flavor
        if (this.room === "lightDoor" && dir === "north" && !this.flags.doorPried) {
          return { text: "The door is barred shut with nailed boards. You'll have " +
            "to PRY them loose first." };
        }
        return { text: "You can't go " + dir + " from here." };
      }
      this.room = exits[dir];
      const first = !this.visited[this.room];
      this.visited[this.room] = true;
      // arriving at the lamp room with the light unlit is fine; ending handled by LIGHT
      return { text: this.describeRoom(!first ? false : false) };
    },

    open: function (cmd) {
      if (!cmd.noun) return { text: "Open what?" };
      // door
      if (/door|boards?|planks?/.test(cmd.noun) && this.room === "lightDoor") {
        return this.verbs.pry.call(this, { noun: "door" });
      }
      // cabinet -> needs unlock
      if (/cabinet|safe/.test(cmd.noun) && this.room === "watchRoom") {
        if (this.flags.cabinetUnlocked) {
          return { text: "The cabinet is already open, its secrets bare." };
        }
        return { text: "The cabinet is locked. You'll need to UNLOCK it with the right key." };
      }
      const id = this.resolveItem(cmd.noun);
      if (id && this.here(id) && W.ITEMS[id].closed) {
        if (this.containerOpen[id]) return { text: W.ITEMS[id].name + " is already open." };
        this.containerOpen[id] = true;
        const inside = this.itemsIn(id);
        let t = "You work the lid loose. " + W.ITEMS[id].name + " opens";
        t += inside.length ? ", revealing " + this.listNames(inside) + "." : ", but it's empty.";
        if (id === "tin" && !this.flags.tinOpen) {
          this.flags.tinOpen = true;
          this.bumpScore(1);
          t += "\n[Your score rises.]";
        }
        return { text: t };
      }
      if (id) return { text: W.ITEMS[id].name + " isn't something you can open." };
      return { text: "There's nothing here called \"" + cmd.noun + "\" to open." };
    },

    close: function (cmd) {
      const id = cmd.noun && this.resolveItem(cmd.noun);
      if (id && this.here(id) && W.ITEMS[id].closed) {
        this.containerOpen[id] = false;
        return { text: "You press the lid shut on " + W.ITEMS[id].name + "." };
      }
      return { text: "You can't close that." };
    },

    unlock: function (cmd) {
      const target = (cmd.noun || "") + " " + (cmd.second || "");
      if (/cabinet|safe|oak/.test(target) || (this.room === "watchRoom" && /it|cabinet/.test(cmd.noun || ""))) {
        if (this.room !== "watchRoom") {
          return { text: "There's no cabinet here to unlock." };
        }
        if (this.flags.cabinetUnlocked) {
          return { text: "The cabinet already stands open." };
        }
        if (!this.has("brassKey")) {
          return { text: "It's locked, and you have nothing that fits the small brass lock." };
        }
        this.flags.cabinetUnlocked = true;
        this.itemLoc.logbook = "watchRoom";
        // safe within the cabinet holds the valve handle
        this.itemLoc.valveHandle = "watchRoom";
        this.bumpScore(1);
        return { text: "The cormorant-headed key turns with a click that seems too " +
          "loud. The oak door swings wide. Inside rests the keeper's LOGBOOK, and " +
          "behind it, in a felt-lined recess, the lamp's missing VALVE HANDLE.\n" +
          "[Your score rises.]" };
      }
      // unlock door
      if (/door/.test(cmd.noun || "") && this.room === "lightDoor") {
        return { text: "There's no lock — only nailed boards. Try PRY DOOR." };
      }
      return { text: "There's nothing here a key would help with." };
    },

    pry: function (cmd) {
      if (this.room !== "lightDoor") {
        return { text: "There's nothing here that needs prying." };
      }
      if (this.flags.doorPried) {
        return { text: "The boards are already torn away; the door stands open north." };
      }
      if (!this.has("crowbar")) {
        return { text: "Your bare fingers won't shift those nailed boards. You need " +
          "something with leverage — a bar of iron, perhaps." };
      }
      this.flags.doorPried = true;
      this.bumpScore(1);
      return { text: "You set the crowbar's claw under the first board and HEAVE. " +
        "Nails shriek out of old wood, one after another, until the boards clatter " +
        "down and the great door swings inward on a breath of cold, mineral air. " +
        "The way NORTH is open.\n[Your score rises.]" };
    },

    use: function (cmd) {
      // normalize: "use X on Y", "use X with Y", "turn valve", "light lamp"
      let a = cmd.noun, b = cmd.second;
      let idA = a && this.resolveItem(a);
      let idB = b && this.resolveItem(b);
      // If the verb's object is a feature (e.g. "fill lamp with oilcan") but the
      // real tool sits in the second slot, swap so the tool is primary.
      const aIsTool = idA && this.here(idA);
      const bIsTool = idB && this.here(idB);
      if (!aIsTool && bIsTool) {
        [a, b] = [b, a];
        [idA, idB] = [idB, idA];
      }

      // --- LAMP fueling chain (lamp room) ---
      if (this.room === "lampRoom") {
        const targetsLamp = (idB === null && b == null) || /lamp/.test(b || "") || (idB == null && /lamp/.test(a || ""));
        // USE OILCAN (ON LAMP)
        if (idA === "oilcan" || (idA == null && /oil/.test(a || ""))) {
          if (!this.has("oilcan")) return { text: "You don't have the oil can." };
          if (this.flags.lampFueled) return { text: "The lamp's reservoir is already brimming with oil." };
          this.flags.lampFueled = true;
          this.bumpScore(1);
          return { text: "You unscrew the lamp's reservoir cap and tip the oil can " +
            "to its long spout. Clear oil glugs down into the brass belly of the " +
            "lamp until it can hold no more. The lamp is fueled — but the regulator " +
            "won't draw without its handle.\n[Your score rises.]" };
        }
        // USE VALVE (ON LAMP)
        if (idA === "valveHandle" || /valve|handle|regulator|wheel/.test(a || "")) {
          if (!this.has("valveHandle")) return { text: "You don't have the valve handle." };
          if (!this.flags.lampFueled) return { text: "You fit the handle to the empty socket — but with no oil in the " +
            "reservoir, turning it does nothing. Fuel the lamp first." };
          if (this.flags.lampValved) return { text: "The valve handle is already fitted, the wick glistening and ready." };
          this.flags.lampValved = true;
          this.bumpScore(1);
          return { text: "You seat the brass starfish into the regulator socket and " +
            "turn. Somewhere below, a valve opens; oil climbs the great wick until " +
            "it darkens with fuel, fat and ready. All it lacks now is a flame.\n" +
            "[Your score rises.]" };
        }
        // USE MATCHES -> redirect to light
        if (idA === "matches" || /match/.test(a || "")) {
          return this.verbs.light.call(this, { noun: "lamp" });
        }
      }

      // --- crowbar on door ---
      if ((idA === "crowbar" || /crowbar|bar/.test(a || "")) && this.room === "lightDoor") {
        return this.verbs.pry.call(this, { noun: "door" });
      }
      // --- key on cabinet ---
      if ((idA === "brassKey" || /key/.test(a || "")) && /cabinet|safe|lock/.test(b || "")) {
        return this.verbs.unlock.call(this, { noun: "cabinet" });
      }

      if (idA && !this.has(idA) && this.itemLoc[idA] !== this.room) {
        return { text: "You don't have any " + a + "." };
      }
      if (idA) {
        return { text: "You can't find any obvious use for " + W.ITEMS[idA].name +
          (b ? " with the " + b : " here") + "." };
      }
      return { text: "Use what, on what?" };
    },

    light: function (cmd) {
      if (this.room !== "lampRoom") {
        return { text: "There's nothing here worth burning a match on." };
      }
      const wantsLamp = !cmd.noun || /lamp|wick|light|it/.test(cmd.noun);
      if (!wantsLamp) {
        return { text: "Light what? The only thing here that wants a flame is the LAMP." };
      }
      if (!this.has("matches")) {
        return { text: "You have nothing to strike a flame with. You'll need matches." };
      }
      if (!this.flags.lampFueled) {
        return { text: "You strike a match and hold it to the wick — but the wick is " +
          "bone-dry. The flame gutters and dies. The lamp needs OIL." };
      }
      if (!this.flags.lampValved) {
        return { text: "The match burns down toward your fingers. The wick is wet at " +
          "the base but the regulator isn't feeding it — you need to fit and turn " +
          "the VALVE HANDLE before the lamp will truly take light." };
      }
      // WIN
      this.flags.ending = "kept";
      this.bumpScore(1);
      return { win: true, text: this.endingText() };
    },

    wait: function () {
      const lines = [
        "Time passes. The sea heaves. Nothing changes, which is its own kind of warning.",
        "You wait. Far off, water moves against water, slow and deliberate.",
        "The dark holds its breath with you."
      ];
      return { text: lines[this.moves % lines.length] };
    },

    help: function () {
      return { text:
        "COMMANDS (synonyms in brackets):\n" +
        "  LOOK [L]              describe where you are\n" +
        "  EXAMINE <thing> [X]   look closely at something\n" +
        "  READ <thing>          read text on an object\n" +
        "  TAKE <thing> [GET]    pick something up (TAKE ALL works)\n" +
        "  DROP <thing>          set something down\n" +
        "  INVENTORY [I]         list what you carry\n" +
        "  GO <dir> / N S E W UP DOWN   move between rooms\n" +
        "  OPEN / CLOSE <thing>  open containers\n" +
        "  UNLOCK <thing>        unlock with a held key\n" +
        "  PRY <thing>           force something with a tool\n" +
        "  USE <a> ON <b>        combine or apply items\n" +
        "  LIGHT <thing>         strike a flame\n" +
        "  MAP                   show the map of explored rooms\n" +
        "  SCORE                 show score and objectives\n" +
        "  SAVE / LOAD / RESTART manage your game\n" +
        "  HELP / ABOUT          this text / the studio\n\n" +
        "Tip: your grandmother left a JOURNAL and a tide CHART upstairs. Read them.",
        tick: false };
    },

    about: function () {
      return { text:
        "THE WAKING DEPTH — Saltwire Interactive, 2026.\n" +
        "Written and coded by the studio. A short, complete interactive fiction " +
        "about a lighthouse, a grandmother, and the thing she kept company in the " +
        "dark. For the full studio notes, open ABOUT.HTML from the header.", tick: false };
    },

    score: function () {
      return { text: this.scoreText(), tick: false };
    },

    map: function () {
      return { text: "(The map is shown in the panel to the side. Type MAP again " +
        "to refresh it.)", tick: false, refreshMap: true };
    },

    save: function () {
      return { text: "[SAVE]", tick: false, action: "save" };
    },
    load: function () {
      return { text: "[LOAD]", tick: false, action: "load" };
    },
    restart: function () {
      return { text: "[RESTART]", tick: false, action: "restart" };
    }
  };

  /* ---- scoring & objectives ---------------------------------------- */

  Engine.prototype.bumpScore = function (n) {
    this.score = Math.min(this.maxScore, this.score + n);
  };

  Engine.prototype.scoreText = function () {
    let t = "SCORE: " + this.score + " / " + this.maxScore + "    MOVES: " + this.moves +
      "\nLOCATION: " + W.ROOMS[this.room].name + "\n\nOBJECTIVES:";
    const obj = [
      ["Get inside Cormorant Light", this.flags.doorPried],
      ["Open the biscuit tin", this.flags.tinOpen],
      ["Open the keeper's cabinet", this.flags.cabinetUnlocked],
      ["Fuel the lamp with oil", this.flags.lampFueled],
      ["Fit the regulator valve handle", this.flags.lampValved],
      ["Relight the lamp", this.flags.ending === "kept"]
    ];
    obj.forEach((o) => {
      t += "\n  [" + (o[1] ? "x" : " ") + "] " + o[0];
    });
    return t;
  };

  /* ---- endings ----------------------------------------------------- */

  Engine.prototype.endingText = function () {
    return (
      "You touch the match to the great wick.\n\n" +
      "It catches. It blooms. The flame climbs the wick and throws itself into " +
      "the thousand prisms of the lens, and the lamp of Cormorant Light WAKES — " +
      "a wheeling, white, impossible brightness that sweeps out across the black " +
      "water in a long slow arm.\n\n" +
      "Below the storm-glass, the pale enormous shape stops turning. It settles. " +
      "It sinks, by some fraction, back into the deep — content, the way a great " +
      "dog settles by a fire it trusts will not go out. You understand, now, your " +
      "grandmother's forty years. You understand the words on the door. WE KEEP " +
      "THE DARK — not from the world, but for it; we keep the dark company, so it " +
      "never comes ashore to look for warmth.\n\n" +
      "You sign your name beneath hers in the logbook. The lamp turns. The watch " +
      "is yours now.\n\n" +
      "                    * * *  THE LAMP IS LIT  * * *\n" +
      "                You kept the watch. THE WAKING DEPTH is won.\n\n" +
      "Score: " + this.score + " / " + this.maxScore + " in " + this.moves + " moves.\n" +
      "Type RESTART to keep the watch again, or read the world bible in STORY.HTML."
    );
  };

  global.WakingEngine = Engine;

})(window);
