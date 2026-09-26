import { test, expect, type Page } from "@playwright/test";

async function ready(page: Page) {
  await page.goto("/", { waitUntil: "domcontentloaded" });
  await page.getByRole("button", { name: "Necessary only", exact: true }).click();
  await page.evaluate(() => document.fonts.ready);
  await expect(page.locator(".hero-scene")).toHaveAttribute(
    "data-phase",
    "hero",
  );
}

async function progress(page: Page, value: number) {
  await page.evaluate((p) => {
    const scene = document.querySelector<HTMLElement>(".hero-scene")!;
    const travel =
      scene.offsetHeight -
      scene.querySelector<HTMLElement>(".scene-sticky")!.offsetHeight;
    const position = scene.offsetTop + (travel * (1.15 + 1.1 * p)) / 2.25;
    scrollTo({
      top: p === 0 ? Math.floor(position) : Math.ceil(position),
      behavior: "instant",
    });
  }, value);
  await page.waitForTimeout(120);
}

async function layout(page: Page) {
  return page.locator("#about").evaluate((about) => {
    const rect = about.getBoundingClientRect();
    return [".about-portrait", "h2", ".cv"].map((selector) => {
      const element = about.querySelector(selector)!;
      const box = element.getBoundingClientRect();
      return {
        x: box.x - rect.x,
        y: box.y - rect.y,
        width: box.width,
        height: box.height,
        transform: getComputedStyle(element).transform,
      };
    });
  });
}

// Hit testing exercises the browser's actual CSS/SVG clip, including the outer
// contour. Temporarily enabling input lets us inspect it; restore inert at once.
async function checkAperture(page: Page, complete = false) {
  return page.evaluate((full) => {
    const about = document.querySelector<HTMLElement>("#about")!;
    const path = document.querySelector<SVGPathElement>(
      "#scene-about-aperture path",
    )!;
    const matrix = path.transform.baseVal.consolidate()!.matrix.inverse();
    const box = about.getBoundingClientRect();
    const wasInert = about.inert;
    about.inert = false;
    const failures: Array<{
      x: number;
      y: number;
      inside: boolean;
      hit: string | undefined;
    }> = [];
    let insideCount = 0,
      outsideCount = 0;
    for (let y = 3; y < innerHeight; y += 29)
      for (let x = 3; x < innerWidth; x += 29) {
        const inside = path.isPointInFill(
          new DOMPoint(x - box.x, y - box.y).matrixTransform(matrix),
        );
        const hit = document.elementFromPoint(x, y);
        if (hit?.closest(".social-rail,.site-header,.player")) continue;
        if (inside) insideCount++;
        else outsideCount++;
        if ((full || inside) !== about.contains(hit))
          failures.push({ x, y, inside, hit: hit?.tagName });
      }
    const corners = [
      [0, 0],
      [innerWidth, 0],
      [0, innerHeight],
      [innerWidth, innerHeight],
    ].map(([x, y]) =>
      path.isPointInFill(
        new DOMPoint(x - box.x, y - box.y).matrixTransform(matrix),
      ),
    );
    about.inert = wasInert;
    return { failures, insideCount, outsideCount, corners };
  }, complete);
}

