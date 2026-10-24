import test from "node:test";
import assert from "node:assert/strict";
import {
  MediaCoordinator,
  adjacentIndex,
  rankAutoplayCandidates,
  type FadeClock,
  type MediaPort,
  type Playable,
} from "../../frontend/src/lib/media/coordinator";
import {
  createShowreelGain,
  type AudioContextPort,
  type ShowreelGainController,
} from "../../frontend/src/lib/media/showreel-audio";
class FakeMedia extends EventTarget implements MediaPort {
  src = "";
  currentTime = 0;
  duration = 60;
  volume = 1;
  muted = false;
  paused = true;
  readyState = 1;
  fail = false;
  async play() {
    if (this.fail) throw Error("blocked");
    this.paused = false;
    this.dispatchEvent(new Event("play"));
  }
  pause() {
    this.paused = true;
    this.dispatchEvent(new Event("pause"));
  }
  load() {}
  removeAttribute() {
    this.src = "";
  }
}
class FakeFadeClock implements FadeClock {
  private frame = 0;
  private callbacks = new Map<number, FrameRequestCallback>();
  time = 0;
  now() {
    return this.time;
  }
  requestFrame(callback: FrameRequestCallback) {
    const frame = ++this.frame;
    this.callbacks.set(frame, callback);
    return frame;
  }
  cancelFrame(frame: number) {
    this.callbacks.delete(frame);
  }
  tick(time: number) {
    this.time = time;
    const callbacks = [...this.callbacks.values()];
    this.callbacks.clear();
    callbacks.forEach(callback => callback(time));
  }
}
class FakeShowreelGain implements ShowreelGainController {
  fadeDurations: number[] = [];
  fadeStarts: number[] = [];
  states: Array<{ muted: boolean; volume: number }> = [];
  holds = 0;
  restores = 0;
  resumes = 0;
  muted = true;
  volume = 1;
  effective = 0;
  setUserState(muted: boolean, volume: number) {
    this.muted = muted;
    this.volume = volume;
    this.effective = muted ? 0 : volume;
    this.states.push({ muted, volume });
  }
  fadeOut(duration: number) {
    this.fadeStarts.push(this.effective);
    this.fadeDurations.push(duration);
    this.effective = 0;
  }
  hold() {
    this.holds++;
  }
  restore() {
    this.restores++;
    this.effective = this.muted ? 0 : this.volume;
  }
  async resume() {
    this.resumes++;
  }
}
class FakeAudioContext implements AudioContextPort {
  currentTime = 4;
  state = "suspended";
  destination = {};
  sourceConnections: unknown[] = [];
  gainConnections: unknown[] = [];
  gainCalls: Array<[string, number, number]> = [];
  resumes = 0;
  createMediaElementSource() {
    return { connect: (destination: unknown) => this.sourceConnections.push(destination) };
  }
  createGain() {
    return {
      gain: {
        cancelScheduledValues: (time: number) => this.gainCalls.push(["cancel", time, 0]),
        setValueAtTime: (value: number, time: number) =>
          this.gainCalls.push(["set", value, time]),
        linearRampToValueAtTime: (value: number, time: number) =>
          this.gainCalls.push(["ramp", value, time]),
      },
      connect: (destination: unknown) => this.gainConnections.push(destination),
    };
  }
  async resume() {
    this.resumes++;
    this.state = "running";
  }
}
const queue: Playable[] = [
  { id: "one", title: "Test one", url: "/one.wav" },
  { id: "two", title: "Test two", url: "/two.wav" },
];
test("seek uses trusted track metadata while native duration is still unavailable", async () => {
  const media = new FakeMedia();
  media.duration = Number.NaN;
  const c = new MediaCoordinator(media);
  await c.select({ ...queue[0]!, duration: 80 }, queue);
  c.seek(.25);
  assert.equal(media.currentTime, 20);
  assert.equal(c.state.time, 20);
});
test("queue, next, previous and end remain predictable", async () => {
  const media = new FakeMedia(),
    c = new MediaCoordinator(media);
  await c.select(queue[0]!, queue);
  assert.equal(c.state.playing, true);
  await c.next();
  assert.equal(c.state.track?.id, "two");
  await c.previous();
  assert.equal(c.state.track?.id, "one");
  await c.next();
  await c.next();
  assert.equal(c.state.playing, false);
  assert.equal(c.state.track?.id, "two");
});
test("shuffle cannot repeat current when another track exists", () => {
  for (const random of [0, 0.5, 0.999])
    assert.notEqual(
      adjacentIndex(5, 2, 1, true, () => random),
      2,
    );
  assert.equal(adjacentIndex(1, 0, 1, true), -1);
  assert.equal(adjacentIndex(0, 0, 1, false), -1);
});
test("single-track Next continues into playable public-library autoplay", async () => {
  const media = new FakeMedia();
  const c = new MediaCoordinator(media);
  const single = [{ ...queue[0]!, emotions: ["calm"], genres: ["Ambient"] }];
  const related = { ...queue[1]!, emotions: ["calm"], genres: ["Ambient"] };
  c.setAutoplayCandidates([single[0]!, related]);
  await c.select(single[0]!, single);
  await c.next();
  assert.equal(c.state.track?.id, "two");
  assert.equal(c.state.playing, true);
});
test("natural track end continues into library autoplay", async () => {
  const media = new FakeMedia();
  const c = new MediaCoordinator(media);
  c.setAutoplayCandidates(queue);
  await c.select(queue[0]!, [queue[0]!]);
  media.dispatchEvent(new Event("ended"));
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(c.state.track?.id, "two");
  assert.equal(c.state.playing, true);
});
test("album shuffle consumes every track once before autoplay", async () => {
  const media = new FakeMedia();
  const album = [
    queue[0]!,
    queue[1]!,
    { id: "three", title: "Test three", url: "/three.wav" },
  ];
  const libraryTrack = { id: "four", title: "Test four", url: "/four.wav" };
  const c = new MediaCoordinator(media, undefined, () => 0);
  c.setAutoplayCandidates([...album, libraryTrack]);
  await c.select(album[0]!, album);
  c.toggleShuffle();
  const played = [c.state.track?.id];
  await c.next(); played.push(c.state.track?.id);
  await c.next(); played.push(c.state.track?.id);
  assert.deepEqual(new Set(played), new Set(["one", "two", "three"]));
  await c.next();
  assert.equal(c.state.track?.id, "four");
});
test("shuffle Previous and Next traverse forward history before autoplay", async () => {
  const media = new FakeMedia();
  const album = [
    queue[0]!,
    queue[1]!,
    { id: "three", title: "Test three", url: "/three.wav" },
  ];
  const libraryTrack = { id: "four", title: "Test four", url: "/four.wav" };
  const c = new MediaCoordinator(media, undefined, () => 0);
  c.setAutoplayCandidates([...album, libraryTrack]);
  await c.select(album[0]!, album);
  c.toggleShuffle();
  await c.next();
  await c.next();
  assert.equal(c.state.track?.id, "three");
  await c.previous();
  assert.equal(c.state.track?.id, "two");
  await c.next();
  assert.equal(c.state.track?.id, "three");
  await c.next();
  assert.equal(c.state.track?.id, "four");
});
test("shuffle supports multiple Previous and Next history traversals", async () => {
  const media = new FakeMedia();
  const album = [
    queue[0]!,
    queue[1]!,
    { id: "three", title: "Test three", url: "/three.wav" },
  ];
  const libraryTrack = { id: "four", title: "Test four", url: "/four.wav" };
  const c = new MediaCoordinator(media, undefined, () => 0);
  c.setAutoplayCandidates([...album, libraryTrack]);
  await c.select(album[0]!, album);
  c.toggleShuffle();
  await c.next();
  await c.next();
  await c.previous();
  await c.previous();
  assert.equal(c.state.track?.id, "one");
  await c.next();
  assert.equal(c.state.track?.id, "two");
  await c.next();
  assert.equal(c.state.track?.id, "three");
  await c.next();
  assert.equal(c.state.track?.id, "four");
});
test("a fresh manual selection clears stale forward shuffle history", async () => {
  const media = new FakeMedia();
  const album = [
    queue[0]!,
    queue[1]!,
    { id: "three", title: "Test three", url: "/three.wav" },
  ];
  const c = new MediaCoordinator(media, undefined, () => 0);
  c.setAutoplayCandidates(album);
  await c.select(album[0]!, album);
  c.toggleShuffle();
  await c.next();
  await c.next();
  await c.previous();
  await c.select(album[0]!, album);
  await c.next();
  assert.equal(c.state.track?.id, "two");
});
test("autoplay ranks shared emotion first and shared genre as its secondary preference", () => {
  const current: Playable = {
    id: "current", title: "Current", url: "/current.wav",
    emotions: ["calm"], genres: ["Ambient"],
  };
  const ranked = rankAutoplayCandidates(current, [
    { id: "other", title: "Other", url: "/other.wav", emotions: ["dark"], genres: ["Ambient"] },
    { id: "emotion", title: "Emotion", url: "/emotion.wav", emotions: ["calm"], genres: ["Jazz"] },
    { id: "both", title: "Both", url: "/both.wav", emotions: ["calm"], genres: ["Ambient"] },
  ]);
  assert.deepEqual(ranked.map(track => track.id), ["both", "emotion", "other"]);
});
test("autoplay excludes unplayable tracks and cannot loop on one playable global track", async () => {
  const c = new MediaCoordinator(new FakeMedia());
  const only = queue[0]!;
  c.setAutoplayCandidates([
    only,
    { id: "missing", title: "Missing", url: null, emotions: ["calm"] },
  ]);
  await c.select(only, [only]);
  await c.next();
  assert.equal(c.state.track?.id, "one");
  assert.equal(c.state.playing, false);
});
test("Previous returns coherently from autoplay to the album track", async () => {
  const media = new FakeMedia();
  const c = new MediaCoordinator(media);
  c.setAutoplayCandidates(queue);
  await c.select(queue[0]!, [queue[0]!]);
  await c.next();
  assert.equal(c.state.track?.id, "two");
  await c.previous();
  assert.equal(c.state.track?.id, "one");
  assert.equal(c.state.playing, true);
});
test("pause-on-section saves position; return never autoplays; close resets", async () => {
  const media = new FakeMedia(),
    c = new MediaCoordinator(media);
  await c.select(queue[0]!, queue);
  media.currentTime = 18;
  c.section("about");
  assert.equal(c.state.playing, false);
  assert.equal(media.currentTime, 18);
  c.section("music");
  assert.equal(c.state.playing, false);
  await c.toggle();
  assert.equal(c.state.playing, true);
  c.section("directing");
  assert.equal(c.state.visible, false);
  c.close();
  assert.equal(media.currentTime, 0);
  assert.equal(c.state.visible, false);
});
test("missing/corrupt audio and rejected play do not claim playback", async () => {
  const media = new FakeMedia(),
    c = new MediaCoordinator(media);
  await c.select({ id: "none", title: "Missing", url: null }, []);
  assert.equal(c.state.playing, false);
  assert.match(c.state.error!, /not available/);
  media.fail = true;
  await c.select(queue[0]!, queue);
  assert.equal(c.state.playing, false);
  assert.match(c.state.error!, /could not start/);
  media.fail = false;
  await c.toggle();
  media.dispatchEvent(new Event("error"));
  assert.equal(c.state.playing, false);
  assert.match(c.state.error!, /could not be loaded/);
});
test("one sounding source: music, external film and the same showreel element", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    c = new MediaCoordinator(audio);
  let stopped = 0;
  c.registerOther(() => stopped++);
  await c.useShowreel(video);
  assert.equal(audio.paused, true);
  assert.equal(video.paused, false);
  video.currentTime = 12;
  c.exposePanel();
  assert.equal(video.currentTime, 12);
  c.pause();
  await c.toggle();
  assert.equal(video.currentTime, 12);
  await c.select(queue[0]!, queue);
  assert.equal(video.paused, true);
  c.activateExternal();
  assert.equal(audio.paused, true);
  assert.ok(stopped >= 3);
});
test("seek, volume and unknown duration are safe", async () => {
  const media = new FakeMedia(),
    c = new MediaCoordinator(media);
  await c.select(queue[0]!, queue);
  c.seek(0.5);
  assert.equal(media.currentTime, 30);
  c.volume(2);
  assert.equal(media.volume, 1);
  media.duration = NaN;
  c.seek(0.8);
  assert.equal(media.currentTime, 30);
  media.dispatchEvent(new Event("durationchange"));
  assert.equal(c.state.duration, null);
});
test("closed player does not reopen on ordinary route changes", async () => {
  const c = new MediaCoordinator(new FakeMedia());
  await c.select(queue[0]!, queue);
  c.close();
  c.section("music");
  assert.equal(c.state.visible, false);
  c.section("directing");
  c.section("home");
  assert.equal(c.state.visible, false);
});

