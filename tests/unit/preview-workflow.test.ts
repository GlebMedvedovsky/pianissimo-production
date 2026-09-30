import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

test("private preview workflow is gated, source-pinned, authenticated, and never activates production", async () => {
  const workflow = await readFile(".github/workflows/pianissimo-preview-builder.yml", "utf8");
  assert.match(workflow, /workflow_dispatch:/);
  assert.match(workflow, /PIANISSIMO_PREVIEW_WORKFLOW_ENABLED/);
  assert.match(workflow, /permissions:\s*\n\s*contents: read/);
  assert.match(workflow, /ref: \$\{\{ inputs\.source_revision \}\}/);
  assert.match(workflow, /api\/publisher\/v1\/previews\/\$PREVIEW_ID\/package/);
  assert.match(workflow, /npm run build:preview/);
  assert.match(workflow, /api\/publisher\/v1\/previews\/\$PREVIEW_ID\/result/);
  assert.match(workflow, /Authorization: Bearer \$PUBLISHER_TOKEN/);
  assert.doesNotMatch(workflow, /pianissimo:production:activate|releases\/static\/current|NETCUP_PRODUCTION_SSH/);
});
