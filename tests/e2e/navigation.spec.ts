import { test, expect } from "@playwright/test";
import { ready, shot, noOverflow } from "./helpers";

test("portfolio pair uses one opaque full-width slide timeline", async ({
  page,
}, info) => {
  test.skip(info.project.name !== "desktop", "desktop transition timeline");
  await ready(page, "/film-music/");
  await page.evaluate(() => {
    const state = window as typeof window & {
      portfolioAnimations?: Animation[];
      portfolioTransitionReady?: Promise<void>;
    };
    state.portfolioTransitionReady = new Promise<void>((resolve, reject) => {
      document.addEventListener(
        "astro:before-swap",
        (event) => {
          const transition = (event as DocumentEventMap["astro:before-swap"])
            .viewTransition;
          void transition.ready.then(() => {
            state.portfolioAnimations = document
              .getAnimations()
              .filter((animation) => {
                const effect = animation.effect;
                return (
                  effect instanceof KeyframeEffect &&
                  effect.pseudoElement?.endsWith("(root)")
                );
              });
            state.portfolioAnimations.forEach((animation) => animation.pause());
            resolve();
          }, reject);
        },
        { once: true },
      );
    });
  });
  await page.locator('header a[href="/directing/"]').click();
  await page.evaluate(
    () =>
      (
        window as typeof window & { portfolioTransitionReady: Promise<void> }
      ).portfolioTransitionReady,
  );
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect(page.locator("header [aria-current=page]")).toHaveText(
    "Directing",
  );
  await expect(page.locator(".site-header--portfolio")).toHaveCount(1);

  for (const percentage of [0, 25, 50, 75, 100]) {
    const geometry = await page.evaluate(async (progress) => {
      const animations = (
        window as typeof window & { portfolioAnimations: Animation[] }
      ).portfolioAnimations;
      for (const animation of animations) {
        const duration = (animation.effect as KeyframeEffect).getTiming().duration;
        animation.currentTime = Number(duration) * progress;
      }
      await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));
      const root = document.documentElement;
      const oldStyle = getComputedStyle(
        root,
        "::view-transition-old(root)",
      );
      const newStyle = getComputedStyle(
        root,
        "::view-transition-new(root)",
      );
      const x = (transform: string) =>
        transform === "none" ? 0 : new DOMMatrixReadOnly(transform).m41;
      return {
        oldX: x(oldStyle.transform),
        newX: x(newStyle.transform),
        oldOpacity: oldStyle.opacity,
        newOpacity: newStyle.opacity,
        oldBlend: oldStyle.mixBlendMode,
        newBlend: newStyle.mixBlendMode,
        width: innerWidth,
      };
    }, percentage / 100);
    expect(
      geometry.newX - geometry.oldX,
      JSON.stringify(geometry),
    ).toBeCloseTo(geometry.width, 0);
    expect(geometry.oldOpacity).toBe("1");
    expect(geometry.newOpacity, JSON.stringify(geometry)).toBe("1");
    expect(geometry.oldBlend).toBe("normal");
    expect(geometry.newBlend).toBe("normal");
    await page.screenshot({
      path: `test-results/${process.env.QA_LABEL || "dev"}/desktop-portfolio-slide-${percentage}.png`,
    });
  }

  await page.evaluate(() => {
    const animations = (
      window as typeof window & { portfolioAnimations: Animation[] }
    ).portfolioAnimations;
    animations.forEach((animation) => animation.finish());
  });
  await expect(page.locator("html")).not.toHaveAttribute(
    "data-portfolio-transition",
  );
  await noOverflow(page);
});

