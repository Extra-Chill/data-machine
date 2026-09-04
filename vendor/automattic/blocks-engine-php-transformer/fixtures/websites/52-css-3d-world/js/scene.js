/* ============================================================================
   VANTAPOINT — scene builder
   ----------------------------------------------------------------------------
   Builds the 3D museum out of plain DOM elements positioned with CSS 3D
   transforms. Reads window.VANTAPOINT (exhibits.js) and window.ARTWORKS.

   COORDINATE SYSTEM
     - World units are CSS pixels inside the .scene (preserve-3d) container.
     - ROOM_SIZE units per room. Room at grid {x,z} is centred at
       (x*ROOM_SIZE, 0, z*ROOM_SIZE). +Z goes "into" the screen/away.
     - Walls are 1px-deep boxes rotated to face the room centre and pushed out
       by HALF on their axis.
     - The camera (camera.js) moves a wrapper that counter-translates the world.
   ========================================================================== */

window.SCENE = (function () {
  'use strict';

  const ROOM_SIZE = 1400;   // wall-to-wall span of a room (world units)
  const HALF = ROOM_SIZE / 2;
  const WALL_H = 760;       // ceiling height
  const DOORW = 360;        // doorway opening width

  /* Build a flat panel element placed on a wall.
     side: 'north'|'east'|'south'|'west'. Coordinates relative to room centre. */
  const FRAME_W = 520, FRAME_H = 460;

  function makeArtPanel(wall, accent) {
    const el = document.createElement('article');
    el.className = 'art-panel';
    el.tabIndex = 0;
    el.setAttribute('role', 'button');
    el.setAttribute('aria-label', wall.title + ', ' + (wall.year || ''));
    el.dataset.exhibit = wall.id;
    el.style.setProperty('--accent', accent);

    if (wall.type === 'art') {
      el.innerHTML =
        '<div class="art-frame"><div class="art-canvas">' + window.ARTWORKS.render(wall.art) + '</div>' +
        '<div class="art-glass" aria-hidden="true"></div></div>' +
        '<div class="art-plate">' +
          '<h3>' + wall.title + '</h3>' +
          '<p class="art-line">' + wall.year + ' &middot; ' + wall.medium + '</p>' +
          '<p class="art-cap">' + wall.caption + '</p>' +
          '<span class="art-more">Approach to read &rarr;</span>' +
        '</div>';
    } else {
      el.className = 'art-panel text-panel';
      el.innerHTML =
        '<div class="text-card"><h3>' + wall.title + '</h3><p>' + wall.body + '</p></div>';
      el.removeAttribute('role'); el.removeAttribute('tabindex');
    }
    return el;
  }

  /* Place a wall surface. Returns the wall element. We split each wall into
     panels so doorways (gaps to adjacent rooms) can be cut where needed. */
  function buildWall(side, content, room, hasDoor) {
    const wall = document.createElement('div');
    wall.className = 'wall wall-' + side;
    wall.style.setProperty('--wc', room.wallColor);

    // transform per side: rotate to face inward, translate out by HALF
    const t = {
      north: 'translateZ(' + (-HALF) + 'px) rotateY(0deg)',
      south: 'translateZ(' + (HALF) + 'px) rotateY(180deg)',
      west:  'translateX(' + (-HALF) + 'px) rotateY(90deg)',
      east:  'translateX(' + (HALF) + 'px) rotateY(-90deg)'
    }[side];
    wall.style.transform = t;
    wall.style.width = ROOM_SIZE + 'px';
    wall.style.height = WALL_H + 'px';

    // baseboard + a soft shadow gradient for shading
    wall.innerHTML = '<div class="wall-shade" aria-hidden="true"></div>' +
                     '<div class="wall-base" aria-hidden="true"></div>';

    if (hasDoor) {
      wall.classList.add('has-door');
      const door = document.createElement('div');
      door.className = 'doorway';
      door.style.width = DOORW + 'px';
      door.innerHTML = '<span class="door-arch" aria-hidden="true"></span>';
      wall.appendChild(door);
    }

    if (content) {
      const panel = makeArtPanel(content, room.accent);
      // centre the panel on the wall; offset sideways if there's a door
      panel.style.left = (ROOM_SIZE / 2 - FRAME_W / 2) + 'px';
      panel.style.top = '90px';
      if (hasDoor) panel.style.left = (ROOM_SIZE / 2 - FRAME_W / 2 + DOORW * 0.62) + 'px';
      wall.appendChild(panel);
    }
    return wall;
  }

  function buildFloorCeiling(room) {
    const frag = document.createDocumentFragment();
    const floor = document.createElement('div');
    floor.className = 'floor';
    floor.style.width = ROOM_SIZE + 'px';
    floor.style.height = ROOM_SIZE + 'px';
    floor.style.transform = 'rotateX(90deg) translateZ(' + (-WALL_H / 2) + 'px)';
    floor.style.setProperty('--accent', room.accent);
    // floor label
    floor.innerHTML = '<div class="floor-grid" aria-hidden="true"></div>' +
      '<div class="floor-label" aria-hidden="true">' + room.name.toUpperCase() +
      '<span>' + room.era + '</span></div>';

    const ceil = document.createElement('div');
    ceil.className = 'ceiling';
    ceil.style.width = ROOM_SIZE + 'px';
    ceil.style.height = ROOM_SIZE + 'px';
    ceil.style.transform = 'rotateX(-90deg) translateZ(' + (-WALL_H / 2) + 'px)';
    ceil.style.setProperty('--accent', room.accent);
    ceil.innerHTML = '<div class="ceil-light" aria-hidden="true"></div>';

    frag.appendChild(floor);
    frag.appendChild(ceil);
    return frag;
  }

  /* Which sides of a room connect to a neighbour (so we cut a doorway). */
  function neighbourSides(room, rooms) {
    const here = room.grid;
    const find = (dx, dz) => rooms.some(r => r.grid.x === here.x + dx && r.grid.z === here.z + dz);
    return {
      north: find(0, -1),
      south: find(0, 1),
      west:  find(-1, 0),
      east:  find(1, 0)
    };
  }

  function build(sceneEl) {
    const data = window.VANTAPOINT;
    const rooms = data.rooms;
    sceneEl.innerHTML = '';

    rooms.forEach(room => {
      const roomEl = document.createElement('section');
      roomEl.className = 'room';
      roomEl.id = 'room-' + room.id;
      roomEl.dataset.room = room.id;
      roomEl.setAttribute('aria-label', room.name);
      roomEl.style.transform =
        'translate3d(' + (room.grid.x * ROOM_SIZE) + 'px, 0, ' + (room.grid.z * ROOM_SIZE) + 'px)';

      roomEl.appendChild(buildFloorCeiling(room));

      const doors = neighbourSides(room, rooms);
      ['north', 'east', 'south', 'west'].forEach(side => {
        // text/welcome panels never sit on a doorway side preferentially; we
        // simply pass door flag so the panel shifts aside if needed.
        roomEl.appendChild(buildWall(side, room.walls[side], room, doors[side]));
      });

      sceneEl.appendChild(roomEl);
    });

    return { ROOM_SIZE, WALL_H, rooms };
  }

  return { build, ROOM_SIZE, WALL_H };
})();
