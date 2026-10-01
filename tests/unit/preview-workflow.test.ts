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
