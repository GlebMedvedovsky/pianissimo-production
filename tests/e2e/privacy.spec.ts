import { test, expect } from "@playwright/test";
import { ready } from "./helpers";
test("contact validates locally; media consent gates and unloads embeds", async ({
  page,
}) => {
  const external: string[] = [];
  await page.route("**/*", (route) => {
    if (
      /youtube|vimeo|spotify|instagram/.test(
        new URL(route.request().url()).hostname,
      )
    ) {
      external.push(route.request().url());
      return route.fulfill({
        status: 200,
        contentType: "text/html",
        body: "External player stub for consent test",
      });
    }
    return route.continue();
  });
  await ready(page, "/directing/");
  expect(external).toEqual([]);
  await page.locator("header [data-contact]").click();
  await page.locator("#contact-form button[type=submit]").click();
  await expect(page.locator("#contact-form .form-status")).toBeEmpty();
  await page.getByLabel("Your name", { exact: true }).fill("Local QA");
  await page
    .getByLabel("Email address", { exact: true })
    .fill("qa@example.invalid");
  await page
    .getByLabel("Your message", { exact: true })
    .fill("A local validation test. Do not send.");
  await page.getByRole("button", { name: "Check message" }).click();
  await expect(page.locator(".form-status")).toContainText(
    "Nothing has been sent",
  );
  await page.keyboard.press("Escape");
  await page.locator("[data-project-card]").first().click();
  await page.locator("#project-dialog [data-activate-film]").click();
  await expect(page.locator("#consent-dialog")).toBeVisible();
  expect(external).toEqual([]);
  await page
    .getByRole("button", { name: "Allow external media", exact: true })
    .click();
  await expect(page.locator("iframe")).toHaveCount(1);
  await expect.poll(() => external.length).toBeGreaterThan(0);
  await page.locator("#project-dialog [data-consent-settings]").click();
  await page.getByRole("button", { name: "Reject / withdraw" }).click();
  await expect(page.locator("iframe")).toHaveCount(0);
});
test("storage denied remains usable and no JavaScript still gives real routes", async ({
  browser,
  baseURL,
}) => {
  const context = await browser.newContext();
  await context.addInitScript(() => {
    Object.defineProperty(window, "localStorage", {
      get() {
        throw new Error("Storage denied for QA");
      },
    });
  });
  const page = await context.newPage();
  await page.goto(baseURL! + "/");
  await expect(page.getByRole("button", { name: "Keep off" })).toBeVisible();
  await page.getByRole("button", { name: "Keep off" }).click();
  await page.locator('header a[href="/film-music/"]').click();
  await expect(page.locator("#portfolio-title")).toHaveText("Film Music.");
  await context.close();
  const nojs = await browser.newContext({ javaScriptEnabled: false });
  const fallback = await nojs.newPage();
  await fallback.goto(baseURL! + "/");
  await fallback.getByRole("link", { name: "Explore Film Music" }).click();
  await expect(fallback.locator("[data-project-card]")).toHaveCount(7);
  await fallback.locator("[data-project-card]").first().click();
  await expect(fallback.locator(".direct-project")).toBeVisible();
  await nojs.close();
});
