import { navigate } from "astro:transitions/client";
type TransitionBeforeSwapEvent = DocumentEventMap["astro:before-swap"];
type TransitionBeforePreparationEvent =
  DocumentEventMap["astro:before-preparation"];
import { aboutTheme, siteConfig, type Section } from "../config/site.config";
import { initScene, scrollToSceneAbout } from "./navigation/scene";
import { initFilters } from "./navigation/filters";
import { initProjectModal } from "./navigation/modals";
import { coordinator, playable, renderPlayer } from "./media/player";
import { initConsent } from "./media/consent";
import { initAnalytics } from "./analytics";
import { initLocalVideo } from "./media/local-video";
let cleanup = () => {};
let navigationDirection = "right";
let initialLoad = true;
type PortfolioTransitionDirection = "to-directing" | "to-music";
type PortfolioTransitionState = { direction: PortfolioTransitionDirection };
let activePortfolioTransition: PortfolioTransitionState | undefined;
const portfolioTransitionDirection = (
  from: URL,
  to: URL,
): PortfolioTransitionDirection | undefined => {
  if (from.pathname === "/film-music/" && to.pathname === "/directing/")
    return "to-directing";
  if (from.pathname === "/directing/" && to.pathname === "/film-music/")
    return "to-music";
};
const markPortfolioTransition = (
  target: Document,
  direction: PortfolioTransitionDirection,
) => {
  const root = target.documentElement;
  root.dataset.portfolioTransition = direction;
  const layers: [string, string][] = [
    [".player:not([hidden])", "portfolio-player"],
  ];
  if (
    matchMedia(
      "(max-width:600px), (max-height:550px) and (orientation:landscape)",
    ).matches
  )
    layers.push(
      [".site-header", "portfolio-header"],
      [".social-rail", "portfolio-social"],
    );
  for (const [selector, name] of layers) {
    const element = target.querySelector<HTMLElement>(selector);
    if (element) element.style.setProperty("view-transition-name", name);
  }
  const oldAnimation =
    direction === "to-directing"
      ? "portfolio-slide-out-left"
      : "portfolio-slide-out-right";
  const newAnimation =
    direction === "to-directing"
      ? "portfolio-slide-in-right"
      : "portfolio-slide-in-left";
  const oldTarget = direction === "to-directing" ? "-100vw" : "100vw";
  const newOrigin = direction === "to-directing" ? "100vw" : "-100vw";
  const style = target.createElement("style");
  style.dataset.portfolioTransitionStyle = "";
  style.textContent = `@keyframes ${oldAnimation}{from{opacity:1;transform:translateX(0)}to{opacity:1;transform:translateX(${oldTarget})}}@keyframes ${newAnimation}{from{opacity:1;transform:translateX(${newOrigin})}to{opacity:1;transform:translateX(0)}}::view-transition{overflow:clip}::view-transition-old(root){animation:${oldAnimation} var(--transition-duration) ease both!important;opacity:1!important;mix-blend-mode:normal}::view-transition-new(root){animation:${newAnimation} var(--transition-duration) ease both!important;opacity:1!important;mix-blend-mode:normal}`;
  target.head.append(style);
  if (target === document) {
    const namedLayer = target.querySelector<HTMLElement>(
      '[style*="view-transition-name"]',
    );
    if (namedLayer) void getComputedStyle(namedLayer).viewTransitionName;
  }
};
const clearPortfolioTransition = (state?: PortfolioTransitionState) => {
  if (state && activePortfolioTransition !== state) return;
  const root = document.documentElement;
  root.removeAttribute("data-portfolio-transition");
  document
    .querySelectorAll<HTMLElement>(".site-header,.social-rail,.player")
    .forEach((element) => element.style.removeProperty("view-transition-name"));
  document
    .querySelectorAll("[data-portfolio-transition-style]")
    .forEach((style) => style.remove());
  activePortfolioTransition = undefined;
};
const currentSection = () =>
  document.documentElement.dataset.section as Section;
