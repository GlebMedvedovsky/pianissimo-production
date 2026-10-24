import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { validateManifest } from '../../contracts/content';
import { railHref, resolveIconRail } from '../../frontend/src/lib/iconRail';

const manifest = validateManifest(JSON.parse(readFileSync('content/manifest.json', 'utf8')));

test('legacy manifests project to one ordered rail without duplicate provider icons', () => {
  const rail = resolveIconRail(manifest.site);
  assert.equal(rail.style, 'outline');
  assert.equal(rail.items[0]?.type, 'mail');
  assert.equal(rail.items.filter((item) => item.type === 'spotify').length, 1);
  assert.ok(rail.items.some((item) => item.type === 'samples'));
  assert.equal(railHref(rail.items[0]!), `mailto:${manifest.site.contacts.email}`);
});

test('canonical rail controls global style, visibility and order', () => {
  const site = structuredClone(manifest.site);
  site.iconRail = {
    style: 'rounded',
    items: [
      { type: 'spotify', label: 'Spotify', destination: 'https://example.test/spotify', visible: false, sort_order: 2 },
      { type: 'mail', label: 'Mail', destination: 'rail@example.test', visible: true, sort_order: 1 },
    ],
  };
  const rail = resolveIconRail(site);
  assert.equal(rail.style, 'rounded');
  assert.deepEqual(rail.items.map((item) => item.type), ['mail', 'spotify']);
  assert.equal(rail.items[1]?.visible, false);
  assert.ok(rail.items.every((item) => !('style' in item)));
});
