import test from "node:test";
import assert from "node:assert/strict";
import {
  MediaCoordinator,
  adjacentIndex,
  type MediaPort,
  type Playable,
} from "../../frontend/src/lib/media/coordinator";
class FakeMedia extends EventTarget implements MediaPort {
  src = "";
  currentTime = 0;
  duration = 60;
  volume = 1;
  muted = false;
  paused = true;
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
const queue: Playable[] = [
  { id: "one", title: "Test one", url: "/one.wav" },
  { id: "two", title: "Test two", url: "/two.wav" },
];
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

test("hero resumes only a navigation pause, keeping time and explicit sound choice", async () => {
  const audio = new FakeMedia(),
    video = new FakeMedia(),
    c = new MediaCoordinator(audio);
  video.muted = true;
  await c.useShowreel(video, false, false);
  video.currentTime = 23;
  c.section("directing");
  assert.equal(c.showreelPauseReason, "navigation");
  await c.resumeShowreelFromNavigation(video);
  assert.equal(video.paused, false);
  assert.equal(video.currentTime, 23);
  assert.equal(video.muted, true);
  video.muted = false;
  c.section("about");
  await c.resumeShowreelFromNavigation(video);
  assert.equal(video.muted, false);
  assert.equal(c.state.visible, true);
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
