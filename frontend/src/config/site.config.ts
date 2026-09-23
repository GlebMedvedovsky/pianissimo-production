export type Theme = "dark" | "light";
export type Section =
  "home" | "music" | "directing" | "about" | "project" | "legal";
export const siteConfig = {
  about: {
    themeMode: "follow-section" as "follow-section" | Theme,
    defaultTheme: "dark" as Theme,
  },
  media: {
    pauseOnSections: ["directing", "about"] as Section[],
    hidePanelOnSections: ["directing", "about"] as Section[],
    volume: 0.65,
    showreelFadeDuration: 1000,
  },
  motion: {
    respectReducedMotion: true,
    transitionDuration: 800,
    scrollDistance: 1.25,
  },
  directing: { defaultFilter: "Director" as "Director" | "Assistant Director" },
  navigation: {
    music: "Film Music",
    directing: "Directing",
    about: "About",
    contact: "Contact",
  },
  contacts: { email: "sebastian@pianissimo-production.de" as string | null },
  prototype: true,
};
export function aboutTheme(
  context: Section | null,
  settings = siteConfig.about,
): Theme {
  return settings.themeMode === "follow-section"
    ? context === "directing"
      ? "light"
      : settings.defaultTheme
    : settings.themeMode;
}