test("hero resumes a navigation pause only after return to Home", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    c = new MediaCoordinator(audio);
  video.muted = true;
  await c.useShowreel(video, false, false);
  video.currentTime = 23;
  c.section("directing");
  assert.equal(c.showreelPauseReason, "navigation");
  await c.resumeShowreelFromNavigation(video);
  assert.equal(video.paused, true);
  assert.equal(c.state.visible, false);
  c.section("home");
  await c.resumeShowreelFromNavigation(video);
  assert.equal(video.paused, false);
  assert.equal(video.currentTime, 23);
  assert.equal(video.muted, true);
  c.section("about");
  await c.resumeShowreelFromNavigation(video);
  assert.equal(video.paused, true);
  assert.equal(video.muted, true);
  assert.equal(c.state.visible, false);
  assert.equal(audio.paused, true);
  c.pause();
  c.section("directing");
  await c.resumeShowreelFromNavigation(video);
  assert.equal(video.paused, true);
  assert.equal(c.showreelPauseReason, "manual");
});
test("native manual pause and another audio source cannot be overridden by hero return", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    c = new MediaCoordinator(audio);
  await c.useShowreel(video, false);
  video.pause();
  c.section("about");
  await c.resumeShowreelFromNavigation(video);
  assert.equal(video.paused, true);
  await c.useShowreel(video, false);
  await c.select(queue[0]!, queue);
  await c.resumeShowreelFromNavigation(video);
  assert.equal(video.paused, true);
  assert.equal(c.currentMedia, audio);
  assert.equal(audio.paused, false);
});
test("hero navigation fade schedules GainNode silence before it pauses", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    clock = new FakeFadeClock(),
    gain = new FakeShowreelGain(),
    c = new MediaCoordinator(audio, clock);
  video.volume = 0.7;
  await c.useShowreel(video, false);
  c.setShowreelGain(video, gain);
  assert.equal(c.fadeShowreelForNavigation(video), true);
  assert.deepEqual(gain.fadeDurations, [1000]);
  assert.deepEqual(gain.fadeStarts, [0.7]);
  clock.tick(500);
  assert.equal(video.volume, 0.7);
  assert.equal(video.paused, false);
  clock.tick(1000);
  assert.equal(video.paused, true);
  assert.equal(c.showreelPauseReason, "navigation");
  assert.equal(gain.restores, 0);
});
test("About hides a sounding showreel and uses the existing GainNode fade", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    clock = new FakeFadeClock(),
    gain = new FakeShowreelGain(),
    c = new MediaCoordinator(audio, clock);
  video.currentTime = 17;
  await c.useShowreel(video, false);
  c.setShowreelGain(video, gain);
  c.section("about");
  assert.equal(c.state.visible, false);
  assert.deepEqual(gain.fadeDurations, [1000]);
  clock.tick(500);
  assert.equal(video.paused, false);
  clock.tick(1000);
  assert.equal(video.paused, true);
  assert.equal(video.currentTime, 17);
});
test("muted and paused showreels enter About without starting a fade", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    gain = new FakeShowreelGain(),
    c = new MediaCoordinator(audio);
  video.muted = true;
  await c.useShowreel(video, false);
  c.setShowreelGain(video, gain);
  c.section("about");
  assert.equal(video.paused, true);
  assert.equal(c.state.visible, false);
  assert.deepEqual(gain.fadeDurations, []);
  c.section("home");
  c.pause();
  c.section("about");
  assert.deepEqual(gain.fadeDurations, []);
});
test("showreel stays hidden and paused outside Home while music tracks keep their panel", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    c = new MediaCoordinator(audio);
  await c.useShowreel(video, false);
  c.hideShowreelPanelForNavigation();
  assert.equal(video.paused, false);
  assert.equal(c.state.visible, false);
  c.section("music");
  assert.equal(video.paused, true);
  assert.equal(c.state.visible, false);
  video.dispatchEvent(new Event("timeupdate"));
  c.exposePanel();
  assert.equal(c.state.visible, false);
  c.section("directing");
  c.section("music");
  assert.equal(c.state.visible, false);
  await c.select(queue[0]!, queue);
  c.section("music");
  assert.equal(c.state.track?.id, "one");
  assert.equal(c.state.visible, true);
  assert.equal(audio.paused, false);
});
test("a newer source selection cancels a hero fade before it can pause that source", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    clock = new FakeFadeClock(),
    gain = new FakeShowreelGain(),
    c = new MediaCoordinator(audio, clock);
  video.volume = 0.7;
  await c.useShowreel(video, false);
  c.setShowreelGain(video, gain);
  c.fadeShowreelForNavigation(video);
  clock.tick(500);
  await c.select(queue[0]!, queue);
  clock.tick(1000);
  assert.equal(audio.paused, false);
  assert.equal(c.currentMedia, audio);
  assert.equal(gain.restores, 0);
  assert.equal(gain.holds, 1);
});
test("header restart seeks the persisted showreel once and keeps its sound choice", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    c = new MediaCoordinator(audio);
  video.muted = true;
  video.currentTime = 28;
  c.restartShowreel(video);
  await Promise.resolve();
  assert.equal(video.currentTime, 0);
  assert.equal(video.muted, true);
  assert.equal(video.paused, false);
  assert.equal(c.state.kind, "showreel");
});
test("muted showreel stays silent through loop, seek, replay and header restart events", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    gain = new FakeShowreelGain(),
    c = new MediaCoordinator(audio);
  video.muted = true;
  await c.useShowreel(video, false);
  c.setShowreelGain(video, gain);
  assert.equal(gain.effective, 0);
  for (const type of ["seeking", "seeked", "timeupdate", "play", "playing"]) {
    video.dispatchEvent(new Event(type));
    assert.equal(gain.effective, 0, `${type} must preserve silence`);
  }
  c.pause();
  await c.toggle();
  assert.equal(gain.effective, 0);
  c.restartShowreel(video);
  await Promise.resolve();
  assert.equal(gain.effective, 0);
  assert.equal(c.muted, true);
});
test("showreel volume is authoritative across mute, touch-style change and loop events", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    gain = new FakeShowreelGain(),
    c = new MediaCoordinator(audio);
  await c.useShowreel(video, false);
  c.setShowreelGain(video, gain);
  c.volume(0.35);
  assert.equal(c.volumeLevel, 0.35);
  assert.equal(gain.effective, 0.35);
  video.volume = 1;
  for (const type of ["seeking", "seeked", "play", "playing", "timeupdate"]) {
    video.dispatchEvent(new Event(type));
  }
  assert.equal(c.volumeLevel, 0.35);
  assert.equal(gain.effective, 0.35);
  c.mute();
  assert.equal(c.muted, true);
  assert.equal(c.volumeLevel, 0.35);
  assert.equal(gain.effective, 0);
  c.mute();
  assert.equal(c.muted, false);
  assert.equal(c.volumeLevel, 0.35);
  assert.equal(gain.effective, 0.35);
});
test("rapid showreel mute toggles finish in a deterministic silent state", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    gain = new FakeShowreelGain(),
    c = new MediaCoordinator(audio);
  await c.useShowreel(video, false);
  c.setShowreelGain(video, gain);
  c.volume(0.35);
  c.mute();
  c.mute();
  c.mute();
  assert.equal(c.muted, true);
  assert.equal(c.volumeLevel, 0.35);
  assert.equal(gain.effective, 0);
  assert.equal(video.muted, true);
});
test("muted showreel never starts a navigation fade", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    gain = new FakeShowreelGain(),
    c = new MediaCoordinator(audio);
  video.muted = true;
  await c.useShowreel(video, false);
  c.setShowreelGain(video, gain);
  assert.equal(c.fadeShowreelForNavigation(video), false);
  assert.deepEqual(gain.fadeDurations, []);
  assert.equal(gain.effective, 0);
});
test("Web Audio showreel graph ramps a single GainNode and resumes in a gesture", async () => {
  const context = new FakeAudioContext();
  const graph = createShowreelGain(
    new FakeMedia() as unknown as HTMLMediaElement,
    () => context,
  );
  assert.ok(graph);
  graph.setUserState(false, 0.35);
  graph.fadeOut(1000);
  graph.restore();
  await graph.resume();
  assert.equal(context.sourceConnections.length, 1);
  assert.equal(context.gainConnections.length, 1);
  assert.deepEqual(context.gainCalls.slice(-5), [
    ["cancel", 4, 0],
    ["set", 0.35, 4],
    ["ramp", 0, 5],
    ["cancel", 4, 0],
    ["set", 0.35, 4],
  ]);
  assert.equal(context.resumes, 1);
});
test("an interrupted Web Audio fade continues monotonically from its current gain", () => {
  const context = new FakeAudioContext();
  const graph = createShowreelGain(
    new FakeMedia() as unknown as HTMLMediaElement,
    () => context,
  )!;
  graph.setUserState(false, 1);
  graph.fadeOut(1000);
  context.currentTime = 4.5;
  graph.hold();
  graph.fadeOut(1000);
  assert.deepEqual(context.gainCalls.slice(-5), [
    ["cancel", 4.5, 0],
    ["set", 0.5, 4.5],
    ["cancel", 4.5, 0],
    ["set", 0.5, 4.5],
    ["ramp", 0, 5.5],
  ]);
});
test("Web Audio restore cannot unmute an authoritatively muted showreel", () => {
  const context = new FakeAudioContext();
  const graph = createShowreelGain(
    new FakeMedia() as unknown as HTMLMediaElement,
    () => context,
  )!;
  graph.setUserState(false, 0.35);
  graph.setUserState(true, 0.35);
  graph.fadeOut(1000);
  graph.restore();
  assert.deepEqual(context.gainCalls.slice(-2), [
    ["cancel", 4, 0],
    ["set", 0, 4],
  ]);
});
