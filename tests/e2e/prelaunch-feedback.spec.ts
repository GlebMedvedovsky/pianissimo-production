import { test, expect } from "@playwright/test";
import { ready } from "./helpers";

test("pre-launch copy, right-side Scroll control and footer spacing", async ({ page }, info) => {
  test.skip(info.project.name !== "desktop", "desktop positioning check");
  await ready(page, "/");
  const cue = await page.locator(".scroll-cue").boundingBox();
  expect(cue).not.toBeNull();
  expect(cue!.x + cue!.width / 2).toBeGreaterThan(1440 * 0.75);
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
