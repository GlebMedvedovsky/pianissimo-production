import { coordinator } from "./player";
import { prepareShowreelAudio } from "./showreel-audio";

/** The hero element persists between Astro pages. Looping belongs exclusively
 * to HTMLMediaElement.loop: no ended handler, source reset, or loop timer. */
export function initLocalVideo(signal: AbortSignal) {
  const hero = document.querySelector<HTMLVideoElement>(
    "[data-slot-video=hero]",
  );
  const scene = document.querySelector<HTMLElement>(".hero-scene");
  const toggle = scene?.querySelector<HTMLButtonElement>("[data-hero-toggle]");
  const sound = scene?.querySelector<HTMLButtonElement>(
    "[data-showreel-sound]",
  );
  const status = scene?.querySelector<HTMLElement>("[data-hero-status]");
  const full = scene?.querySelector<HTMLButtonElement>("[data-hero-full]");
  const close = scene?.querySelector<HTMLButtonElement>("[data-hero-close]");
  let observer: IntersectionObserver | undefined;
  let wasOverflow = "";
  const render = () => {
    if (!hero) return;
    if (toggle) {
      const label = hero.paused ? "Play showreel" : "Pause showreel";
      toggle.dataset.state = hero.paused ? "play" : "pause";
      toggle.dataset.tooltip = label;
      toggle.setAttribute("aria-label", label);
    }
    if (sound) {
      const label = hero.muted ? "Enable sound" : "Mute sound";
      sound.dataset.state = hero.muted ? "off" : "on";
      sound.dataset.tooltip = label;
      sound.setAttribute("aria-label", label);
      sound.setAttribute("aria-pressed", String(!hero.muted));
    }
    if (status)
      status.textContent = hero.error
        ? "Video unavailable. Please try again."
        : hero.paused
          ? "Showreel paused"
          : "";
  };
  const closeFull = () => {
    if (!scene?.classList.contains("is-full-frame")) return;
    scene.classList.remove("is-full-frame");
    scene.removeAttribute("role");
    scene.removeAttribute("aria-modal");
    hero!.controls = false;
    hero!.removeAttribute("tabindex");
    close!.hidden = true;
    document.documentElement.style.overflow = wasOverflow;
    full?.focus({ preventScroll: true });
  };
  if (hero) {
    for (const event of [
      "play",
      "pause",
      "volumechange",
      "error",
      "loadedmetadata",
    ])
      hero.addEventListener(event, render, { signal });
    toggle?.addEventListener(
      "click",
      () => {
        if (hero.paused) void coordinator.useShowreel(hero, false);
        else coordinator.pause();
      },
      { signal },
    );
    const enableShowreelAudio = () => {
      try {
        const gain = prepareShowreelAudio(hero);
        if (gain) {
          coordinator.setShowreelGain(hero, gain);
          coordinator.resumeShowreelAudio(hero);
        }
      } catch (error) {
        console.warn("Showreel Web Audio could not be prepared.", error);
      }
    };
    sound?.addEventListener(
      "click",
      () => {
        if (hero.muted) {
          enableShowreelAudio();
          void coordinator.useShowreel(hero);
        } else coordinator.mute();
      },
      { signal },
    );
    document.addEventListener(
      "click",
      (event) => {
        const mute = (event.target as Element).closest("[data-player=mute]");
        if (mute && coordinator.currentMedia === hero && !hero.muted)
          enableShowreelAudio();
      },
      { signal },
    );
    full?.addEventListener(
      "click",
      () => {
        wasOverflow = document.documentElement.style.overflow;
        document.documentElement.style.overflow = "hidden";
        scene!.classList.add("is-full-frame");
        scene!.setAttribute("role", "dialog");
        scene!.setAttribute("aria-modal", "true");
        hero.controls = true;
        hero.tabIndex = 0;
        close!.hidden = false;
        close!.focus({ preventScroll: true });
      },
      { signal },
    );
    close?.addEventListener("click", closeFull, { signal });
    document.addEventListener(
      "keydown",
      (event) => {
        if (!scene?.classList.contains("is-full-frame")) return;
        if (event.key === "Escape") {
          event.preventDefault();
          closeFull();
        }
        if (event.key === "Tab") {
          event.preventDefault();
          if (document.activeElement === close) hero?.focus();
          else close?.focus();
        }
      },
      { signal },
    );
    // The first visit is muted. Navigation pauses may resume on return; explicit
    // user pauses remain paused. The persisted video keeps its source and sound state.
    if (scene && !hero.dataset.started) {
      hero.dataset.started = "true";
      if (
        !matchMedia("(prefers-reduced-motion: reduce)").matches &&
        location.hash !== "#about" &&
        !coordinator.state.track
      ) {
        hero.muted = true;
        void coordinator.useShowreel(hero, false, false);
      }
    }
    const resume = () => {
      if (
        scene &&
        location.hash !== "#about" &&
        scrollY < scene.offsetTop + innerHeight * 0.25 &&
        !matchMedia("(prefers-reduced-motion: reduce)").matches
      )
        void coordinator.resumeShowreelFromNavigation(hero);
    };
    if (
      document.documentElement.dataset.section === "directing" ||
      location.hash === "#about"
    )
      coordinator.pauseShowreelForNavigation(hero);
    else resume();
    if (scene) {
      observer = new IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            if (
              !entry.isIntersecting &&
              hero.muted &&
              !scene.classList.contains("is-full-frame")
            )
              coordinator.pauseShowreelForNavigation(hero);
            else if (entry.isIntersecting) resume();
          }
        },
        { threshold: 0 },
      );
      observer.observe(scene.querySelector(".scene-sticky")!);
    }
    if (scene)
      window.addEventListener(
        "scroll",
        () => {
          if (
            hero.muted &&
            !scene.classList.contains("is-full-frame") &&
            scrollY >
              scene.offsetTop +
                Math.max(1, scene.offsetHeight - innerHeight) * 0.55
          )
            coordinator.pauseShowreelForNavigation(hero);
          else resume();
        },
        { passive: true, signal },
      );
    render();
  }
  let startingNative: HTMLVideoElement | null = null;
  const stopVideos = coordinator.registerOther(() =>
    document.querySelectorAll<HTMLVideoElement>("video").forEach((v) => {
      if (v !== startingNative) v.pause();
    }),
  );
  // A native trailer play gesture participates in the same one-source policy.
  document.addEventListener(
    "play",
    (event) => {
      const video = event.target;
      if (video === hero && coordinator.currentMedia !== hero) {
        startingNative = hero;
        void coordinator.useShowreel(hero!, false);
        startingNative = null;
      }
      if (
        video instanceof HTMLVideoElement &&
        video.matches("[data-local-trailer]")
      ) {
        startingNative = video;
        coordinator.activateExternal();
        startingNative = null;
      }
    },
    { capture: true, signal },
  );
  const hoverAllowed = matchMedia(
    "(hover:hover) and (pointer:fine) and (prefers-reduced-motion:no-preference)",
  );
  for (const video of document.querySelectorAll<HTMLVideoElement>(
    "[data-hover-video]",
  )) {
    const card = video.closest<HTMLElement>("[data-project-card]")!;
    const start = () => {
      if (!hoverAllowed.matches) return;
      document
        .querySelectorAll<HTMLVideoElement>("[data-hover-video]")
        .forEach((v) => {
          if (v !== video) v.pause();
        });
      if (!video.getAttribute("src")) video.src = video.dataset.src!;
      video.muted = true;
      void video
        .play()
        .then(() => card.classList.add("hover-playing"))
        .catch(() => {});
    };
    const stop = () => {
      video.pause();
      card.classList.remove("hover-playing");
    };
    card.addEventListener("pointerenter", start, { signal });
    card.addEventListener("pointerleave", stop, { signal });
    card.addEventListener("focus", start, { signal });
    card.addEventListener("blur", stop, { signal });
  }
  return () => {
    closeFull();
    observer?.disconnect();
    stopVideos();
    document
      .querySelectorAll<HTMLVideoElement>(
        "[data-hover-video],[data-local-trailer]",
      )
      .forEach((v) => v.pause());
  };
}