test("curved reveal, stationary frames, reverse scroll and natural About handoff", async ({
  page,
}, info) => {
  test.setTimeout(90_000);
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(String(error)));
  await ready(page);
  await expect(page.locator("#about")).toHaveCount(1);
  await expect(page.locator("#about")).toBeHidden();
  let revealedLayout: Awaited<ReturnType<typeof layout>> | undefined;
  let previousOpacity = 0;
  const frames =
    info.project.name === "desktop" ? [0, 0.25, 0.5, 0.75, 1] : [0, 0.5, 1];
  for (const value of frames) {
    await progress(page, value);
    const about = page.locator("#about");
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    ).toBe(true);
    const opacity = Number(
      await about.evaluate((element) => getComputedStyle(element).opacity),
    );
    expect(opacity).toBeGreaterThanOrEqual(previousOpacity);
    previousOpacity = opacity;
    if (value === 0) {
      await expect(about).toBeHidden();
      expect(
        await about.evaluate((element) => (element as HTMLElement).inert),
      ).toBe(true);
    } else {
      const coverage = await checkAperture(page, value === 1);
      expect(coverage.failures).toEqual([]);
      expect(coverage.insideCount).toBeGreaterThan(0);
      if (value === 1) {
        expect(opacity).toBeCloseTo(1, 2);
        revealedLayout = await layout(page);
        expect(coverage.corners).toEqual([true, true, true, true]);
        expect(
          await about.evaluate((element) => (element as HTMLElement).inert),
        ).toBe(false);
        await expect(page.locator(".scene-visuals")).toBeHidden();
      } else {
        expect(coverage.outsideCount).toBeGreaterThan(0);
        expect(
          await about.evaluate((element) => (element as HTMLElement).inert),
        ).toBe(true);
        const before = await page
          .locator("#scene-about-aperture path")
          .getAttribute("transform");
        const still = await page.screenshot();
        await page.waitForTimeout(2100);
        expect(
          await page
            .locator("#scene-about-aperture path")
            .getAttribute("transform"),
        ).toBe(before);
        expect(await page.screenshot()).toEqual(still);
        // Programmatic focus must also be refused while content is partially hidden.
        await page
          .locator("#about [data-contact]")
          .evaluate((element) => (element as HTMLElement).focus());
        expect(
          await page.evaluate(
            () => !!document.activeElement?.closest("#about"),
          ),
        ).toBe(false);
      }
    }
    await page.screenshot({
      path: info.outputPath(`reveal-${value * 100}.png`),
    });
  }
  // Capture closely spaced frames during forward AND reverse motion.
  for (const direction of ["reverse", "forward"]) {
    for (let step = 0; step <= 10; step++) {
      await progress(page, direction === "reverse" ? 1 - step / 10 : step / 10);
      await page.screenshot({
        path: info.outputPath(`${direction}-${step}.png`),
      });
    }
  }
  await progress(page, 0.5);
  expect(Number(await page.locator('#about').evaluate(element =>
    getComputedStyle(element).getPropertyValue('--about-reveal-progress'),
  ))).toBeCloseTo(.5, 1);
  expect(await page.locator("#scene-about-aperture path").getAttribute("transform")).toBeTruthy();
  await progress(page, 0);
  await expect(page.locator("#about")).toBeHidden();
  await expect(
    page.getByRole("link", { name: "Explore Film Music" }),
  ).toBeVisible();
  await progress(page, 1);
  await page.evaluate(() => scrollBy({ top: 25, behavior: "instant" }));
  await page.waitForTimeout(120);
  const scrolledLayout = await layout(page);
  // The existing desktop portrait sticks during ordinary About scrolling.
  // Its size is unchanged; copy moves with the section, without a handoff jump.
  expect(scrolledLayout.map(({ y, ...box }) => box)).toEqual(
    revealedLayout!.map(({ y, ...box }) => box),
  );
  expect(scrolledLayout.slice(1)).toEqual(revealedLayout!.slice(1));
  const gap = await page.evaluate(
    () =>
      document.querySelector(".site-footer")!.getBoundingClientRect().top -
      document.querySelector("#about")!.getBoundingClientRect().bottom,
  );
  expect(Math.abs(gap)).toBeLessThan(2);
  await page.locator(".site-footer").scrollIntoViewIfNeeded();
  await expect(page.locator(".site-footer")).toBeInViewport();
  await page.screenshot({ path: info.outputPath("footer.png") });
  expect(errors).toEqual([]);
});

test("hero branding reveals with zoom progress and reverses without a flash", async ({ page }, info) => {
  await ready(page);
  const heading = page.locator('.hero-heading');
  await expect(heading).toBeHidden();
  const setTimelineTime = (value: number) => page.evaluate((time) => {
    const scene = document.querySelector<HTMLElement>('.hero-scene')!;
    const sticky = scene.querySelector<HTMLElement>('.scene-sticky')!;
    const travel = scene.offsetHeight - sticky.offsetHeight;
    scrollTo({ top: scene.offsetTop + travel * (time / 2.25), behavior: 'instant' });
  }, value);
  await setTimelineTime(.42);
  await page.waitForTimeout(120);
  await expect(heading).toBeHidden();
  await setTimelineTime(.70);
  await page.waitForTimeout(120);
  expect(Number(await heading.evaluate(element => getComputedStyle(element).opacity))).toBeGreaterThan(.35);
  const backdrops = await page.evaluate(() => ({
    showreel: Number(getComputedStyle(document.querySelector('.hero-backdrop')!).opacity),
    split: Number(getComputedStyle(document.querySelector('.split-backdrop')!).opacity),
  }));
  expect(backdrops.split).toBeGreaterThan(.7);
  expect(backdrops.split).toBeGreaterThan(backdrops.showreel);
  await page.screenshot({ path: info.outputPath('hero-brand-reveal.png') });
  await setTimelineTime(.42);
  await page.waitForTimeout(120);
  await expect(heading).toBeHidden();
});

