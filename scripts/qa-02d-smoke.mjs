import { chromium } from "@playwright/test";
import { mkdir, writeFile } from "node:fs/promises";
import assert from "node:assert/strict";
const out = "docs/qa/02D";
await mkdir(out, { recursive: true });
const browser = await chromium.launch({ channel: "chrome", headless: true });
const results = [];
try {
  for (const [name, size, mobile] of [
    ["desktop", { width: 1440, height: 900 }, false],
    ["tablet", { width: 768, height: 1024 }, true],
    ["mobile", { width: 390, height: 844 }, true],
    ["small", { width: 360, height: 780 }, true],
    ["landscape", { width: 844, height: 390 }, true],
  ]) {
    const context = await browser.newContext({
      viewport: size,
      isMobile: mobile,
      hasTouch: mobile,
    });
    const page = await context.newPage();
    const errors = [],
      external = [];
    await context.route("**/*", (route) => {
      const url = new URL(route.request().url());
      if (!["127.0.0.1", "localhost"].includes(url.hostname)) {
        external.push(url.origin);
        return route.abort();
      }
      return route.continue();
    });
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto("http://127.0.0.1:4321/");
    await page.waitForTimeout(1400);
    const hero = await page
      .locator("[data-slot-video=hero]")
      .evaluate((v) => ({
        time: v.currentTime,
        paused: v.paused,
        muted: v.muted,
        loop: v.loop,
        width: v.videoWidth,
        error: v.error?.message,
      }));
    assert.ok(hero.time > 0, JSON.stringify(hero));
    assert.ok(hero.muted && hero.loop);
    await page.screenshot({ path: `${out}/${name}-hero.png` });
    await page.evaluate(() =>
      scrollTo({
        top: document.querySelector(".hero-scene").offsetHeight - innerHeight,
        behavior: "instant",
      }),
    );
    await page.waitForTimeout(700);
    await page.screenshot({ path: `${out}/${name}-menu.png` });
    for (const [path, label, count] of [
      ["/film-music/", "music", 12],
      ["/directing/", "directing", 3],
    ]) {
      await page.goto("http://127.0.0.1:4321" + path);
      await page.waitForTimeout(700);
      assert.equal(await page.locator("[data-project-card]").count(), count);
      await page.screenshot({ path: `${out}/${name}-${label}.png` });
      assert.ok(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
      );
    }
    await page.goto("http://127.0.0.1:4321/projects/kinnhakenhank/");
    await page.waitForTimeout(500);
    assert.equal(await page.locator("article [data-local-trailer]").count(), 1);
    assert.equal(await page.locator("iframe").count(), 0);
    await page.screenshot({ path: `${out}/${name}-project.png` });
    await page.goto("http://127.0.0.1:4321/#about");
    await page.waitForTimeout(700);
    await page.screenshot({ path: `${out}/${name}-about.png` });
    const broken = await page
      .locator("img")
      .evaluateAll((ims) =>
        ims
          .filter(
            (i) =>
              i.getAttribute("src") &&
              i.loading !== "lazy" &&
              (!i.complete || i.naturalWidth === 0),
          )
          .map((i) => i.src),
      );
    assert.deepEqual(broken, []);
    assert.deepEqual(errors, []);
    assert.deepEqual(
      external.filter((u) => !u.includes("kaspersky-labs.com")),
      [],
    );
    results.push({ name, hero, errors, external, status: "passed" });
    console.log(name, "passed");
    await context.close();
  }
} finally {
  await writeFile(`${out}/smoke.json`, JSON.stringify(results, null, 2));
  await browser.close();
}
