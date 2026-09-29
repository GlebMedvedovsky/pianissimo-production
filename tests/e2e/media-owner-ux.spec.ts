import { expect, test, type Page } from "@playwright/test";
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve(import.meta.dirname, "../..");
const digest = (file: string) =>
  crypto.createHash("sha256").update(fs.readFileSync(file)).digest("hex");

async function login(page: Page) {
  const qaCredentials = path.join(
    root,
    "backend/storage/app/private/browser-qa.json",
  );
  const credentials = JSON.parse(
    fs.readFileSync(
      fs.existsSync(qaCredentials)
        ? qaCredentials
        : path.join(root, "backend/storage/app/private/local-admin.json"),
      "utf8",
    ),
  ) as { email: string; password: string };
  await page.goto("http://127.0.0.1:8080/admin/login");
  await page.locator("input[type=email]").fill(credentials.email);
  await page.locator("input[type=password]").fill(credentials.password);
  await page.getByRole("button", { name: "Anmelden", exact: true }).click();
  await page.waitForURL("**/admin/dashboard");
}

test("owner sees explicit media modes, independent project roles and type-safe selectors", async ({
  page,
}, info) => {
  test.skip(
    info.project.name !== "desktop",
    "one authenticated desktop owner flow",
  );
  test.setTimeout(300_000);
  const manifest = path.join(root, "content/manifest.json");
  const manifestBefore = digest(manifest);
  await login(page);

  await page.goto("http://127.0.0.1:8080/admin/about");
  await expect(
    page.getByRole("radio", { name: "Bild", exact: true }),
  ).toBeChecked();
  await expect(
    page.getByText("Aktive Darstellung: Bild.", { exact: false }),
  ).toBeVisible();
  await expect(page.getByRole("combobox", { name: /^Bild/ })).toBeVisible();
  await page.getByRole("radio", { name: "Video", exact: true }).check();
  await expect(
    page.getByText("Aktive Darstellung: Video.", { exact: false }),
  ).toBeVisible();
  await expect(page.getByRole("combobox", { name: /^Video\*/ })).toBeVisible();
  await expect(
    page.getByRole("combobox", { name: "Video-Poster", exact: true }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("radio", { name: "Bild", exact: true }),
  ).toBeChecked();

  await page.goto("http://127.0.0.1:8080/admin/projects/kinnhakenhank/edit");
  await page
    .getByRole("tab", { name: "Richtungen & Bilder", exact: true })
    .click();
  await expect(
    page.getByRole("combobox", { name: /^Projekt-Cover/ }).first(),
  ).toBeVisible();
  await expect(
    page.getByRole("combobox", { name: "Hover-Video", exact: true }).first(),
  ).toBeVisible();
  await expect(
    page.getByRole("combobox", { name: /^Eigenständige Medienrolle/ }).first(),
  ).toBeVisible();
  await expect(
    page.getByText("Galeriebilder werden durch Verschieben sortiert.", {
      exact: false,
    }),
  ).toBeVisible();

  await page.goto("http://127.0.0.1:8080/admin/tracks/au-milieu-song/edit");
  await expect(
    page.getByRole("combobox", { name: "Playback-Datei", exact: true }),
  ).toContainText("· Audio ·");

  await page.goto("http://127.0.0.1:8080/admin/seo");
  await expect(
    page.getByRole("combobox", { name: "Standard-OG-Bild", exact: true }),
  ).toBeVisible();

  await page.goto("http://127.0.0.1:8080/admin/publication");
  const popupPromise = page.waitForEvent("popup");
  await page
    .getByRole("link", { name: "Website-Vorschau öffnen", exact: true })
    .click();
  const preview = await popupPromise;
  await preview.waitForLoadState("domcontentloaded");
  await preview.goto(`${preview.url()}#about`, {
    waitUntil: "domcontentloaded",
    timeout: 120_000,
  });
  await expect(preview.locator('#about [data-slot="about"] img')).toHaveCount(
    1,
  );
  await expect(preview.locator('#about [data-slot-video="about"]')).toHaveCount(
    0,
  );
  expect(preview.url()).not.toContain("token=");
  expect(digest(manifest)).toBe(manifestBefore);
});
