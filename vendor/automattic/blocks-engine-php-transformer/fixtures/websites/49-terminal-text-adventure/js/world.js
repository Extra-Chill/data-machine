/* ============================================================================
   THE WAKING DEPTH  —  world data
   A drowned-lighthouse interactive fiction by Saltwire Interactive.

   This file is pure data + a few small helpers. The parser/engine lives in
   engine.js. Everything here is hand-authored: rooms, items, the puzzle
   chain, and the prose. No procedural generation, no placeholders.

   THE WINNING PATH (for maintainers — also stated in about.html):
     west, take crowbar, take rope, take oilcan, east,
     north, pry door, north,
     west, take matches, take tin, open tin, take key, east,
     up, read journal, take chart, up,
     unlock cabinet, read logbook, down,
     read chart  (gives the year 1891),
     down, west, ... use key on cabinet already done...
     -- full canonical solution is listed in about.html and the README of intent.
   ============================================================================ */

(function (global) {
  "use strict";

  /* ------------------------------------------------------------------ */
  /*  ROOMS                                                              */
  /*  Each room: id, name, an exits map, a base description, and an      */
  /*  optional dark flag. Descriptions are written to read well on a     */
  /*  green-phosphor screen, in second person, present tense.            */
  /* ------------------------------------------------------------------ */

  const ROOMS = {
    jetty: {
      name: "The Jetty",
      exits: { north: "shorePath", west: "boathouse" },
      desc:
        "Black water laps the rotten planks of the jetty, slow and patient, " +
        "the way water laps a thing it has decided to keep. Your boat knocks " +
        "against a piling behind you. Ahead, a gravel path climbs north toward " +
        "the dark column of CORMORANT LIGHT, the lighthouse where your " +
        "grandmother kept the lamp for forty years and then, one autumn, " +
        "stopped writing letters. A low BOATHOUSE leans to the west, its door ajar."
    },

    boathouse: {
      name: "The Boathouse",
      exits: { east: "jetty" },
      desc:
        "Inside the boathouse the air is thick with tar and old rope. A " +
        "skeletal dinghy hangs from the rafters, keel-up, dripping. Tools are " +
        "scattered across a workbench gone soft with damp. The whole place " +
        "smells of work abandoned mid-sentence."
    },

    shorePath: {
      name: "The Shore Path",
      exits: { south: "jetty", north: "lightDoor" },
      desc:
        "The path runs between heaped stones furred with bladderwrack. " +
        "Gulls wheel overhead but make no sound — you notice this and then " +
        "try very hard to un-notice it. To the north the lighthouse door " +
        "waits, crossed and recrossed with boards somebody nailed up in a hurry."
    },

    lightDoor: {
      name: "The Lighthouse Door",
      exits: { south: "shorePath" }, // north opens after prying
      desc:
        "The great door of Cormorant Light is barred shut with planks, the " +
        "nail-heads weeping rust. Carved above the lintel, half-swallowed by " +
        "lichen, three words: WE KEEP THE DARK. You were always told it read " +
        "WE KEEP THE LIGHT. You were always told a lot of things."
    },

    foyer: {
      name: "The Flooded Foyer",
      exits: { south: "lightDoor", west: "kitchen", up: "quarters" },
      desc:
        "Six inches of cold seawater stand on the foyer floor, perfectly " +
        "still, a black mirror that shows the ceiling and, when you move, " +
        "something that is not quite your reflection a half-second late. A " +
        "spiral stair winds up into shadow. An archway opens west to the kitchen."
    },

    kitchen: {
      name: "The Keeper's Kitchen",
      exits: { east: "foyer" },
      desc:
        "A cast-iron stove, cold as a held breath. A single chair pulled out " +
        "from the table as if its sitter rose mid-meal and never came back. A " +
        "cup of tea on the table has gone to a skin of dust. Drawers hang open. " +
        "On the windowsill, a battered TIN catches the grey light."
    },

    quarters: {
      name: "The Keeper's Quarters",
      exits: { down: "foyer", up: "watchRoom" },
      desc:
        "A narrow iron bed, neatly made, waits as if for a return. Your " +
        "grandmother's coat still hangs on the door, salt-stiff, the pockets " +
        "turned out. A writing desk faces the wall — never the window, you " +
        "remember her saying, never the sea. On the desk lies a leather " +
        "JOURNAL and, pinned above it, a tide CHART gone brown at the edges. " +
        "The stair continues up to the watch room."
    },

    watchRoom: {
      name: "The Watch Room",
      exits: { down: "quarters", up: "lampRoom" },
      desc:
        "The watch room rings the tower just below the lamp. Brass " +
        "instruments line the walls, their needles all stopped at the same " +
        "instant. A locked CABINET of dark oak is bolted to the curve of the " +
        "wall — the kind of cabinet a careful person keeps the important things " +
        "in. A short iron ladder leads up into the lamp room."
    },

    lampRoom: {
      name: "The Lamp Room",
      exits: { down: "watchRoom" },
      desc:
        "You stand inside the great glass eye of the lighthouse. The LAMP " +
        "squats at the centre, a vast cold contraption of brass and wick and " +
        "mirror, dead these long months. Beyond the storm-glass the sea heaves " +
        "without breaking, and far out — too far, too low — a pale shape turns " +
        "slowly beneath the surface, as though something very large were waking, " +
        "and waiting, very politely, for the light to go out for good."
    }
  };

  /* ------------------------------------------------------------------ */
  /*  ITEMS                                                              */
  /*  Each item:                                                         */
  /*    name        canonical display name                              */
  /*    aliases     words the parser accepts                            */
  /*    loc         starting location: a room id, "inventory", or null  */
  /*                (null = not yet in the world / inside something)     */
  /*    portable    can it be taken                                     */
  /*    look        examine text                                        */
  /*    read        (optional) text for READ                            */
  /*    fixed       (optional) custom refusal when taking               */
  /* ------------------------------------------------------------------ */

  const ITEMS = {
    crowbar: {
      name: "an iron crowbar",
      short: "crowbar",
      aliases: ["crowbar", "bar", "pry bar", "prybar", "iron"],
      loc: "boathouse",
      portable: true,
      look:
        "A heavy iron crowbar, the good kind, cold enough to ache your palm. " +
        "One end is flattened into a claw made for tearing nails out of wood."
    },
    rope: {
      name: "a coil of tarred rope",
      short: "rope",
      aliases: ["rope", "coil", "line"],
      loc: "boathouse",
      portable: true,
      look:
        "Forty feet of tarred rope, stiff with age but sound. It smells of " +
        "every storm it ever held a boat against."
    },
    oilcan: {
      name: "a brass oil can",
      short: "oil can",
      aliases: ["oilcan", "oil can", "can", "oil"],
      loc: "boathouse",
      portable: true,
      look:
        "A long-spouted brass oil can, the sort used to feed a lamp. You " +
        "shake it: it sloshes, still nearly full of clear whale-bright oil. " +
        "Enough, maybe, to wake a sleeping light."
    },
    matches: {
      name: "a box of matches",
      short: "matches",
      aliases: ["matches", "match", "matchbox", "box of matches"],
      loc: "kitchen",
      portable: true,
      look:
        "A tin matchbox, dented but dry. You slide it open: a dozen good " +
        "matches, their red heads intact. Fire, in a place the sea has tried " +
        "so hard to drown."
    },
    tin: {
      name: "a battered biscuit tin",
      short: "tin",
      aliases: ["tin", "biscuit tin", "biscuit"],
      loc: "kitchen",
      portable: true,
      look:
        "A biscuit tin gone to rust, painted once with a fishing scene now " +
        "almost worn away. It has a snug lid. Something shifts inside when you " +
        "tilt it.",
      closed: true,
      contains: ["brassKey"]
    },
    brassKey: {
      name: "a small brass key",
      short: "brass key",
      aliases: ["key", "brass key", "brasskey", "small key"],
      loc: null, // inside the tin
      portable: true,
      look:
        "A small brass key, warm in a way brass should not be, worn smooth " +
        "by a thumb that turned it ten thousand times. The bow is shaped like " +
        "a cormorant with its wings spread to dry."
    },
    journal: {
      name: "your grandmother's journal",
      short: "journal",
      aliases: ["journal", "diary", "book", "leather journal"],
      loc: "quarters",
      portable: true,
      look:
        "A leather journal, swollen with damp, your grandmother's slanting " +
        "hand crowding every page. The last entries are not in ink but in " +
        "something darker, and the writing leans harder, as if written fast.",
      read:
        "You turn to the final pages.\n\n" +
        "  \"It does not want the dark. That is the lie they carve on doors. It " +
        "wants the WATCHER kept watching — wants a light it can swim toward, a " +
        "hearth to circle, a reason to stay close to shore. The keepers before " +
        "me thought we kept the thing away. We did not. We kept it COMPANY.\n\n" +
        "  If the light goes out it will come in to find a new one. So you do " +
        "not let it out. You feed the lamp. You turn the lens. You hold the " +
        "watch.\n\n" +
        "  The combination is in the LOGBOOK, as it always was — the year the " +
        "lamp was first lit. I am old. I am tired of being a candle for a god. " +
        "If you are reading this, child, you have a choice I never let myself " +
        "make. The cabinet key is in the biscuit tin, where I hid it from " +
        "myself on the bad nights.\""
    },
    chart: {
      name: "a tide chart",
      short: "tide chart",
      aliases: ["chart", "tide chart", "tidechart", "map", "tides"],
      loc: "quarters",
      portable: true,
      look:
        "A tide chart for these waters, hand-corrected in your grandmother's " +
        "pen. In the margin, again and again, she has written a single number, " +
        "ringed and ringed until the paper nearly tears.",
      read:
        "Most of the chart is ordinary — high water, low water, the long " +
        "slow arithmetic of the moon. But the margin note, ringed a dozen " +
        "times, reads:\n\n" +
        "  \"LAMP FIRST LIT — 1891. Do not forget it. EVERYTHING turns on it.\"\n\n" +
        "It feels less like a date than a password."
    },
    logbook: {
      name: "the keeper's logbook",
      short: "logbook",
      aliases: ["logbook", "log", "log book", "ledger"],
      loc: null, // inside the cabinet
      portable: true,
      look:
        "A great ledger bound in sealskin, the official log of Cormorant " +
        "Light, every night of weather and watch set down in a column of " +
        "tireless hands going back generations.",
      read:
        "The first page, in the oldest ink, is a dedication:\n\n" +
        "  \"Cormorant Light. Lamp first lit this night, the keeper's hand " +
        "steady, the year of our account 1891. May it never go dark while the " +
        "deep is awake.\"\n\n" +
        "Every keeper since has signed beneath it. There is a blank line at " +
        "the bottom, waiting."
    },
    valveHandle: {
      name: "the regulator valve handle",
      short: "valve handle",
      aliases: ["valve", "handle", "valve handle", "regulator", "wheel"],
      loc: null, // inside the safe (cabinet)
      portable: true,
      look:
        "A starfish of dark brass — the missing handle for the lamp's oil " +
        "regulator. Without it the lamp cannot draw fuel; with it, the lamp " +
        "can be made to drink. Stamped on the hub: CORMORANT LIGHT — DO NOT " +
        "REMOVE."
    }
  };

  /* ------------------------------------------------------------------ */
  /*  FIXED SCENERY                                                      */
  /*  Non-takeable features that EXAMINE should respond to, keyed by     */
  /*  the room they live in. Keeps the world feeling solid.             */
  /* ------------------------------------------------------------------ */

  const SCENERY = {
    jetty: {
      boat: "Your little boat rides low and tired. You will not be needing it " +
            "again tonight. One way or another.",
      water: "The water is black and unnervingly flat, as if something below " +
             "were holding it down smooth to watch you better.",
      boathouse: "A low shed of grey board, west of here. Its door stands ajar."
    },
    boathouse: {
      bench: "The workbench has held every tool the light ever needed. Most " +
             "have rusted to lace, but a few are still good.",
      dinghy: "A wrecked dinghy hangs keel-up from the rafters. Nothing in it " +
              "but rainwater and the memory of being useful."
    },
    shorePath: {
      gulls: "The gulls wheel in perfect silence. You decide, firmly, that the " +
             "wind is simply taking their cries the other way.",
      stones: "Stones furred with bladderwrack, slick and black. Beneath one, " +
              "the white curve of something that might be a sheep's skull. " +
              "Might be."
    },
    lightDoor: {
      door: "The boards are nailed thick and crosswise. A crowbar's claw would " +
            "make short work of them. (Try: PRY DOOR.)",
      lintel: "Carved into the lintel: WE KEEP THE DARK. The chisel-strokes are " +
              "old, and someone has tried, and failed, to scrape them away.",
      boards: "Planks nailed over the door in a panic. The nails weep rust. " +
              "Something to pry."
    },
    foyer: {
      reflection: "You look at the water and the water looks back, a beat too " +
                  "slow, and you decide not to do that again.",
      stair: "A spiral stair of rusted iron winds up into the tower. It leads " +
             "UP.",
      water: "Six inches of still seawater, cold as the grave and twice as " +
             "patient. It does not ripple even when you step."
    },
    kitchen: {
      stove: "A cold cast-iron stove. The ash in it is grey and old and has " +
             "not been disturbed in a long while.",
      tea: "A cup of tea skinned over with dust. She would have hated to leave " +
           "it. She left it.",
      chair: "A single chair pulled out from the table, mid-rise, as though " +
             "she stood up to answer something and simply kept walking.",
      window: "Through the salt-blind window: only the sea, only the dark, only " +
              "the long grey nothing she refused to face while she wrote."
    },
    quarters: {
      bed: "An iron bed, made up with hospital corners, the pillow still dented " +
           "where a head used to rest while a light burned overhead.",
      coat: "Her oilskin coat, salt-stiff, the pockets turned inside out. " +
            "Whoever searched it was in a hurry. It may have been her.",
      desk: "A plain writing desk facing the wall. On it: the JOURNAL. Pinned " +
            "above: the tide CHART.",
      window: "The one window in the room has been papered over from the " +
              "inside. Never the sea, she always said."
    },
    watchRoom: {
      instruments: "Barometers, a tide gauge, a clock — every needle stopped at " +
                   "the same dead instant, as if time itself missed a watch.",
      cabinet: "A cabinet of dark oak bolted to the wall, with a small brass " +
               "lock. It will need a key. Inside, surely, the things that " +
               "mattered most.",
      ladder: "A short iron ladder bolted to the wall, leading UP into the lamp " +
              "room."
    },
    lampRoom: {
      lamp: "The lamp: a cathedral of brass and wick and curved mirror, large " +
            "as a man, utterly dead. A socket gapes where its regulator handle " +
            "should be. Feed it oil, fit the handle, strike a match, and it " +
            "might yet wake. (Try, in order: USE OILCAN ON LAMP, USE VALVE ON " +
            "LAMP, LIGHT LAMP.)",
      lens: "The great Fresnel lens, a thousand prisms cut to throw one flame " +
            "for thirty miles. It is whole. It is waiting. It is hungry for a " +
            "flame.",
      glass: "Storm-glass thick as your wrist. Beyond it the sea heaves and " +
             "will not break, and the pale shape below turns, and turns, and " +
             "is patient.",
      shape: "Do not look at the shape. It is very far away. It is very large. " +
             "The two facts do not sit comfortably together, so the longer you " +
             "look the closer it seems, until you make yourself stop.",
      sea: "The sea does not break. It only heaves, slow and full, like the " +
           "breathing of something enormous that has not yet decided to open " +
           "its eyes."
    }
  };

  /* ------------------------------------------------------------------ */
  /*  STATE FLAGS, the spine of the puzzle chain                         */
  /* ------------------------------------------------------------------ */

  const INITIAL_FLAGS = {
    doorPried: false,   // crowbar -> boards -> foyer accessible
    tinOpen: false,     // tin opened -> key obtainable
    cabinetUnlocked: false, // brass key -> logbook + safe
    lampFueled: false,  // oilcan on lamp
    lampValved: false,  // valve handle fitted
    ending: null        // 'kept' (win) or 'freed' (alt ending)
  };

  /* ------------------------------------------------------------------ */
  /*  INTRO TEXT                                                         */
  /* ------------------------------------------------------------------ */

  const INTRO = [
    "THE WAKING DEPTH",
    "An interactive fiction in one long night.",
    "Saltwire Interactive, 2026.",
    "",
    "Your grandmother kept Cormorant Light for forty years and then the " +
      "letters stopped. The county says she drowned. The county says a great " +
      "many things. Tonight you rowed out yourself, across water too calm to " +
      "trust, to the rock where she kept her watch — to find out what she was " +
      "watching, and why she never, ever let the lamp go dark.",
    "",
    "Type HELP for commands, or LOOK to begin. Type a direction (N, S, E, W, " +
      "UP, DOWN) to move.",
    ""
  ];

  /* ------------------------------------------------------------------ */
  /*  EXPORT                                                             */
  /* ------------------------------------------------------------------ */

  global.WAKING = {
    START_ROOM: "jetty",
    ROOMS: ROOMS,
    ITEMS: ITEMS,
    SCENERY: SCENERY,
    INITIAL_FLAGS: INITIAL_FLAGS,
    INTRO: INTRO,
    THE_YEAR: "1891"
  };

})(window);
