import { chromium } from "playwright";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";

const root = path.resolve(import.meta.dirname, "../..");
const credentials = JSON.parse(
  fs.readFileSync(
    path.join(root, "backend/storage/app/private/browser-qa.json"),
    "utf8",
  ),
);
const baseline = JSON.parse(
  fs.readFileSync(
    path.join(
      root,
      "backend/storage/app/private/browser-self-service-baseline.json",
    ),
    "utf8",
  ),
);
const fixture = path.join(
  root,
  "backend/storage/app/private/browser-self-service.jpg",
);
fs.writeFileSync(
  fixture,
  Buffer.from(
    "/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==",
    "base64",
  ),
);

const manifest = path.join(root, "content/manifest.json");
const manifestBefore = hashFile(manifest);
const browser = await chromium.launch({ channel: "chrome", headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
page.setDefaultTimeout(120_000);

try {
  await page.goto("http://127.0.0.1:8080/admin/login");
  await page.locator("input[type=email]").fill(credentials.email);
  await page.locator("input[type=password]").fill(credentials.password);
  await page.getByRole("button", { name: "Anmelden", exact: true }).click();
  await page.waitForURL("**/admin/dashboard");

  await page.goto("http://127.0.0.1:8080/admin/media-assets/create");
  await page.getByLabel(/Stabile ID/).fill("synthetic-self-service");
  await page.locator("input[type=file]").setInputFiles(fixture);
  await page
    .getByText("browser-self-service.jpg", { exact: false })
    .first()
    .waitFor();
  await page.getByLabel("Alternativtext").fill("Synthetic private preview QA");
  await page.getByLabel("Materialstatus").selectOption("test");
  await page.getByRole("button", { name: "Erstellen", exact: true }).click();
  await page.waitForURL("**/synthetic-self-service/edit");

  await page.goto("http://127.0.0.1:8080/admin/about");
  await page.getByRole("radio", { name: "Bild", exact: true }).check();
  const image = page.getByRole("combobox", { name: /^Bild/ });
  await image.click();
  await page
    .getByRole("option", { name: /Synthetic private preview QA/ })
    .click();
  await page
    .getByRole("button", { name: "Entwurf speichern", exact: true })
    .click();
  await page.getByText("Entwurf gespeichert", { exact: true }).waitFor();
  assert.equal(hashFile(manifest), manifestBefore);

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
  const previewImage = preview.locator(
    'img[src*="/media/preview/synthetic-self-service."]',
  );
  await previewImage.waitFor();
  assert(
    (await previewImage.evaluate(
      (element) => element.complete && element.naturalWidth > 0,
    )) === true,
  );
  assert(!preview.url().includes("token="));

  await page
    .getByRole("button", { name: /Änderungen (?:erneut )?prüfen/ })
    .click();
  await page
    .getByText("Test-Medium im regulären Paket.", { exact: false })
    .waitFor();
  assert.equal(hashFile(manifest), baseline.manifestSha256);
  console.log(
    "Synthetic Test/unconfirmed media rendered in private Preview and remained blocked for publication.",
  );
} finally {
  fs.rmSync(fixture, { force: true });
  await browser.close();
}

function hashFile(file) {
  return crypto
    .createHash("sha256")
    .update(fs.readFileSync(file))
    .digest("hex");
}
