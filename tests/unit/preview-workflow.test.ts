import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { parse } from "yaml";
import { readPublisherDigest } from "../../scripts/read-publisher-digest.mjs";

function workflowJob(workflow: string) {
  const document = parse(workflow) as { on: { workflow_dispatch: { inputs: Record<string, unknown> } }; jobs: { build: { env: Record<string, unknown>; steps: Array<Record<string, unknown>> } } };
  assert.ok(document.jobs?.build?.env && Array.isArray(document.jobs.build.steps));
  return { document, job: document.jobs.build };
}

test("private preview workflow is gated, source-pinned, authenticated, and never activates production", async () => {
  const workflow = await readFile(".github/workflows/pianissimo-preview-builder.yml", "utf8");
  const { document } = workflowJob(workflow);
  assert.deepEqual(Object.keys(document.on.workflow_dispatch.inputs), ["preview_identifier", "package_sha256", "source_revision"]);
  assert.match(workflow, /workflow_dispatch:/);
  assert.match(workflow, /PIANISSIMO_PREVIEW_WORKFLOW_ENABLED/);
  assert.match(workflow, /permissions:\s*\n\s*contents: read/);
  assert.match(workflow, /ref: \$\{\{ inputs\.source_revision \}\}/);
  assert.match(workflow, /api\/publisher\/v1\/previews\/\$PREVIEW_ID\/package/);
  assert.match(workflow, /npm run build:preview/);
  assert.match(workflow, /PREVIEW_CHUNK_BYTES: "4194304"/);
  assert.match(workflow, /split -b "\$PREVIEW_CHUNK_BYTES"/);
  assert.match(workflow, /api\/publisher\/v1\/previews\/\$PREVIEW_ID\/result\/chunks\/\$index/);
  assert.match(workflow, /api\/publisher\/v1\/previews\/\$PREVIEW_ID\/result\/finalize/);
  assert.match(workflow, /X-Pianissimo-Chunk-Sha256/);
  assert.match(workflow, /X-Pianissimo-Chunk-Count/);
  assert.doesNotMatch(workflow, /--data-binary "@\$OUTPUT_ARCHIVE"/);
  assert.match(workflow, /Authorization: Bearer \$PUBLISHER_TOKEN/);
  assert.doesNotMatch(workflow, /PIANISSIMO_PRODUCTION_WORKFLOW_ENABLED|pianissimo:production:activate|static-release:activate|releases\/static\/current|NETCUP_PRODUCTION_SSH/);
});

test("private preview temporary paths are initialized only at runner step time", async () => {
  const workflow = await readFile(".github/workflows/pianissimo-preview-builder.yml", "utf8");
  const { job } = workflowJob(workflow);
  assert.doesNotMatch(JSON.stringify(job.env), /runner\.temp|RUNNER_TEMP/);
  assert.doesNotMatch(workflow, /\$\{\{\s*runner\.temp\s*\}\}/);

  const initIndex = job.steps.findIndex((step) => step.name === "Initialize runner-local temporary paths");
  const gateIndex = job.steps.findIndex((step) => String(step.name).startsWith("Enforce preview workflow gate"));
  assert.ok(initIndex > gateIndex);
  const initStep = String(job.steps[initIndex]?.run);
  for (const variable of [
    "PACKAGE_ARCHIVE", "PACKAGE_HEADERS", "PACKAGE_DIR", "OUTPUT_DIR",
    "OUTPUT_ARCHIVE", "OUTPUT_LIST", "OUTPUT_CHUNKS",
  ]) {
    assert.equal(Object.hasOwn(job.env, variable), false);
    assert.match(initStep, new RegExp(`"${variable}=\\$RUNNER_TEMP\\/[^"\\r\\n]+"`));
    assert.ok(job.steps.findIndex((step, index) => index > initIndex && JSON.stringify(step).includes(`$${variable}`)) > initIndex);
  }
  assert.match(initStep, /\}\s*>> "\$GITHUB_ENV"/);
});

test("publisher response digest parsing accepts lowercase HTTP/2 headers and rejects invalid SHA values", async () => {
  const fixture = "tests/fixtures/publisher-response.headers";
  assert.equal(await readPublisherDigest(fixture, "X-Pianissimo-Package-Sha256"), "a".repeat(64));
  assert.equal(await readPublisherDigest(fixture, "X-Pianissimo-Transport-Sha256"), "b".repeat(64));
  assert.equal(await readPublisherDigest("tests/fixtures/publisher-response-multiple.headers", "X-Pianissimo-Package-Sha256"), "c".repeat(64));
  assert.equal(await readPublisherDigest("tests/fixtures/publisher-response-multiple.headers", "X-Pianissimo-Transport-Sha256"), "d".repeat(64));
  await assert.rejects(readPublisherDigest("tests/fixtures/publisher-response-invalid.headers", "X-Pianissimo-Package-Sha256"), /Invalid or missing/);
  const workflow = await readFile(".github/workflows/pianissimo-preview-builder.yml", "utf8");
  assert.doesNotMatch(workflow, /IGNORECASE/);
  assert.match(workflow, /read-publisher-digest\.mjs/);
  assert.match(workflow, /logical_sha.*\^\[0-9a-f\]\{64\}\$/);
});
