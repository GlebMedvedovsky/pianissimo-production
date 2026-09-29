import { z } from "zod";
export const musicCategories = [
  "Fiction Film",
  "Documentary",
  "Commercial",
  "Solo Work",
  "Games",
] as const;
export const genres = [
  "Orchestral",
  "Electronic",
  "Experimental",
  "Jazz",
  "Ambient",
  "Acoustic",
  "Songs",
] as const;
export const emotions = [
  "uplifting",
  "empowering",
  "happy",
  "sorrowful",
  "dark",
  "epic",
  "gritty",
  "calm",
] as const;
const id = z.string().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/);
const url = z
  .url()
  .refine(
    (v) => ["https:", "http:"].includes(new URL(v).protocol),
    "Only HTTP(S) URLs are allowed",
  );
const status = z.enum(["published", "draft", "needs-confirmation", "test"]);
const links = z.object({
  spotify: url.optional(),
  tidal: url.optional(),
  deezer: url.optional(),
  appleMusic: url.optional(),
});
const profileLinks = z.object({
  imdb: url.optional(),
  crewUnited: url.optional(),
  musicHub: url.optional(),
});
export const socialProviders = [
  "instagram",
  "imdb",
  "crew-united",
  "music-hub",
  "spotify",
  "tidal",
  "apple-music",
  "deezer",
  "youtube",
  "vimeo",
  "soundcloud",
  "letterboxd",
  "website",
  "other",
] as const;
const socialLink = z.object({
  provider: z.enum(socialProviders),
  label: z.string().min(1).max(100),
  url,
  visible: z.boolean(),
  sort_order: z.number().int(),
});
const collective = z.object({
  name: z.string().min(1),
  url,
  logoAssetId: id.nullable().optional(),
  membershipText: z.string().min(1),
});
const consent = z.object({
  version: z.number().int().positive(),
  necessary: z.literal(true),
  externalMedia: z.boolean(),
  analytics: z.boolean(),
  marketing: z.boolean(),
  bannerText: z.string().max(500),
  privacyLink: z.string().regex(/^\/[a-z0-9/-]+$/),
});
const analytics = z.object({
  enabled: z.boolean(),
  provider: z.enum(["none", "ga4", "plausible"]),
  measurementId: z
    .string()
    .regex(/^G-[A-Z0-9]{4,20}$/)
    .nullable()
    .optional(),
  siteDomain: z
    .string()
    .regex(/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/)
    .nullable()
    .optional(),
});
const crop = z.object({
  x: z.number().min(0).max(100),
  y: z.number().min(0).max(100),
});
const membership = z.object({
  order: z.number().int(),
  role: z.string().min(1),
  coverAssetId: id,
  crop,
  fit: z.enum(["cover", "contain"]).optional(),
  hoverAssetId: id.optional(),
});
export const assetSchema = z.object({
  id,
  type: z.enum(["image", "audio", "video"]),
  path: z
    .string()
    .regex(
      /^media\/[a-zA-Z0-9/_-]+\.(webp|jpg|jpeg|png|mp3|wav|ogg|mp4|webm)$/,
    ),
  width: z.number().positive().optional(),
  height: z.number().positive().optional(),
  duration: z.number().positive().optional(),
  alt: z.string(),
  crop,
  origin: z.string().min(1),
  source: z.string().min(1),
  state: z.enum(["approved", "temporary", "test"]),
  credit: z.string().nullable().optional(),
  sourceSha256: z
    .string()
    .regex(/^[a-f0-9]{64}$/)
    .optional(),
  sha256: z
    .string()
    .regex(/^[a-f0-9]{64}$/)
    .optional(),
});
export const projectSchema = z.object({
  id,
  slug: id,
  title: z.string().min(1),
  synopsis: z.string(),
  status,
  order: z.number().int(),
  productionType: z.string(),
  year: z.string().nullable(),
  credits: z.array(z.object({ role: z.string(), name: z.string() })),
  categories: z.array(z.enum(musicCategories)),
  memberships: z.object({
    music: membership.optional(),
    directing: membership
      .extend({ role: z.enum(["Director", "Assistant Director"]) })
      .optional(),
  }),
  awards: z.array(z.string()),
  gallery: z.array(id),
  externalFilm: z
    .object({
      provider: z.enum(["youtube", "vimeo"]),
      videoId: z.string().regex(/^[a-zA-Z0-9_-]+$/),
      url,
    })
    .nullable(),
  platformLinks: links,
  tracks: z.array(z.object({ trackId: id, order: z.number().int() })),
  sourceIds: z.array(id),
  presentationStatus: z.literal("placeholder").optional(),
  genres: z.array(z.enum(genres)).optional(),
  emotions: z.array(z.enum(emotions)).optional(),
  yearStatus: z.literal("pending").optional(),
  orderStatus: z.literal("temporary_local_draft").optional(),
  localTrailerAssetId: id.optional(),
  localTrailerPosterId: id.optional(),
  externalLinks: z.object({ instagram: url.optional() }).optional(),
});
export const trackSchema = z.object({
  id,
  title: z.string().min(1),
  audioAssetId: id.nullable(),
  duration: z.number().positive().nullable(),
  peaks: z.array(z.number().min(0).max(1)).nullable(),
  genres: z.array(z.enum(genres)),
  emotions: z.array(z.enum(emotions)),
  platformOverrides: links,
  status,
  libraryVisible: z.boolean().optional(),
  audioSha256: z
    .string()
    .regex(/^[a-f0-9]{64}$/)
    .optional(),
  waveformSha256: z
    .string()
    .regex(/^[a-f0-9]{64}$/)
    .optional(),
  tagSource: z.enum(["track_answer", "inherited_from_project"]).optional(),
});
const slot = z.object({
  image: id.nullable(),
  video: id.nullable(),
  poster: id.nullable(),
});
export const manifestSchema = z.object({
  schemaVersion: z.literal("1.0.0"),
  mode: z.enum(["prototype", "release", "test"]),
  sources: z.array(z.object({ id, url: z.string(), note: z.string() })),
  assets: z.array(assetSchema),
  projects: z.array(projectSchema),
  tracks: z.array(trackSchema),
  site: z.object({
    language: z.literal("en"),
    title: z.string(),
    description: z.string(),
    biography: z.array(z.string()),
    cv: z.array(
      z.object({
        date: z.string(),
        text: z.string(),
        status,
        link: url.optional(),
        linkText: z.string().min(1).optional(),
      }),
    ),
    contacts: z.object({
      email: z.email().nullable(),
      instagram: url.nullable(),
      spotify: url.nullable(),
      samples: url.nullable(),
    }),
    legal: z.object({
      notice: z.string(),
      privacy: z.string(),
      approved: z.boolean(),
    }),
    productionDomain: url.nullable(),
    artistLinks: links.optional(),
    profileLinks: profileLinks.optional(),
    socialLinks: z.array(socialLink).optional(),
    collective: collective.optional(),
    consent: consent.optional(),
    analytics: analytics.optional(),
    mediaSlots: z.object({
      hero: slot,
      composerMenu: slot,
      directingMenu: slot,
      about: slot,
    }),
  }),
});
export type Manifest = z.infer<typeof manifestSchema>;
export type Project = z.infer<typeof projectSchema>;
export type Track = z.infer<typeof trackSchema>;
export type MediaAsset = z.infer<typeof assetSchema>;
export type ContentValidationPolicy = "publication" | "draft-preview";
export function validateManifest(
  input: unknown,
  release = false,
  policy: ContentValidationPolicy = "publication",
): Manifest {
  const data = manifestSchema.parse(input);
  const unique = (values: string[], label: string) => {
    if (new Set(values).size !== values.length)
      throw Error(`Duplicate ${label}`);
  };
  for (const name of ["assets", "projects", "tracks", "sources"] as const)
    unique(
      data[name].map((x) => x.id),
      name,
    );
  unique(
    data.projects.map((p) => p.slug),
    "slug",
  );
  const assets = new Map(data.assets.map((a) => [a.id, a]));
  const tracks = new Set(data.tracks.map((t) => t.id));
  const sources = new Set(data.sources.map((s) => s.id));
  const ref = (value: string | null, type?: MediaAsset["type"]) => {
    if (
      value &&
      (!assets.has(value) || (type && assets.get(value)?.type !== type))
    )
      throw Error(`Invalid asset reference: ${value}`);
  };
  for (const p of data.projects) {
    for (const m of Object.values(p.memberships)) {
      ref(m.coverAssetId, "image");
      ref(m.hoverAssetId || null, "video");
    }
    ref(p.localTrailerAssetId || null, "video");
    ref(p.localTrailerPosterId || null, "image");
    p.gallery.forEach((a) => ref(a, "image"));
    unique(
      p.tracks.map((t) => t.trackId),
      `project track ${p.id}`,
    );
    p.tracks.forEach((t) => {
      if (!tracks.has(t.trackId)) throw Error(`Unknown track ${t.trackId}`);
    });
    p.sourceIds.forEach((s) => {
      if (!sources.has(s)) throw Error(`Unknown source ${s}`);
    });
  }
  data.tracks.forEach((t) => {
    ref(t.audioAssetId, "audio");
    if (t.peaks && !t.duration) throw Error(`Peaks need duration: ${t.id}`);
  });
  Object.values(data.site.mediaSlots).forEach((s) => {
    ref(s.image, "image");
    ref(s.poster, "image");
    ref(s.video, "video");
  });
  ref(data.site.collective?.logoAssetId || null, "image");
  if (
    policy === "publication" &&
    data.mode !== "test" &&
    (data.assets.some((a) => a.state === "test") ||
      data.projects.some((p) => p.status === "test") ||
      data.tracks.some((t) => t.status === "test"))
  )
    throw Error("Test fixtures cannot enter ordinary content");
  if (data.projects.find((p) => p.id === "kinnhakenhank")?.externalFilm)
    throw Error("Kinnhakenhank permits only the selected local trailer");
  const letterboxd = data.projects.find(
    (p) => p.id === "letterboxd-everything-everywhere-all-at-once",
  );
  if (
    letterboxd &&
    (letterboxd.externalFilm ||
      letterboxd.localTrailerAssetId ||
      Object.values(letterboxd.memberships).some((m) => m.hoverAssetId))
  )
    throw Error("Letterboxd permits only confirmed screenshots, no video");
  if (release || data.mode === "release") {
    const failures: string[] = [];
    if (data.mode !== "release") failures.push("manifest mode must be release");
    if (data.assets.some((a) => a.state !== "approved"))
      failures.push("unapproved assets");
    if (
      [...data.projects, ...data.tracks, ...data.site.cv].some(
        (x) => x.status !== "published",
      )
    )
      failures.push("draft, test or unconfirmed content");
    if (data.tracks.some((t) => !t.audioAssetId))
      failures.push("missing audio");
    if (
      !data.site.legal.approved ||
      !data.site.contacts.email ||
      !data.site.productionDomain
    )
      failures.push("legal/contact/domain approval missing");
    if (failures.length) throw Error(`Release blocked: ${failures.join("; ")}`);
  }
  return data;
}
