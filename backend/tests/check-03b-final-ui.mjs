import { chromium } from "playwright";
import fs from "node:fs";
import path from "node:path";
import assert from "node:assert/strict";
const root = path.resolve(import.meta.dirname, "../.."),
  out = path.join(root, "docs/qa/03B/browser");
const credentials = JSON.parse(
  fs.readFileSync(
    path.join(root, "backend/storage/app/private/local-admin.json"),
  ),
);
const browser = await chromium.launch({ channel: "chrome", headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
page.setDefaultTimeout(60000);
try {
  await page.goto("http://127.0.0.1:8080/admin/login");
  await page.locator("input[type=email]").fill(credentials.email);
  await page.locator("input[type=password]").fill(credentials.password);
  await page.getByRole("button", { name: "Anmelden", exact: true }).click();
  await page.waitForURL("**/admin/dashboard");
  assert.match(
    await page.locator("main").innerText(),
    /Öffentliche Website: noch nicht verbunden/,
  );
  await page.locator(".fi-user-menu-trigger").click();
  for (const label of [
    "Dark Mode einschalten",
    "Light Mode einschalten",
    "Systemthema benutzen",
  ])
    await page
      .getByRole("button", { name: label, exact: true })
      .waitFor({ state: "visible" });
  await page.screenshot({
    path: path.join(out, "admin-theme-menu.png"),
    fullPage: true,
  });
  await page.keyboard.press("Escape");
  await page.goto("http://127.0.0.1:8080/admin/publication");
  await page
    .getByRole("button", { name: "Vorschau erstellen", exact: true })
    .click();
  await page.getByText("Lokales Paket erstellt", { exact: true }).waitFor();
  assert.match(
    await page.locator("code[title]").textContent(),
    /^[a-f0-9]{64}$/,
  );
  assert.match(await page.locator("main").innerText(), /Letzte Übernahme:/s);
  await page.screenshot({
    path: path.join(out, "cms-final-history.png"),
    fullPage: true,
  });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  await page
    .getByRole("button", { name: "Vorschau erstellen", exact: true })
    .click();
  await page.getByText("Lokales Paket erstellt", { exact: true }).waitFor();
  assert(
    await page.locator("body").evaluate((e) => e.scrollWidth <= innerWidth + 1),
  );
  await page.screenshot({
    path: path.join(out, "publication-mobile.png"),
    fullPage: true,
    animations: "disabled",
  });
  fs.writeFileSync(
    path.join(out, "final-ui.json"),
    JSON.stringify(
      {
        passed: 4,
        checks: [
          "Dashboard distinguishes local apply and production",
          "Top-right user menu exposes all three Admin Theme options",
          "Full candidate hash selectable and rollback history visible",
          "Publication page fits 390px",
        ],
        browser: await browser.version(),
      },
      null,
      2,
    ),
  );
  console.log("4 final CMS UI checks passed");
} finally {
  await browser.close();
}