function init() {
  cleanup();
  const controller = new AbortController();
  const signal = controller.signal;
  const removeScene = initScene();
  const removeVideo = initLocalVideo(signal);
  initFilters(signal);
  initProjectModal();
  initConsent(signal);
  initAnalytics(signal);
  coordinator.section(location.hash === "#about" ? "about" : currentSection());
  renderPlayer();
  const root = document.documentElement;
  root.classList.add("js");
  root.classList.remove("is-loading");
  root.style.setProperty(
    "--transition-duration",
    `${siteConfig.motion.transitionDuration}ms`,
  );
  const header = document.querySelector<HTMLElement>(".site-header--portfolio");
  let headerObserver: ResizeObserver | undefined;
  if (header) {
    const syncHeaderSpace = () =>
      root.style.setProperty(
        "--portfolio-header-height",
        `${header.getBoundingClientRect().height}px`,
      );
    headerObserver = new ResizeObserver(syncHeaderSpace);
    headerObserver.observe(header);
    syncHeaderSpace();
  }
  if (location.hash === "#about" || (initialLoad && location.hash)) {
    requestAnimationFrame(() => {
      if (location.hash === "#about" && scrollToSceneAbout()) return;
      document
        .getElementById(location.hash.slice(1))
        ?.scrollIntoView({ behavior: "instant" });
    });
  }
  initialLoad = false;
  const about = document.querySelector<HTMLElement>("#about");
  let aboutObserver: IntersectionObserver | undefined;
  if (about) {
    const theme = aboutTheme(history.state?.aboutContext || null);
    about.dataset.theme = theme;
    root.dataset.aboutTheme = theme;
    let intersects = false;
    let wasActive = false;
    const syncAbout = () => {
      const scene = about.closest<HTMLElement>(".hero-scene");
      const active =
        intersects &&
        (!scene?.dataset.phase || scene.dataset.phase === "about");
      if (active) {
        root.dataset.theme = theme;
        if (!wasActive) coordinator.section("about");
      } else if (currentSection() === "home") {
        root.dataset.theme = "dark";
      }
      wasActive = active;
    };
    aboutObserver = new IntersectionObserver(
      (entries) => {
        intersects = entries[0]?.isIntersecting || false;
        syncAbout();
      },
      { threshold: 0, rootMargin: "-30% 0px -40% 0px" },
    );
    aboutObserver.observe(about);
    document.addEventListener("pianissimo:scene-change", syncAbout, { signal });
    if (location.hash === "#about") root.dataset.theme = theme;
  }
  document
    .querySelectorAll<HTMLDialogElement>(".small-dialog")
    .forEach((dialog) => {
      dialog.addEventListener(
        "click",
        (event) => {
          if (event.target === dialog) dialog.close();
        },
        { signal },
      );
    });
  cleanup = () => {
    controller.abort();
    removeScene();
    removeVideo();
    aboutObserver?.disconnect();
    headerObserver?.disconnect();
    root.style.removeProperty("--portfolio-header-height");
  };
}
document.addEventListener("astro:page-load", init);
document.addEventListener("astro:before-preparation", (event) => {
  const e = event as TransitionBeforePreparationEvent;
  clearPortfolioTransition();
  const portfolioDirection = portfolioTransitionDirection(e.from, e.to);
  if (
    portfolioDirection &&
    !matchMedia("(prefers-reduced-motion: reduce)").matches &&
    typeof document.startViewTransition === "function"
  ) {
    const state = { direction: portfolioDirection };
    activePortfolioTransition = state;
    markPortfolioTransition(document, portfolioDirection);
    e.signal.addEventListener("abort", () => clearPortfolioTransition(state), {
      once: true,
    });
    const load = e.loader;
    e.loader = async () => {
      try {
        await load();
      } catch (error) {
        clearPortfolioTransition(state);
        throw error;
      }
      if (e.defaultPrevented) clearPortfolioTransition(state);
    };
  }
  document.documentElement.classList.add("is-loading");
  const leavingHomeForShowreelDestination =
    currentSection() === "home" &&
    (e.to.pathname === "/film-music/" ||
      e.to.pathname === "/directing/" ||
      (e.to.pathname === "/" && e.to.hash === "#about"));
  if (leavingHomeForShowreelDestination) {
    const hero = document.querySelector<HTMLVideoElement>(
      "[data-slot-video=hero]",
    );
    coordinator.hideShowreelPanelForNavigation();
    if (hero && !coordinator.fadeShowreelForNavigation(hero)) {
      coordinator.pauseShowreelForNavigation(hero);
    }
  } else coordinator.cancelShowreelFade();
  if (e.navigationType === "traverse") {
    navigationDirection =
      e.to.pathname === "/film-music/"
        ? "left"
        : e.to.pathname === "/directing/"
          ? "right"
          : currentSection() === "music"
            ? "right"
            : "left";
  }
  document.documentElement.dataset.direction = navigationDirection;
});
document.addEventListener("astro:before-swap", (event) => {
  const e = event as TransitionBeforeSwapEvent;
  cleanup();
  e.newDocument.documentElement.classList.add("js");
  e.newDocument.documentElement.dataset.direction = navigationDirection;
  const portfolioDirection = portfolioTransitionDirection(e.from, e.to);
  const transitionState = activePortfolioTransition;
  if (portfolioDirection && transitionState?.direction === portfolioDirection) {
    markPortfolioTransition(e.newDocument, portfolioDirection);
    void e.viewTransition.finished.then(
      () => clearPortfolioTransition(transitionState),
      () => clearPortfolioTransition(transitionState),
    );
  } else clearPortfolioTransition();
  if (e.to.pathname === "/" && e.to.hash === "#about") {
    const context = e.info?.aboutContext ?? history.state?.aboutContext ?? null;
    const theme = aboutTheme(context);
    e.newDocument.documentElement.dataset.theme = theme;
    e.newDocument.documentElement.dataset.aboutTheme = theme;
    const about = e.newDocument.querySelector<HTMLElement>("#about");
    if (about) about.dataset.theme = theme;
  }
  e.newDocument.documentElement.style.setProperty(
    "--transition-duration",
    `${siteConfig.motion.transitionDuration}ms`,
  );
});
// Resolve the sticky About destination after the router's native hash scroll.
document.addEventListener(
  "click",
  (event) => {
    const el = event.target as Element;
    const brand = el.closest<HTMLAnchorElement>("[data-brand-home]");
    if (
      brand &&
      event.button === 0 &&
      !event.metaKey &&
      !event.ctrlKey &&
      !event.shiftKey &&
      !event.altKey
    ) {
      event.preventDefault();
      event.stopImmediatePropagation();
      const hero = document.querySelector<HTMLVideoElement>(
        "[data-slot-video=hero]",
      );
      if (hero) coordinator.restartShowreel(hero);
      void navigate("/");
      return;
    }
    const about = el.closest<HTMLAnchorElement>("[data-about]");
    if (about) {
      event.preventDefault();
      event.stopImmediatePropagation();
      const section = currentSection();
      const context =
        section === "directing"
          ? "directing"
          : section === "music"
            ? "music"
            : null;
      void navigate("/#about", {
        state: { aboutContext: context },
        info: { aboutContext: context },
      }).then(() => {
        requestAnimationFrame(() => scrollToSceneAbout());
      });
      return;
    }
    const link = el.closest<HTMLElement>("[data-direction]");
    if (link) navigationDirection = link.dataset.direction || "right";
  },
  true,
);
document.addEventListener("click", (event) => {
  const el = event.target as Element;
  const contact = el.closest("[data-contact]");
  if (contact) {
    document.querySelector<HTMLDialogElement>("#contact-dialog")!.showModal();
  }
  const close = el.closest("[data-close-dialog]");
  if (close) close.closest("dialog")?.close();
  const track = el.closest<HTMLElement>("[data-play-track]");
  if (track) {
    const list = track.closest(".track-list");
    const ids = Array.from(
      list?.querySelectorAll<HTMLElement>("[data-track-row]:not([hidden])") ||
        [],
    ).map((row) => row.dataset.trackRow!);
    void coordinator.select(
      playable(track.dataset.playTrack!),
      ids.map(playable),
    );
  }
});
document.addEventListener("submit", (event) => {
  const form = event.target as HTMLFormElement;
  if (form.id !== "contact-form") return;
  event.preventDefault();
  if (form.reportValidity())
    form.querySelector(".form-status")!.textContent =
      "Your message is ready. Nothing has been sent: delivery is disabled in this local prototype.";
});
window.addEventListener("pageshow", () =>
  document.documentElement.classList.remove("is-loading"),
);
// Native dialog supplies inert/background behavior; explicit wrapping also keeps Tab out of browser chrome.
document.addEventListener("keydown", (event) => {
  if (event.key !== "Tab") return;
  const dialogs = Array.from(
    document.querySelectorAll<HTMLDialogElement>("dialog[open]"),
  );
  const dialog = dialogs.at(-1);
  if (!dialog) return;
  const controls = Array.from(
    dialog.querySelectorAll<HTMLElement>(
      'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),summary,[tabindex="0"]',
    ),
  ).filter((el) => el.getClientRects().length > 0);
  const first = controls[0],
    last = controls.at(-1);
  if (!first) return;
  if (
    event.shiftKey &&
    (document.activeElement === first ||
      !dialog.contains(document.activeElement))
  ) {
    event.preventDefault();
    last!.focus();
  } else if (
    !event.shiftKey &&
    (document.activeElement === last ||
      !dialog.contains(document.activeElement))
  ) {
    event.preventDefault();
    first.focus();
  }
});
