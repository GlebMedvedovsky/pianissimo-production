import { chromium } from "@playwright/test";
import { mkdir, writeFile, readFile } from "node:fs/promises";
import assert from "node:assert/strict";
const out = "docs/qa/02D";
await mkdir(out, { recursive: true });
const result = { checks: [], audio: [], errors: [], external: [] };
const browser = await chromium.launch({ channel: "chrome", headless: true });
const base = "http://127.0.0.1:4321";
try {
  const c = await browser.newContext({
    viewport: { width: 1440, height: 900 },
  });
  await c.route("**/*", (r) => {
    if (new URL(r.request().url()).hostname !== "127.0.0.1") {
      result.external.push(new URL(r.request().url()).origin);
      return r.abort();
    }
    return r.continue();
  });
  const p = await c.newPage();
  p.on("pageerror", (e) => result.errors.push(e.message));
  await p.goto(base);
  await p.waitForFunction(
    () => document.querySelector("[data-slot-video=hero]")?.currentTime > 0,
  );
  await p.getByRole("button", { name: "Necessary only", exact: true }).click();
  await p.locator("[data-showreel-sound]").click();
  await p.waitForFunction(
    () => !document.querySelector("[data-slot-video=hero]").muted,
  );
  await p
    .locator("[data-slot-video=hero]")
    .evaluate((v) => (v.dataset.qaIdentity = "persistent-hero"));
  await p.evaluate(() =>
    scrollTo({
      top: document.querySelector(".hero-scene").offsetHeight - innerHeight,
      behavior: "instant",
    }),
  );
  await p.waitForTimeout(700);
  const before = await p
    .locator("[data-slot-video=hero]")
    .evaluate((v) => v.currentTime);
  await p.locator(".composer-choice").click();
  await p.waitForURL("**/film-music/");
  await p.waitForTimeout(700);
  const after = await p
    .locator("[data-slot-video=hero]")
    .evaluate((v) => ({
      id: v.dataset.qaIdentity,
      time: v.currentTime,
      paused: v.paused,
      muted: v.muted,
    }));
  assert.equal(after.id, "persistent-hero");
  assert.ok(after.time > before && !after.paused && !after.muted);
  result.checks.push("One persisted hero continues through menu to Film Music");
  await p.locator('.site-header a[href="/directing/"]').click();
  await p.waitForURL("**/directing/");
  await p.waitForTimeout(500);
  assert.ok(
    await p.locator("[data-slot-video=hero]").evaluate((v) => v.paused),
  );
  await p.locator('.site-header a[href="/film-music/"]').click();
  await p.waitForURL("**/film-music/");
  assert.ok(
    await p.locator("[data-slot-video=hero]").evaluate((v) => v.paused),
  );
  result.checks.push("Directing pauses hero; return does not resume");
  await p.locator(".site-header [data-about]").click();
  await p.waitForURL("**/#about");
  await p.waitForTimeout(700);
  assert.equal(await p.locator("html").getAttribute("data-theme"), "dark");
  assert.ok(
    await p.locator("[data-slot-video=hero]").evaluate((v) => v.paused),
  );
  await p.goto(base + "/directing/");
  await p.locator(".site-header [data-about]").click();
  await p.waitForURL("**/#about");
  await p.waitForTimeout(700);
  assert.equal(await p.locator("html").getAttribute("data-theme"), "light");
  result.checks.push("About context and pause");
  await p.goto(base + "/film-music/");
  await p.locator('[data-project-filter="Games"]').click();
  assert.equal(await p.locator("[data-project-card]:visible").count(), 1);
  await p.locator('[data-project-filter="All"]').click();
  await p.locator("#emotion-filter").selectOption("calm");
  assert.ok(
    (await p.locator(".music-library [data-play-track]:visible").count()) > 0,
  );
  await p.locator("[data-reset-tracks]").click();
  result.checks.push("Games and calm filters");
  const ids = await p
    .locator(".music-library [data-play-track]")
    .evaluateAll((bs) => bs.map((b) => b.dataset.playTrack));
  assert.equal(ids.length, 12);
  for (const id of ids) {
    await p.locator(`.music-library [data-play-track="${id}"]`).click();
    await p.waitForFunction(() => {
      const a = document.querySelector("#shared-audio");
      return !a.paused && a.currentTime > 0.08 && a.duration > 0;
    });
    const info = await p
      .locator("#shared-audio")
      .evaluate((a) => ({
        duration: a.duration,
        src: a.currentSrc,
        time: a.currentTime,
      }));
    await p.locator("[data-player-seek]").fill("50");
    await p.locator("[data-player-seek]").dispatchEvent("input");
    await p.waitForTimeout(100);
    assert.ok(
      await p
        .locator("#shared-audio")
        .evaluate((a) => Math.abs(a.currentTime / a.duration - 0.5) < 0.03),
    );
    assert.equal(
      await p
        .locator("video")
        .evaluateAll((vs) => vs.filter((v) => !v.paused && !v.muted).length),
      0,
    );
    result.audio.push({ id, ...info, seek: "passed" });
  }
  await p.locator('[data-player="previous"]').click();
  await p.waitForTimeout(200);
  assert.ok(
    await p.locator("#shared-audio").evaluate((a) => a.currentTime < 1),
  );
  await p.locator('[data-player="mute"]').click();
  assert.ok(await p.locator("#shared-audio").evaluate((a) => a.muted));
  await p.locator('[data-player="mute"]').click();
  await p.locator(`.music-library [data-play-track="${ids[0]}"]`).click();
  await p.waitForTimeout(250);
  await p
    .locator("#shared-audio")
    .evaluate((a) => (a.currentTime = a.duration - 0.35));
  const manifest = JSON.parse(await readFile("content/manifest.json", "utf8"));
  const nextTitle = manifest.tracks.find((t) => t.id === ids[1]).title;
  await p.waitForFunction(
    (title) =>
      document.querySelector("[data-player-title]").textContent === title,
    nextTitle,
  );
  assert.ok(await p.locator("#shared-audio").evaluate((a) => !a.paused));
  result.checks.push(
    "12 local tracks play and seek; previous, mute and natural end exercised",
  );
  await p.locator('[data-player="close"]').click();
  assert.ok(
    await p
      .locator("#shared-audio")
      .evaluate((a) => a.paused && a.currentTime === 0),
  );
  const hover = await p.locator("[data-hover-video]").count();
  assert.equal(hover, 6);
  for (const card of await p.locator("[data-project-card]:has(video)").all()) {
    await card.hover();
    await p.waitForFunction(() =>
      Array.from(document.querySelectorAll("[data-hover-video]")).some(
        (v) => !v.paused && v.currentTime > 0,
      ),
    );
    assert.ok(
      await p
        .locator("[data-hover-video]")
        .evaluateAll((vs) => vs.every((v) => v.muted)),
    );
    await p.mouse.move(2, 2);
    await p.waitForTimeout(150);
    assert.ok(
      await p
        .locator("[data-hover-video]")
        .evaluateAll((vs) => vs.every((v) => v.paused)),
    );
  }
  result.checks.push("Six lazy muted hovers");
  await p.locator('[data-project-card][data-project="kinnhakenhank"]').click();
  await p.waitForTimeout(500);
  assert.ok(await p.locator("#project-dialog").evaluate((d) => d.open));
  await p
    .locator("#project-dialog [data-local-trailer]")
    .evaluate((v) => v.play());
  await p.waitForTimeout(500);
  assert.ok(
    await p
      .locator("#project-dialog [data-local-trailer]")
      .evaluate((v) => !v.loop && !v.paused && v.currentTime > 0),
  );
  await p.keyboard.press("Escape");
  await p.waitForTimeout(500);
  assert.equal(
    await p.locator("#project-dialog").evaluate((d) => d.open),
    false,
  );
  await p.goForward();
  await p.waitForTimeout(400);
  assert.ok(await p.locator("#project-dialog").evaluate((d) => d.open));
  await p.keyboard.press("Escape");
  result.checks.push("V2 native trailer, modal Escape and Forward");
  await p.goto(base);
  await p.waitForTimeout(500);
  await p.locator("[data-hero-toggle]").click();
  const paused = await p
    .locator("[data-slot-video=hero]")
    .evaluate((v) => v.currentTime);
  await p.waitForTimeout(1200);
  assert.ok(
    await p
      .locator("[data-slot-video=hero]")
      .evaluate(
        (v, t) => v.paused && Math.abs(v.currentTime - t) < 0.1,
        paused,
      ),
  );
  await p.locator("[data-hero-full]").click();
  await p.screenshot({ path: out + "/desktop-full-frame.png" });
  assert.ok(
    await p.locator("[data-slot-video=hero]").evaluate((v) => v.controls),
  );
  await p.keyboard.press("Escape");
  assert.equal(await p.locator(".is-full-frame").count(), 0);
  result.checks.push("Manual pause persists; full frame uses same element");
  const reduced = await browser.newContext({
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
    reducedMotion: "reduce",
  });
  await reduced.route("**/*", (r) =>
    new URL(r.request().url()).hostname === "127.0.0.1"
      ? r.continue()
      : r.abort(),
  );
  const rp = await reduced.newPage();
  await rp.goto(base);
  await rp.waitForTimeout(1200);
  assert.ok(
    await rp
      .locator("[data-slot-video=hero]")
      .evaluate((v) => v.paused && v.currentTime === 0),
  );
  await rp.locator("[data-hero-full]").click();
  await rp.screenshot({ path: out + "/mobile-full-frame-reduced.png" });
  await rp.locator("[data-slot-video=hero]").evaluate((v) => v.play());
  await rp.waitForTimeout(400);
  assert.ok(
    await rp
      .locator("[data-slot-video=hero]")
      .evaluate((v) => v.currentTime > 0),
  );
  result.checks.push(
    "Reduced motion has no autoplay; explicit full frame available on mobile",
  );
  await reduced.close();
  assert.deepEqual(result.errors, []);
  assert.deepEqual(
    result.external.filter((u) => !u.includes("kaspersky-labs.com")),
    [],
  );
  result.status = "passed";
  console.log(JSON.stringify(result, null, 2));
} catch (error) {
  result.status = "failed";
  result.failure = String(error);
  throw error;
} finally {
  await writeFile(out + "/functional.json", JSON.stringify(result, null, 2));
  await browser.close();
}
