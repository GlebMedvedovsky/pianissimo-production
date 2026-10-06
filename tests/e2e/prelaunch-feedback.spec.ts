import { test, expect } from "@playwright/test";
import { ready } from "./helpers";

test("pre-launch copy, right-side Scroll control and footer spacing", async ({ page }, info) => {
  test.skip(info.project.name !== "desktop", "desktop positioning check");
  await ready(page, "/");
  const cue = await page.locator(".scroll-cue").boundingBox();
  expect(cue).not.toBeNull();
  expect(cue!.x + cue!.width / 2).toBeGreaterThan(1440 * 0.75);
  const [rail, fullscreen] = await Promise.all([
    page.locator(".social-rail").boundingBox(),
    page.locator("[data-hero-full]").boundingBox(),
  ]);
  expect(rail).not.toBeNull();
  expect(fullscreen).not.toBeNull();
  const centers = [rail!, fullscreen!, cue!].map(box => box.x + box.width / 2);
  expect(Math.max(...centers) - Math.min(...centers)).toBeLessThan(1);
  await page.locator(".scroll-cue").click();
  await expect(page).toHaveURL(/\/#main-menu$/);
  await expect.poll(() => page.evaluate(() => {
    const target = document.getElementById("main-menu")!;
    return Math.abs(scrollY - target.offsetTop);
  })).toBeLessThan(3);

  await page.goto("/film-music/");
  await expect(page.getByText("TURN YOUR WORRIES OFF AND THE MUSIC ON", { exact: true })).toBeVisible();
  await expect(page.getByText("THE PERSON BEHIND THE WORK", { exact: true })).toBeVisible();
  const footerPadding = await page.locator(".site-footer").evaluate(element => {
    const style = getComputedStyle(element);
    return [parseFloat(style.paddingTop), parseFloat(style.paddingBottom)];
  });
  expect(footerPadding[0]).toBeGreaterThanOrEqual(32);
  expect(footerPadding[1]).toBeGreaterThanOrEqual(28);
  await page.goto("/directing/");
  await expect(page.getByRole("button", { name: "Corporate Video", exact: true })).toHaveCount(0);
});

test("mobile Music Library selects have internal left spacing", async ({ page }, info) => {
  test.skip(info.project.name !== "mobile", "mobile-only spacing check");
  await ready(page, "/film-music/");
  for (const selector of ["#genre-filter", "#emotion-filter"]) {
    const padding = await page.locator(selector).evaluate(element =>
      parseFloat(getComputedStyle(element).paddingLeft),
    );
    expect(padding).toBeGreaterThanOrEqual(10);
  }
});

test("footer includes the external developer credit", async ({ page }) => {
  await ready(page, "/");
  const credit = page.getByRole("link", { name: "Web development by Gleb Medvedovskyy ↗" });
  await expect(credit).toHaveAttribute("href", "https://www.gleb-digital.de/");
  await expect(credit).toHaveAttribute("target", "_blank");
  await expect(credit).toHaveAttribute("rel", "noreferrer");
  await expect(credit.locator("xpath=ancestor::footer")).toHaveClass(/site-footer/);
});
