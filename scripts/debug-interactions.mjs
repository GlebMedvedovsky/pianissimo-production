import { chromium } from "@playwright/test";
const b = await chromium.launch({ channel: "chrome", headless: true });
const p = await b.newPage({
  viewport: { width: 390, height: 844 },
  isMobile: true,
  hasTouch: true,
});
await p.goto("http://127.0.0.1:4321/film-music/");
await p.getByRole("button", { name: "Necessary only", exact: true }).click();
await p.getByRole("button", { name: "Fiction Film", exact: true }).click();
await p.locator("[data-project-card]:visible").first().click();
await p.keyboard.press("Tab");
await p.evaluate(() => {
  window.events = [];
  document.addEventListener("focusin", (e) =>
    window.events.push({
      type: "focus",
      tag: e.target.outerHTML.slice(0, 120),
    }),
  );
  window.addEventListener(
    "popstate",
    (e) => window.events.push({ type: "pop", state: e.state }),
    true,
  );
  document.addEventListener("astro:before-swap", () =>
    window.events.push({ type: "swap" }),
  );
});
await p.goBack();
await p.waitForTimeout(700);
console.log(
  "modal debug",
  await p.evaluate(() => ({
    events: window.events,
    active: document.activeElement.outerHTML.slice(0, 250),
    state: history.state,
    y: scrollY,
  })),
);
await p.goto("http://127.0.0.1:4323/film-music/");
await p.waitForFunction(() => window.__mediaTest);
const fixtures = JSON.parse(
  await (
    await import("node:fs/promises")
  ).readFile("tests/fixtures/audio.json", "utf8"),
);
await p.evaluate(async (tracks) => {
  await Promise.all(tracks.map((t) => window.__mediaTest.select(t.id, tracks)));
}, fixtures);
await p.waitForTimeout(600);
await p.evaluate(() => window.__mediaTest.coordinator.seek(0.98));
for (let i = 0; i < 4; i++) {
  console.log(
    "audio",
    await p.evaluate(() => ({
      state: window.__mediaTest.coordinator.state,
      element: {
        time: document.querySelector("audio").currentTime,
        duration: document.querySelector("audio").duration,
        paused: document.querySelector("audio").paused,
        ended: document.querySelector("audio").ended,
        readyState: document.querySelector("audio").readyState,
      },
    })),
  );
  await p.waitForTimeout(1500);
}
await b.close();
