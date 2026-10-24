import type { Manifest } from '../../../contracts/content';
import { socialIcon } from './social';

export type IconRail = NonNullable<Manifest['site']['iconRail']>;
export type IconRailItem = IconRail['items'][number];

const labels: Record<IconRailItem['type'], string> = {
  mail: 'Mail', instagram: 'Instagram', imdb: 'IMDb', spotify: 'Spotify', 'music-hub': 'Music Hub',
  'crew-united': 'Crew United', tidal: 'Tidal', 'apple-music': 'Apple Music', samples: 'Samples unterm Sofa',
  deezer: 'Deezer', youtube: 'YouTube', vimeo: 'Vimeo', soundcloud: 'SoundCloud', letterboxd: 'Letterboxd', website: 'Website', other: 'Other',
};

export const railIcon: Record<IconRailItem['type'], string> = {
  ...socialIcon,
  mail: 'mail', samples: 'sofa',
} as Record<IconRailItem['type'], string>;

/** Explicit compatibility projection for manifests created before Phase 5C. */
export function resolveIconRail(site: Manifest['site']): IconRail {
  if (site.iconRail) return {
    style: site.iconRail.style,
    items: [...site.iconRail.items].sort((a, b) => a.sort_order - b.sort_order),
  };
  const items: IconRailItem[] = [];
  const add = (type: IconRailItem['type'], destination: string | null | undefined, label = labels[type], visible = true) => {
    if (!destination || items.some((item) => item.type === type)) return;
    items.push({ type, label, destination, visible, sort_order: items.length });
  };
  add('mail', site.contacts.email);
  for (const link of [...(site.socialLinks || [])].sort((a, b) => a.sort_order - b.sort_order)) add(link.provider as IconRailItem['type'], link.url, link.label, link.visible);
  add('instagram', site.contacts.instagram);
  add('music-hub', site.profileLinks?.musicHub);
  add('imdb', site.profileLinks?.imdb);
  add('crew-united', site.profileLinks?.crewUnited);
  add('spotify', site.artistLinks?.spotify || site.contacts.spotify);
  add('tidal', site.artistLinks?.tidal);
  add('apple-music', site.artistLinks?.appleMusic);
  add('deezer', site.artistLinks?.deezer);
  add('samples', site.contacts.samples);
  return { style: 'outline', items };
}

export function railHref(item: IconRailItem): string {
  return item.type === 'mail' ? `mailto:${item.destination}` : item.destination || '#';
}
