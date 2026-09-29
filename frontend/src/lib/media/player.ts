import { content, asset } from "../content/client-data";
import { projectForTrack, platformLinks } from "../content/selectors";
import { MediaCoordinator, type Playable } from "./coordinator";
export const coordinator = new MediaCoordinator(
  document.querySelector<HTMLAudioElement>("#shared-audio")!,
);
export const playable = (id: string): Playable => {
  const track = content.tracks.find((t) => t.id === id)!;
  return {
    id: track.id,
    title: track.title,
    url: track.audioAssetId ? "/" + asset(track.audioAssetId)!.path : null,
    projectId: projectForTrack(content, id)?.id,
    duration: track.duration,
    peaks: track.peaks,
  };
};
const format = (time: number | null) =>
  time === null || !Number.isFinite(time)
    ? "—"
    : `${Math.floor(time / 60)}:${String(Math.floor(time % 60)).padStart(2, "0")}`;
const panel = () => document.querySelector<HTMLElement>(".player");
export function renderPlayer() {
  const root = panel();
  if (!root) return;
  const s = coordinator.state;
  root.hidden = !s.visible;
  root.dataset.source = s.kind;
  document.documentElement.style.setProperty(
    "--player-space",
    s.visible ? `${root.getBoundingClientRect().height + 15}px` : "0px",
  );
  const text = (selector: string, value: string) => {
    const el = root.querySelector(selector);
    if (el) el.textContent = value;
  };
  text("[data-player-title]", s.track?.title || "");
  const project = content.projects.find((p) => p.id === s.track?.projectId);
  text(
    "[data-player-subtitle]",
    s.kind === "showreel" ? "Showreel audio" : project?.title || "Audio",
  );
  text("[data-player-time]", format(s.time));
  text("[data-player-duration]", format(s.duration));
  const cover = root.querySelector<HTMLImageElement>("[data-player-cover]")!;
  const coverAsset = asset(project?.memberships.music?.coverAssetId);
  cover.hidden = !coverAsset;
  if (coverAsset) {
    const src = "/" + coverAsset.path.replace(".webp", "-480.webp");
    if (cover.getAttribute("src") !== src) cover.src = src;
  }
  root.querySelector<HTMLElement>("[data-play-icon]")!.hidden = s.playing;
  root.querySelector<HTMLElement>("[data-pause-icon]")!.hidden = !s.playing;
  root
    .querySelector("[data-player=toggle]")!
    .setAttribute("aria-label", s.playing ? "Pause" : "Play");
  root
    .querySelectorAll("[data-player=shuffle]")
    .forEach((button) =>
      button.setAttribute("aria-pressed", String(s.shuffle)),
    );
  root.querySelectorAll<HTMLElement>("[data-player=mute]").forEach((mute) => {
    mute.setAttribute(
      "aria-label",
      coordinator.muted ? "Unmute audio" : "Mute audio",
    );
    mute.setAttribute("aria-pressed", String(coordinator.muted));
  });
  root
    .querySelectorAll<HTMLElement>("[data-sound-icon]")
    .forEach((icon) => (icon.hidden = coordinator.muted));
  root
    .querySelectorAll<HTMLElement>("[data-sound-off-icon]")
    .forEach((icon) => (icon.hidden = !coordinator.muted));
  root
    .querySelectorAll<HTMLInputElement>("[data-player-volume]")
    .forEach((volume) => (volume.value = String(coordinator.volumeLevel)));
  const seek = root.querySelector<HTMLInputElement>("[data-player-seek]")!;
  seek.value = String(s.duration ? (s.time / s.duration) * 100 : 0);
  seek.disabled = !s.duration;
  root
    .querySelectorAll<HTMLAnchorElement>("[data-player-project]")
    .forEach((info) => {
      info.hidden = !project;
      if (project) {
        info.href = `/projects/${project.slug}/`;
        info.dataset.project = project.id;
      }
    });
  const track = content.tracks.find((t) => t.id === s.track?.id);
  const links = track && project ? platformLinks(track, project) : {};
  const signature = JSON.stringify(links);
  root
    .querySelectorAll<HTMLElement>("[data-player-platforms]")
    .forEach((platforms) => {
      platforms.hidden = !Object.keys(links).length;
      if (platforms.dataset.links !== signature) {
        platforms.dataset.links = signature;
        platforms.replaceChildren(
          ...Object.entries(links).map(([name, url]) => {
            const a = document.createElement("a");
            a.href = url!;
            a.target = "_blank";
            a.rel = "noreferrer";
            a.dataset.playerPlatform = name;
            const label =
              name === "appleMusic"
                ? "Apple Music"
                : name.charAt(0).toUpperCase() + name.slice(1);
            a.textContent = label + " ↗";
            a.setAttribute(
              "aria-label",
              `${label} ${url?.includes("/track/") ? "track" : "album"}`,
            );
            return a;
          }),
        );
      }
    });
  const error = root.querySelector<HTMLElement>(".player-error")!;
  error.hidden = !s.error;
  error.textContent = s.error || "";
}
coordinator.subscribe(renderPlayer);
new ResizeObserver(() => {
  const root = panel();
  document.documentElement.style.setProperty(
    "--player-space",
    root && !root.hidden
      ? `${root.getBoundingClientRect().height + 15}px`
      : "0px",
  );
}).observe(panel()!);
document.addEventListener("click", (event) => {
  const button = (event.target as Element).closest<HTMLElement>(
    "[data-player]",
  );
  if (!button) return;
  const action = button.dataset.player;
  if (action === "toggle") void coordinator.toggle();
  if (action === "next") void coordinator.next();
  if (action === "previous") void coordinator.previous();
  if (action === "shuffle") coordinator.toggleShuffle();
  if (action === "close") coordinator.close();
  if (action === "mute") coordinator.mute();
});
const handleRange = (event: Event) => {
  const input = event.target as HTMLInputElement;
  if (input.matches("[data-player-seek]"))
    coordinator.seek(Number(input.value) / 100);
  if (input.matches("[data-player-volume]"))
    coordinator.volume(Number(input.value));
};
document.addEventListener("input", handleRange);
document.addEventListener("change", (event) => {
  const input = event.target as HTMLInputElement;
  if (input.matches("[data-player-volume]"))
    coordinator.volume(Number(input.value));
});
// Only the explicitly started Vite test mode imports fixtures. This branch is erased from normal builds.
if (import.meta.env.MODE === "test")
  void import("./test-adapter").then((m) => m.installTestAdapter(coordinator));
