# Runtime-Backed Pipeline Builder Fuzzing

Run the versioned Pipeline Builder fixture against a disposable WordPress runtime:

```bash
npm run build
wp-codebox run-fuzz-suite --runner-mode=runtime-backed --input-file=tests/fuzz/pipeline-builder-runtime.json --format=json
```

`wp-codebox` is the public executable. The similarly named `wp codebox` WordPress CLI command invokes an in-process ability and cannot execute browser or runtime actions.

Validate the Data Machine-owned fixture contract without booting a runtime:

```bash
node tests/fuzz/pipeline-builder-runtime-contract.test.mjs
```

The fixture requires compiled Pipeline Builder assets and a disposable recipe runtime per case. Because the input lives in `tests/fuzz`, its Data Machine mount is `../..`. Each workload activates the mounted plugin, creates and restores a checkpoint inside its own recipe workflow, and creates its own prerequisite pipeline or flow. Its selectors come from the builder source and target concrete controls, including `input[placeholder="Pipeline name"]` and `input[type="file"][accept=".csv,text/csv"]`.

Keep a real regression as a replayable failing case with its artifacts; do not weaken assertions to hide it.
