import test from "node:test";
import assert from "node:assert/strict";
import raw from "../../content/manifest.json";
import releasePolicyFixture from "../../backend/tests/Fixtures/kinnhakenhank-release-manifest.json";
import { validateManifest } from "../../contracts/content";
import {
  selectDirectingFilters,
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
test("profile links and collective are optional for older manifests", () => {
  const previous = structuredClone(raw);
  Reflect.deleteProperty(previous.site, "profileLinks");
  Reflect.deleteProperty(previous.site, "collective");
  Reflect.deleteProperty(previous.site.artistLinks, "spotify");
  assert.equal(validateManifest(previous).site.profileLinks, undefined);
  const current = validateManifest(raw);
  assert.equal(current.site.profileLinks?.musicHub, "https://music-hub.bio/sebastiangreim");
  assert.equal(current.site.collective?.name, "Samples unterm Sofa");
});
test("public consent and analytics configuration is strict and backwards compatible", () => {
  const configured = structuredClone(raw) as typeof raw & {site: typeof raw.site & Record<string,unknown>};
  configured.site.consent = {version:2,necessary:true,externalMedia:true,analytics:true,marketing:false,bannerText:"Your choice.",privacyLink:"/privacy/"};
  configured.site.analytics = {enabled:true,provider:"ga4",measurementId:"G-TEST1234",siteDomain:null};
  assert.equal(validateManifest(configured).site.consent?.version,2);
  const invalid = structuredClone(configured);
  invalid.site.analytics = {enabled:true,provider:"ga4",measurementId:"not-an-id"};
  assert.throws(()=>validateManifest(invalid));
  const old = structuredClone(raw);
  assert.equal(validateManifest(old).site.consent,undefined);
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
test("Kinnhakenhank release policy has exact ID/SHA parity with PHP", () => {
  const valid = structuredClone(releasePolicyFixture) as unknown as ReturnType<
    typeof validateManifest
  >;
  assert.equal(validateManifest(valid, true).mode, "release");

  const wrongSha = structuredClone(valid);
  wrongSha.assets[1]!.sha256 = "a".repeat(64);
  assert.throws(() => validateManifest(wrongSha, true), /checksum mismatch/);

  const wrongId = structuredClone(valid);
  wrongId.assets[1]!.id = "local-f-unapproved-trailer";
  wrongId.projects[0]!.localTrailerAssetId = "local-f-unapproved-trailer";
  assert.throws(() => validateManifest(wrongId, true), /approved V2 trailer ID/);

  const external = structuredClone(valid);
  external.projects[0]!.externalFilm = {
    provider: "youtube",
    videoId: "full-film",
    url: "https://example.test/full-film",
  };
  assert.throws(() => validateManifest(external, true), /Kinnhakenhank/);

  const hover = structuredClone(valid);
  hover.assets.push({
    id: "local-f-1967744a58884a30",
    type: "video",
    path: "media/kinnhakenhank-silent-preview.mp4",
    duration: 18.28,
    alt: "Unapproved silent preview",
    crop: { x: 50, y: 50 },
    origin: "Policy fixture",
    source: "Policy fixture",
    state: "approved",
    sha256: "2".repeat(64),
  });
  hover.projects[0]!.memberships.music = {
    order: 1,
    role: "Composer",
    coverAssetId: "release-cover",
    crop: { x: 50, y: 50 },
    hoverAssetId: "local-f-1967744a58884a30",
  };
  assert.throws(() => validateManifest(hover, true), /unapproved video reference/);
});
test("Letterboxd release regression still permits confirmed stills without video", () => {
  const manifest = structuredClone(
    releasePolicyFixture,
  ) as unknown as ReturnType<typeof validateManifest>;
  manifest.projects[0]!.id = "letterboxd-everything-everywhere-all-at-once";
  manifest.projects[0]!.slug = "letterboxd-everything-everywhere-all-at-once";
  delete manifest.projects[0]!.localTrailerAssetId;
  assert.equal(validateManifest(manifest, true).mode, "release");
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
test("Corporate Video is valid and its public filter only appears when published content uses it", () => {
  const data = validateManifest(raw);
  assert.equal(selectDirectingFilters(data).includes("Corporate Video"), false);
  const altered = structuredClone(data);
  const project = altered.projects.find(item => item.memberships.directing)!;
  project.memberships.directing = {
    ...project.memberships.directing!,
    role: "Corporate Video",
  };
  const validated = validateManifest(altered);
  assert.equal(selectDirectingFilters(validated).includes("Corporate Video"), true);
  assert.equal(
    selectProjects(validated, "directing", "Corporate Video")[0]?.id,
    project.id,
  );
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
