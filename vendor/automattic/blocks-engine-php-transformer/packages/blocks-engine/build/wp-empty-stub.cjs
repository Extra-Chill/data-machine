// Build-time stand-in for @wordpress packages reached only through block `edit`
// components, which the engine never executes. Any access returns a no-op.
const noop = function () { return null; };
module.exports = new Proxy(noop, {
  get(_t, p) {
    if (p === '__esModule') return true;
    if (p === 'default') return noop;
    return noop;
  },
});
