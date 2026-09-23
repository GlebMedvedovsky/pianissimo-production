import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e",
  testMatch: "scene-about.spec.ts",
  outputDir: "../test-results/scene-about",
  reporter: "list",
  workers: 1,
  timeout: 60000,
  use: {
    baseURL: "http://127.0.0.1:4321",
    channel: "chrome",
    headless: true,
    trace: "retain-on-failure",
  },
  webServer: {
    command:
      "node scripts/astro.mjs dev --root frontend --host 127.0.0.1 --port 4321 --ignore-lock",
    url: "http://127.0.0.1:4321",
    reuseExistingServer: true,
  },
  projects: [
    { name: "desktop", use: { viewport: { width: 1440, height: 900 } } },
    { name: "wide", use: { viewport: { width: 1920, height: 1080 } } },
    {
      name: "tablet",
      use: { viewport: { width: 820, height: 1180 }, hasTouch: true },
    },
    {
      name: "mobile",
      use: {
        viewport: { width: 390, height: 844 },
        hasTouch: true,
        isMobile: true,
      },
    },
    {
      name: "narrow",
      use: {
        viewport: { width: 320, height: 740 },
        hasTouch: true,
        isMobile: true,
      },
    },
  ],
});
