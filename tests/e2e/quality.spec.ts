import { test, expect } from "@playwright/test";
test("local pages have no application console errors, broken requests or external embeds", async ({
  page,
}) => {
  const errors: string[] = [],
    failures: string[] = [],
    thirdParty: string[] = [];
  page.on("pageerror", (e) => errors.push(String(e)));
  page.on("console", (m) => {
    if (
      m.type() === "error" &&
      !/kaspersky|ERR_NETWORK|favicon/i.test(m.text())
    )
      errors.push(m.text());
  });
  page.on("response", (r) => {
    if (r.url().startsWith("http://127.0.0.1:") && r.status() >= 400)
      failures.push(`${r.status()} ${r.url()}`);
  });
  page.on("request", (r) => {
    const h = new URL(r.url()).hostname;
    if (/youtube|vimeo|spotify|instagram|googleapis|gstatic/.test(h))
      thirdParty.push(r.url());
  });
  for (const route of [
    "/",
    "/film-music/",
    "/directing/",
    "/projects/kinnhakenhank/",
    "/legal/",
    "/privacy/",
  ]) {
    await page.goto(route);
    await page.evaluate(() => document.fonts.ready);
    await expect(page.locator("meta[name=robots]")).toHaveAttribute(
      "content",
      "noindex, nofollow",
    );
    await expect(page.locator("iframe")).toHaveCount(0);
  }
  expect(errors).toEqual([]);
  expect(failures).toEqual([]);
  expect(thirdParty).toEqual([]);
});
test("keyboard can choose both logo halves; modal focus stays contained", async ({
  page,
}) => {
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.goto("/");
  await page.getByRole("button", { name: "Keep off" }).click();
  const music = page.getByRole("link", { name: "Explore Film Music" }),
    directing = page.getByRole("link", { name: "Explore Directing" });
  await music.focus();
  await expect(music).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(directing).toBeFocused();
  await page.keyboard.press("Enter");
  await expect(page).toHaveURL(/\/directing\/$/);
  await page.locator("[data-project-card]").first().focus();
  await page.keyboard.press("Enter");
  await expect(page.locator("#project-dialog")).toBeVisible();
  for (let i = 0; i < 12; i++) {
    await page.keyboard.press("Tab");
    expect(
      await page.evaluate(
        () => !!document.activeElement?.closest("#project-dialog"),
      ),
    ).toBe(true);
  }
  await page.keyboard.press("Escape");
  await expect(page.locator("[data-project-card]").first()).toBeFocused();
});
