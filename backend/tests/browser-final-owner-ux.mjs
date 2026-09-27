import { chromium } from "playwright";
import fs from "node:fs";
import path from "node:path";
import assert from "node:assert/strict";

const root = path.resolve(import.meta.dirname, "../..");
const credentials = JSON.parse(
  fs.readFileSync(
    path.join(root, "backend/storage/app/private/browser-qa.json"),
    "utf8",
  ),
);
const browser = await chromium.launch({ channel: "chrome", headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
page.setDefaultTimeout(60000);
const checks = [];

async function globalSearch(query, expected) {
  const input = page.locator(".fi-global-search-field input[type=search]");
  await input.fill(query);
  await page.locator(".fi-global-search-results-ctn").waitFor();
  await page.getByText(expected, { exact: false }).first().waitFor();
  return page.locator(".fi-global-search-results-ctn").innerText();
}

try {
  await page.goto("http://127.0.0.1:8080/admin/login");
  await page.locator("input[type=email]").fill(credentials.email);
  await page.locator("input[type=password]").fill(credentials.password);
  await page.getByRole("button", { name: "Anmelden", exact: true }).click();
  await page.waitForURL("**/admin/dashboard");

  const cases = [
    ["Sebastian", "About"],
    ["Kinnhakenhank", "Projekt: Kinnhakenhank"],
    ["Au milieu", "Au milieu"],
    ["Bild", "Medien"],
    ["About Bild", "About"],
    ["Galerie", "Galerie sortieren"],
    ["Trailer", "Trailer"],
    ["Credits", "Credits bearbeiten"],
    ["Spotify", "Social Links"],
    ["SEO", "SEO"],
    ["Suchmaschine", "SEO"],
    ["noindex", "Index / noindex"],
    ["Cookie", "Cookie / Consent"],
    ["Analytics", "Analytics"],
    ["Veröffentlichen", "Veröffentlichung"],
    ["Vorschau", "Website-Vorschau öffnen"],
    ["Rollback", "Letzte Änderung zurücksetzen"],
    ["Passwort", "Passwort zurücksetzen"],
    ["verofentlichen", "Veröffentlichung"],
    ["galeri", "Galerie"],
    ["analytycs", "Analytics"],
    ["suchmachine", "SEO"],
  ];
  for (const [query, expected] of cases) await globalSearch(query, expected);
  checks.push(
    "22 realistic and typo-tolerant global searches return owner-facing results",
  );

  const topSearch = page.locator(".fi-global-search-field input[type=search]");
  await topSearch.fill("Spotify");
  await page.waitForFunction(() => {
    const box = document.querySelector(".fi-global-search-results-ctn");
    return (
      box?.innerText.includes("Social Links") &&
      !box?.innerText.includes("Projekte")
    );
  });
  const beforeKeyboardOpen = page.url();
  await topSearch.press("ArrowDown");
  await page.waitForFunction(() =>
    document.activeElement?.classList.contains("fi-global-search-result-link"),
  );
  await page.keyboard.press("Enter");
  await page.waitForURL((url) => url.toString() !== beforeKeyboardOpen);
  assert.match(page.url(), /\/admin\/(contact|social|help)/);
  await globalSearch("Cookie", "Cookie / Consent");
  await topSearch.press("Escape");
  await page
    .locator(".fi-global-search-results-ctn")
    .waitFor({ state: "hidden" });
  checks.push("Global search keyboard Down/Enter and Escape behavior");

  await globalSearch("Kinnhakenhank", "Projekt: Kinnhakenhank");
  await page.getByText("Projekt: Kinnhakenhank", { exact: true }).click();
  await page.waitForURL("**/admin/projects/**/edit");
  await page.getByRole("tab", { name: "Richtungen & Bilder" }).click();
  await page
    .getByText(/Ziehen zum Sortieren; alternativ die Pfeiltasten/)
    .first()
    .waitFor();
  await page.getByText(/Galeriebild = Bild\/Still/).waitFor();
  await page.getByRole("tab", { name: "Credits & Links" }).click();
  await page
    .getByText(/\* bedeutet nur: In einem vorhandenen Eintrag/)
    .waitFor();
  await page
    .getByText(/Die Reihenfolge bestimmt die Darstellung auf der Website/)
    .waitFor();
  await page.getByRole("tab", { name: "SEO" }).click();
  await page.getByText(/keine H1\/H2\/H3-Überschrift/).waitFor();
  await page
    .getByText(
      /Aktiviert: Dieses Projekt soll NICHT|Deaktiviert: Dieses Projekt darf/,
    )
    .waitFor();
  checks.push("Project gallery, Credits, SEO, noindex and reorder guidance");

  await page.goto("http://127.0.0.1:8080/admin/media-assets/create");
  await page
    .getByText(/Diese Angabe beschreibt nur den vorgesehenen Einsatz/)
    .waitFor();
  await page.getByText("Upload-Hilfe", { exact: true }).waitFor();
  await page.goto("http://127.0.0.1:8080/admin/about");
  await page.getByText(/Aktive Darstellung: (Bild|Video)/).waitFor();
  await page.getByText("About-Hilfe", { exact: true }).waitFor();
  await page.goto("http://127.0.0.1:8080/admin/social");
  await page
    .getByText(/Ziehen zum Sortieren; alternativ die Pfeiltasten/)
    .waitFor();
  checks.push("Media purpose, About mode and Social reorder wording");

  await page.goto("http://127.0.0.1:8080/admin/help");
  const helpSearch = page.getByPlaceholder("Nur im Handbuch suchen …");
  await helpSearch.fill("galeri");
  await page.getByText("Galerie sortieren", { exact: true }).waitFor();
  await helpSearch.fill("Au milieu");
  await page.getByText("Keine passenden Artikel", { exact: true }).waitFor();
  await helpSearch.fill("verofentlichen");
  await page.getByText("Für Website übernehmen", { exact: true }).waitFor();
  checks.push("Help search remains help-only and typo tolerant");

  await page.goto("http://127.0.0.1:8080/admin/publication");
  await page.getByRole("link", { name: "Anleitung", exact: true }).waitFor();
  await page
    .getByRole("link", { name: "Website-Vorschau öffnen", exact: true })
    .waitFor();
  await page
    .getByRole("button", { name: /Änderungen( erneut)? prüfen/ })
    .waitFor();
  const publication = await page.locator("main").innerText();
  assert(!/PowerShell|Node|SSR|filesystem path/i.test(publication));
  checks.push("Publication owner flow and contextual handbook action");

  const ownerPaths = [
    "/admin/dashboard",
    "/admin/help",
    "/admin/projects",
    "/admin/tracks",
    "/admin/media-assets",
    "/admin/about",
    "/admin/cv-entries",
    "/admin/home",
    "/admin/music",
    "/admin/directing",
    "/admin/contact",
    "/admin/social",
    "/admin/seo",
    "/admin/appearance",
    "/admin/analytics",
    "/admin/impressum",
    "/admin/privacy",
    "/admin/consent",
    "/admin/external-services",
    "/admin/licenses",
    "/admin/site",
    "/admin/users",
    "/admin/publication",
  ];
  for (const route of ownerPaths) {
    const response = await page.goto(`http://127.0.0.1:8080${route}`);
    assert(response?.ok(), `${route} failed`);
    await page.locator("main").waitFor();
  }
  checks.push("All 23 main owner pages load in the real CMS");

  await page.setViewportSize({ width: 390, height: 844 });
  for (const route of ownerPaths) {
    const response = await page.goto(`http://127.0.0.1:8080${route}`);
    assert(response?.ok(), `${route} failed at narrow width`);
    await page.locator("main").waitFor();
    assert(
      await page
        .locator("body")
        .evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
      `${route} overflows at 390px`,
    );
  }
  await globalSearch("Kinnhakenhank", "Kinnhakenhank");
  assert(
    await page
      .locator("body")
      .evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
    "Global search overflows at 390px",
  );
  checks.push(
    "All main pages and long search results avoid horizontal overflow at 390px",
  );

  console.log(JSON.stringify({ passed: checks.length, checks }, null, 2));
} finally {
  await browser.close();
}
