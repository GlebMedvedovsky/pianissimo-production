import test from "node:test";
import assert from "node:assert/strict";
import raw from "../../content/manifest.json";
import { validateManifest } from "../../contracts/content";
import { publicContent } from "../../contracts/public-content";
import { selectTracks } from "../../frontend/src/lib/content/selectors";
test("standalone library opt-in is explicit, deduplicated and respects draft status", () => {
  const d = validateManifest(raw),
    track = d.tracks.find((t) => t.id === "hole-in-that-dough")!;
  d.projects.forEach(
    (p) => (p.tracks = p.tracks.filter((t) => t.trackId !== track.id)),
  );
  assert(!selectTracks(publicContent(d)).some((t) => t.id === track.id));
  track.libraryVisible = true;
  assert.equal(
    selectTracks(publicContent(d)).filter((t) => t.id === track.id).length,
    1,
  );
  d.projects
    .find((p) => p.id === "dough")!
    .tracks.push({ trackId: track.id, order: 0 });
  assert.equal(
    selectTracks(publicContent(d)).filter((t) => t.id === track.id).length,
    1,
  );
  track.status = "draft";
  assert(!publicContent(d).tracks.some((t) => t.id === track.id));
});
test("browser boundary strips drafts, unreferenced tracks/assets and source locations", () => {
  const data = validateManifest(raw);
  data.projects[0]!.status = "draft";
  data.projects[0]!.title = "PRIVATE DRAFT TITLE";
  data.assets[0]!.source = "https://private.invalid/original";
  const result = publicContent(data);
  assert.ok(!JSON.stringify(result).includes("PRIVATE DRAFT TITLE"));
  assert.ok(!JSON.stringify(result).includes("private.invalid"));
  assert.ok(!result.tracks.some((t) => t.id === "in-the-club"));
  assert.ok(!result.assets.some((a) => a.id === "abszission"));
  assert.deepEqual(result.sources, []);
});
