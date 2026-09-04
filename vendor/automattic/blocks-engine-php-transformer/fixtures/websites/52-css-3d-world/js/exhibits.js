/* ============================================================================
   VANTAPOINT — exhibit data
   ----------------------------------------------------------------------------
   The single source of truth for the museum. Used by:
     - scene.js     (to build the walls of the 3D rooms)
     - directory.html inline script (to build the flat 2D index)
     - hud.js       (to populate detail overlays)

   Each ROOM is a square cell on a grid. Rooms are 1200 units wide, walls are
   placed at +/- 600 on each axis. The artworks hang on the four walls.

   Artwork "art" is a small function name resolved in artworks.js that returns
   an inline SVG string, so every visual renders with zero external assets.
   ========================================================================== */

window.VANTAPOINT = {
  artist: {
    name: 'Mira Solveig Halden',
    life: '1948 – 2019',
    place: 'Bergen, Norway',
    tagline: 'Kinetic light, refracted weather, and the geometry of waiting.'
  },

  /* ROOMS -----------------------------------------------------------------
     grid: {x,z} in room-units. The camera travels between room centres.
     Each room has a `name`, an `era`, a `wallColor` (the painted gallery
     wall), an `accent`, and four walls (north/east/south/west) each holding
     either an artwork or a text panel. North wall faces -Z (toward entrance).
  ---------------------------------------------------------------------- */
  rooms: [
    {
      id: 'atrium',
      name: 'The Atrium',
      grid: { x: 0, z: 0 },
      era: '1968 – 1972',
      wallColor: '#15171e',
      accent: '#6ce0d6',
      intro: 'Where weather was first slowed down enough to look at.',
      walls: {
        north: { type: 'text',
          title: 'Welcome to the Vantapoint',
          body: 'You are standing inside the reconstructed studio-museum of Mira Solveig Halden — kinetic-light artist, failed meteorologist, and lifelong collector of grey skies. Walk the rooms in any order. Each holds one finished work and the wreckage of its making.' },
        east:  { type: 'art', art: 'auroraGrid', id: 'slow-weather-1',
          title: 'Slow Weather No. 1', year: '1969', medium: 'Polarised acrylic, motorised armature, daylight',
          dims: '180 × 180 × 40 cm',
          caption: 'A grid of rotating polarised panes that takes the colour of the sky directly behind it and replays it eleven minutes late. Halden called it "a window with a memory."',
          detail: 'Built from salvaged camera filters in a Bergen boatyard, Slow Weather No. 1 was the first piece in which Halden abandoned paint entirely. A clockwork armature rotates 144 polarised squares at differing speeds; sunlight passing through them is split into shifting bands of cyan and rose. The eleven-minute lag is mechanical, not electronic — a deliberately imperfect gear train she refused to ever repair.' },
        south: { type: 'art', art: 'tideClock', id: 'tide-for-a-room',
          title: 'A Tide for a Room', year: '1971', medium: 'Glass, mercury-free fluid, brass',
          dims: '90 × 240 cm',
          caption: 'A horizon line of blue fluid that rises and falls across the wall on the real tidal cycle of the Byfjorden.',
          detail: 'Twin reservoirs and a slow brass pump move 40 litres of dyed fluid up and down a sealed glass channel, tracking the genuine tide table of the fjord outside the original studio. On spring tides the "sea" climbs nearly to the ceiling; on neaps it barely stirs. The work has no off switch.' },
        west:  { type: 'art', art: 'prismFan', id: 'morning-index',
          title: 'Morning Index', year: '1972', medium: 'Cut glass, steel, north light',
          dims: '210 × 95 cm',
          caption: 'Forty glass blades fanned so that each catches the sun at a different minute of the morning, drawing a clock made of light across the floor.',
          detail: 'Each blade is angled to flare exactly once per morning as the low northern sun sweeps past. Read left to right, the flares form a 40-minute timeline burned briefly onto the gallery floor — a sundial that only works in autumn.' }
      }
    },
    {
      id: 'cold-room',
      name: 'The Cold Room',
      grid: { x: 1, z: 0 },
      era: '1973 – 1981',
      wallColor: '#101820',
      accent: '#8fb6ff',
      intro: 'The decade Halden tried to make the temperature visible.',
      walls: {
        north: { type: 'text',
          title: 'On Cold',
          body: '"I am not interested in the picture of winter," Halden wrote in 1974. "I want the actual cold to do the drawing." For eight years she chilled, froze, and condensed her materials, letting frost and dew compose the work and then photographing the result before it melted.' },
        east:  { type: 'art', art: 'frostBloom', id: 'condensation-portrait',
          title: 'Condensation Portrait', year: '1976', medium: 'Chilled steel plate, breath, gelatin print',
          dims: '50 × 60 cm (each, edition of 9)',
          caption: 'Sitters breathed onto a refrigerated plate; the fogged shape of each exhale was lit and photographed as a likeness.',
          detail: 'No camera ever pointed at the sitter\'s face. Instead each person breathed three times onto a steel plate held just below freezing, and Halden photographed the bloom of frost their breath left behind. The nine surviving prints are the only "portraits" she ever made of friends.' },
        south: { type: 'art', art: 'iceLens', id: 'lens-of-january',
          title: 'Lens of January', year: '1979', medium: 'Frozen distilled water, projector',
          dims: 'Projection, dimensions variable',
          caption: 'A block of ice ground into a crude lens; as it melts under the projector lamp it focuses, blurs, and finally drowns the image.',
          detail: 'Halden froze distilled water in a hand-turned mould, then ground one face into a rough plano-convex lens. Mounted in front of a slide projector it sharpens an image of the fjord for roughly twenty minutes before the lamp\'s heat defeats it. The work is re-frozen and re-screened nightly; it is never the same twice and never lasts.' },
        west:  { type: 'art', art: 'snowGraph', id: 'eleven-winters',
          title: 'Eleven Winters', year: '1981', medium: 'Ink, snowfall data, linen',
          dims: '300 × 120 cm',
          caption: 'Every snowfall in Bergen across eleven winters, drawn as a single accreting white line.',
          detail: 'A rare paper work. Halden plotted daily snow-depth readings as a horizon that thickens and thins across three metres of grey linen, the white ink built up in dozens of translucent passes until the heaviest winters stand almost in relief.' }
      }
    },
    {
      id: 'long-hall',
      name: 'The Long Hall',
      grid: { x: 0, z: 1 },
      era: '1982 – 1994',
      wallColor: '#1b1620',
      accent: '#e0a3ff',
      intro: 'Light put to work over distances and years.',
      walls: {
        north: { type: 'text',
          title: 'Patience as Medium',
          body: 'In her middle period Halden stopped trying to capture a moment and began composing for the very slow. These works reveal themselves over hours, seasons, or — in one case — the length of a marriage. Stand still. Some of them are moving even if you cannot see it.' },
        east:  { type: 'art', art: 'pendulumField', id: 'forty-second-room',
          title: 'The Forty-Second Room', year: '1985', medium: 'Brass pendulums, sand, single bulb',
          dims: 'Installation, 6 × 4 m',
          caption: 'Sixty pendulums of differing lengths swing in and out of phase; once every forty seconds they align and the room briefly fills with one shadow.',
          detail: 'Each brass bob trails a fine line of sand. Out of phase they scribble chaos across the floor, but the lengths are tuned so that every forty seconds every pendulum points the same way at once, merging their shadows into a single bar of darkness that crosses the wall and is gone. Visitors are told only to wait.' },
        south: { type: 'art', art: 'longExposure', id: 'one-year-of-evenings',
          title: 'One Year of Evenings', year: '1990', medium: 'Single photographic plate, one-year exposure',
          dims: '120 × 120 cm',
          caption: 'One photographic plate left facing west for 365 sunsets, recording the sun\'s migrating arc as a fan of overlapping fire.',
          detail: 'A pinhole and a single sheet of paper, sealed in a steel box bolted to the studio wall, open every evening for one year. The accumulated sunsets stack into a fan of 365 arcs — high and pale in June, low and red in December — that together map the whole tilt of the planet from one window.' },
        west:  { type: 'art', art: 'mirrorMaze', id: 'reciprocal-light',
          title: 'Reciprocal Light', year: '1994', medium: 'Two-way mirror, lamp, second viewer',
          dims: '160 × 160 cm',
          caption: 'A mirror that only completes its image when someone stands on the far side at the same time.',
          detail: 'Half-silvered glass shows you your own face — but switch on the far lamp (or wait for another visitor to arrive on the opposite side) and your reflection dissolves into theirs. Halden built it the year she remarried; the work is famously impossible to photograph alone.' }
      }
    },
    {
      id: 'observatory',
      name: 'The Observatory',
      grid: { x: 1, z: 1 },
      era: '1995 – 2009',
      wallColor: '#0d1418',
      accent: '#ffd479',
      intro: 'Late work: the sky brought indoors and asked questions.',
      walls: {
        north: { type: 'text',
          title: 'Looking Up',
          body: 'After a fall in 1995 left her unable to climb the studio stairs, Halden moved her practice to the ground floor and turned it toward the ceiling. The Observatory gathers the last fourteen years — quieter, funnier, and increasingly preoccupied with the difference between the sky and a picture of the sky.' },
        east:  { type: 'art', art: 'cameraObscura', id: 'ceiling-of-fog',
          title: 'A Ceiling of Fog', year: '1999', medium: 'Camera obscura, fjord weather, plaster dome',
          dims: 'Domed room, 4 m diameter',
          caption: 'A camera obscura projects the live, upside-down sky of Bergen onto a white plaster dome — usually grey, occasionally astonishing.',
          detail: 'A single lens in the roof casts the actual present-tense weather onto the inside of a domed ceiling. Visitors lie on the floor and watch real clouds, real gulls, real rain crawl across the plaster, inverted and softened. On the famous clear days of 2003 the room filled with people simply waiting for it to cloud over again.' },
        south: { type: 'art', art: 'starPlot', id: 'index-of-missing-stars',
          title: 'Index of Missing Stars', year: '2006', medium: 'Pinpricked steel, backlight',
          dims: '200 × 200 cm',
          caption: 'A black steel sky pricked only where stars should be but can no longer be seen from the city for the light.',
          detail: 'Halden plotted every star theoretically visible from her roof in 1948, then drilled the steel only at those now drowned out by Bergen\'s grown skyline. Backlit, the panel is a negative constellation — a map made entirely of what light pollution has taken.' },
        west:  { type: 'art', art: 'lastBulb', id: 'the-last-bulb',
          title: 'The Last Bulb', year: '2009', medium: 'Single incandescent bulb, dimmer, eleven years',
          dims: '15 × 15 × 220 cm',
          caption: 'One bulb, slowly dimming on a schedule meant to reach total darkness in 2030 — Halden\'s final, ongoing work.',
          detail: 'Her last piece, installed the year before her death. A single incandescent bulb is being dimmed by an imperceptible amount each day; its filament is calculated to reach complete darkness in 2030. Staff are forbidden to replace it. "When it goes out," she instructed, "the museum is finished. Lock the door and leave it dark."' }
      }
    }
  ]
};
