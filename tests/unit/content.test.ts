import test from "node:test";
import assert from "node:assert/strict";
import raw from "../../content/manifest.json";
import { validateManifest } from "../../contracts/content";
import {
  selectProjects,
  selectTracks,
  platformLinks,
} from "../../frontend/src/lib/content/selectors";
import { aboutTheme } from "../../frontend/src/config/site.config";
test("Letterboxd only permits confirmed stills, never video or hover video", () => {
  for (const kind of ["external", "local", "hover"]) {
    const m = structuredClone(raw) as unknown as ReturnType<
        typeof validateManifest
      >,
      p = m.projects.find((p) => p.id === "kinnhakenhank")!;
    p.id = "letterboxd-everything-everywhere-all-at-once";
    delete p.localTrailerAssetId;
    if (kind === "external")
      p.externalFilm = {
        provider: "youtube",
        url: "https://www.youtube.com/watch?v=synthetic",
        videoId: "synthetic",
        consentRequired: true,
      } as never;
    if (kind === "local") p.localTrailerAssetId = "local-f-87d401c4a58387a5";
    if (kind !== "hover")
      for (const membership of Object.values(p.memberships))
        delete membership.hoverAssetId;
    assert.throws(() => validateManifest(m), /Letterboxd/);
  }
});
test("manifest validates, IDs and references are checked", () => {
  assert.equal(validateManifest(raw).projects.length, 15);
  const duplicate = structuredClone(raw);
  duplicate.projects.push(duplicate.projects[0]!);
  assert.throws(() => validateManifest(duplicate), /Duplicate/);
  const broken = structuredClone(raw);
  broken.projects[0]!.tracks[0]!.trackId = "missing";
  assert.throws(() => validateManifest(broken), /Unknown track/);
  const path = structuredClone(raw);
  path.assets[0]!.path = "../private.jpg";
  assert.throws(() => validateManifest(path));
});
test("release fails honestly; fixtures are blocked from prototype", () => {
  assert.throws(() => validateManifest(raw, true), /Release blocked/);
  const fixture = structuredClone(raw);
  fixture.assets[0]!.state = "test";
  assert.throws(() => validateManifest(fixture), /Test fixtures/);
  const draft = structuredClone(raw);
  draft.projects[0]!.status = "draft";
  assert.equal(
    selectProjects(validateManifest(draft), "music", "All").length,
    12,
  );
  assert.throws(
    () => validateManifest(draft, true),
    /draft, test or unconfirmed/,
  );
});
test("unsafe protocols and invalid optional values fail", () => {
  const bad = structuredClone(raw);
  bad.site.contacts.instagram = "javascript:alert(1)";
  assert.throws(() => validateManifest(bad));
  const peaks = structuredClone(raw);
  peaks.tracks[0] = { ...peaks.tracks[0]!, peaks: [0.3] as never };
  assert.throws(() => validateManifest(peaks), /Peaks need duration/);
});
test("independent membership order, shared project identity, category empty states", () => {
  const data = validateManifest(raw);
  const music = selectProjects(data, "music", "All"),
    directing = selectProjects(data, "directing", "Director");
  assert.equal(music[0]!.id, "kinnhakenhank");
  assert.strictEqual(
    music[0],
    directing.find((p) => p.id === "kinnhakenhank"),
  );
  assert.equal(directing[0]!.id, "cello");
  assert.equal(selectProjects(data, "music", "Documentary").length, 0);
  assert.equal(
    selectProjects(data, "directing", "Assistant Director").length,
    1,
  );
  const altered = structuredClone(data);
  altered.projects.find((p) => p.id === "abszission")!.memberships.directing = {
    ...altered.projects[0]!.memberships.music!,
    role: "Director",
    order: 0,
  };
  assert.equal(
    selectProjects(altered, "directing", "Director")[0]!.id,
    "abszission",
  );
  assert.equal(selectProjects(altered, "music", "All")[0]!.id, "kinnhakenhank");
});
test("catalog shares IDs and combines genre and emotion", () => {
  const data = validateManifest(raw);
  assert.equal(selectTracks(data).length, 17);
  assert.ok(
    selectTracks(data, "Jazz", "uplifting").some(
      (t) => t.id === "hole-in-that-dough",
    ),
  );
  assert.equal(selectTracks(data, "Orchestral", "gritty").length, 0);
});
test("track platform override wins; project album fallback remains an album", () => {
  const data = validateManifest(raw);
  const project = data.projects[0]!,
    track = data.tracks[0]!;
  assert.equal(
    platformLinks(track, project).spotify,
    project.platformLinks.spotify,
  );
  assert.equal(
    platformLinks(
      {
        ...track,
        platformOverrides: { spotify: "https://open.spotify.com/track/test" },
      },
      project,
    ).spotify,
    "https://open.spotify.com/track/test",
  );
  assert.deepEqual(
    platformLinks(
      { ...track, platformOverrides: {} },
      { ...project, platformLinks: {} },
    ),
    {},
  );
});
test("About direct entry, context and manual theme overrides", () => {
  assert.equal(aboutTheme(null), "dark");
  assert.equal(aboutTheme("home"), "dark");
  assert.equal(aboutTheme("music"), "dark");
  assert.equal(aboutTheme("directing"), "light");
  assert.equal(
    aboutTheme("directing", { themeMode: "dark", defaultTheme: "dark" }),
    "dark",
  );
  assert.equal(
    aboutTheme(null, { themeMode: "light", defaultTheme: "dark" }),
    "light",
  );
});
