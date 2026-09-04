/* ============================================================================
   VANTAPOINT — camera / navigation
   ----------------------------------------------------------------------------
   A first-person camera implemented purely with CSS 3D transforms.

   MODEL
     The .scene is transformed by the INVERSE of the camera:
        rotateX(-pitch) rotateY(-yaw) translate3d(-x, -y, -z)
     applied in that order means: first move the whole world so the camera is
     at the origin, then rotate the world opposite to where the camera looks.
     The fixed perspective on .viewport provides the lens.

   STATE
     pos {x,y,z}  camera position in world units
     yaw          left/right look, radians (0 = facing -Z, "into" the museum)
     pitch        up/down look, radians, clamped

   MOVEMENT
     WASD / arrows drive a velocity vector in camera-local space, integrated
     each animation frame with smoothing. Drag (mouse / touch) or Q/E turns.
     gotoRoom() eases pos + yaw to a target (used by minimap + room buttons).
   ========================================================================== */

window.CAMERA = (function () {
  'use strict';

  let scene, build;
  const pos = { x: 0, y: 0, z: -260 };   // start just inside the atrium, looking in
  let yaw = 0, pitch = 0;
  const vel = { f: 0, s: 0 };            // forward / strafe input (-1..1)
  let turn = 0;                          // keyboard turn input
  let running = false, reduced = false, enabled = true;
  let tween = null;                      // active gotoRoom tween
  const EYE = 0;                         // eye height offset from room mid

  const SPEED = 7.2;          // units per frame at full input
  const TURN_SPEED = 0.035;   // radians per frame (keyboard)
  const ACCEL = 0.18;         // input smoothing
  const ROOM_SIZE = window.SCENE.ROOM_SIZE;
  const PITCH_LIMIT = 0.55;
  const ROOM_HALF = ROOM_SIZE / 2;
  const WALL_PAD = 130;       // keep camera off the walls

  const smooth = { f: 0, s: 0 };
  const listeners = [];

  function onMove(cb) { listeners.push(cb); }
  function emit() {
    const room = currentRoom();
    listeners.forEach(cb => cb({ pos, yaw, pitch, room }));
  }

  /* current room id by nearest grid centre --------------------------------- */
  function currentRoom() {
    const rooms = window.VANTAPOINT.rooms;
    let best = rooms[0], bd = Infinity;
    rooms.forEach(r => {
      const cx = r.grid.x * ROOM_SIZE, cz = r.grid.z * ROOM_SIZE;
      const d = (pos.x - cx) ** 2 + (pos.z - cz) ** 2;
      if (d < bd) { bd = d; best = r; }
    });
    return best;
  }

  /* clamp camera inside the union of room footprints (allow doorways) ------- */
  function clampToRooms() {
    const rooms = window.VANTAPOINT.rooms;
    // find the room whose extended footprint we're inside; if none, snap to nearest
    let inside = null;
    for (const r of rooms) {
      const cx = r.grid.x * ROOM_SIZE, cz = r.grid.z * ROOM_SIZE;
      if (Math.abs(pos.x - cx) <= ROOM_HALF + 200 && Math.abs(pos.z - cz) <= ROOM_HALF + 200) {
        inside = r; break;
      }
    }
    if (!inside) inside = currentRoom();
    const cx = inside.grid.x * ROOM_SIZE, cz = inside.grid.z * ROOM_SIZE;
    const has = (dx, dz) => rooms.some(rr => rr.grid.x === inside.grid.x + dx && rr.grid.z === inside.grid.z + dz);

    const limX = ROOM_HALF - WALL_PAD, limZ = ROOM_HALF - WALL_PAD;
    let nx = pos.x - cx, nz = pos.z - cz;

    // allow passing through doorways (a corridor of DOOR width) into neighbours
    const corridor = 150;
    const inDoorX = Math.abs(nz) < corridor;   // moving along X uses a door if |z| small
    const inDoorZ = Math.abs(nx) < corridor;

    if (nx > limX && !(has(1, 0) && inDoorX)) nx = limX;
    if (nx < -limX && !(has(-1, 0) && inDoorX)) nx = -limX;
    if (nz > limZ && !(has(0, 1) && inDoorZ)) nz = limZ;
    if (nz < -limZ && !(has(0, -1) && inDoorZ)) nz = -limZ;

    pos.x = cx + nx;
    pos.z = cz + nz;
  }

  /* apply transform -------------------------------------------------------- */
  function apply() {
    const deg = 180 / Math.PI;
    scene.style.transform =
      'rotateX(' + (-pitch * deg) + 'deg) rotateY(' + (-yaw * deg) + 'deg) ' +
      'translate3d(' + (-pos.x) + 'px,' + (-(pos.y + EYE)) + 'px,' + (-pos.z) + 'px)';
  }

  /* one frame -------------------------------------------------------------- */
  function frame() {
    if (!running) return;

    if (tween) {
      tween.t += 1 / tween.dur;
      const k = tween.t >= 1 ? 1 : easeInOut(tween.t);
      pos.x = lerp(tween.from.x, tween.to.x, k);
      pos.z = lerp(tween.from.z, tween.to.z, k);
      yaw = lerpAngle(tween.from.yaw, tween.to.yaw, k);
      pitch = lerp(tween.from.pitch, tween.to.pitch, k);
      if (tween.t >= 1) tween = null;
    } else {
      // smooth the directional input
      smooth.f += (vel.f - smooth.f) * ACCEL;
      smooth.s += (vel.s - smooth.s) * ACCEL;
      yaw += turn * TURN_SPEED;

      const sinY = Math.sin(yaw), cosY = Math.cos(yaw);
      // forward (-Z when yaw 0); strafe along X
      pos.x += (smooth.f * -sinY + smooth.s * cosY) * SPEED;
      pos.z += (smooth.f * -cosY - smooth.s * sinY) * SPEED;
      clampToRooms();
    }

    apply();
    emit();
    requestAnimationFrame(frame);
  }

  function start() {
    if (running || reduced || !enabled) return;
    running = true;
    requestAnimationFrame(frame);
  }
  function stop() { running = false; }

  /* ---- public movement intents (also used by on-screen buttons) ---------- */
  function setForward(v) { vel.f = v; }
  function setStrafe(v) { vel.s = v; }
  function setTurn(v) { turn = v; }
  function nudge(f, s) { // single-tap step for buttons (mobile)
    if (tween) return;
    const sinY = Math.sin(yaw), cosY = Math.cos(yaw);
    pos.x += (f * -sinY + s * cosY) * SPEED * 14;
    pos.z += (f * -cosY - s * sinY) * SPEED * 14;
    clampToRooms(); apply(); emit();
  }
  function look(dx, dy) {
    yaw += dx;
    pitch = clamp(pitch + dy, -PITCH_LIMIT, PITCH_LIMIT);
    if (reduced) { apply(); emit(); }
  }

  /* ease camera to face a specific exhibit/wall ----------------------------- */
  function gotoRoom(roomId, opts) {
    const r = window.VANTAPOINT.rooms.find(x => x.id === roomId);
    if (!r) return;
    const cx = r.grid.x * ROOM_SIZE, cz = r.grid.z * ROOM_SIZE;
    travelTo({ x: cx, z: cz, yaw: (opts && opts.yaw) || 0, pitch: 0 }, opts && opts.dur);
  }

  function gotoExhibit(roomId, side) {
    const r = window.VANTAPOINT.rooms.find(x => x.id === roomId);
    if (!r) return;
    const cx = r.grid.x * ROOM_SIZE, cz = r.grid.z * ROOM_SIZE;
    // stand back from the chosen wall, facing it (leave room to frame the art)
    const stand = ROOM_HALF - 640;
    const target = { north: { x: cx, z: cz - stand, yaw: 0 },
                     south: { x: cx, z: cz + stand, yaw: Math.PI },
                     east:  { x: cx + stand, z: cz,  yaw: -Math.PI / 2 },
                     west:  { x: cx - stand, z: cz,  yaw: Math.PI / 2 } }[side];
    travelTo({ x: target.x, z: target.z, yaw: target.yaw, pitch: 0 });
  }

  function travelTo(to, dur) {
    if (reduced) { pos.x = to.x; pos.z = to.z; yaw = to.yaw; pitch = to.pitch || 0; apply(); emit(); return; }
    tween = {
      from: { x: pos.x, z: pos.z, yaw, pitch },
      to: { x: to.x, z: to.z, yaw: to.yaw, pitch: to.pitch || 0 },
      t: 0, dur: dur || 80
    };
    if (!running) start();
  }

  function reset() {
    pos.x = 0; pos.z = -260; yaw = 0; pitch = 0; tween = null;
    apply(); emit();
  }

  /* set reduced-motion / disabled (flat mode) ------------------------------ */
  function setReduced(v) { reduced = v; if (v) stop(); }
  function setEnabled(v) {
    enabled = v;
    if (!v) stop(); else if (!reduced) start();
  }

  function init(sceneEl, buildInfo) {
    scene = sceneEl; build = buildInfo;
    apply();
    emit();
  }

  /* math helpers ----------------------------------------------------------- */
  function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
  function lerp(a, b, t) { return a + (b - a) * t; }
  function easeInOut(t) { return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; }
  function lerpAngle(a, b, t) {
    let d = b - a;
    while (d > Math.PI) d -= Math.PI * 2;
    while (d < -Math.PI) d += Math.PI * 2;
    return a + d * t;
  }

  return {
    init, start, stop, reset,
    setForward, setStrafe, setTurn, nudge, look,
    gotoRoom, gotoExhibit, onMove, currentRoom,
    setReduced, setEnabled,
    get state() { return { pos, yaw, pitch }; }
  };
})();
