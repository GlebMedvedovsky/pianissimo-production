import { test, expect } from "@playwright/test";
import { ready } from "./helpers";
test("contact validates locally; media consent gates and unloads embeds", async ({
  page,
}) => {
  await page.addInitScript(() => {if(!sessionStorage.getItem('qa-consent-start')){localStorage.clear();sessionStorage.setItem('qa-consent-start','1')}});
  const external: string[] = [];
  await page.route("**/*", (route) => {
    if (
      /youtube|vimeo|spotify|instagram|googletagmanager|plausible/.test(
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
  await page.goto("/directing/");
  await page.locator("html.js").waitFor();
  await page.evaluate(() => document.fonts.ready);
  await expect(page.getByText("Your privacy choices")).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
  expect(external).toEqual([]);
  await page.getByRole("button", { name: "Necessary only", exact: true }).click();
  await expect(page.getByText("Your privacy choices")).toBeHidden();
  await page.reload();
  await expect(page.getByText("Your privacy choices")).toBeHidden();
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
  await page.locator('[data-project-card][data-project="leaving-earth"]').click();
  await page.locator("#project-dialog [data-activate-film]").click();
  await expect(page.locator("#consent-dialog")).toBeVisible();
  expect(external).toEqual([]);
  await page.getByLabel("External media").check();
  await page.getByRole("button", { name: "Save settings", exact: true }).click();
  await expect(page.locator("iframe")).toHaveCount(1);
  await expect.poll(() => external.length).toBeGreaterThan(0);
  await page.locator("#project-dialog [data-consent-settings]").click();
  await expect(page.getByLabel("External media")).toBeChecked();
  await page.getByRole("button", { name: "Necessary only" }).click();
  await expect(page.locator("iframe")).toHaveCount(0);
  expect(external.some(url=>/googletagmanager|plausible/.test(url))).toBe(false);
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
  await expect(page.getByRole("button", { name: "Necessary only" })).toBeVisible();
  await page.getByRole("button", { name: "Necessary only" }).click();
  await page.locator('header a[href="/film-music/"]').click();
  await expect(page.locator("#portfolio-title")).toHaveText("Film Music.");
  await context.close();
  const nojs = await browser.newContext({ javaScriptEnabled: false });
  const fallback = await nojs.newPage();
  await fallback.goto(baseURL! + "/");
  await fallback.getByRole("link", { name: "Explore Film Music" }).click();
  await expect(fallback.locator("[data-project-card]")).toHaveCount(13);
  await fallback.locator("[data-project-card]").first().click();
  await expect(fallback.locator(".direct-project")).toBeVisible();
  await nojs.close();
});

test("a changed consent version asks again and settings stay keyboard accessible", async ({page}) => {
  await page.emulateMedia({reducedMotion:'reduce'});
  await page.addInitScript(() => localStorage.setItem('pianissimo.consent',JSON.stringify({version:999,externalMedia:true,analytics:true,marketing:true})));
  await page.goto("/");
  await page.locator("html.js").waitFor();
  await expect(page.getByText("Your privacy choices")).toBeVisible();
  await page.getByRole("button",{name:"Settings",exact:true}).focus();
  await page.keyboard.press("Enter");
  await expect(page.locator("#consent-dialog")).toBeVisible();
  await expect(page.getByLabel("Necessary")).toBeChecked();
  await expect(page.getByLabel("Necessary")).toBeDisabled();
  expect(await page.locator('.consent-dialog').evaluate(element=>getComputedStyle(element).scrollBehavior)).toBe('auto');
});
