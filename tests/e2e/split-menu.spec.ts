import { expect, test, type Page } from "@playwright/test";
import { ready } from "./helpers";

const composerImage = ".music-half [data-slot=composerMenu] img";
const directingImage = ".directing-half [data-slot=directingMenu] img";

async function showSplitMenu(page: Page) {
  await ready(page, "/");
  await page.evaluate(() =>
    scrollTo({ top: innerHeight * 0.69, behavior: "instant" }),
  );
  await expect(page.locator(".hero-scene")).toHaveAttribute("data-phase", "menu");
  await expect(page.locator(".split-backdrop")).toHaveCSS("opacity", "1");
  await expect(
    page.getByRole("link", { name: "Explore Film Music" }),
  ).toBeVisible();
}

async function capture(page: Page, name: string) {
  await page.waitForTimeout(450);
  await page.screenshot({ path: `docs/qa/split-menu-${name}.png` });
}

test("desktop split menu previews only the active image in colour", async ({
  page,
}, info) => {
  test.skip(info.project.name !== "desktop", "desktop fine-pointer coverage");
  test.setTimeout(90_000);
  await showSplitMenu(page);

  const composer = page.locator(composerImage);
  const directing = page.locator(directingImage);
  const composerLink = page.getByRole("link", { name: "Explore Film Music" });
  const directingLink = page.getByRole("link", { name: "Explore Directing" });

  await expect(composer).toHaveCSS("filter", "grayscale(1)");
  await expect(directing).toHaveCSS("filter", "grayscale(1)");
  await capture(page, "desktop-rest");

  await composerLink.hover();
  await expect(composer).toHaveCSS("filter", "grayscale(0)");
  await expect(directing).toHaveCSS("filter", "grayscale(1)");
  await capture(page, "desktop-film-music-hover");

  await directingLink.hover();
  await expect(composer).toHaveCSS("filter", "grayscale(1)");
  await expect(directing).toHaveCSS("filter", "grayscale(0)");
  await capture(page, "desktop-directing-hover");

  await page.locator(".menu-about").hover();
  await expect(composer).toHaveCSS("filter", "grayscale(1)");
  await expect(directing).toHaveCSS("filter", "grayscale(1)");

  await directingLink.focus();
  await page.keyboard.press("Shift+Tab");
  await expect(composerLink).toBeFocused();
  expect(await composerLink.evaluate((link) => link.matches(":focus-visible"))).toBe(
    true,
  );
  await expect(composer).toHaveCSS("filter", "grayscale(0)");
  await expect(directing).toHaveCSS("filter", "grayscale(1)");
});

test("mobile split menu stays in colour and a tap navigates immediately", async ({
  page,
}, info) => {
  test.skip(info.project.name !== "mobile", "mobile touch coverage");
  test.setTimeout(90_000);
  await showSplitMenu(page);

  await expect(page.locator(composerImage)).toHaveCSS("filter", "none");
  await expect(page.locator(directingImage)).toHaveCSS("filter", "none");
  await capture(page, "mobile");

  await page
    .getByRole("link", { name: "Explore Film Music" })
    .tap({ noWaitAfter: true });
  await expect(page).toHaveURL(/\/film-music\/$/);
});

test("reduced motion removes the split-menu colour transition", async ({
  page,
}, info) => {
  test.skip(info.project.name !== "desktop", "desktop fine-pointer coverage");
  await page.emulateMedia({ reducedMotion: "reduce" });
  await ready(page, "/");

  const composer = page.locator(composerImage);
  const directing = page.locator(directingImage);
  await expect(composer).toHaveCSS("filter", "grayscale(1)");
  await expect(directing).toHaveCSS("filter", "grayscale(1)");
  const transitionMs = await composer.evaluate((image) => {
    const duration = getComputedStyle(image).transitionDuration;
    return parseFloat(duration) * (duration.endsWith("ms") ? 1 : 1_000);
  });
  expect(transitionMs).toBeLessThanOrEqual(0.001);

  await page.getByRole("link", { name: "Explore Film Music" }).hover();
  await expect(composer).toHaveCSS("filter", "grayscale(0)");
  await expect(directing).toHaveCSS("filter", "grayscale(1)");
});

test("split-logo labels scale continuously with both logo halves", async ({
  page,
}, info) => {
  test.skip(info.project.name !== "desktop", "desktop scale coverage");
  test.setTimeout(180_000);
  await ready(page, "/");

  const travel = await page.locator(".hero-scene").evaluate((scene) => {
    const sticky = scene.querySelector<HTMLElement>(".scene-sticky")!;
    return scene.clientHeight - sticky.clientHeight;
  });
  const checkpoints = [0, 0.25, 0.5, 0.75, 1];
  const widths: number[] = [];
  const ratios: number[][] = [];

  const readState = () =>
    page.evaluate(() => {
      const bounds = (selector: string) => {
        const rect = document.querySelector<SVGGraphicsElement>(selector)!
          .getBoundingClientRect();
        return {
          left: rect.left,
          top: rect.top,
          right: rect.right,
          bottom: rect.bottom,
          width: rect.width,
        };
      };
      const logo = document
        .querySelector<HTMLElement>(".scene-logo")!
        .getBoundingClientRect();
      return {
        logoWidth: logo.width,
        halves: [".composer-choice", ".director-choice"].map((choice) => ({
          path: bounds(`${choice} path`),
          label: bounds(`${choice} .logo-label`),
          opacity: getComputedStyle(
            document.querySelector<SVGGraphicsElement>(`${choice} .logo-label`)!,
          ).opacity,
        })),
      };
    });

  for (const [index, progress] of checkpoints.entries()) {
    await page.evaluate(
      ({ scrollTop }) => scrollTo({ top: scrollTop, behavior: "instant" }),
      { scrollTop: travel * (progress / 2.25) },
    );
    await page.waitForTimeout(200);
    const state = await readState();
    widths.push(state.logoWidth);
    ratios.push(state.halves.map(({ label }) => label.width / state.logoWidth));

    for (const { path, label, opacity } of state.halves) {
      expect(opacity).toBe("1");
      expect(label.left).toBeGreaterThanOrEqual(path.left - 1);
      expect(label.top).toBeGreaterThanOrEqual(path.top - 1);
      expect(label.right).toBeLessThanOrEqual(path.right + 1);
      expect(label.bottom).toBeLessThanOrEqual(path.bottom + 1);
    }

    await page.screenshot({
      path: `docs/qa/split-logo-scale-${index * 25}.png`,
    });
  }

  for (let index = 1; index < widths.length; index += 1) {
    expect(widths[index]).toBeGreaterThan(widths[index - 1]);
    for (let half = 0; half < 2; half += 1) {
      expect(ratios[index][half]).toBeCloseTo(ratios[0][half], 3);
    }
  }

  for (const progress of checkpoints.slice(0, -1).reverse()) {
    await page.evaluate(
      ({ scrollTop }) => scrollTo({ top: scrollTop, behavior: "instant" }),
      { scrollTop: travel * (progress / 2.25) },
    );
    await page.waitForTimeout(100);
    const state = await readState();
    for (const { opacity } of state.halves) expect(opacity).toBe("1");
  }
});
