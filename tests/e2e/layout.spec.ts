import { test, expect } from "@playwright/test";
import { ready, shot, noOverflow } from "./helpers";
test("compact player transport icons are exact mirrored peers", async ({ page }, info) => {
  test.skip(!["desktop", "mobile"].includes(info.project.name), "desktop/mobile player control check");
  await ready(page, "/film-music/");
  await page.locator("[data-play-track]").first().click();
  await expect(page.locator(".player")).toBeVisible();
  if (info.project.name === "mobile") await page.getByRole("button", { name: "More player controls" }).click();
  const controls = info.project.name === "mobile" ? ".player-secondary" : ".player-buttons";
  const previous = page.locator(`${controls} [data-player="previous"]`);
  const next = page.locator(`${controls} [data-player="next"]`);
  await expect(previous).toHaveAttribute("aria-label", "Previous track");
  await expect(next).toHaveAttribute("aria-label", "Next track");
  await expect(previous.locator("path")).toHaveAttribute("d", "M5 5v14m13-14L7 12l11 7Z");
  await expect(next.locator("path")).toHaveAttribute("d", "M19 5v14M6 5L17 12 6 19Z");
  for (const button of [previous, next]) await expect(button.locator("svg")).toHaveAttribute("stroke", "currentColor");
  const [previousBox, nextBox, previousSvg, nextSvg] = await Promise.all([previous.boundingBox(), next.boundingBox(), previous.locator("svg").boundingBox(), next.locator("svg").boundingBox()]);
  expect(previousBox).not.toBeNull();expect(nextBox).not.toBeNull();expect(previousSvg).not.toBeNull();expect(nextSvg).not.toBeNull();
  expect(nextBox!.width).toBe(previousBox!.width);expect(nextBox!.height).toBe(previousBox!.height);
  expect(nextSvg!.width).toBe(previousSvg!.width);expect(nextSvg!.height).toBe(previousSvg!.height);
});
test("mobile portfolio header stays pinned without covering content", async ({ page }, info) => {
  test.skip(info.project.name === "desktop", "mobile-only layout assertion");
  test.setTimeout(90_000);
  if (info.project.name === "narrow") await page.setViewportSize({ width: 320, height: 568 });
  await ready(page, "/film-music/");
  const header = page.locator(".site-header--portfolio");
  await expect(header).toHaveCSS("position", "fixed");
  const initial = await page.evaluate(() => {
    const header = document.querySelector<HTMLElement>(".site-header")!;
    const intro = document.querySelector<HTMLElement>(".portfolio-intro")!;
    return { header: header.getBoundingClientRect(), intro: intro.getBoundingClientRect() };
  });
  expect(initial.intro.top).toBeGreaterThanOrEqual(initial.header.bottom);
  await page.evaluate(() => scrollTo({ top: 1400, behavior: "instant" }));
  await expect.poll(() => header.evaluate((element) => element.getBoundingClientRect().top)).toBeCloseTo(0, 0);
  await shot(page, "sticky-music-scroll", info);
  await noOverflow(page);
  const navigationFits = await page.locator(".site-header nav a, .site-header nav button").evaluateAll((items) =>
    items.every((item) => {
      const box = item.getBoundingClientRect();
      return box.left >= 0 && box.right <= innerWidth;
    }),
  );
  expect(navigationFits).toBe(true);
  await page.locator('header a[href="/directing/"]').click();
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect(header).toHaveCSS("view-transition-name", "portfolio-header");
  await expect
    .poll(() => header.evaluate((element) => element.getBoundingClientRect().top))
    .toBeCloseTo(0, 0);
  await expect(page.locator("html")).not.toHaveAttribute("data-portfolio-transition");
  await expect(page.locator("header [aria-current=page]")).toHaveText("Directing");
  await expect(header).toHaveCSS("position", "fixed");
  await page.evaluate(() => scrollTo({ top: 1400, behavior: "instant" }));
  await expect.poll(() => header.evaluate((element) => element.getBoundingClientRect().top)).toBeCloseTo(0, 0);
  await shot(page, "sticky-directing-scroll", info);
  await page.locator('header a[href="/film-music/"]').click();
  await expect(page).toHaveURL(/\/film-music\/$/);
  await expect(page.locator("html")).not.toHaveAttribute("data-portfolio-transition");
  await expect(page.locator("header [aria-current=page]")).toHaveText("Film Music");
  await expect(header).toHaveCSS("position", "fixed");
  await page.locator('header a[href="/directing/"]').click();
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect(page.locator("html")).not.toHaveAttribute("data-portfolio-transition");
  await page.locator("[data-project-card]").first().click();
  await expect(page.locator("#project-dialog")).toBeVisible();
  expect(await page.evaluate(() => document.activeElement?.closest("#project-dialog") !== null)).toBe(true);
  expect(
    await page.evaluate(() => {
      const header = document.querySelector<HTMLElement>(".site-header")!;
      const dialog = document.querySelector<HTMLDialogElement>("#project-dialog")!;
      const point = header.getBoundingClientRect();
      return document.elementFromPoint(point.left + 8, point.top + 8)?.closest("#project-dialog") === dialog;
    }),
  ).toBe(true);
  await page.keyboard.press("Escape");
  await expect(page.locator("#project-dialog")).not.toBeVisible();
  await page.locator("header [data-brand-home]").click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.locator(".site-header--portfolio")).toHaveCount(0);
});