test("portfolio slide direction follows routes and survives history cancellation", async ({
  page,
}) => {
  test.setTimeout(90_000);
  await ready(page, "/film-music/");
  await page.evaluate(() => {
    const state = window as typeof window & {
      portfolioRoutes?: { from: string; to: string; mode?: string }[];
    };
    state.portfolioRoutes = [];
    document.addEventListener("astro:before-swap", (event) => {
      const transition = event as DocumentEventMap["astro:before-swap"];
      state.portfolioRoutes!.push({
        from: transition.from.pathname,
        to: transition.to.pathname,
        mode: document.documentElement.dataset.portfolioTransition,
      });
    });
  });
  await page.locator('header a[href="/directing/"]').click();
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect(page.locator("html")).not.toHaveAttribute(
    "data-portfolio-transition",
  );
  await page.locator('header a[href="/film-music/"]').click();
  await expect(page).toHaveURL(/\/film-music\/$/);
  await expect(page.locator("html")).not.toHaveAttribute(
    "data-portfolio-transition",
  );
  await page.evaluate(() => scrollTo({ top: 1200, behavior: "instant" }));
  const musicScroll = await page.evaluate(() => scrollY);
  await page.locator(".section-switch").click();
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect(page.locator("html")).not.toHaveAttribute(
    "data-portfolio-transition",
  );
  expect(await page.evaluate(() => scrollY)).toBeLessThan(10);

  await page.evaluate(() => scrollTo({ top: 900, behavior: "instant" }));
  const directingScroll = await page.evaluate(() => scrollY);
  await expect
    .poll(() => page.evaluate(() => history.state?.scrollY))
    .toBeCloseTo(directingScroll, -1);
  await page.goBack();
  await expect(page).toHaveURL(/\/film-music\/$/);
  await expect
    .poll(() => page.evaluate(() => scrollY))
    .toBeCloseTo(musicScroll, -1);
  await page.goForward();
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect
    .poll(() => page.evaluate(() => scrollY))
    .toBeCloseTo(directingScroll, -1);

  await page.locator(".section-switch").click();
  await expect(page).toHaveURL(/\/film-music\/$/);
  await page.evaluate(() => {
    document.addEventListener(
      "astro:after-swap",
      () => setTimeout(() => history.back(), 40),
      { once: true },
    );
  });
  await page.locator('header a[href="/directing/"]').click();
  await expect(page).toHaveURL(/\/film-music\/$/);
  await expect(page.locator("html")).not.toHaveAttribute(
    "data-portfolio-transition",
  );
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  await expect(page.locator("h1")).toContainText("Film Music");
  await page.getByRole("button", { name: "Fiction Film", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Fiction Film", exact: true }),
  ).toHaveAttribute("aria-pressed", "true");

  const routes = await page.evaluate(
    () =>
      (
        window as typeof window & {
          portfolioRoutes: { from: string; to: string; mode?: string }[];
        }
      ).portfolioRoutes,
  );
  expect(routes).toEqual(
    expect.arrayContaining([
      {
        from: "/film-music/",
        to: "/directing/",
        mode: "to-directing",
      },
      {
        from: "/directing/",
        to: "/film-music/",
        mode: "to-music",
      },
    ]),
  );
});

test("portfolio pair falls back to a direct swap for reduced motion", async ({
  page,
}) => {
  await page.emulateMedia({ reducedMotion: "reduce" });
  await ready(page, "/film-music/");
  await page.evaluate(() => {
    (window as typeof window & { portfolioMode?: string }).portfolioMode =
      "not-observed";
    document.addEventListener(
      "astro:before-swap",
      () => {
        (window as typeof window & { portfolioMode?: string }).portfolioMode =
          document.documentElement.dataset.portfolioTransition || "none";
      },
      { once: true },
    );
  });
  await page.locator(".section-switch").click();
  await expect(page).toHaveURL(/\/directing\/$/);
  expect(
    await page.evaluate(
      () =>
        (window as typeof window & { portfolioMode?: string }).portfolioMode,
    ),
  ).toBe("none");
  await expect(page.locator("html")).not.toHaveAttribute(
    "data-portfolio-transition",
  );
  await expect(page.locator("h1")).toContainText("Directing");
});

test("portfolio pair safely swaps when View Transitions are unavailable", async ({
  page,
}, info) => {
  test.skip(info.project.name !== "desktop", "one API fallback check");
  await page.addInitScript(() => {
    Object.defineProperty(document, "startViewTransition", {
      configurable: true,
      value: undefined,
    });
  });
  await ready(page, "/film-music/");
  await page.locator(".section-switch").click();
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect(page.locator("html")).not.toHaveAttribute(
    "data-portfolio-transition",
  );
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await expect(page.locator("h1")).toContainText("Directing");
});

test("portfolio transition smoke preserves shared UI and project dialog", async ({
  page,
}, info) => {
  test.skip(info.project.name !== "desktop", "one desktop smoke path");
  test.setTimeout(90_000);
  await ready(page, "/");
  await page.getByRole("button", { name: "Enable sound", exact: true }).click();
  await expect(page.locator(".player")).toBeVisible();
  await page.getByRole("link", { name: "Explore Film Music" }).click();
  await expect(page).toHaveURL(/\/film-music\/$/);
  await page.locator(".section-switch").click();
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect(page.locator("html")).not.toHaveAttribute(
    "data-portfolio-transition",
  );
  await page.locator("header [data-about]").click();
  await expect(page).toHaveURL(/\/#about$/);
  await expect(page.locator("#about")).toBeInViewport();
  await page.locator("header [data-brand-home]").click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.locator("#shared-audio")).toHaveCount(1);
  await expect(page.locator(".player")).toHaveCount(1);
  await page.getByRole("link", { name: "Explore Film Music" }).click();
  await expect(page).toHaveURL(/\/film-music\/$/);
  await page.locator("[data-project-card]").first().click();
  await expect(page.locator("#project-dialog")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.locator("#project-dialog")).not.toBeVisible();
  await expect(page.locator("#project-dialog")).toHaveCount(1);
  await noOverflow(page);
});

test("filters, modal history, focus and direct project reload", async ({
  page,
}, info) => {
  await ready(page, "/film-music/");
  await page.getByRole("button", { name: "Documentary", exact: true }).click();
  await expect(page.locator(".portfolio-empty")).toBeVisible();
  await page.getByRole("button", { name: "Fiction Film", exact: true }).click();
  const card = page.locator("[data-project-card]:visible").first();
  await card.scrollIntoViewIfNeeded();
  const y = await page.evaluate(() => scrollY);
  await card.click();
  await expect(page.locator("#project-dialog")).toBeVisible();
  await expect(page).toHaveURL(/\/projects\/kinnhakenhank\/$/);
  await shot(page, "project-modal", info);
  await page.keyboard.press("Tab");
  expect(
    await page.evaluate(
      () => !!document.activeElement?.closest("#project-dialog"),
    ),
  ).toBe(true);
  await page.goBack();
  await expect(page.locator("#project-dialog")).not.toBeVisible();
  expect(await page.evaluate(() => scrollY)).toBeCloseTo(y, -1);
  await expect(card).toBeFocused();
  await expect(
    page.getByRole("button", { name: "Fiction Film", exact: true }),
  ).toHaveAttribute("aria-pressed", "true");
  await page.goForward();
  await expect(page.locator("#project-dialog")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.locator("#project-dialog")).not.toBeVisible();
  await card.click();
  await page.reload();
  await expect(page.locator(".direct-project")).toBeVisible();
  await expect(page.locator("#project-dialog")).not.toBeVisible();
  await expect(page.locator(".direct-project h1")).toHaveText("Kinnhakenhank");
});
test("contextual About theme, navigation and history", async ({
  page,
}, info) => {
  await ready(page, "/directing/");
  await page
    .getByRole("button", { name: "Assistant Director", exact: true })
    .click();
  await expect(page.locator(".portfolio-empty")).toBeVisible();
  await page.locator("header [data-about]").click();
  await expect(page).toHaveURL(/\/#about$/);
  await expect(page.locator("#about")).toBeInViewport();
  await expect(page.locator("#about")).toHaveAttribute("data-theme", "light");
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await shot(page, "about-light", info);
  await page.goBack();
  await expect(page).toHaveURL(/\/directing\/$/);
  await expect(
    page.getByRole("button", { name: "Assistant Director", exact: true }),
  ).toHaveAttribute("aria-pressed", "true");
  await page.goForward();
  await expect(page.locator("#about")).toHaveAttribute("data-theme", "light");
  await page.locator(".back-menu").click();
  await expect(page).toHaveURL(/\/#main-menu$/);
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  await page.getByRole("link", { name: "Explore Film Music" }).click();
  await expect(page).toHaveURL(/\/film-music\/$/);
  await page.locator("header [data-about]").click();
  await expect(page.locator("#about")).toHaveAttribute("data-theme", "dark");
});
test("catalog combines filters, no fake audio and no handler accumulation", async ({
  page,
}, info) => {
  await ready(page, "/film-music/");
  await page.locator("#genre-filter").selectOption("Jazz");
  await page.locator("#emotion-filter").selectOption("uplifting");
  await expect(
    page.locator(".music-library [data-track-row]:visible"),
  ).toHaveCount(1);
  await page.locator("#emotion-filter").selectOption("dark");
  await expect(page.locator(".library-empty")).toBeVisible();
  await page.locator("[data-reset-tracks]").click();
  await expect(
    page.locator(".music-library [data-track-row]:visible"),
  ).toHaveCount(5);
  await expect(page.locator("[data-play-track]")).toHaveCount(0);
  await shot(page, "catalog", info);
  for (let i = 0; i < 3; i++) {
    await page.locator(".section-switch").click();
    await expect(page).toHaveURL(/\/directing\/$/);
    await page.locator(".section-switch").click();
    await expect(page).toHaveURL(/\/film-music\/$/);
  }
  await expect(page.locator("#shared-audio")).toHaveCount(1);
  await expect(page.locator(".player")).toHaveCount(1);
  await expect(page.locator("#project-dialog")).toHaveCount(1);
  await page.locator("[data-project-card]").first().click();
  await expect(page.locator("#project-dialog")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page).toHaveURL(/\/film-music\/$/);
});
