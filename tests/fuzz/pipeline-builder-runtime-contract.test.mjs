import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const directory = dirname(fileURLToPath(import.meta.url));
const repository = resolve(directory, "../..");
const fixture = JSON.parse(await readFile(resolve(directory, "pipeline-builder-runtime.json"), "utf8"));

assert.equal(fixture.schema, "wp-codebox/fuzz-suite/v1");
assert.equal("resetPolicy" in fixture, false, "Recipe-backed component suites reset inside each workload, not through a suite executor.");
assert.equal(fixture.metadata.runtime_requirements.extra_plugins[0].source, "../..");
assert.equal(fixture.metadata.runtime_requirements.extra_plugins[0].path, "../..");
assert.equal("coveragePlan" in fixture, false, "Runtime execution, not static fixture data, owns executable coverage accounting.");

const seeds = new Set();
for (const fuzzCase of fixture.cases) {
  assert.match(fuzzCase.id, /^[a-z0-9-]+$/);
  assert.equal(typeof fuzzCase.metadata.seed, "number");
  assert.equal(seeds.has(fuzzCase.metadata.seed), false, `duplicate seed for ${fuzzCase.id}`);
  seeds.add(fuzzCase.metadata.seed);
  assert.ok(fuzzCase.metadata.dimensions.length > 0, `${fuzzCase.id} must declare exercised dimensions`);
  assert.equal(fuzzCase.target ?? fixture.target, fixture.target, `${fuzzCase.id} must use the canonical runtime workload target`);

  const setup = fuzzCase.phases?.setup ?? [];
  const teardown = fuzzCase.phases?.teardown ?? [];
  assert.ok(setup.some((step) => step.command === "wp-codebox.checkpoint-create"), `${fuzzCase.id} must create its own checkpoint`);
  assert.ok(teardown.some((step) => step.command === "wp-codebox.checkpoint-restore"), `${fuzzCase.id} must restore its own checkpoint`);
  assert.ok(setup.some((step) => step.command === "wordpress.ensure-plugin-active"), `${fuzzCase.id} must activate its own component runtime`);
  assert.ok(Object.values(fuzzCase.phases).flat().some((step) => step.command === "wordpress.browser-actions" || step.command === "wordpress.rest-request" || step.command === "wordpress.run-php"), `${fuzzCase.id} must exercise a real runtime surface`);
  for (const step of Object.values(fuzzCase.phases).flat()) {
    assert.equal((step.args ?? []).some((arg) => arg.startsWith("session=")), false, `${fuzzCase.id} must not depend on an undeclared recipe user session`);
    if (step.command === "wordpress.browser-actions") {
      const steps = JSON.parse(step.args.find((arg) => arg.startsWith("steps-json="))?.slice("steps-json=".length));
      assert.equal(steps[0]?.kind, "navigate", `${fuzzCase.id} browser actions must own their page lifecycle`);
    }
    if (step.command === "wordpress.run-php" && step.args.some((arg) => arg.includes("rest_do_request"))) {
      const code = step.args.find((arg) => arg.startsWith("code="));
      assert.ok(code.includes("datamachine_activate_full_runtime"), `${fuzzCase.id} direct REST dispatch must activate the full runtime`);
      assert.ok(code.includes("rest_api_init"), `${fuzzCase.id} direct REST dispatch must register routes`);
    }
  }
}

const sourceFiles = await Promise.all([
  "inc/Core/Admin/Pages/Pipelines/assets/react/PipelinesApp.jsx",
  "inc/Core/Admin/Pages/Pipelines/assets/react/components/pipelines/PipelineHeader.jsx",
  "inc/Core/Admin/Pages/Pipelines/assets/react/components/pipelines/EmptyStepCard.jsx",
  "inc/Core/Admin/Pages/Pipelines/assets/react/components/flows/EmptyFlowCard.jsx",
  "inc/Core/Admin/Pages/Pipelines/assets/react/components/flows/FlowsSection.jsx",
  "inc/Core/Admin/Pages/Pipelines/assets/react/components/modals/StepSelectionModal.jsx",
  "inc/Core/Admin/Pages/Pipelines/assets/react/components/modals/ImportExportModal.jsx",
  "inc/Core/Admin/Pages/Pipelines/assets/react/components/modals/import-export/CSVDropzone.jsx",
  "inc/Core/Admin/Pages/Pipelines/assets/react/components/modals/import-export/ImportTab.jsx",
  "inc/Core/Admin/Pages/Pipelines/assets/react/utils/api.js",
  "inc/Core/Admin/Pages/Pipelines/assets/react/stores/uiStore.js",
  "inc/Engine/Filters/Admin.php",
].map((path) => readFile(resolve(repository, path), "utf8")));
const source = sourceFiles.join("\n");

assert.ok(source.includes("'datamachine-' . $slug"), "Admin.php must retain the datamachine- page slug convention used by this fixture.");

for (const expected of [
  "Add New Pipeline",
  "Pipeline name",
  "Delete Pipeline",
  "datamachine-step-card--empty",
  "datamachine-flow-card--empty",
  "datamachine-flows-list",
  "datamachine-step-selection-modal",
  "datamachine-modal-card",
  "Import / Export",
  "datamachine-import-export-modal",
  "datamachine-ui-store",
  "accept=\".csv,text/csv\"",
]) {
  assert.ok(source.includes(expected), `fixture selector or label no longer exists in builder source: ${expected}`);
}

const serializedFixture = JSON.stringify(fixture);
assert.equal(serializedFixture.includes("page=pipelines"), false, "Pipeline Builder must use the Admin.php datamachine-pipelines slug.");
assert.ok(serializedFixture.includes("page=datamachine-pipelines"), "Fixture must exercise the registered Pipeline Builder admin route.");
assert.equal(serializedFixture.includes("/wp-json/"), false, "Runtime REST requests must use WP_REST_Request routes, not HTTP wp-json paths.");
assert.equal(serializedFixture.includes("batch_import"), false, "Fixture must not exercise the dead custom pipeline import arguments.");
assert.ok(serializedFixture.includes("/wp-abilities/v1/abilities/datamachine/import-pipelines/run"), "Fixture must execute the canonical REST-visible import ability.");
assert.ok(serializedFixture.includes("format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings"), "Fixture imports must use the canonical 1.0 CSV header.");
assert.ok(source.includes("path: '/wp-abilities/v1/abilities/datamachine/import-pipelines/run'"), "Pipeline Builder must call the canonical REST-visible import ability.");
assert.ok(source.includes("const count = response.count || 0"), "Pipeline Builder must consume the ability's direct count result.");
assert.equal(source.includes("response.data.created_count"), false, "Pipeline Builder must not consume the retired bulk-create envelope.");

console.log("pipeline builder fuzz fixture contract ok");