test("desktop portfolio header keeps its original positioning", async ({ page }, info) => {
  test.skip(info.project.name !== "desktop", "desktop-only layout assertion");
  await ready(page, "/film-music/");
  await expect(page.locator(".site-header--portfolio")).toHaveCSS("position", "absolute");
});
test("hero, reversible logo and viewport layouts", async ({ page }, info) => {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(String(e)));
  await ready(page, "/");
  await shot(page, "hero-small", info);
  await noOverflow(page);
  const initial = await page.locator(".scene-logo").boundingBox();
  await page.evaluate(() =>
    scrollTo({ top: innerHeight * 0.63, behavior: "instant" }),
  );
  await page.waitForTimeout(500);
  await shot(page, "logo-middle", info);
  await page.evaluate(() =>
    scrollTo({ top: innerHeight * 1.25, behavior: "instant" }),
  );
  await page.waitForTimeout(500);
  await shot(page, "logo-large", info);
  const final = await page.locator(".scene-logo").boundingBox();
  expect(final!.width).toBeGreaterThan(initial!.width * 4);
  await expect(
    page.getByRole("link", { name: "Explore Film Music" }),
  ).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Explore Directing" }),
  ).toBeVisible();
  await noOverflow(page);
  await page.evaluate(() => scrollTo({ top: 0, behavior: "instant" }));
  await page.waitForTimeout(500);
  expect((await page.locator(".scene-logo").boundingBox())!.width).toBeLessThan(
    initial!.width * 1.2,
  );
  expect(errors).toEqual([]);
});
test("desktop hero reserves a lower safe frame when the shared player is visible", async ({
  page,
}, info) => {
  test.skip(info.project.name !== "desktop", "desktop-only player framing");
  await ready(page, "/");
  await page.getByRole("button", { name: "Enable sound", exact: true }).click();
  await expect(page.locator(".player")).toBeVisible();
  const layout = await page.evaluate(() => {
    const video = document.querySelector<HTMLVideoElement>(
      "[data-slot-video=hero]",
    )!;
    const player = document.querySelector<HTMLElement>(".player")!;
    const videoBox = video.getBoundingClientRect(),
      playerBox = player.getBoundingClientRect(),
      style = getComputedStyle(video);
    return {
      videoBottom: videoBox.bottom,
      playerTop: playerBox.top,
      fit: style.objectFit,
      position: style.objectPosition,
    };
  });
  expect(layout.videoBottom).toBeLessThanOrEqual(layout.playerTop);
  expect(layout.fit).toBe("contain");
  expect(layout.position).toBe("50% 100%");
  await page
    .getByRole("button", { name: "View full frame", exact: true })
    .click();
  await expect(page.locator(".hero-scene")).toHaveClass(/is-full-frame/);
  await expect(page.locator("[data-slot-video=hero]")).toHaveCSS(
    "object-fit",
    "contain",
  );
  await page.keyboard.press("Escape");
  await expect(page.locator(".hero-scene")).not.toHaveClass(/is-full-frame/);
});
test("grids, About and contact fit the viewport", async ({ page }, info) => {
  await ready(page, "/film-music/");
  await shot(page, "music", info);
  await noOverflow(page);
  const music = page.locator("[data-project-card]").first();
  const cover = await music.locator(".card-image").boundingBox();
  expect(Math.abs(cover!.width - cover!.height)).toBeLessThan(2);
  await page.goto("/directing/");
  await shot(page, "directing", info);
  await noOverflow(page);
  const film = await page.locator(".card-image").first().boundingBox();
  expect(film!.width / film!.height).toBeCloseTo(16 / 9, 1);
  await page.goto("/#about");
  await expect(page.locator("#about")).toBeInViewport();
  await shot(page, "about-dark", info);
  await noOverflow(page);
  await page.locator("#about [data-contact]").click();
  await expect(page.locator("#contact-dialog")).toBeVisible();
  await shot(page, "contact", info);
  await page.keyboard.press("Escape");
  await expect(page.locator("#contact-dialog")).not.toBeVisible();
});
test("reduced motion removes artificial travel and keeps the real About accessible", async ({
  page,
}, info) => {
  await page.emulateMedia({ reducedMotion: "reduce" });
  await ready(page, "/");
  const layout = await page.evaluate(() => {
    const scene = document.querySelector<HTMLElement>(".hero-scene")!;
    const visuals = document.querySelector<HTMLElement>(".scene-visuals")!;
    const about = document.querySelector<HTMLElement>("#about")!;
    const after = getComputedStyle(scene, "::after");
    return {
      sceneHeight: scene.getBoundingClientRect().height,
      naturalContentHeight:
        visuals.getBoundingClientRect().height + about.getBoundingClientRect().height,
      artificialTravel: after.content !== "none" && parseFloat(after.height) > 1,
      stickyPosition: getComputedStyle(
        document.querySelector<HTMLElement>(".scene-sticky")!,
      ).position,
      aboutVisibility: getComputedStyle(about).visibility,
    };
  });
  expect(layout.artificialTravel).toBe(false);
  expect(layout.sceneHeight).toBeLessThanOrEqual(layout.naturalContentHeight + 5);
  expect(layout.stickyPosition).toBe("relative");
  expect(layout.aboutVisibility).toBe("visible");
  await expect(
    page.getByRole("link", { name: "Explore Directing" }),
  ).toBeVisible();
  await expect(page.locator("#about")).toBeVisible();
  await expect(
    page.locator("#about").getByRole("button", { name: "Get in touch" }),
  ).toBeVisible();
  await shot(page, "reduced-motion", info);
  await page.getByRole("link", { name: "Explore Directing" }).click();
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
});
