import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (path: string) => readFileSync(path, "utf8");

test("final pre-launch copy is consistent", () => {
  const library = read("frontend/src/components/MusicLibrary.astro");
  const pages = [
    read("frontend/src/components/Hero.astro"),
    read("frontend/src/pages/film-music.astro"),
    read("frontend/src/pages/directing.astro"),
    read("frontend/src/pages/draft-preview.astro"),
  ].join("\n");
  assert.match(library, /TURN YOUR WORRIES OFF AND THE MUSIC ON/);
  assert.doesNotMatch(library, /A WORLD OF SOUND/);
  assert.match(pages, /THE PERSON BEHIND THE WORK/);
  assert.doesNotMatch(pages, /BEHIND EVERY NOTE, A STORY|THE PERSON BEHIND THE PICTURE/);
});

test("scroll, footer and mobile filter spacing regressions stay encoded", () => {
  const css = read("frontend/src/styles/global.css");
  assert.match(css, /\.scroll-cue\s*\{[^}]*right:\s*var\(--page-gutter\)/s);
  assert.match(css, /\.scroll-cue\s+svg\s*\{[^}]*width:\s*24px/s);
  assert.match(css, /\.site-footer\s*\{[^}]*padding:\s*clamp\(32px/s);
  assert.match(css, /@media\(max-width:600px\)[\s\S]*\.library-filters select\s*\{[^}]*padding-left:\s*12px/s);
});

test("navigation uses one configured 0.5–1.0 second motion duration and reduced-motion bypass", () => {
  const config = read("frontend/src/config/site.config.ts");
  const scene = read("frontend/src/lib/navigation/scene.ts");
  assert.match(config, /transitionDuration:\s*900/);
  assert.match(scene, /duration=siteConfig\.motion\.transitionDuration/);
  assert.match(scene, /prefers-reduced-motion:\s*reduce/);
});
