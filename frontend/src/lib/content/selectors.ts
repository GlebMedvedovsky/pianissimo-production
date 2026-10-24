import {
  directingRoles,
  type Manifest,
  type Project,
  type Track,
} from "../../../../contracts/content";
export function selectProjects(
  data: Manifest,
  section: "music" | "directing",
  filter: string,
) {
  return data.projects
    .filter(
      (p) =>
        p.status === "published" &&
        p.memberships[section] &&
        (filter === "All" ||
          (section === "music"
            ? p.categories.includes(filter as never)
            : p.memberships.directing?.role === filter)),
    )
    .sort(
      (a, b) => a.memberships[section]!.order - b.memberships[section]!.order,
    );
}
export function selectDirectingFilters(data: Manifest) {
  return directingRoles.filter(
    role =>
      role !== "Corporate Video" ||
      selectProjects(data, "directing", role).length > 0,
  );
}
export function selectTracks(data: Manifest, genre = "", emotion = "") {
  const ids = [
    ...new Set(
      selectProjects(data, "music", "All")
        .flatMap((p) =>
          p.tracks
            .slice()
            .sort((a, b) => a.order - b.order)
            .map((t) => t.trackId),
        )
        .concat(data.tracks.filter((t) => t.libraryVisible).map((t) => t.id)),
    ),
  ];
  return ids
    .map((id) => data.tracks.find((t) => t.id === id))
    .filter(
      (t): t is Track =>
        !!t &&
        t.status === "published" &&
        (!genre || t.genres.includes(genre as never)) &&
        (!emotion || t.emotions.includes(emotion as never)),
    );
}
export function platformLinks(track: Track, project: Project) {
  return { ...project.platformLinks, ...track.platformOverrides };
}
export function projectForTrack(data: Manifest, id: string) {
  return data.projects.find(
    (p) => p.status === "published" && p.tracks.some((t) => t.trackId === id),
  );
}
export function trackCoverAssetId(data: Manifest, track: Track) {
  if (track.coverAssetId) return track.coverAssetId;
  const candidates = [
    ...new Set(
      data.projects
        .filter(
          project =>
            project.status === "published" &&
            project.tracks.some(item => item.trackId === track.id) &&
            project.memberships.music,
        )
        .map(project => project.memberships.music!.coverAssetId),
    ),
  ];
  return candidates.length === 1 ? candidates[0] : undefined;
}
