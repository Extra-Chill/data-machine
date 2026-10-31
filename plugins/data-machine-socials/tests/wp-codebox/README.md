# WP Codebox crop-modal smoke test

A [WP Codebox](https://github.com/chubes4/wp-codebox) recipe that drives the
Data Machine Socials editor's **image crop modal** under WordPress **trunk**
(i.e. **React 19**) and asserts that the [`react-easy-crop`](https://github.com/ricardo-ch/react-easy-crop)
component still **works** under interaction — not just that it renders.

It is the plugin-specific consumer of WP Codebox's `wordpress.browser-actions`
interaction probe. The vendor-neutral reference example for that capability
lives in the wp-codebox cookbook; this recipe is the real regression test for
*this* plugin's cropper, kept next to the code it guards.

## What it does

1. Boots WordPress trunk in WordPress Playground, installs a block theme, logs
   in as admin.
2. Mounts this plugin (`source: ../../`) and runs `crop-modal-seed.php`, which:
   - generates a real same-origin image attachment (GD PNG) and sets it as a
     seeded draft post's featured image, so the cropper has genuine pixels to
     draw into its `<canvas>`;
   - writes a mu-plugin harness (see "What is real vs. stubbed" below).
3. Runs a `wordpress.browser-actions` step that opens the "Social Post" sidebar,
   selects Instagram, selects the featured image to open the cropper, asserts
   the `react-easy-crop` modal is present, drags a crop handle, asserts the
   container is still connected after interaction, and screenshots before/after.

## Running it

From this plugin's repository root, with a wp-codebox checkout available:

```bash
node /path/to/wp-codebox/packages/cli/dist/index.js \
  recipe-run --recipe ./tests/wp-codebox/crop-modal.json --json
```

(or, from inside a wp-codebox checkout, `npm run wp-codebox -- recipe-run --recipe /path/to/this/tests/wp-codebox/crop-modal.json --json`)

A green run reports **9/9 assertions passed, 0 failed, 0 page errors**, with
evidence under `artifacts/<runtime>/files/browser/`:

- `steps.jsonl` — per-step index/kind/selector/timing/ok-fail
- `action-summary.json` — the `assertions` block (`total`/`passed`/`failed`)
- `screenshot-*.png` — `editor-loaded`, `sidebar-open`, `image-selector`,
  `crop-modal-open`, `after-crop-drag`

## Why this exists

react-easy-crop is exactly the kind of third-party React component that can
break silently on a major React bump (React 18 → 19 changed effect timing,
`ref` semantics, and Strict Mode double-invocation). A render-only smoke test
("the modal mounts") would not catch a regression where the cropper renders but
the drag/crop interaction is dead. The interaction probe drives the real
component and asserts behavior — the difference between "it renders" and "it
works."

## What is real vs. stubbed

The component under test is **100% real**: the recipe mounts and loads this
plugin's actual built `build/index.js`, so `SocialEditor`, `ImageSelector`,
`ImageCropper`, and the bundled `react-easy-crop` are production code.

Two dependencies are handled by a documented **test harness** (a mu-plugin
written by the seed), because honesty beats a silent stub:

1. **Data Machine core is not booted.** This plugin hard-depends on Data Machine
   core (`Requires Plugins: data-machine`), which serves the
   `GET /datamachine/v1/socials/auth/status` route the editor uses to leave its
   "no accounts connected" gate. Booting core (ActionScheduler + agents-api +
   the pipeline engine) is unrelated to whether the cropper survives React 19,
   so the harness stubs that single route to report Instagram authenticated.
   Everything downstream of that gate is the real editor.
Note: an earlier revision of this harness also registered the auth-status route
at a doubled `/wp-json/wp-json/...` path to work around the `REST_BASE`
double-prefix bug (#145). That bug is now fixed, so the harness registers the
route only at the correct `/datamachine/v1/socials/auth/status` path.

## Extending

Edit `crop-modal-seed.php` to seed a different image, multiple images (to
exercise the multi-image crop flow), or a different platform/aspect-ratio
combination. Edit the `steps-json` in `crop-modal.json` to drive additional
cropper interactions (zoom slider, rotate buttons, the "Skip Cropping" / "Done"
actions) and assert their results.
