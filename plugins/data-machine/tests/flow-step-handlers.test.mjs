// Regression test: flow step cards must read the canonical handler shape
// (handler_slugs + handler_configs) for single-handler step types too.
import assert from "node:assert/strict";

import {
  getFlowStepHandlerSlugs,
  getFlowStepHandlerConfig,
} from "../inc/Core/Admin/Pages/Pipelines/assets/react/utils/flowStepHandlers.js";

// Canonical shape returned by get-flows for a single-handler step (event_import).
const singleCanonical = {
  step_type: "event_import",
  handler_slugs: ["ticketmaster"],
  handler_configs: { ticketmaster: { classification_type: "music" } },
};
assert.deepEqual(getFlowStepHandlerSlugs(singleCanonical, false), ["ticketmaster"]);
assert.deepEqual(getFlowStepHandlerConfig(singleCanonical, "ticketmaster"), { classification_type: "music" });

// Single-handler steps expose at most one slug.
assert.deepEqual(getFlowStepHandlerSlugs({ handler_slugs: ["a", "b"] }, false), ["a"]);

// Multi-handler steps keep every slug and per-slug config.
const multi = {
  handler_slugs: ["a", "b"],
  handler_configs: { a: { x: 1 }, b: { y: 2 } },
};
assert.deepEqual(getFlowStepHandlerSlugs(multi, true), ["a", "b"]);
assert.deepEqual(getFlowStepHandlerConfig(multi, "b"), { y: 2 });

// Legacy single-handler shape still resolves.
const legacy = { handler_slug: "rss", handler_config: { feed_url: "u" } };
assert.deepEqual(getFlowStepHandlerSlugs(legacy, false), ["rss"]);
assert.deepEqual(getFlowStepHandlerConfig(legacy, "rss"), { feed_url: "u" });

// Non-handler steps (AI, system tasks) use the flat handler_config.
const ai = { step_type: "ai", handler_config: { prompt: "p" } };
assert.deepEqual(getFlowStepHandlerSlugs(ai, false), []);
assert.deepEqual(getFlowStepHandlerConfig(ai, ""), { prompt: "p" });

// Empty step.
assert.deepEqual(getFlowStepHandlerSlugs(undefined, true), []);
assert.deepEqual(getFlowStepHandlerConfig(undefined, "x"), {});

console.log("flow-step-handlers test passed");
