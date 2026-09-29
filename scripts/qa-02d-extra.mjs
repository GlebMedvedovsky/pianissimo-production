import { chromium, webkit } from "@playwright/test";
import { existsSync } from "node:fs";
import { writeFile } from "node:fs/promises";
import assert from "node:assert/strict";
const result = {
  checks: [],
  errors: [],
  environment: [],
  webkitInstalled: existsSync(webkit.executablePath()),
};
const browser = await chromium.launch({ channel: "chrome", headless: true });
try {
  const c = await browser.newContext({
    viewport: { width: 1440, height: 900 },
  });
  const requests = [];
  await c.route("**/*", (r) => {
    const u = new URL(r.request().url());
    if (u.hostname === "127.0.0.1") return r.continue();
    if (u.hostname.includes("kaspersky-labs.com")) {
      result.environment.push(u.origin);
      return r.abort();
    }
    requests.push(u.origin);
    return r.fulfill({
      status: 200,
      contentType: "text/html",
      body: "<p>Isolated provider test response</p>",
    });
  });
  const p = await c.newPage();
  p.on("pageerror", (e) => result.errors.push(e.message));
  for (const direction of ["composer", "director"]) {
    await p.goto("http://127.0.0.1:4321/");
    await p.evaluate(() =>
      scrollTo({
        top: document.querySelector(".hero-scene").offsetHeight - innerHeight,
        behavior: "instant",
      }),
    );
    await p.waitForTimeout(500);
    const link = p.locator("." + direction + "-choice");
    await link.focus();
    assert.notEqual(
      await link.locator("path").evaluate((e) => getComputedStyle(e).stroke),
      "none",
    );
    await p.keyboard.press("Enter");
    await p.waitForURL(
      direction === "composer" ? "**/film-music/" : "**/directing/",
    );
  }
  result.checks.push("Both SVG directions by keyboard, visible focus");
  await p.locator(".site-header [data-contact]").click();
  await p.locator("[name=name]").fill("Local QA fixture");
  await p.locator("[name=email]").fill("fixture@example.invalid");
  await p
    .locator("[name=message]")
    .fill("Isolated local validation, no delivery.");
  const sent = [];
  p.on("request", (r) => {
    if (r.method() === "POST") sent.push(r.url());
  });
  await p.getByRole("button", { name: "Check message", exact: true }).click();
  assert.match(
    await p.locator(".form-status").textContent(),
    /Nothing has been sent/,
  );
  assert.deepEqual(sent, []);
  await p.keyboard.press("Escape");
  result.checks.push("Form validates without request or fake delivery");
  await p.goto("http://127.0.0.1:4321/projects/leaving-earth/");
  assert.equal(await p.locator("iframe").count(), 0);
  assert.deepEqual(requests, []);
  await p.locator("article [data-activate-film]").click();
  await p.locator("[data-consent=accepted]").click();
  await p.waitForSelector("article iframe");
  assert.ok(requests.some((u) => u.includes("youtube")));
  await p.locator("article [data-consent-settings]").click();
  await p.locator("#consent-dialog [data-consent=rejected]").click();
  assert.equal(await p.locator("iframe").count(), 0);
  result.checks.push(
    "External consent blocks until action; isolated provider response; withdrawal removes iframe",
  );
  await p.goto("http://127.0.0.1:4323/film-music/");
  assert.equal(await p.locator("[data-project-card]").count(), 12);
  assert.equal(await p.locator(".music-library [data-play-track]").count(), 12);
  assert.deepEqual(
    await p
      .locator("[data-hover-video]")
      .evaluateAll((vs) =>
        vs.filter((v) => v.hasAttribute("src")).map((v) => v.src),
      ),
    [],
  );
  await p.goto("http://127.0.0.1:4323/directing/");
  assert.equal(await p.locator("[data-project-card]").count(), 3);
  await p.goto("http://127.0.0.1:4323/projects/kinnhakenhank/");
  assert.equal(await p.locator("article [data-local-trailer]").count(), 1);
  assert.doesNotMatch(
    await p.content(),
    /eyXBrA1LUsI|FINAL_KHH|Pianissimo_Fragen|admin_email|entity_address/,
  );
  assert.deepEqual(
    await p
      .locator("a[href]")
      .evaluateAll((as) =>
        as
          .filter((a) =>
            /^https?:\/\/(127\.0\.0\.1|localhost)/.test(a.getAttribute("href")),
          )
          .map((a) => a.getAttribute("href")),
      ),
    [],
  );
  const hidden = await p.request.get(
    "http://127.0.0.1:4323/content/manifest.json",
  );
  assert.equal(hidden.status(), 404);
  result.checks.push(
    "Isolated built site: 12/3 projects, 12 local tracks, trailer, no source directory serving or loopback visitor URLs",
  );
  const mobile = await browser.newContext({
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  });
  await mobile.route("**/*", (r) =>
    new URL(r.request().url()).hostname === "127.0.0.1"
      ? r.continue()
      : r.abort(),
  );
  const mp = await mobile.newPage();
  await mp.goto("http://127.0.0.1:4321/");
  await mp.getByRole("button", { name: "Necessary only", exact: true }).click();
  await mp.evaluate(() =>
    scrollTo({
      top: document.querySelector(".hero-scene").offsetHeight - innerHeight,
      behavior: "instant",
    }),
  );
  await mp.waitForTimeout(600);
  await mp.screenshot({ path: "docs/qa/02D/mobile-menu-final.png" });
  await mp.goto("http://127.0.0.1:4321/film-music/");
  assert.ok(
    await mp
      .locator("[data-hover-video]")
      .evaluateAll((vs) => vs.every((v) => !v.getAttribute("src") && v.paused)),
  );
  await mp.locator('[data-project-card][data-project="dough"]').click();
  await mp.waitForTimeout(300);
  assert.ok(await mp.locator("#project-dialog").evaluate((d) => d.open));
  result.checks.push(
    "Touch uses posters and opens project without hover playback",
  );
  await mobile.close();
  assert.deepEqual(result.errors, []);
  result.status = "passed";
  console.log(JSON.stringify(result, null, 2));
} catch (e) {
  result.status = "failed";
  result.failure = String(e);
  throw e;
} finally {
  await writeFile("docs/qa/02D/extra.json", JSON.stringify(result, null, 2));
  await browser.close();
}