test("split-logo text reveals only after the split photos dominate and reverses with scroll", async ({ page }, info) => {
  await ready(page);
  const labels = page.locator('.scene-logo .logo-label');
  const setTimelineTime = (value: number) => page.evaluate((time) => {
    const scene = document.querySelector<HTMLElement>('.hero-scene')!;
    const sticky = scene.querySelector<HTMLElement>('.scene-sticky')!;
    const travel = scene.offsetHeight - sticky.offsetHeight;
    scrollTo({ top: scene.offsetTop + travel * (time / 2.25), behavior: 'instant' });
  }, value);
  const opacity = () => labels.evaluateAll(elements => elements.map(element => Number(getComputedStyle(element).opacity)));
  const capture = async (name: string) => page.screenshot({ path: info.outputPath(`split-logo-text-${name}.png`) });

  await expect(page.locator('.scene-logo .logo-left')).toBeVisible();
  expect(await opacity()).toEqual([0, 0]);
  await capture('earliest');
  await setTimelineTime(.50);await page.waitForTimeout(120);
  expect(await opacity()).toEqual([0, 0]);
  await capture('mid');
  await setTimelineTime(.70);await page.waitForTimeout(120);
  const emerging = await opacity();
  emerging.forEach(value => { expect(value).toBeGreaterThan(.2);expect(value).toBeLessThan(.9); });
  const backdrops = await page.evaluate(() => ({
    hero: Number(getComputedStyle(document.querySelector('.hero-backdrop')!).opacity),
    split: Number(getComputedStyle(document.querySelector('.split-backdrop')!).opacity),
  }));
  expect(backdrops.split).toBeGreaterThan(.8);
  expect(backdrops.split).toBeGreaterThan(backdrops.hero);
  await capture('emerging');
  await setTimelineTime(.92);await page.waitForTimeout(120);
  (await opacity()).forEach(value => expect(value).toBeGreaterThan(.97));
  await capture('full');
  await setTimelineTime(.50);await page.waitForTimeout(120);
  (await opacity()).forEach(value => expect(value).toBeLessThan(.05));
});

test("reduced motion exposes the same About and footer without pinning", async ({
  page,
}, info) => {
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.goto("/", { waitUntil: "domcontentloaded" });
  await page.getByRole("button", { name: "Necessary only", exact: true }).click();
  await expect(page.locator("#about")).toHaveCount(1);
  expect(
    await page
      .locator(".scene-sticky")
      .evaluate((element) => getComputedStyle(element).position),
  ).toBe("relative");
  expect(
    await page
      .locator(".hero-scene")
      .evaluate((element) => getComputedStyle(element, "::after").content),
  ).toBe("none");
  await page.locator(".menu-about").click();
  await expect(page.locator("#about")).toBeInViewport();
  expect(
    await page
      .locator("#about")
      .evaluate((element) => ({
        inert: (element as HTMLElement).inert,
        clip: getComputedStyle(element).clipPath,
      })),
  ).toEqual({ inert: false, clip: "none" });
  await page.locator("#about [data-contact]").focus();
  await expect(page.locator("#about [data-contact]")).toBeFocused();
  await page.screenshot({ path: info.outputPath("reduced-about.png") });
  await page.locator(".site-footer").scrollIntoViewIfNeeded();
  await expect(page.locator(".site-footer")).toBeInViewport();
});

test("header outline follows Film Music and Directing themes on client navigation", async ({
  page,
}, info) => {
  await page.goto("/film-music/", { waitUntil: "domcontentloaded" });
  await page.getByRole("button", { name: "Necessary only", exact: true }).click();
  for (const section of ["music", "directing", "music"]) {
    if (section === "directing")
      await page.locator('header a[href="/directing/"]').click();
    else if (
      (await page.locator("html").getAttribute("data-section")) !== "music"
    )
      await page.locator('header a[href="/film-music/"]').click();
    const theme = section === "music" ? "dark" : "light";
    await expect(page.locator("html")).toHaveAttribute("data-theme", theme);
    const styles = await page.locator(".brand .piano-mark").evaluate((mark) =>
      [".logo-left", ".logo-right"].map((selector) => {
        const style = getComputedStyle(mark.querySelector(selector)!);
        return {
          fill: style.fill,
          stroke: style.stroke,
          width: style.strokeWidth,
          vector: style.vectorEffect,
        };
      }),
    );
    expect(styles.map((style) => style.fill)).toEqual([
      "rgb(20, 20, 20)",
      "rgb(243, 240, 234)",
    ]);
    expect(styles[section === "music" ? 0 : 1]).toMatchObject({
      stroke: section === "music" ? "rgb(181, 176, 167)" : "rgb(99, 94, 85)",
      width: "1px",
      vector: "non-scaling-stroke",
    });
    expect(styles[section === "music" ? 1 : 0].stroke).toBe("none");
    await page
      .locator(".site-header")
      .screenshot({ path: info.outputPath(`header-${theme}.png`) });
  }
});
