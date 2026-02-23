"use strict";
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// packages/e2e-test-utils-playwright/src/page-utils/emulate-network-conditions.ts
var emulate_network_conditions_exports = {};
__export(emulate_network_conditions_exports, {
  emulateNetworkConditions: () => emulateNetworkConditions
});
module.exports = __toCommonJS(emulate_network_conditions_exports);
var PredefinedNetworkConditions = {
  // Generally aligned with DevTools
  // https://source.chromium.org/chromium/chromium/src/+/main:third_party/devtools-frontend/src/front_end/core/sdk/NetworkManager.ts;l=398;drc=225e1240f522ca684473f541ae6dae6cd766dd33.
  "Slow 3G": {
    offline: false,
    // ~500Kbps down
    downloadThroughput: 500 * 1e3 / 8 * 0.8,
    // ~500Kbps up
    uploadThroughput: 500 * 1e3 / 8 * 0.8,
    // 400ms RTT
    latency: 400 * 5
  },
  "Fast 3G": {
    offline: false,
    // ~1.6 Mbps down
    downloadThroughput: 1.6 * 1e3 * 1e3 / 8 * 0.9,
    // ~0.75 Mbps up
    uploadThroughput: 750 * 1e3 / 8 * 0.9,
    // 150ms RTT
    latency: 150 * 3.75
  },
  // alias to Fast 3G to align with Lighthouse (crbug.com/342406608)
  // and DevTools (crbug.com/342406608),
  "Slow 4G": {
    offline: false,
    // ~1.6 Mbps down
    downloadThroughput: 1.6 * 1e3 * 1e3 / 8 * 0.9,
    // ~0.75 Mbps up
    uploadThroughput: 750 * 1e3 / 8 * 0.9,
    // 150ms RTT
    latency: 150 * 3.75
  },
  "Fast 4G": {
    offline: false,
    // 9 Mbps down
    downloadThroughput: 9 * 1e3 * 1e3 / 8 * 0.9,
    // 1.5 Mbps up
    uploadThroughput: 1.5 * 1e3 * 1e3 / 8 * 0.9,
    // 60ms RTT
    latency: 60 * 2.75
  },
  /**
   * Network conditions used for desktop in Lighthouse/PSI.
   *
   * 10,240 kb/s throughput with 40 ms TCP RTT.
   *
   * @see https://github.com/paulirish/lighthouse/blob/f0855904aaffaecf3089169449646960782d7e92/core/config/constants.js#L40-L49
   * @see https://docs.google.com/document/d/1-p4HSp42REEA5-jCBVB6PqQcVhI1nQIblBCNKhPJUXg/edit?tab=t.0#heading=h.jsap7yf4phk6
   */
  Broadband: {
    offline: false,
    downloadThroughput: 10240 * 1e3 / 8,
    uploadThroughput: 10240 * 1e3 / 8,
    latency: 40
  }
};
async function emulateNetworkConditions(condition) {
  if ("chromium" !== this.browserName) {
    throw new Error(
      "CDP sessions are only supported on Chromium-based browsers"
    );
  }
  const session = await this.page.context().newCDPSession(this.page);
  await session.send(
    "Network.emulateNetworkConditions",
    "string" === typeof condition ? PredefinedNetworkConditions[condition] : condition
  );
  await session.detach();
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  emulateNetworkConditions
});
//# sourceMappingURL=emulate-network-conditions.cjs.map
